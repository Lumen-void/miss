<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class WorkbookMappingImporter
{
    public function __construct(private Database $db)
    {
    }

    public function import(int $runId, int $sourceFileId, string $path, bool $replace): array
    {
        if (!is_file($path) || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            return ['profit_loss' => 0, 'cogs' => 0];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);

        return [
            'profit_loss' => $this->importProfitLoss($runId, $sourceFileId, $workbook, $replace),
            'cogs' => $this->importCogs($workbook),
        ];
    }

    private function importProfitLoss(int $runId, int $sourceFileId, Spreadsheet $workbook, bool $replace): int
    {
        $sheet = $this->sheetByName($workbook, 'Profit and loss');
        if (!$sheet) {
            return 0;
        }
        if ($replace) {
            $this->db->execute('DELETE FROM profit_loss_entries WHERE run_id = ?', [$runId]);
        }

        $section = '';
        $count = 0;
        for ($row = 2; $row <= min($sheet->getHighestDataRow(), 2000); $row++) {
            $account = $this->text($sheet->getCell('A' . $row)->getValue());
            $rawAmount = $sheet->getCell('B' . $row)->getValue();
            $pnlCategory = $this->text($sheet->getCell('C' . $row)->getValue());
            $productCategory = $this->text($sheet->getCell('D' . $row)->getValue());

            if ($account === '' && $pnlCategory === '' && $productCategory === '') {
                continue;
            }
            if ($account !== '' && $pnlCategory === '' && $productCategory === '' && !$this->isNumericAmount($rawAmount)) {
                $section = $account;
                continue;
            }
            if ($pnlCategory === '' || !$this->isNumericAmount($rawAmount) || $this->isTotalRow($account)) {
                continue;
            }

            $this->db->execute(
                'INSERT INTO profit_loss_entries (run_id, source_file_id, row_number, section, account, amount, pnl_category, product_category, raw_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $runId,
                    $sourceFileId,
                    $row,
                    $section,
                    $account,
                    $this->num($rawAmount),
                    $pnlCategory,
                    $productCategory,
                    json_encode([
                        'account' => $account,
                        'total' => $rawAmount,
                        'pnl_category' => $pnlCategory,
                        'product_category' => $productCategory,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]
            );
            $count++;
        }
        return $count;
    }

    private function importCogs(Spreadsheet $workbook): int
    {
        $sheet = $this->sheetByName($workbook, 'COGS Cal.');
        if (!$sheet) {
            return 0;
        }

        $count = 0;
        for ($row = 3; $row <= min($sheet->getHighestDataRow(), 2000); $row++) {
            $item = $this->text($sheet->getCell('A' . $row)->getValue());
            if ($item === '' || $this->isTotalRow($item)) {
                continue;
            }
            $category = $this->text($sheet->getCell('B' . $row)->getValue());
            $multiplier = $this->num($sheet->getCell('H' . $row)->getValue(), 1);
            $purchase = $this->num($sheet->getCell('Y' . $row)->getValue());
            $packaging = $this->num($sheet->getCell('AF' . $row)->getValue());

            $this->db->execute(
                'INSERT INTO product_costs (item_name, category, multiplier, purchase_price, packaging_rate)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE category = VALUES(category), multiplier = VALUES(multiplier), purchase_price = VALUES(purchase_price), packaging_rate = VALUES(packaging_rate)',
                [$item, $category, $multiplier, $purchase, $packaging]
            );
            $this->db->execute(
                'INSERT INTO sku_mappings (product_name, cogs_sku, mis_sku, category)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE cogs_sku = VALUES(cogs_sku), mis_sku = VALUES(mis_sku), category = VALUES(category)',
                [$item, $item, $category, $category]
            );
            $this->db->execute(
                'INSERT INTO inventory_items (sku, item_name, category, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), category = VALUES(category), updated_at = NOW()',
                [$item, $item, $category]
            );
            $count++;
        }
        return $count;
    }

    private function sheetByName(Spreadsheet $workbook, string $name): ?Worksheet
    {
        $target = $this->key($name);
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            if ($this->key($sheet->getTitle()) === $target) {
                return $sheet;
            }
        }
        return null;
    }

    private function key(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($value)) ?? strtolower($value));
    }

    private function text(mixed $value): string
    {
        $value = trim((string) $value);
        if (str_starts_with($value, '=')) {
            return '';
        }
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function isNumericAmount(mixed $value): bool
    {
        if (is_numeric($value)) {
            return true;
        }
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '=')) {
            return false;
        }
        $clean = preg_replace('/[^0-9.\-]+/', '', $value) ?? '';
        return is_numeric($clean);
    }

    private function num(mixed $value, float $default = 0.0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (str_starts_with(trim((string) $value), '=')) {
            return $default;
        }
        $clean = preg_replace('/[^0-9.\-]+/', '', (string) $value) ?? '';
        return is_numeric($clean) ? (float) $clean : $default;
    }

    private function isTotalRow(string $value): bool
    {
        $value = strtolower(trim($value));
        return $value === '' || str_starts_with($value, 'total for ') || str_starts_with($value, 'total ');
    }
}
