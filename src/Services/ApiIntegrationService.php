<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use RuntimeException;
use Throwable;

final class ApiIntegrationService
{
    public function __construct(private Database $db, private string $root)
    {
        foreach ([$this->storageDir(), $this->root . '/storage/logs'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create API import folder: ' . $dir);
            }
            @chmod($dir, 0777);
        }
    }

    public function connectors(): array
    {
        return [
            'website_api' => [
                'label' => 'Website / Custom Sales API',
                'source_type' => 'website_sales',
                'mode' => 'live_api',
                'auth' => ['bearer', 'api_key', 'basic', 'none'],
                'summary' => 'Use this for Shopify, WooCommerce, custom website exports, or any endpoint that can return sales rows.',
                'setup' => 'Endpoint should return CSV, JSON array, or JSON object with rows/items/data using columns like order_number, product_name, quantity, taxable_amount, igst.',
            ],
            'shopify' => [
                'label' => 'Shopify Admin API',
                'source_type' => 'website_sales',
                'mode' => 'official_api',
                'auth' => ['bearer', 'api_key'],
                'summary' => 'Connect through a private/custom app token or an export endpoint already normalized to MIS columns.',
                'setup' => 'Use a sales/orders export endpoint. For full OAuth, add Shopify app credentials and callback approval, then reuse this token store.',
            ],
            'woocommerce' => [
                'label' => 'WooCommerce REST API',
                'source_type' => 'website_sales',
                'mode' => 'official_api',
                'auth' => ['basic', 'api_key'],
                'summary' => 'Use WooCommerce consumer key/secret or a normalized report endpoint for automatic website sales import.',
                'setup' => 'The import works immediately when the endpoint returns MIS-compatible CSV/JSON sales rows.',
            ],
            'amazon_spapi' => [
                'label' => 'Amazon Selling Partner API',
                'source_type' => 'amazon_b2c',
                'mode' => 'approval_required',
                'auth' => ['oauth'],
                'summary' => 'Best long-term path for Amazon, but it needs Amazon developer approval and OAuth/LWA credentials.',
                'setup' => 'Keep browser import as fallback until SP-API app approval and report permissions are available.',
            ],
            'zoho_books' => [
                'label' => 'Zoho Books P&L API',
                'source_type' => 'sample_workbook',
                'mode' => 'mapping_only',
                'auth' => ['oauth', 'bearer'],
                'summary' => 'Use only for Profit and Loss mapping, not invoice sales import.',
                'setup' => 'This connector is reserved for P&L extraction and category mapping as requested.',
            ],
        ];
    }

    public function connections(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM api_integrations ORDER BY label, provider');
        $map = [];
        foreach ($rows as $row) {
            $map[$row['provider']] = $row;
        }
        return $map;
    }

    public function connection(string $provider): ?array
    {
        return $this->db->fetch('SELECT * FROM api_integrations WHERE provider = ? LIMIT 1', [$provider]);
    }

    public function save(array $input): void
    {
        $provider = trim((string) ($input['provider'] ?? ''));
        $connectors = $this->connectors();
        if (!isset($connectors[$provider])) {
            throw new RuntimeException('Unknown API connector.');
        }
        $connector = $connectors[$provider];
        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new RuntimeException('API endpoint URL is required.');
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException('API endpoint must start with http:// or https://.');
        }
        $allowedAuth = $connector['auth'];
        $authType = (string) ($input['auth_type'] ?? $allowedAuth[0]);
        if (!in_array($authType, $allowedAuth, true)) {
            $authType = $allowedAuth[0];
        }
        $sourceType = (string) ($input['source_type'] ?? $connector['source_type']);
        $label = trim((string) ($input['label'] ?? $connector['label'])) ?: $connector['label'];
        $extra = [
            'header_name' => trim((string) ($input['header_name'] ?? 'X-API-Key')),
            'username' => trim((string) ($input['username'] ?? '')),
            'date_param' => trim((string) ($input['date_param'] ?? 'month')),
            'notes' => trim((string) ($input['notes'] ?? '')),
        ];
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $accessToken = trim((string) ($input['access_token'] ?? ''));
        if (($authType === 'api_key' || $authType === 'basic') && $apiKey === '' && $accessToken !== '') {
            $apiKey = $accessToken;
            $accessToken = '';
        }
        $refreshToken = trim((string) ($input['refresh_token'] ?? ''));

