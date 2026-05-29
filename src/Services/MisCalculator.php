<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;

final class MisCalculator
{
    private ProductMapper $mapper;

    public function __construct(private Database $db)
    {
        $this->mapper = new ProductMapper($db);
    }

    public function calculate(int $runId): void
    {
        $this->db->begin();
        try {
            $this->db->execute('DELETE FROM mis_platform_summary WHERE run_id = ?', [$runId]);
            $this->db->execute('DELETE FROM mis_sku_summary WHERE run_id = ?', [$runId]);
            $this->db->execute('DELETE FROM mis_overview_lines WHERE run_id = ?', [$runId]);
            $this->db->execute('DELETE FROM calculation_log WHERE run_id = ?', [$runId]);

            $this->enrichRunMappings($runId);
            $this->platformSummary($runId);
            $this->skuSummary($runId);
            $this->adjustmentSummary($runId);
            $this->overviewSummary($runId);
            $this->validateRun($runId);
            $this->db->execute('UPDATE monthly_runs SET status = ?, updated_at = NOW() WHERE id = ?', ['calculated', $runId]);
            $this->log($runId, 'Calculation completed', ['rows' => $this->countRows($runId)]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function enrichRunMappings(int $runId): void
    {
        $asinProductMap = [];
        $asinRows = $this->db->fetchAll(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.asin')) AS asin, product_name
             FROM import_rows
             WHERE run_id = ?
               AND product_name <> ''
               AND JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.asin')) IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.asin')) <> ''
             ORDER BY CASE source_type WHEN 'amazon_b2c' THEN 1 WHEN 'amazon_b2b' THEN 2 WHEN 'mcf_sales' THEN 3 ELSE 9 END",
            [$runId]
        );
        foreach ($asinRows as $row) {
            $asin = trim((string) ($row['asin'] ?? ''));
            if ($asin !== '' && !isset($asinProductMap[$asin])) {
                $asinProductMap[$asin] = (string) $row['product_name'];
            }
        }

        if ($asinProductMap) {
            $blankRows = $this->db->fetchAll(
                "SELECT id, JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.asin')) AS asin
                 FROM import_rows
                 WHERE run_id = ?
                   AND product_name = ''
                   AND JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.asin')) IS NOT NULL
                   AND JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.asin')) <> ''",
                [$runId]
            );
            foreach ($blankRows as $row) {
                $asin = trim((string) ($row['asin'] ?? ''));
                $product = $asinProductMap[$asin] ?? '';
                if ($product === '') {
                    continue;
                }
                $mapping = $this->mapper->resolveMapping($product, '', '');
                $this->db->execute(
                    'UPDATE import_rows SET product_name = ?, cogs_sku = ?, mis_sku = ?, category = ? WHERE id = ?',
                    [$product, $mapping['cogs_sku'], $mapping['mis_sku'], $mapping['category'], $row['id']]
                );
            }
        }

        $rows = $this->db->fetchAll("SELECT id, product_name, cogs_sku, mis_sku, category FROM import_rows WHERE run_id = ? AND product_name <> ''", [$runId]);
        $updated = 0;
        foreach ($rows as $row) {
            $mapping = $this->mapper->resolveMapping((string) $row['product_name'], (string) $row['cogs_sku'], (string) $row['mis_sku']);
            if (($mapping['category'] ?? 'Unmapped') === 'Unmapped') {
                continue;
            }
            if ($mapping['cogs_sku'] === $row['cogs_sku'] && $mapping['mis_sku'] === $row['mis_sku'] && $mapping['category'] === $row['category']) {
                continue;
            }
            $this->db->execute(
                'UPDATE import_rows SET cogs_sku = ?, mis_sku = ?, category = ? WHERE id = ?',
                [$mapping['cogs_sku'], $mapping['mis_sku'], $mapping['category'], $row['id']]
            );
            $updated++;
        }

        if ($updated > 0) {
            $this->log($runId, 'Product mappings enriched from workbook and product text', ['rows_updated' => $updated]);
        }
    }

    private function platformSummary(int $runId): void
    {
        $platforms = $this->db->fetchAll('SELECT DISTINCT platform FROM import_rows WHERE run_id = ? ORDER BY platform', [$runId]);
        $sort = 10;
        foreach ($platforms as $platformRow) {
            $platform = $platformRow['platform'];
            $sales = $this->aggregate($runId, $platform, 'quantity > 0');
            $returns = $this->aggregate($runId, $platform, 'quantity < 0');
            $net = $this->aggregate($runId, $platform, '1=1');

            $this->insertPlatform($runId, $platform, 'Sales including GST', $sales, $sort++);
            $this->insertPlatform($runId, $platform, 'Returns', $returns, $sort++);
            $this->insertPlatform($runId, $platform, 'Net Sales', $net, $sort++);

            $tax = $this->db->fetch(
                'SELECT COUNT(DISTINCT order_id) AS order_count, SUM(quantity) AS quantity, SUM(tax_amount) AS amount FROM import_rows WHERE run_id = ? AND platform = ?',
                [$runId, $platform]
            );
            $this->insertPlatform($runId, $platform, 'Tax', $tax, $sort++);
        }

        $total = $this->aggregate($runId, '', '1=1');
        $this->insertPlatform($runId, 'Total', 'Net Sales', $total, 999);
    }

    private function skuSummary(int $runId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT category, platform, SUM(quantity) AS quantity, SUM(net_revenue) AS revenue
             FROM import_rows
             WHERE run_id = ?
             GROUP BY category, platform
             ORDER BY category, platform',
            [$runId]
        );

        foreach ($rows as $row) {
            $cost = $this->costFor($row['category']);
            $quantity = (float) $row['quantity'];
            $revenue = (float) $row['revenue'];
            $purchaseUnits = $quantity * (float) $cost['multiplier'];
            $cogs = $purchaseUnits * (float) $cost['purchase_price'];
            $packaging = $quantity * (float) $cost['packaging_rate'];
            $grossProfit = $revenue - $cogs - $packaging;
            $this->db->execute(
                'INSERT INTO mis_sku_summary (run_id, category, platform, quantity, revenue, cogs, packaging, gross_profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$runId, $row['category'] ?: 'Unmapped', $row['platform'], $quantity, $revenue, $cogs, $packaging, $grossProfit]
            );
        }
    }

