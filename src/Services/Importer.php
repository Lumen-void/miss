<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

final class Importer
{
    private SheetReader $reader;
    private ProductMapper $mapper;

    public function __construct(private Database $db)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $this->reader = new SheetReader();
        $this->mapper = new ProductMapper($db);
    }

    public function import(int $runId, string $sourceType, string $path, string $originalName, string $importMode = 'replace', bool $retainSourceFile = true): int
    {
        $importMode = $importMode === 'append' ? 'append' : 'replace';
        $checksum = is_file($path) ? hash_file('sha256', $path) : null;
        $this->db->begin();
        try {
            $this->db->execute(
                'INSERT INTO source_files (run_id, source_type, original_name, stored_path, rows_imported, uploaded_at, import_mode, checksum) VALUES (?, ?, ?, ?, 0, NOW(), ?, ?)',
                [$runId, $sourceType, $originalName, $retainSourceFile ? $path : '', $importMode, $checksum]
            );
            $fileId = $this->db->lastInsertId();
            if ($importMode === 'replace') {
                $this->db->execute('DELETE FROM import_rows WHERE run_id = ? AND source_type = ?', [$runId, $sourceType]);
            }
            $this->db->execute('DELETE FROM validation_issues WHERE run_id = ?', [$runId]);

            $rows = 0;
            if ($sourceType === 'sample_workbook' || $sourceType === 'easecommerce') {
                (new WorkbookMappingImporter($this->db))->import($runId, $fileId, $path, $importMode === 'replace');
                $sheets = $this->reader->sheets($path, array_keys($this->sheetTypeMap()));
                if ($importMode === 'replace') {
                    $this->db->execute('DELETE FROM import_rows WHERE run_id = ?', [$runId]);
                }
                foreach ($this->sheetTypeMap() as $sheetName => $mappedType) {
                    foreach ($this->matchingSheets($sheets, $sheetName) as $sheetRows) {
                        $rows += $this->importRows($runId, $fileId, $mappedType, $sheetRows);
                    }
                }
            } else {
                $sheets = $this->reader->sheets($path);
                foreach ($sheets as $sheetRows) {
                    $rows += $this->importRows($runId, $fileId, $sourceType, $sheetRows);
                }
            }

            $this->db->execute('UPDATE source_files SET rows_imported = ? WHERE id = ?', [$rows, $fileId]);
            $this->db->execute('UPDATE monthly_runs SET status = ?, updated_at = NOW() WHERE id = ?', ['imported', $runId]);
            $this->db->commit();
            return $rows;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function importRows(int $runId, int $fileId, string $sourceType, array $sheetRows): int
    {
        $config = $this->configs()[$sourceType] ?? null;
        if (!$config) {
            throw new RuntimeException('Unsupported source type: ' . $sourceType);
        }

        try {
            $records = $this->reader->tableFromRows($sheetRows, $config['required']);
        } catch (RuntimeException $e) {
            $this->issue($runId, $fileId, 'error', $sourceType . ': ' . $e->getMessage());
            return 0;
        }

        $count = 0;
        foreach ($records as $record) {
            $normalized = $this->normalizeRecord($sourceType, $record);
            if ($normalized['product_name'] === '' && $normalized['gross_amount'] == 0.0 && $normalized['taxable_amount'] == 0.0 && $normalized['net_revenue'] == 0.0) {
                continue;
            }
            $mapping = $this->mapper->resolveMapping($normalized['product_name'], $normalized['cogs_sku'], $normalized['mis_sku']);
            $normalized['cogs_sku'] = $mapping['cogs_sku'] ?: $normalized['cogs_sku'];
            $normalized['mis_sku'] = $mapping['mis_sku'] ?: $normalized['mis_sku'];
            $normalized['category'] = $mapping['category'] ?: $normalized['mis_sku'] ?: 'Unmapped';
            if ($normalized['product_name'] !== '' && ($normalized['category'] === 'Unmapped' || $normalized['cogs_sku'] === '')) {
                $this->issue($runId, $fileId, 'warning', 'Unmapped product: ' . $normalized['product_name'], ['source' => $sourceType, 'row' => $record['_row_number']]);
            }

            $this->db->execute(
                'INSERT INTO import_rows (run_id, source_file_id, source_type, platform, row_number, order_date, order_id, product_name, cogs_sku, mis_sku, category, quantity, gross_amount, taxable_amount, tax_amount, net_revenue, transaction_type, raw_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $runId,
                    $fileId,
                    $sourceType,
                    $normalized['platform'],
                    (int) $record['_row_number'],
                    $normalized['order_date'],
                    $normalized['order_id'],
                    $normalized['product_name'],
                    $normalized['cogs_sku'],
                    $normalized['mis_sku'],
                    $normalized['category'],
                    $normalized['quantity'],
                    $normalized['gross_amount'],
                    $normalized['taxable_amount'],
                    $normalized['tax_amount'],
                    $normalized['net_revenue'],
                    $normalized['transaction_type'],
                    json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            $count++;
        }
        return $count;
    }

    private function normalizeRecord(string $sourceType, array $r): array
    {
        return match ($sourceType) {
            'flipkart' => $this->row('Flipkart', $r, [
                'date' => ['order_date', 'invoice_date', 'dispatch_date', 'sale_date'],
                'order' => ['order_id', 'order_item_id', 'order_no', 'order_number'],
                'product' => ['product_title_description', 'product_title', 'product_name', 'item_name', 'description'],
                'cogs' => ['cogs_sku', 'sku', 'seller_sku', 'fsn'],
                'mis' => ['mis_sku', 'sku', 'seller_sku', 'fsn'],
                'qty' => ['item_quantity', 'quantity', 'qty', 'units'],
                'gross' => ['final_invoice_amount_price_after_discount_shipping_charges', 'final_invoice_amount', 'invoice_amount', 'gross_amount', 'total_amount'],
                'taxable' => ['taxable_value_final_invoice_amount_taxes', 'taxable_value', 'tax_exclusive_gross', 'taxable_amount'],
                'tax' => ['igst_amount', 'total_tax_amount', 'tax_amount', 'gst_amount'],
                'type' => ['event_type', 'transaction_type', 'order_type', 'status'],
            ]),
            'blinkit' => $this->row('Blinkit', $r, [
                'date' => ['order_date', 'invoice_date', 'payout_date', 'sale_date'],
                'order' => ['order_id', 'order_number', 'invoice_number'],
                'product' => ['product_name', 'item_name', 'item_description', 'title'],
                'cogs' => ['cogs_sku', 'sku', 'item_id'],
                'mis' => ['mis_sku', 'sku', 'item_id'],
                'qty' => ['quantity', 'qty', 'item_quantity', 'units'],
                'gross' => ['selling_price_rs', 'selling_price', 'item_total', 'total', 'gross_amount'],
                'taxable' => ['taxable_value', 'taxable_amount', 'sub_total', 'net_amount'],
                'tax' => ['igst_value', 'igst', 'tax_amount', 'gst'],
                'type' => ['order_type', 'transaction_type', 'status'],
            ], grossMultiplier: 'qty'),
            'amazon_b2c' => $this->row('Amazon', $r, [
                'date' => 'invoice_date',
                'order' => 'order_id',
                'product' => 'item_description',
                'cogs' => 'cogs_sku',
                'mis' => 'mis_sku',
                'qty' => 'quantity',
                'gross' => 'invoice_amount',
                'taxable' => 'tax_exclusive_gross',
                'tax' => 'total_tax_amount',
                'type' => 'transaction_type',
            ]),
            'amazon_b2b' => $this->row('Amazon', $r, [
                'date' => 'invoice_date',
                'order' => 'order_id',
                'product' => 'item_description',
                'cogs' => 'cogs_sku',
                'mis' => 'mis_sku',
                'qty' => 'quantity',
                'gross' => 'invoice_amount',
                'taxable' => 'tax_exclusive_gross',
                'tax' => 'total_tax_amount',
                'type' => 'transaction_type',
            ]),
            'amazon_str' => $this->row('Amazon STR', $r, [
                'date' => ['settlement_date', 'posted_date', 'transaction_date', 'invoice_date', 'order_date'],
                'order' => ['order_id', 'amazon_order_id', 'transaction_id', 'invoice_number'],
                'product' => ['item_description', 'item_name', 'product_name', 'description', 'sku'],
                'cogs' => ['cogs_sku', 'sku', 'seller_sku'],
                'mis' => ['mis_sku', 'sku', 'seller_sku'],
                'qty' => ['quantity', 'qty', 'item_quantity'],
                'gross' => ['invoice_amount', 'total', 'gross_amount', 'amount', 'tax_exclusive_gross'],
                'taxable' => ['tax_exclusive_gross', 'taxable_value', 'taxable_amount', 'principal_amount'],
                'tax' => ['total_tax_amount', 'tax_amount', 'igst', 'cgst', 'sgst'],
                'type' => ['transaction_type', 'type', 'status'],
            ]),
            'mcf_sales' => $this->row('Website fulfillment _Amazon', $r, [
                'date' => 'invoice_date',
                'order' => 'order_id',
                'product' => 'item_name',
                'cogs' => 'cogs_sku',
                'mis' => 'mis_sku',
                'qty' => 'quantity',
                'gross' => 'invoice_value',
                'taxable' => 'taxable_value',
                'tax' => 'total_tax',
                'type' => 'transaction_type',
            ]),
            'website_sales' => $this->row('Website', $r, [
                'date' => 'invoice_date',
                'order' => 'order_number',
                'product' => 'product_name',
                'cogs' => 'cogs_sku',
                'mis' => 'mis_sku',
                'qty' => 'quantity',
                'gross' => 'taxable_amount',
                'taxable' => 'taxable_amount',
                'tax' => 'igst',
                'type' => 'sales_type',
            ]),
            'website_mcf_returns' => $this->row('Website', $r, [
                'date' => 'credit_note_date',
                'order' => 'associated_invoice_number',
                'product' => 'item_name',
                'cogs' => 'cogs_sku',
                'mis' => 'mis_sku',
                'qty' => 'quantity',
                'gross' => 'item_total',
                'taxable' => 'item_total',
                'tax' => 'item_tax_amount',
                'type' => 'credit_note_status',
            ]),
            default => throw new RuntimeException('Unsupported source type: ' . $sourceType),
        };
    }

    private function row(string $platform, array $r, array $map, string $grossMultiplier = ''): array
    {
        $qty = $this->num($this->cell($r, $map['qty']));
        $gross = $this->num($this->cell($r, $map['gross']));
        if ($grossMultiplier === 'qty') {
            $gross *= $qty;
        }
        $taxable = $this->num($this->cell($r, $map['taxable']));
        $tax = $this->num($this->cell($r, $map['tax']));
        $type = trim((string) $this->cell($r, $map['type']));

        if ($this->isReturn($type) && $qty > 0) {
            $qty *= -1;
            $gross *= -1;
            $taxable *= -1;
            $tax *= -1;
        }

        return [
            'platform' => $platform,
            'order_date' => $this->dateValue($this->cell($r, $map['date'])),
            'order_id' => trim((string) $this->cell($r, $map['order'])),
            'product_name' => $this->cleanProduct($this->cell($r, $map['product'])),
            'cogs_sku' => $this->cleanFormulaValue($this->cell($r, $map['cogs'])),
            'mis_sku' => $this->cleanFormulaValue($this->cell($r, $map['mis'])),
            'category' => '',
            'quantity' => $qty,
            'gross_amount' => $gross,
            'taxable_amount' => $taxable ?: $gross - $tax,
            'tax_amount' => $tax,
            'net_revenue' => $taxable ?: $gross - $tax,
            'transaction_type' => $type,
        ];
    }

    private function cell(array $row, string|array $keys): mixed
    {
        foreach ((array) $keys as $key) {
            $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $key))) ?? '';
            $normalized = trim($normalized, '_');
            if (array_key_exists($normalized, $row) && $row[$normalized] !== null && trim((string) $row[$normalized]) !== '') {
                return $row[$normalized];
            }
        }
        return '';
    }

    private function resolveMapping(string $product, string $cogs, string $mis): array
    {
        if ($product !== '') {
            $row = $this->db->fetch('SELECT * FROM sku_mappings WHERE lower(product_name) = lower(?)', [$product]);
            if ($row && ($row['category'] ?? '') !== 'Unmapped') {
                return $row;
            }
            $guessed = $this->guessCostByProduct($product);
            if ($guessed) {
                return $guessed;
            }
            if ($row) {
                return $row;
            }
        }
        if ($cogs !== '') {
            $row = $this->db->fetch('SELECT item_name AS cogs_sku, category AS mis_sku, category FROM product_costs WHERE lower(item_name) = lower(?) OR lower(category) = lower(?)', [$cogs, $cogs]);
            if ($row) {
                return $row;
            }
        }
        if ($mis !== '') {
            $row = $this->db->fetch('SELECT item_name AS cogs_sku, category AS mis_sku, category FROM product_costs WHERE lower(category) = lower(?)', [$mis]);
            if ($row) {
                return $row;
            }
        }
        return ['cogs_sku' => $cogs, 'mis_sku' => $mis, 'category' => 'Unmapped'];
    }

    private function guessCostByProduct(string $product): ?array
    {
        $costs = $this->db->fetchAll('SELECT item_name, category FROM product_costs');
        $productTokens = $this->tokens($product);
        $best = null;
        $bestScore = 0;
        foreach ($costs as $cost) {
            $tokens = array_unique([...$this->tokens($cost['item_name']), ...$this->tokens($cost['category'])]);
            $score = count(array_intersect($productTokens, $tokens));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $cost;
            }
        }
        if (!$best || $bestScore < 2) {
            return null;
        }
        return [
            'cogs_sku' => $best['item_name'],
            'mis_sku' => $best['category'],
            'category' => $best['category'],
        ];
    }

    private function tokens(string $value): array
    {
        $stop = ['naturesum', 'nature', 'sum', 'organic', 'pure', 'with', 'for', 'and', 'the', 'high', 'rich', 'in', 'to', 'of', 'ml', 'gm', 'g', 'kg', 'x', 'rare', 'vitamin', 'boosts'];
        $parts = preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [];
        return array_values(array_unique(array_filter($parts, fn($part) => strlen($part) > 2 && !in_array($part, $stop, true))));
    }

    private function configs(): array
    {
        return [
            'flipkart' => ['required' => [['Order ID', 'Order Item ID', 'Order No'], ['Product Title/Description', 'Product Title', 'Product Name', 'Item Name'], ['Item Quantity', 'Quantity', 'Qty']]],
            'blinkit' => ['required' => [['Order ID', 'Order Number', 'Invoice Number'], ['Product Name', 'Item Name', 'Item Description'], ['Quantity', 'Qty', 'Item Quantity']]],
            'amazon_b2c' => ['required' => [['Order Id', 'Order ID'], ['Item Description', 'Item Name', 'Product Name'], ['Quantity', 'Qty']]],
            'amazon_b2b' => ['required' => [['Order Id', 'Order ID'], ['Item Description', 'Item Name', 'Product Name'], ['Quantity', 'Qty']]],
            'amazon_str' => ['required' => [['Order Id', 'Amazon Order ID', 'Transaction ID', 'Invoice Number'], ['Item Description', 'Item Name', 'Product Name', 'SKU'], ['Amount', 'Total', 'Invoice Amount', 'Tax Exclusive Gross']]],
            'mcf_sales' => ['required' => [['Order Id', 'Order ID'], ['Item name', 'Item Name', 'Product Name'], ['Quantity', 'Qty']]],
            'website_sales' => ['required' => [['Order Number', 'Order ID'], ['Product Name', 'Item Name'], ['Quantity', 'Qty']]],
            'website_mcf_returns' => ['required' => [['Credit Note Number', 'Associated Invoice Number', 'Order ID'], ['Item Name', 'Product Name'], ['Quantity', 'Qty']]],
        ];
    }

    private function sheetTypeMap(): array
    {
        return [
            'Flipkart' => 'flipkart',
            'Blink it' => 'blinkit',
            'Amazon b2c' => 'amazon_b2c',
            'Amazon b2b' => 'amazon_b2b',
            'MCF Sales' => 'mcf_sales',
            'Website Sales' => 'website_sales',
            'Website MCF Returns' => 'website_mcf_returns',
        ];
    }

    private function matchingSheets(array $sheets, string $targetName): array
    {
        if (isset($sheets[$targetName])) {
            return [$sheets[$targetName]];
        }

        $matches = [];
        $target = $this->reader->normalizeHeader($targetName);
        foreach ($sheets as $sheetName => $rows) {
            if (str_contains($this->reader->normalizeHeader((string) $sheetName), $target)) {
                $matches[] = $rows;
            }
        }
        return $matches;
    }

    private function issue(int $runId, int $fileId, string $severity, string $message, array $context = []): void
    {
        $existing = $this->db->fetch('SELECT id FROM validation_issues WHERE run_id = ? AND message = ? LIMIT 1', [$runId, $message]);
        if ($existing) {
            return;
        }
        $this->db->execute(
            'INSERT INTO validation_issues (run_id, source_file_id, severity, message, context_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$runId, $fileId, $severity, $message, json_encode($context)]
        );
    }

    private function num(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (str_starts_with(trim((string) $value), '=')) {
            return 0.0;
        }
        $value = preg_replace('/[^0-9.\-]+/', '', (string) $value) ?? '';
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        $time = strtotime($text);
        return $time ? date('Y-m-d', $time) : $text;
    }

    private function cleanProduct(mixed $value): string
    {
        $value = trim((string) $value);
        if (str_starts_with($value, '=')) {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return $value;
    }

    private function cleanFormulaValue(mixed $value): string
    {
        $value = trim((string) $value);
        if (str_starts_with($value, '=')) {
            return '';
        }
        return $value;
    }

    private function isReturn(string $value): bool
    {
        $value = strtolower($value);
        return str_contains($value, 'return') || str_contains($value, 'refund') || str_contains($value, 'credit');
    }
}
