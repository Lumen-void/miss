<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExcelExporter
{
    public function __construct(
        private Database $db,
        private string $exportDir
    ) {
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0775, true);
        }
        @chmod($this->exportDir, 0777);
    }

    public function export(int $runId): string
    {
        $run = $this->db->fetch('SELECT * FROM monthly_runs WHERE id = ?', [$runId]);
        $monthName = $run ? date("M'y", strtotime($run['month'] . '-01')) : date("M'y");
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('MIS Tool')->setTitle('MIS ' . $monthName);

        $this->summarySheet($spreadsheet->getActiveSheet(), $runId, $monthName);
        $this->misPlatformSheet($spreadsheet->createSheet(), $runId, $monthName);
        $this->misSkuSheet($spreadsheet->createSheet(), $runId, $monthName);
        $this->cogsSheet($spreadsheet->createSheet(), $runId);
        $this->adjustmentsSheet($spreadsheet->createSheet(), $runId);
        $this->validationSheet($spreadsheet->createSheet(), $runId);
        $this->normalizedSourceSheet($spreadsheet->createSheet(), $runId);
        $this->inventoryStockSheet($spreadsheet->createSheet());
        $this->inventoryLedgerSheet($spreadsheet->createSheet(), $runId, (string) ($run['month'] ?? date('Y-m')));
        $this->profitLossSheet($spreadsheet->createSheet(), $runId, $monthName);

        $filename = 'MIS_' . preg_replace('/[^0-9A-Za-z_-]+/', '_', $run['month'] ?? date('Y-m')) . '_' . date('Ymd_His') . '.xlsx';
        $path = $this->exportDir . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        @chmod($path, 0666);
        return $path;
    }

    private function summarySheet($sheet, int $runId, string $monthName): void
    {
        $sheet->setTitle('Management Summary');
        $total = $this->db->fetch('SELECT COUNT(*) AS rows_count, SUM(quantity) AS qty, SUM(gross_amount) AS gross, SUM(tax_amount) AS tax, SUM(net_revenue) AS net FROM import_rows WHERE run_id = ?', [$runId]) ?: [];
        $issues = $this->db->fetch('SELECT COUNT(*) AS count FROM validation_issues WHERE run_id = ? AND status = "open"', [$runId]) ?: [];
        $adjust = $this->db->fetch("SELECT SUM(CASE WHEN adjustment_type='addition' THEN amount ELSE 0 END) AS additions, SUM(CASE WHEN adjustment_type='deduction' THEN amount ELSE 0 END) AS deductions FROM monthly_adjustments WHERE run_id = ?", [$runId]) ?: [];
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'Monthly Income Statement - ' . $monthName);
        $sheet->fromArray([
            ['Metric', 'Value', '', 'Control', 'Value'],
            ['Imported Rows', (float) ($total['rows_count'] ?? 0), '', 'Open Validation Issues', (float) ($issues['count'] ?? 0)],
            ['Quantity', (float) ($total['qty'] ?? 0), '', 'Manual Additions', (float) ($adjust['additions'] ?? 0)],
            ['Gross Sales', (float) ($total['gross'] ?? 0), '', 'Manual Deductions', (float) ($adjust['deductions'] ?? 0)],
            ['Tax', (float) ($total['tax'] ?? 0), '', 'Net Revenue', (float) ($total['net'] ?? 0)],
        ], null, 'A3');
        $platforms = $this->db->fetchAll('SELECT platform, SUM(net_revenue) AS revenue FROM import_rows WHERE run_id = ? GROUP BY platform ORDER BY revenue DESC', [$runId]);
        $sheet->fromArray(['Platform', 'Revenue'], null, 'A10');
        $sheet->fromArray($platforms, null, 'A11');
        $this->styleSheet($sheet, 'A3:E' . max(7, 10 + count($platforms)));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('152033');
        $sheet->getStyle('B4:B7')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('E4:E7')->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function misPlatformSheet($sheet, int $runId, string $monthName): void
    {
        $sheet->setTitle('MIS Platform ' . $monthName);
        $sheet->fromArray(['Platform', 'Particulars', 'No. of Order', 'Quantity', 'Amount'], null, 'A1');
        $rows = $this->db->fetchAll('SELECT platform, line_item, order_count, quantity, amount FROM mis_platform_summary WHERE run_id = ? ORDER BY sort_order, platform', [$runId]);
        $sheet->fromArray($rows, null, 'A2');
        $last = max(2, count($rows) + 1);
        $sheet->setCellValue('G1', 'Formula checks');
        $sheet->setCellValue('G2', '=SUM(E2:E' . $last . ')');
        $sheet->setCellValue('G3', '=IF(E2<>0,E4/E2,0)');
        $this->styleSheet($sheet, 'A1:E' . $last);
        $sheet->setAutoFilter('A1:E' . $last);
    }

    private function misSkuSheet($sheet, int $runId, string $monthName): void
    {
        $sheet->setTitle('MIS SKU ' . $monthName);
        $sheet->fromArray(['Category', 'Platform', 'Quantity', 'Revenue', 'COGS', 'Packaging', 'Gross Profit', 'Margin %'], null, 'A1');
        $rows = $this->db->fetchAll('SELECT category, platform, quantity, revenue, cogs, packaging, gross_profit FROM mis_sku_summary WHERE run_id = ? ORDER BY category, platform', [$runId]);
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, 'A' . $rowIndex);
            $sheet->setCellValue('H' . $rowIndex, '=IFERROR(G' . $rowIndex . '/D' . $rowIndex . ',0)');
            $rowIndex++;
        }
        $last = max(2, $rowIndex - 1);
        $sheet->setCellValue('J1', 'SUMIFS example');
        $sheet->setCellValue('J2', '=SUMIFS(D:D,A:A,A2)');
        $this->styleSheet($sheet, 'A1:H' . $last);
        $sheet->setAutoFilter('A1:H' . $last);
    }

    private function cogsSheet($sheet, int $runId): void
    {
        $sheet->setTitle('COGS Cal.');
        $sheet->fromArray(['Item Name', 'Category', 'Total Qty Sold', 'Revenue', 'Multiplier', 'Purchase Price', 'COGS', 'Packaging Rate', 'Packaging Cost'], null, 'A1');
        $rows = $this->db->fetchAll(
            'SELECT pc.item_name, pc.category,
                    COALESCE(SUM(ir.quantity), 0) AS quantity,
                    COALESCE(SUM(ir.net_revenue), 0) AS revenue,
                    pc.multiplier, pc.purchase_price, pc.packaging_rate
             FROM product_costs pc
             LEFT JOIN import_rows ir ON ir.run_id = ? AND (lower(ir.category) = lower(pc.category) OR lower(ir.cogs_sku) = lower(pc.item_name))
             GROUP BY pc.id
             ORDER BY pc.item_name',
            [$runId]
        );
        $i = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([$row['item_name'], $row['category'], $row['quantity'], $row['revenue'], $row['multiplier'], $row['purchase_price']], null, 'A' . $i);
            $sheet->setCellValue('G' . $i, '=C' . $i . '*E' . $i . '*F' . $i);
            $sheet->setCellValue('H' . $i, $row['packaging_rate']);
            $sheet->setCellValue('I' . $i, '=C' . $i . '*H' . $i);
            $i++;
        }
        $this->styleSheet($sheet, 'A1:I' . max(2, $i - 1));
    }

    private function adjustmentsSheet($sheet, int $runId): void
    {
        $sheet->setTitle('Adjustments');
        $sheet->fromArray(['Type', 'Platform', 'Category', 'Description', 'Amount', 'Created'], null, 'A1');
        $rows = $this->db->fetchAll('SELECT adjustment_type, platform, category, description, amount, created_at FROM monthly_adjustments WHERE run_id = ? ORDER BY created_at, id', [$runId]);
        $sheet->fromArray($rows, null, 'A2');
        $last = max(2, count($rows) + 1);
        $sheet->setCellValue('H1', 'Net Adjustments');
        $sheet->setCellValue('H2', '=SUMIFS(E:E,A:A,"addition")-SUMIFS(E:E,A:A,"deduction")');
        $this->styleSheet($sheet, 'A1:F' . $last);
        $sheet->setAutoFilter('A1:F' . $last);
    }

    private function validationSheet($sheet, int $runId): void
    {
        $sheet->setTitle('Validation');
        $sheet->fromArray(['Severity', 'Status', 'Message', 'Context', 'Created'], null, 'A1');
        $rows = $this->db->fetchAll('SELECT severity, status, message, context_json, created_at FROM validation_issues WHERE run_id = ? ORDER BY severity, id', [$runId]);
        $sheet->fromArray($rows, null, 'A2');
        $last = max(2, count($rows) + 1);
        $this->styleSheet($sheet, 'A1:E' . $last);
        $sheet->setAutoFilter('A1:E' . $last);
    }

    private function normalizedSourceSheet($sheet, int $runId): void
    {
        $sheet->setTitle('Normalized Imports');
        $headers = ['Source', 'Platform', 'Order Date', 'Order ID', 'Product', 'COGS SKU', 'MIS SKU', 'Category', 'Qty', 'Gross', 'Taxable', 'Tax', 'Net Revenue', 'Type'];
        $sheet->fromArray($headers, null, 'A1');
        $rows = $this->db->fetchAll(
            'SELECT source_type, platform, order_date, order_id, product_name, cogs_sku, mis_sku, category, quantity, gross_amount, taxable_amount, tax_amount, net_revenue, transaction_type
             FROM import_rows WHERE run_id = ? ORDER BY platform, id',
            [$runId]
        );
        $this->writeRows($sheet, $rows, 2);
        $last = max(2, count($rows) + 1);
        $this->styleSheet($sheet, 'A1:N' . $last);
        $sheet->setAutoFilter('A1:N' . $last);
    }

    private function inventoryStockSheet($sheet): void
    {
        $sheet->setTitle('Inventory Stock');
        $sheet->fromArray(['SKU', 'Item', 'Category', 'Reorder Level', 'Stock Qty', 'Stock Value', 'Status'], null, 'A1');
        $rows = (new InventoryService($this->db))->stockSummary();
        $i = 2;
        foreach ($rows as $row) {
            $low = (float) $row['reorder_level'] > 0 && (float) $row['stock_qty'] <= (float) $row['reorder_level'];
            $sheet->fromArray([
                $row['sku'],
                $row['item_name'],
                $row['category'],
                (float) $row['reorder_level'],
                (float) $row['stock_qty'],
                (float) $row['stock_value'],
                $low ? 'Low stock' : 'OK',
            ], null, 'A' . $i);
            $i++;
        }
        $last = max(2, $i - 1);
        $this->styleSheet($sheet, 'A1:G' . $last);
        $sheet->setAutoFilter('A1:G' . $last);
    }

    private function inventoryLedgerSheet($sheet, int $runId, string $month): void
    {
        $sheet->setTitle('Inventory Ledger');
        $sheet->fromArray(['SKU', 'Item', 'Category', 'Warehouse', 'Opening', 'Inward', 'Outward', 'Transfer In', 'Transfer Out', 'Adjustment', 'Closing'], null, 'A1');
        $rows = (new InventoryService($this->db))->monthlyLedger($month);
        $i = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['sku'],
                $row['item_name'],
                $row['category'],
                $row['warehouse_name'],
                (float) $row['opening_qty'],
                (float) $row['inward_qty'],
                (float) $row['outward_qty'],
                (float) $row['transfer_in_qty'],
                (float) $row['transfer_out_qty'],
                (float) $row['adjustment_qty'],
                (float) $row['closing_qty'],
            ], null, 'A' . $i);
            $i++;
        }
        $last = max(2, $i - 1);
        $sheet->setCellValue('M1', 'Ledger Check');
        $sheet->setCellValue('M2', '=SUM(K2:K' . $last . ')');
        $this->styleSheet($sheet, 'A1:K' . $last);
        $sheet->setAutoFilter('A1:K' . $last);
    }

    private function profitLossSheet($sheet, int $runId, string $monthName): void
    {
        $sheet->setTitle('Profit and loss');
        $entries = $this->db->fetchAll(
            'SELECT section, account, amount, pnl_category, product_category
             FROM profit_loss_entries
             WHERE run_id = ?
             ORDER BY row_number, id',
            [$runId]
        );
        if ($entries) {
            $sheet->fromArray(['Section', 'Account', 'Total', 'P&L Mapping', 'Product Mapping'], null, 'A1');
            $rowIndex = 2;
            foreach ($entries as $entry) {
                $sheet->fromArray([
                    $entry['section'],
                    $entry['account'],
                    (float) $entry['amount'],
                    $entry['pnl_category'],
                    $entry['product_category'],
                ], null, 'A' . $rowIndex);
                $rowIndex++;
            }
            $last = max(2, $rowIndex - 1);
            $sheet->setAutoFilter('A1:E' . $last);
            $this->styleSheet($sheet, 'A1:E' . $last);
            return;
        }

        $total = $this->db->fetch('SELECT SUM(net_revenue) AS revenue, SUM(tax_amount) AS tax FROM import_rows WHERE run_id = ?', [$runId]);
        $cost = $this->db->fetch('SELECT SUM(cogs) AS cogs, SUM(packaging) AS packaging, SUM(gross_profit) AS profit FROM mis_sku_summary WHERE run_id = ?', [$runId]);
        $adjust = $this->db->fetch("SELECT SUM(CASE WHEN adjustment_type='addition' THEN amount ELSE -amount END) AS net_adjustments FROM monthly_adjustments WHERE run_id = ?", [$runId]);
        $sheet->fromArray([
            ['Account', 'Total', 'Month'],
            ['Operating Income', '', $monthName],
            ['Sales', (float) ($total['revenue'] ?? 0), ''],
            ['Total Tax', (float) ($total['tax'] ?? 0), ''],
            ['COGS', -(float) ($cost['cogs'] ?? 0), ''],
            ['Packaging', -(float) ($cost['packaging'] ?? 0), ''],
            ['Manual Adjustments', (float) ($adjust['net_adjustments'] ?? 0), ''],
            ['Gross Profit', '=SUM(B3:B7)', ''],
        ], null, 'A1');
        $this->styleSheet($sheet, 'A1:C8');
    }

    private function writeRows($sheet, array $rows, int $startRow): void
    {
        $rowNumber = $startRow;
        foreach ($rows as $row) {
            $colNumber = 1;
            foreach (array_values($row) as $value) {
                if (is_numeric($value)) {
                    $sheet->setCellValue([$colNumber, $rowNumber], (float) $value);
                } else {
                    $sheet->setCellValueExplicit([$colNumber, $rowNumber], (string) $value, DataType::TYPE_STRING);
                }
                $colNumber++;
            }
            $rowNumber++;
        }
    }

    private function styleSheet($sheet, string $range): void
    {
        [$start, $end] = explode(':', $range);
        $sheet->getStyle($start . ':' . preg_replace('/\d+/', '1', $end))->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '274C77']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFD9DEE8'));
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00');
        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_LESSTHAN)
            ->addCondition('0');
        $conditional->getStyle()->getFont()->getColor()->setRGB('B42318');
        $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2F0');
        $sheet->getStyle($range)->setConditionalStyles([$conditional]);
        foreach (range('A', $sheet->getHighestDataColumn()) as $column) {
            $sheet->getColumnDimension($column)->setWidth(18);
        }
        $sheet->freezePane('A2');
    }
}