    private function adjustmentSummary(int $runId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT platform, adjustment_type, SUM(amount) AS amount
             FROM monthly_adjustments
             WHERE run_id = ?
             GROUP BY platform, adjustment_type
             ORDER BY platform, adjustment_type',
            [$runId]
        );
        $sort = 800;
        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            $line = $row['adjustment_type'] === 'deduction' ? 'Manual Deductions' : 'Manual Additions';
            $signed = $row['adjustment_type'] === 'deduction' ? -abs($amount) : abs($amount);
            $this->insertPlatform($runId, $row['platform'] ?: 'Adjustments', $line, ['order_count' => 0, 'quantity' => 0, 'amount' => $signed], $sort++);
        }
    }

    private function overviewSummary(int $runId): void
    {
        $sales = $this->db->fetch(
            "SELECT COUNT(*) AS rows_count,
                    COUNT(DISTINCT NULLIF(order_id, '')) AS order_count,
                    SUM(quantity) AS quantity,
                    SUM(CASE WHEN quantity > 0 THEN gross_amount ELSE 0 END) AS sales_gross,
                    SUM(CASE WHEN quantity < 0 THEN -ABS(gross_amount) ELSE 0 END) AS returns_gross,
                    SUM(tax_amount) AS tax,
                    SUM(net_revenue) AS net_sales
             FROM import_rows
             WHERE run_id = ?",
            [$runId]
        ) ?: [];
        $cost = $this->db->fetch('SELECT SUM(cogs) AS cogs, SUM(packaging) AS packaging FROM mis_sku_summary WHERE run_id = ?', [$runId]) ?: [];

        $netSales = (float) ($sales['net_sales'] ?? 0);
        $salesGross = (float) ($sales['sales_gross'] ?? 0);
        $returnsGross = (float) ($sales['returns_gross'] ?? 0);
        $tax = (float) ($sales['tax'] ?? 0);
        $cogs = (float) ($cost['cogs'] ?? 0);
        $packaging = (float) ($cost['packaging'] ?? 0);

        $sellingFee = $this->expense($this->sumPnl($runId, ['Seller Fee']));
        $logistics = $this->expense($this->sumPnl($runId, ['Logistics']));
        $storage = $this->expense($this->sumPnl($runId, ['Storage Charges']));
        $paymentCharges = $this->expense($this->sumPnl($runId, ['Transactions Charges']));
        $packing = $this->expense($this->sumPnl($runId, ['Packing']));
        $support = $this->expense($this->sumPnl($runId, ['Other support services']));
        $coldStorage = $this->expense($this->sumPnl($runId, ['Cold Storage Charges']));
        $labour = $this->expense($this->sumPnl($runId, ['Labour charges']));
        $pnlCogs = $this->expense($this->sumPnl($runId, ['COGS']));
        $operationsCost = $sellingFee + $logistics + $storage + $paymentCharges + $packing + $support + $coldStorage + $labour;
        $netProceeds = $netSales + $operationsCost;

        $marketing = $this->expense($this->sumPnl($runId, ['Marketing']));
        $afterMarketing = $netProceeds + $marketing;

        $productCost = $this->expense($cogs);
        $packagingCost = $this->expense($packaging);
        $grossMargin = $afterMarketing + $productCost + $packagingCost + $pnlCogs;

        $agencyFees = $this->expense($this->sumPnl($runId, ['Code incentive', 'Snell Business collective LLP', 'Muskaan jain']));
        $admin = $this->expense($this->sumPnl($runId, ['G & A expenses', 'Professional fees', 'Rates & Taxes', 'Misc. Expense']));
        $netSurplus = $grossMargin + $agencyFees + $admin;

        $sort = 10;
        $this->insertOverview($runId, 'Revenue', 'Sales including GST', $salesGross, $netSales, 'Positive sales before returns and tax', $sort++);
        $this->insertOverview($runId, 'Revenue', 'Returns', $returnsGross, $netSales, 'Returned/refunded sales', $sort++);
        $this->insertOverview($runId, 'Revenue', 'GST / tax', -abs($tax), $netSales, 'Tax removed from gross sales', $sort++);
        $this->insertOverview($runId, 'Revenue', 'Net sales after tax', $netSales, $netSales, (int) ($sales['order_count'] ?? 0) . ' orders, ' . round((float) ($sales['quantity'] ?? 0), 2) . ' qty', $sort++);

        foreach ([
            'Selling fee / commission' => $sellingFee,
            'Fulfilment and logistics' => $logistics,
            'Storage charges' => $storage,
            'Payment transaction charges' => $paymentCharges,
            'Packing' => $packing,
            'Other support services' => $support,
            'Cold storage charges' => $coldStorage,
            'Labour charges' => $labour,
        ] as $line => $amount) {
            if (abs($amount) < 0.00001) {
                continue;
            }
            $this->insertOverview($runId, 'Platform costs', $line, $amount, $netSales, '', $sort++);
        }
        $this->insertOverview($runId, 'Platform costs', 'Net proceeds', $netProceeds, $netSales, 'Sales after portal and operating costs', $sort++);

        $this->insertOverview($runId, 'Marketing', 'Marketing spend', $marketing, $netSales, 'From Profit and loss column C mapping', $sort++);
        $this->insertOverview($runId, 'Marketing', 'After marketing', $afterMarketing, $netSales, '', $sort++);

        $this->insertOverview($runId, 'Product costs', 'COGS - raw material', $productCost, $netSales, 'From COGS Cal. purchase rates', $sort++);
        $this->insertOverview($runId, 'Product costs', 'Packaging cost', $packagingCost, $netSales, 'From COGS Cal. packaging rates', $sort++);
        if (abs($pnlCogs) > 0.00001) {
            $this->insertOverview($runId, 'Product costs', 'Extra COGS from P&L', $pnlCogs, $netSales, 'From Profit and loss column C mapping', $sort++);
        }
        $this->insertOverview($runId, 'Product costs', 'Gross margin after COGS', $grossMargin, $netSales, '', $sort++);

        $this->insertOverview($runId, 'Admin', 'Agency / consultant fees', $agencyFees, $netSales, '', $sort++);
        $this->insertOverview($runId, 'Admin', 'General and professional expenses', $admin, $netSales, '', $sort++);
        $this->insertOverview($runId, 'Admin', 'Net surplus / burn', $netSurplus, $netSales, 'Final MIS result', $sort++);
    }

    private function validateRun(int $runId): void
    {
        $this->db->execute('DELETE FROM validation_issues WHERE run_id = ? AND source_file_id IS NULL', [$runId]);

        $totalRows = $this->db->fetch('SELECT COUNT(*) AS count FROM import_rows WHERE run_id = ?', [$runId]);
        if ((int) ($totalRows['count'] ?? 0) === 0) {
            $this->issue($runId, 'error', 'No sales rows imported for this run.', []);
        }

        $emptyFiles = $this->db->fetchAll(
            'SELECT source_type, original_name FROM source_files WHERE run_id = ? AND rows_imported = 0 ORDER BY uploaded_at DESC LIMIT 100',
            [$runId]
        );
        foreach ($emptyFiles as $file) {
            $this->issue($runId, 'warning', 'Imported file has zero usable sales rows: ' . $file['original_name'], ['source' => $file['source_type']]);
        }

        $duplicateFiles = $this->db->fetchAll(
            "SELECT source_type, checksum, COUNT(*) AS file_count
             FROM source_files
             WHERE run_id = ? AND checksum IS NOT NULL AND checksum <> ''
             GROUP BY source_type, checksum
             HAVING COUNT(*) > 1",
            [$runId]
        );
        foreach ($duplicateFiles as $file) {
            $this->issue($runId, 'notice', 'Duplicate source file checksum for ' . $file['source_type'], ['files' => (int) $file['file_count']]);
        }

        $hasFullWorkbook = $this->db->fetch(
            "SELECT id FROM source_files WHERE run_id = ? AND source_type = 'sample_workbook' AND rows_imported > 0 LIMIT 1",
            [$runId]
        );
        if (!$hasFullWorkbook) {
            $missingSources = $this->db->fetchAll(
                "SELECT ps.source_type, ps.label
                 FROM portal_sources ps
                 LEFT JOIN source_files sf ON sf.run_id = ? AND sf.source_type = ps.source_type AND sf.rows_imported > 0
                 WHERE ps.enabled = 1 AND sf.id IS NULL
                 ORDER BY ps.source_type",
                [$runId]
            );
            foreach ($missingSources as $source) {
                $this->issue($runId, 'notice', 'No imported sales data yet for ' . $source['label'], ['source' => $source['source_type']]);
            }
        }

        $unmapped = $this->db->fetchAll(
            "SELECT product_name, COUNT(*) AS row_count
             FROM import_rows
             WHERE run_id = ? AND product_name <> '' AND (category = 'Unmapped' OR cogs_sku = '')
             GROUP BY product_name
             ORDER BY row_count DESC
             LIMIT 100",
            [$runId]
        );
        foreach ($unmapped as $row) {
            $this->issue($runId, 'warning', 'Unmapped product: ' . ($row['product_name'] ?: 'blank product'), ['rows' => (int) $row['row_count']]);
        }

        $blankProducts = $this->db->fetchAll(
            "SELECT source_type, platform, COUNT(*) AS row_count, SUM(net_revenue) AS revenue
             FROM import_rows
             WHERE run_id = ? AND product_name = ''
             GROUP BY source_type, platform
             ORDER BY row_count DESC",
            [$runId]
        );
        foreach ($blankProducts as $row) {
            $this->issue($runId, 'warning', 'Rows missing product names in ' . $row['platform'], ['source' => $row['source_type'], 'rows' => (int) $row['row_count'], 'revenue' => (float) $row['revenue']]);
        }

        $duplicates = $this->db->fetchAll(
            "SELECT platform, order_id, product_name, COUNT(*) AS row_count
             FROM import_rows
             WHERE run_id = ? AND order_id <> ''
             GROUP BY platform, order_id, product_name
             HAVING COUNT(*) > 1
             ORDER BY row_count DESC
             LIMIT 50",
            [$runId]
        );
        foreach ($duplicates as $row) {
            $this->issue($runId, 'notice', 'Duplicate order rows: ' . $row['platform'] . ' / ' . $row['order_id'], ['product' => $row['product_name'], 'rows' => (int) $row['row_count']]);
        }

        $negativeSales = $this->db->fetchAll(
            "SELECT platform, COUNT(*) AS row_count, SUM(net_revenue) AS net
             FROM import_rows
             WHERE run_id = ? AND quantity > 0 AND net_revenue < 0
             GROUP BY platform",
            [$runId]
        );
        foreach ($negativeSales as $row) {
            $this->issue($runId, 'warning', 'Positive quantity with negative revenue in ' . $row['platform'], ['rows' => (int) $row['row_count'], 'net' => (float) $row['net']]);
        }

        $summary = $this->db->fetch('SELECT COUNT(*) AS rows_count, SUM(net_revenue) AS revenue FROM import_rows WHERE run_id = ?', [$runId]);
        $this->log($runId, 'Validation refreshed', $summary ?: []);
    }

    private function aggregate(int $runId, string $platform, string $where): array
    {
        $params = [$runId];
        $platformClause = '';
        if ($platform !== '') {
            $platformClause = ' AND platform = ?';
            $params[] = $platform;
        }
        return $this->db->fetch(
            "SELECT COUNT(DISTINCT NULLIF(order_id, '')) AS order_count, SUM(quantity) AS quantity, SUM(gross_amount) AS amount
             FROM import_rows WHERE run_id = ? {$platformClause} AND {$where}",
            $params
        ) ?? ['order_count' => 0, 'quantity' => 0, 'amount' => 0];
    }

    private function insertPlatform(int $runId, string $platform, string $lineItem, ?array $values, int $sort): void
    {
        $this->db->execute(
            'INSERT INTO mis_platform_summary (run_id, platform, line_item, order_count, quantity, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $runId,
                $platform,
                $lineItem,
                (int) ($values['order_count'] ?? 0),
                (float) ($values['quantity'] ?? 0),
                (float) ($values['amount'] ?? 0),
                $sort,
            ]
        );
    }

    private function insertOverview(int $runId, string $section, string $lineItem, float $amount, float $netSales, string $note, int $sort): void
    {
        $ratio = abs($netSales) > 0.00001 ? $amount / $netSales : null;
        $this->db->execute(
            'INSERT INTO mis_overview_lines (run_id, section, line_item, amount, ratio, note, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$runId, $section, $lineItem, $amount, $ratio, $note, $sort]
        );
    }

    private function sumPnl(int $runId, array $categories): float
    {
        if (!$categories) {
            return 0.0;
        }
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $params = array_merge([$runId], $categories);
        $row = $this->db->fetch(
            "SELECT SUM(amount) AS amount FROM profit_loss_entries WHERE run_id = ? AND pnl_category IN ({$placeholders})",
            $params
        );
        return (float) ($row['amount'] ?? 0);
    }

    private function expense(float $amount): float
    {
        return -abs($amount);
    }

    private function costFor(string $category): array
    {
        $row = $this->db->fetch(
            'SELECT * FROM product_costs WHERE lower(category) = lower(?) OR lower(item_name) = lower(?) ORDER BY category = ? DESC LIMIT 1',
            [$category, $category, $category]
        );
        return $row ?: ['multiplier' => 1, 'purchase_price' => 0, 'packaging_rate' => 0];
    }

    private function issue(int $runId, string $severity, string $message, array $context): void
    {
        $this->db->execute(
            'INSERT INTO validation_issues (run_id, source_file_id, severity, message, context_json, issue_key, status, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, NOW())',
            [$runId, $severity, $message, json_encode($context), sha1($message), 'open']
        );
    }

    private function log(int $runId, string $message, array $context): void
    {
        $this->db->execute(
            'INSERT INTO calculation_log (run_id, message, context_json, created_at) VALUES (?, ?, ?, NOW())',
            [$runId, $message, json_encode($context)]
        );
    }

    private function countRows(int $runId): int
    {
        $row = $this->db->fetch('SELECT COUNT(*) AS count FROM import_rows WHERE run_id = ?', [$runId]);
        return (int) ($row['count'] ?? 0);
    }
}
