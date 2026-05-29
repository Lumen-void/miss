<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use RuntimeException;
use Throwable;

final class AutoImportService
{
    public function __construct(private Database $db, private string $root)
    {
        foreach ([$this->storageDir(), $this->downloadRoot(), $this->profileDir(), $this->root . '/storage/logs'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create auto-import folder: ' . $dir);
            }
            @chmod($dir, 0777);
        }
        $this->seedPortalSources();
    }

    public function portalSources(): array
    {
        return [
            'flipkart' => [
                'label' => 'Flipkart Report Centre',
                'url' => 'https://seller.flipkart.com/index.html#dashboard/metrics/report-centre?query=%7B%22one_time_request%22%3A%7B%22reportGroup%22%3Anull%2C%22reportName%22%3Anull%2C%22enable%22%3Atrue%2C%22status%22%3Anull%7D%2C%22repeat_request%22%3A%7B%22repeat_report_group_name%22%3Anull%2C%22repeat_report_name%22%3Anull%2C%22repeat_enable%22%3Atrue%7D%2C%22pagination%22%3A%7B%22page_size%22%3A10%2C%22starting_page%22%3A1%7D%2C%22request_report%22%3A%7B%22create_request%22%3Afalse%2C%22report_type%22%3Anull%2C%22report_subtype%22%3Anull%2C%22repeat_report%22%3Afalse%7D%7D',
            ],
            'blinkit' => [
                'label' => 'Blinkit Payout Details',
                'url' => 'https://seller.blinkit.com/dashboard/billing?billing=payout_details',
            ],
            'easecommerce' => [
                'label' => 'EaseCommerce Sales Report',
                'url' => 'https://easecommerce.in/app/employee/reports/sales-report',
            ],
            'amazon_b2c' => [
                'label' => 'Amazon MTR B2C',
                'url' => 'https://sellercentral.amazon.in/mytax/gstreports/ondemand',
                'report_hint' => 'Open GST Reports, choose MTR, then download the B2C report.',
            ],
            'amazon_b2b' => [
                'label' => 'Amazon MTR B2B',
                'url' => 'https://sellercentral.amazon.in/mytax/gstreports/ondemand',
                'report_hint' => 'Open GST Reports, choose MTR, then download the B2B report.',
            ],
            'amazon_str' => [
                'label' => 'Amazon STR',
                'url' => 'https://sellercentral.amazon.in/mytax/gstreports/ondemand',
                'report_hint' => 'Open GST Reports, choose STR, then download the settlement/tax report.',
            ],
        ];
    }

    public function createJob(int $runId, array $sourceTypes, string $importMode): int
    {
        $sources = $this->normalizeSources($sourceTypes);
        if (!$sources) {
            throw new RuntimeException('Choose at least one portal source.');
        }
        $importMode = $importMode === 'append' ? 'append' : 'replace';
        $this->db->execute(
            'INSERT INTO auto_import_jobs (run_id, status, import_mode, sources_json, downloads_dir, profile_dir, created_at, updated_at) VALUES (?, "queued", ?, ?, ?, ?, NOW(), NOW())',
            [$runId, $importMode, json_encode($sources), $this->downloadRoot() . '/' . $runId, $this->profileDir()]
        );
        $jobId = $this->db->lastInsertId();
        $this->event($jobId, '', 'info', 'Auto-import job queued.', ['sources' => $sources, 'mode' => $importMode]);
        return $jobId;
    }

    public function startJobInBackground(int $jobId): void
    {
        $php = $this->phpBinary();
        $worker = $this->root . '/bin/run_auto_import.php';
        $log = $this->root . '/storage/logs/auto-import-job-' . $jobId . '.log';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --job-id=' . (int) $jobId . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $output = [];
        exec($cmd, $output);
        $pid = isset($output[0]) ? (int) $output[0] : null;
        $this->db->execute('UPDATE auto_import_jobs SET runner_pid = ?, updated_at = NOW() WHERE id = ?', [$pid, $jobId]);
        $this->event($jobId, '', 'info', 'Background runner started.', ['pid' => $pid]);
    }

    public function runJob(int $jobId): void
    {
        $job = $this->db->fetch('SELECT * FROM auto_import_jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            throw new RuntimeException('Auto-import job not found.');
        }
        if (in_array($job['status'], ['running', 'importing'], true)) {
            return;
        }

        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [(int) $job['run_id']]);
        if (!$run) {
            throw new RuntimeException('Monthly run not found for auto-import job.');
        }
        if ((int) ($run['locked'] ?? 0) === 1) {
            $this->failJob($jobId, 'This run is finalized and locked. Unlock it before auto-importing.');
            return;
        }