        $existing = $this->connection($provider);
        $apiKeySql = $apiKey !== '' ? $this->encrypt($apiKey) : ($existing['api_key_enc'] ?? null);
        $accessTokenSql = $accessToken !== '' ? $this->encrypt($accessToken) : ($existing['access_token_enc'] ?? null);
        $refreshTokenSql = $refreshToken !== '' ? $this->encrypt($refreshToken) : ($existing['refresh_token_enc'] ?? null);
        $status = $this->hasAuth($authType, $apiKeySql, $accessTokenSql, $extra) ? 'configured' : 'needs_credentials';

        $this->db->execute(
            'INSERT INTO api_integrations (provider, source_type, label, auth_type, base_url, api_key_enc, access_token_enc, refresh_token_enc, extra_json, status, last_message, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE source_type = VALUES(source_type), label = VALUES(label), auth_type = VALUES(auth_type), base_url = VALUES(base_url), api_key_enc = VALUES(api_key_enc), access_token_enc = VALUES(access_token_enc), refresh_token_enc = VALUES(refresh_token_enc), extra_json = VALUES(extra_json), status = VALUES(status), last_message = VALUES(last_message), enabled = 1, updated_at = NOW()',
            [
                $provider,
                $sourceType,
                $label,
                $authType,
                $baseUrl,
                $apiKeySql,
                $accessTokenSql,
                $refreshTokenSql,
                json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $status,
                $status === 'configured' ? 'API credentials saved. Use Test & Import to pull sales.' : 'Endpoint saved. Add credentials before importing.',
            ]
        );
    }

    public function disconnect(string $provider): void
    {
        $this->db->execute(
            'UPDATE api_integrations SET enabled = 0, status = "disconnected", api_key_enc = NULL, access_token_enc = NULL, refresh_token_enc = NULL, last_message = "Disconnected by user.", updated_at = NOW() WHERE provider = ?',
            [$provider]
        );
    }

    public function import(int $runId, string $provider, string $importMode = 'replace'): array
    {
        $connection = $this->connection($provider);
        if (!$connection || (int) ($connection['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('API connector is not configured.');
        }
        $authType = (string) $connection['auth_type'];
        $extra = json_decode((string) ($connection['extra_json'] ?? '{}'), true) ?: [];
        $url = $this->urlWithRunMonth((string) $connection['base_url'], $runId, (string) ($extra['date_param'] ?? 'month'));
        $headers = ['Accept: text/csv, application/json;q=0.9, */*;q=0.5'];
        $username = (string) ($extra['username'] ?? '');
        $apiKey = $this->decrypt((string) ($connection['api_key_enc'] ?? ''));
        $accessToken = $this->decrypt((string) ($connection['access_token_enc'] ?? ''));
        if ($authType === 'bearer' || $authType === 'oauth') {
            if ($accessToken === '') {
                throw new RuntimeException('Access token is required for this connector.');
            }
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        } elseif ($authType === 'api_key') {
            if ($apiKey === '') {
                throw new RuntimeException('API key is required for this connector.');
            }
            $headers[] = ((string) ($extra['header_name'] ?? 'X-API-Key') ?: 'X-API-Key') . ': ' . $apiKey;
        } elseif ($authType === 'basic') {
            if ($username === '' || $apiKey === '') {
                throw new RuntimeException('Username and API key/secret are required for Basic auth.');
            }
            $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $apiKey);
        }

        $response = $this->httpGet($url, $headers);
        $file = $this->responseToFile($provider, $response['body'], $response['content_type']);
        $rows = (new Importer($this->db))->import($runId, (string) $connection['source_type'], $file, basename($file), $importMode, false);
        (new MisCalculator($this->db))->calculate($runId);
        @unlink($file);
        $this->db->execute(
            'UPDATE api_integrations SET status = "connected", last_sync_at = NOW(), last_message = ?, updated_at = NOW() WHERE provider = ?',
            ['Imported ' . $rows . ' sales row(s) through API.', $provider]
        );
        return ['rows' => $rows, 'message' => 'Imported ' . $rows . ' sales row(s) through API.'];
    }

    public function connectionHealth(array $connection): string
    {
        $status = (string) ($connection['status'] ?? 'not_configured');
        return match ($status) {
            'connected' => 'Connected',
            'configured' => 'Configured',
            'needs_credentials' => 'Needs credentials',
            'failed' => 'Failed',
            'disconnected' => 'Disconnected',
            default => 'Not configured',
        };
    }

    private function hasAuth(string $authType, ?string $apiKey, ?string $accessToken, array $extra): bool
    {
        return match ($authType) {
            'none' => true,
            'bearer', 'oauth' => !empty($accessToken),
            'api_key' => !empty($apiKey),
            'basic' => !empty($apiKey) && trim((string) ($extra['username'] ?? '')) !== '',
            default => false,
        };
    }

    private function httpGet(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $meta = $http_response_header ?? [];
        $status = 0;
        $contentType = '';
        foreach ($meta as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $match)) {
                $status = (int) $match[1];
            }
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, 13));
            }
        }
        if ($body === false || $status >= 400) {
            throw new RuntimeException('API request failed' . ($status ? ' with HTTP ' . $status : '') . '.');
        }
        return ['body' => $body, 'content_type' => $contentType];
    }

    private function responseToFile(string $provider, string $body, string $contentType): string
    {
        $dir = $this->storageDir();
        $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $provider) ?: 'api';
        $isJson = str_contains(strtolower($contentType), 'json') || str_starts_with(trim($body), '[') || str_starts_with(trim($body), '{');
        $file = $dir . '/' . $base . '_' . time() . ($isJson ? '.csv' : '.csv');
        if ($isJson) {
            $rows = $this->jsonRows($body);
            $handle = fopen($file, 'wb');
            if (!$handle) {
                throw new RuntimeException('Could not create API import file.');
            }
            $headers = [];
            foreach ($rows as $row) {
                $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
            }
            if (!$headers) {
                throw new RuntimeException('API returned JSON, but no sales rows were found.');
            }
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn(string $key): mixed => $row[$key] ?? '', $headers));
            }
            fclose($handle);
            return $file;
        }
        file_put_contents($file, $body);
        return $file;
    }

    private function jsonRows(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('API returned invalid JSON.');
        }
        foreach (['rows', 'items', 'data', 'orders', 'sales'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $decoded = $decoded[$key];
                break;
            }
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $this->flatten($row);
            }
        }
        return $rows;
    }

    private function flatten(array $row, string $prefix = ''): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '_' . $key;
            if (is_array($value)) {
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList) {
                    $flat[$name] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $flat += $this->flatten($value, $name);
                }
            } else {
                $flat[$name] = $value;
            }
        }
        return $flat;
    }

    private function urlWithRunMonth(string $url, int $runId, string $dateParam): string
    {
        if ($dateParam === '') {
            return $url;
        }
        $run = $this->db->fetch('SELECT month FROM monthly_runs WHERE id = ?', [$runId]);
        $month = (string) ($run['month'] ?? '');
        if ($month === '' || str_contains($url, '{month}')) {
            return str_replace('{month}', rawurlencode($month), $url);
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . rawurlencode($dateParam) . '=' . rawurlencode($month);
    }

    private function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $this->key(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Could not encrypt API credential.');
        }
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $payload = substr($raw, 16);
        $decrypted = openssl_decrypt($payload, 'AES-256-CBC', $this->key(), OPENSSL_RAW_DATA, $iv);
        return $decrypted === false ? '' : $decrypted;
    }

    private function key(): string
    {
        return hash('sha256', getenv('MIS_SECRET_KEY') ?: $this->root . '|mis-tool-api', true);
    }

    private function storageDir(): string
    {
        return $this->root . '/storage/api-imports';
    }
}
