<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class MasterSeeder
{
    public function __construct(
        private Database $db,
        private string $samplePath
    ) {
    }

    public function seedIfEmpty(): void
    {
        $existing = $this->db->fetch('SELECT COUNT(*) AS count FROM product_costs');
        if (($existing['count'] ?? 0) > 0 || !is_file($this->samplePath)) {
            return;
        }

        $reader = IOFactory::createReaderForFile($this->samplePath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['COGS Cal.', 'Flipkart', 'Blink it', 'Amazon b2c', 'Amazon b2b', 'MCF Sales', 'Website Sales', 'Website MCF Returns']);
        $workbook = $reader->load($this->samplePath);

        if ($workbook->sheetNameExists('COGS Cal.')) {
            $sheet = $workbook->getSheetByName('COGS Cal.');
            for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
                $item = trim((string) $sheet->getCell('A' . $row)->getValue());
                if ($item === '') {
                    continue;
                }
                $category = trim((string) $sheet->getCell('B' . $row)->getValue());
                $multiplier = $this->num($sheet->getCell('H' . $row)->getValue(), 1);
                $purchase = $this->num($sheet->getCell('Y' . $row)->getValue(), 0);
                $packaging = $this->num($sheet->getCell('AF' . $row)->getValue(), 0);
                $this->db->execute(
                    'INSERT IGNORE INTO product_costs (item_name, category, multiplier, purchase_price, packaging_rate) VALUES (?, ?, ?, ?, ?)',
                    [$item, $category, $multiplier, $purchase, $packaging]
                );
                $this->db->execute(
                    'INSERT IGNORE INTO sku_mappings (product_name, cogs_sku, mis_sku, category) VALUES (?, ?, ?, ?)',
                    [$item, $item, $category, $category]
                );
            }
        }

        foreach (['Flipkart', 'Blink it', 'Amazon b2c', 'Amazon b2b', 'MCF Sales', 'Website Sales', 'Website MCF Returns'] as $sheetName) {
            if (!$workbook->sheetNameExists($sheetName)) {
                continue;
            }
            $sheet = $workbook->getSheetByName($sheetName);
            $highestRow = min($sheet->getHighestDataRow(), 2000);
            for ($row = 2; $row <= $highestRow; $row++) {
                $product = $this->productFromSheet($sheetName, $sheet, $row);
                if ($product === '') {
                    continue;
                }
                $this->db->execute(
                    'INSERT IGNORE INTO sku_mappings (product_name, cogs_sku, mis_sku, category) VALUES (?, ?, ?, ?)',
                    [$product, $product, $product, 'Unmapped']
                );
            }
        }
    }

    private function productFromSheet(string $sheetName, $sheet, int $row): string
    {
        return match ($sheetName) {
            'Flipkart' => trim((string) $sheet->getCell('F' . $row)->getValue()),
            'Blink it' => trim((string) $sheet->getCell('Q' . $row)->getValue()),
            'Amazon b2c', 'Amazon b2b' => trim((string) $sheet->getCell('M' . $row)->getValue()),
            'MCF Sales' => trim((string) $sheet->getCell('U' . $row)->getValue()),
            'Website Sales' => trim((string) $sheet->getCell('R' . $row)->getValue()),
            'Website MCF Returns' => trim((string) $sheet->getCell('T' . $row)->getValue()),
            default => '',
        };
    }

    private function num(mixed $value, float $default): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }
}