        $sources = json_decode((string) $job['sources_json'], true) ?: [];
        $downloadsDir = $this->downloadRoot() . '/' . $job['run_id'] . '/job-' . $jobId;
        $jobDir = $this->storageDir() . '/job-' . $jobId;
        foreach ([$downloadsDir, $jobDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create auto-import job folder.');
            }
            @chmod($dir, 0777);
        }

        $eventsPath = $jobDir . '/events.jsonl';
        $manifestPath = $jobDir . '/manifest.json';
        $configPath = $jobDir . '/runner-config.json';
        $config = [
            'jobId' => $jobId,
            'runId' => (int) $job['run_id'],
            'sources' => $this->sourceConfigs($sources),
            'profileDir' => $this->profileDir(),
            'downloadDir' => $downloadsDir,
            'eventsPath' => $eventsPath,
            'manifestPath' => $manifestPath,
            'manualWaitMs' => 180000,
            'debugPort' => $this->debugPort(),
        ];
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($configPath, 0666);

        $this->db->execute(
            'UPDATE auto_import_jobs SET status = "running", downloads_dir = ?, profile_dir = ?, manifest_path = ?, events_path = ?, events_synced_bytes = 0, started_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$downloadsDir, $this->profileDir(), $manifestPath, $eventsPath, $jobId]
        );

        $this->event($jobId, '', 'info', 'Opening browser automation runner.', ['config' => $configPath]);
        $code = $this->runNodeRunner($configPath);
        $this->syncEventFile($jobId);

        if ($code !== 0 && !is_file($manifestPath)) {
            $this->failJob($jobId, 'Browser runner failed before producing a manifest.');
            return;
        }

        $this->db->execute('UPDATE auto_import_jobs SET status = "importing", updated_at = NOW() WHERE id = ?', [$jobId]);
        $importedRows = $this->importManifestFiles($jobId, (int) $job['run_id'], $manifestPath, (string) $job['import_mode']);
        $this->syncEventFile($jobId);
        (new MisCalculator($this->db))->calculate((int) $job['run_id']);
        if ($importedRows > 0) {
            $warehouse = $this->db->fetch('SELECT id FROM warehouses WHERE is_active = 1 ORDER BY id LIMIT 1');
            if ($warehouse) {
                $stockRows = (new InventoryService($this->db))->syncSalesRun((int) $job['run_id'], (int) $warehouse['id']);
                $this->event($jobId, '', 'success', 'Synced imported sales into warehouse stock movements.', ['rows' => $stockRows]);
            }
        }

        $status = $importedRows > 0 ? 'completed' : 'needs_attention';
        $message = $importedRows > 0 ? 'Auto-import completed inside the app.' : 'No files were imported. Complete login or use the portal export button in the browser window so the app can capture it.';
        $this->db->execute('UPDATE auto_import_jobs SET status = ?, completed_at = NOW(), updated_at = NOW(), error_message = NULL WHERE id = ?', [$status, $jobId]);
        $this->event($jobId, '', $importedRows > 0 ? 'success' : 'warning', $message, ['rows_imported' => $importedRows]);
    }

    public function syncEventFile(int $jobId): void
    {
        $job = $this->db->fetch('SELECT events_path, events_synced_bytes FROM auto_import_jobs WHERE id = ?', [$jobId]);
        $path = $job['events_path'] ?? '';
        if (!$path || !is_file($path)) {
            return;
        }
        $offset = (int) ($job['events_synced_bytes'] ?? 0);
        $size = filesize($path);
        if ($size === false || $size <= $offset) {
            return;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return;
        }
        fseek($handle, $offset);
        $chunk = stream_get_contents($handle) ?: '';
        fclose($handle);
        foreach (explode("\n", trim($chunk)) as $line) {
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }
            $this->event(
                $jobId,
                (string) ($event['sourceType'] ?? ''),
                (string) ($event['level'] ?? 'info'),
                (string) ($event['message'] ?? ''),
                $event['context'] ?? []
            );
        }
        $this->db->execute('UPDATE auto_import_jobs SET events_synced_bytes = ?, updated_at = NOW() WHERE id = ?', [$size, $jobId]);
    }

    public function saveSchedule(int $runId, bool $enabled, string $frequency, string $runTime, array $sourceTypes, string $importMode): void
    {
        $frequency = in_array($frequency, ['daily', 'monthly'], true) ? $frequency : 'daily';
        $runTime = preg_match('/^\d{2}:\d{2}$/', $runTime) ? $runTime : '09:00';
        $sources = $this->normalizeSources($sourceTypes);
        if (!$sources) {
            $sources = array_keys($this->portalSources());
        }
        $importMode = $importMode === 'append' ? 'append' : 'replace';
        $nextRunAt = $enabled ? $this->nextRunAt($frequency, $runTime) : null;
        $this->db->execute(
            'INSERT INTO auto_import_schedules (run_id, enabled, frequency, run_time, import_mode, sources_json, next_run_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), frequency = VALUES(frequency), run_time = VALUES(run_time), import_mode = VALUES(import_mode), sources_json = VALUES(sources_json), next_run_at = VALUES(next_run_at), updated_at = NOW()',
            [$runId, $enabled ? 1 : 0, $frequency, $runTime, $importMode, json_encode($sources), $nextRunAt]
        );
    }

    public function runDueSchedules(): int
    {
        $schedules = $this->db->fetchAll('SELECT * FROM auto_import_schedules WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) ORDER BY next_run_at, id');
        $count = 0;
        foreach ($schedules as $schedule) {
            $sources = json_decode((string) $schedule['sources_json'], true) ?: [];
            $jobId = $this->createJob((int) $schedule['run_id'], $sources, (string) $schedule['import_mode']);
            $this->runJob($jobId);
            $nextRunAt = $this->nextRunAt((string) $schedule['frequency'], (string) $schedule['run_time']);
            $this->db->execute('UPDATE auto_import_schedules SET last_run_at = NOW(), next_run_at = ?, updated_at = NOW() WHERE id = ?', [$nextRunAt, $schedule['id']]);
            $count++;
        }
        return $count;
    }

    public function stopJob(int $jobId): void
    {
        $job = $this->db->fetch('SELECT * FROM auto_import_jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            return;
        }
        $patterns = array_filter([
            (string) ($job['manifest_path'] ?? ''),
            (string) ($job['events_path'] ?? ''),
            $this->storageDir() . '/job-' . $jobId . '/runner-config.json',
        ]);
        $killed = 0;
        $commands = explode("\n", (string) shell_exec('ps axo pid=,command= 2>/dev/null'));
        foreach ($commands as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('/^(\d+)\s+(.+)$/', $line, $match)) {
                continue;
            }
            $pid = (int) $match[1];
            $command = $match[2];
            if ($pid <= 0 || $pid === getmypid()) {
                continue;
            }
            $matchesJob = false;
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && str_contains($command, $pattern)) {
                    $matchesJob = true;
                    break;
                }
            }
            if (!$matchesJob) {
                continue;
            }
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 15);
            } else {
                @exec('kill ' . (int) $pid . ' 2>/dev/null');
            }
            $killed++;
        }
        $this->db->execute(
            'UPDATE auto_import_jobs SET status = "failed", error_message = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?',
            ['Stopped by user. Start a fresh fetch when ready.', $jobId]
        );
        $this->deleteDirectory((string) ($job['downloads_dir'] ?? ''));
        $this->event($jobId, '', 'warning', 'Stopped browser auto-import job.', ['processes' => $killed]);
    }

    public function cleanupTempReports(): void
    {
        $this->deleteDirectory($this->root . '/storage/browser-downloads');
        $this->deleteDirectory($this->root . '/storage/app-captured-reports');
        foreach ([$this->downloadRoot(), $this->root . '/storage/app-captured-reports'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
                @chmod($dir, 0777);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $root = realpath($this->root . '/storage');
        $target = realpath($dir);
        if (!$root || !$target || !str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_dir($target)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($target);
    }

    private function importManifestFiles(int $jobId, int $runId, string $manifestPath, string $importMode): int
    {
        if (!is_file($manifestPath)) {
            $this->event($jobId, '', 'warning', 'Runner did not produce a manifest file.', []);
            return 0;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
        $files = $manifest['files'] ?? [];
        $total = 0;
        $seenSourceCounts = [];
        foreach ($files as $file) {
            $sourceType = (string) ($file['sourceType'] ?? '');
            $path = (string) ($file['path'] ?? '');
            if (!$sourceType || !is_file($path)) {
                continue;
            }
            $checksum = hash_file('sha256', $path);
            $duplicate = $this->db->fetch('SELECT id FROM source_files WHERE run_id = ? AND source_type = ? AND checksum = ? LIMIT 1', [$runId, $sourceType, $checksum]);
            if ($duplicate) {
                $this->event($jobId, $sourceType, 'warning', 'Skipped duplicate captured report.', ['file' => basename($path)]);
                $this->deleteCapturedReport($path);
                continue;
            }
            try {
                $effectiveMode = ($seenSourceCounts[$sourceType] ?? 0) === 0 ? $importMode : 'append';
                $rows = (new Importer($this->db))->import($runId, $sourceType, $path, basename($path), $effectiveMode, false);
                $seenSourceCounts[$sourceType] = ($seenSourceCounts[$sourceType] ?? 0) + 1;
                $total += $rows;
                $this->deleteCapturedReport($path);
                $this->event($jobId, $sourceType, 'success', 'Imported report into the app and removed the captured file.', ['file' => basename($path), 'rows' => $rows, 'mode' => $effectiveMode]);
            } catch (Throwable $e) {
                $this->event($jobId, $sourceType, 'error', 'Captured report could not be imported: ' . $e->getMessage(), ['file' => basename($path)]);
            }
        }
        return $total;
    }

    private function deleteCapturedReport(string $path): void
    {
        $root = realpath($this->downloadRoot());
        $file = realpath($path);
        if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
            @unlink($file);
        }
    }

    private function runNodeRunner(string $configPath): int
    {
        $node = $this->nodeBinary();
        $runner = $this->root . '/bin/portal_runner.js';
        $cmd = escapeshellarg($node) . ' ' . escapeshellarg($runner) . ' --config ' . escapeshellarg($configPath);
        $output = [];
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $this->event((int) (json_decode((string) file_get_contents($configPath), true)['jobId'] ?? 0), '', 'error', 'Node runner exited with an error.', ['exit_code' => $code, 'output' => implode("\n", array_slice($output, -10))]);
        }
        return (int) $code;
    }

    private function nodeBinary(): string
    {
        $configured = getenv('MIS_NODE_BIN');
        if ($configured && is_executable($configured)) {
            return $configured;
        }
        foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'node';
    }

    private function failJob(int $jobId, string $message): void
    {
        $this->db->execute('UPDATE auto_import_jobs SET status = "failed", error_message = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?', [$message, $jobId]);
        $this->event($jobId, '', 'error', $message, []);
    }

    private function event(int $jobId, string $sourceType, string $level, string $message, array $context): void
    {
        $this->db->execute(
            'INSERT INTO auto_import_events (job_id, source_type, level, message, context_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$jobId, $sourceType, $level, $message, json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
    }

    private function sourceConfigs(array $sourceTypes): array
    {
        $all = $this->portalSources();
        $configs = [];
        foreach ($sourceTypes as $sourceType) {
            if (!isset($all[$sourceType])) {
                continue;
            }
            $configs[] = [
                'sourceType' => $sourceType,
                'label' => $all[$sourceType]['label'],
                'url' => $all[$sourceType]['url'],
            ];
        }
        return $configs;
    }

    private function normalizeSources(array $sourceTypes): array
    {
        $valid = array_keys($this->portalSources());
        $sources = [];
        foreach ($sourceTypes as $sourceType) {
            $sourceType = (string) $sourceType;
            if (in_array($sourceType, $valid, true) && !in_array($sourceType, $sources, true)) {
                $sources[] = $sourceType;
            }
        }
        return $sources;
    }

    private function seedPortalSources(): void
    {
        $valid = array_keys($this->portalSources());
        if ($valid) {
            $placeholders = implode(',', array_fill(0, count($valid), '?'));
            $this->db->execute("UPDATE portal_sources SET enabled = 0, updated_at = NOW() WHERE source_type NOT IN ({$placeholders})", $valid);
        }
        foreach ($this->portalSources() as $sourceType => $source) {
            $this->db->execute(
                'INSERT INTO portal_sources (source_type, label, source_url, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label), source_url = VALUES(source_url), enabled = 1, updated_at = NOW()',
                [$sourceType, $source['label'], $source['url']]
            );
        }
    }

    private function nextRunAt(string $frequency, string $runTime): string
    {
        $now = new \DateTimeImmutable('now');
        [$hour, $minute] = array_map('intval', explode(':', $runTime));
        $candidate = $now->setTime($hour, $minute);
        if ($frequency === 'monthly') {
            $candidate = $now->modify('first day of this month')->setTime($hour, $minute);
            if ($candidate <= $now) {
                $candidate = $candidate->modify('first day of next month');
            }
            return $candidate->format('Y-m-d H:i:s');
        }
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }
        return $candidate->format('Y-m-d H:i:s');
    }

    private function phpBinary(): string
    {
        $configured = getenv('MIS_PHP_CLI');
        if ($configured) {
            return $configured;
        }
        $xampp = '/Applications/XAMPP/xamppfiles/bin/php';
        if (is_executable($xampp)) {
            return $xampp;
        }
        return str_contains(PHP_BINARY, 'php') ? PHP_BINARY : 'php';
    }

    private function debugPort(): int
    {
        $configured = (int) (getenv('MIS_CHROME_DEBUG_PORT') ?: 9333);
        return $configured > 0 ? $configured : 9333;
    }

    private function storageDir(): string
    {
        return $this->root . '/storage/auto-import';
    }

    private function downloadRoot(): string
    {
        return $this->root . '/storage/app-captured-reports';
    }

    private function profileDir(): string
    {
        return $this->root . '/storage/browser-visible-profile';
    }
}
