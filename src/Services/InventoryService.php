<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;
use RuntimeException;

final class InventoryService
{
    public function __construct(private Database $db)
    {
    }

    public function seedItemsFromMasters(): int
    {
        $this->cleanupGeneratedSeedNoise();
        $count = 0;
        $rows = $this->db->fetchAll(
            "SELECT item_name AS sku, item_name, category FROM product_costs WHERE item_name <> ''
             UNION
             SELECT COALESCE(NULLIF(cogs_sku, ''), NULLIF(mis_sku, ''), product_name) AS sku, product_name AS item_name, category FROM sku_mappings WHERE product_name <> ''"
        );
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['item_name'] ?? $sku));
            if (!$this->isUsableItemSeed($sku) || !$this->isUsableItemSeed($name)) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO inventory_items (sku, item_name, category, reorder_level, created_at, updated_at)
                 VALUES (?, ?, ?, 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), category = VALUES(category), updated_at = NOW()',
                [$sku, $name, trim((string) ($row['category'] ?? ''))]
            );
            $count++;
        }
        return $count;
    }

    public function saveItem(int $id, string $sku, string $name, string $category, float $reorderLevel): void
    {
        $sku = trim($sku);
        $name = trim($name);
        if ($sku === '' || $name === '') {
            throw new RuntimeException('SKU and item name are required.');
        }
        if ($id > 0) {
            $this->db->execute('UPDATE inventory_items SET sku = ?, item_name = ?, category = ?, reorder_level = ?, updated_at = NOW() WHERE id = ?', [$sku, $name, $category, $reorderLevel, $id]);
            return;
        }
        $this->db->execute('INSERT INTO inventory_items (sku, item_name, category, reorder_level, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [$sku, $name, $category, $reorderLevel]);
    }

    public function saveWarehouse(int $id, string $name, string $location): void
    {
        $name = trim($name);
        $location = trim($location);
        if ($name === '') {
            throw new RuntimeException('Warehouse name is required.');
        }
        if ($id > 0) {
            $this->db->execute('UPDATE warehouses SET name = ?, location = ?, updated_at = NOW() WHERE id = ?', [$name, $location, $id]);
            return;
        }
        $this->db->execute(
            'INSERT INTO warehouses (name, location, is_active, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE location = VALUES(location), is_active = 1, updated_at = NOW()',
            [$name, $location]
        );
    }

    public function addMovement(int $itemId, int $warehouseId, string $type, float $quantity, float $unitCost, string $reference, string $notes): void
    {
        if ($itemId <= 0 || $warehouseId <= 0 || $quantity == 0.0) {
            throw new RuntimeException('Item, warehouse, and non-zero quantity are required.');
        }
        $valid = ['opening', 'purchase', 'adjustment', 'transfer_in', 'transfer_out', 'sale', 'return', 'damage', 'expired'];
        $type = in_array($type, $valid, true) ? $type : 'adjustment';
        $quantity = $this->signedQuantity($type, $quantity);
        $this->db->execute(
            'INSERT INTO inventory_movements (item_id, warehouse_id, movement_type, reference, quantity, unit_cost, notes, moved_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$itemId, $warehouseId, $type, $reference, $quantity, $unitCost, $notes]
        );
    }

    public function transferStock(int $itemId, int $fromWarehouseId, int $toWarehouseId, float $quantity, string $reference, string $notes): void
    {
        if ($itemId <= 0 || $fromWarehouseId <= 0 || $toWarehouseId <= 0 || $fromWarehouseId === $toWarehouseId || $quantity <= 0) {
            throw new RuntimeException('Item, different warehouses, and positive transfer quantity are required.');
        }
        $reference = trim($reference) ?: 'Transfer ' . date('YmdHis');
        $this->db->begin();
        try {
            $this->addMovement($itemId, $fromWarehouseId, 'transfer_out', $quantity, 0, $reference, $notes);
            $this->addMovement($itemId, $toWarehouseId, 'transfer_in', $quantity, 0, $reference, $notes);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function syncSalesRun(int $runId, int $warehouseId): int
    {
        if ($warehouseId <= 0) {
            throw new RuntimeException('Warehouse is required.');
        }
        $this->seedItemsFromMasters();
        $rows = $this->db->fetchAll(
            "SELECT ir.*, COALESCE(NULLIF(ir.cogs_sku, ''), NULLIF(ir.mis_sku, ''), NULLIF(ir.product_name, ''), CONCAT('row-', ir.id)) AS stock_sku
             FROM import_rows ir
             LEFT JOIN inventory_movements im ON im.import_row_id = ir.id AND im.movement_type IN ('sale', 'return')
             WHERE ir.run_id = ? AND im.id IS NULL",
            [$runId]
        );

        $count = 0;
        foreach ($rows as $row) {
            $sku = trim((string) $row['stock_sku']);
            $item = $this->db->fetch('SELECT * FROM inventory_items WHERE sku = ?', [$sku]);
            if (!$item) {
                $this->db->execute(
                    'INSERT INTO inventory_items (sku, item_name, category, reorder_level, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())',
                    [$sku, trim((string) $row['product_name']) ?: $sku, trim((string) $row['category'])]
                );
                $item = $this->db->fetch('SELECT * FROM inventory_items WHERE sku = ?', [$sku]);
            }
            $quantity = abs((float) $row['quantity']);
            if ($quantity == 0.0) {
                continue;
            }
            $type = ((float) $row['quantity']) < 0 || str_contains(strtolower((string) $row['transaction_type']), 'return') ? 'return' : 'sale';
            $signedQuantity = $type === 'sale' ? -$quantity : $quantity;
            $this->db->execute(
                'INSERT INTO inventory_movements (item_id, warehouse_id, run_id, import_row_id, movement_type, reference, quantity, unit_cost, notes, moved_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())',
                [(int) $item['id'], $warehouseId, $runId, (int) $row['id'], $type, (string) $row['order_id'], $signedQuantity, (string) $row['platform']]
            );
            $count++;
        }
        return $count;
    }

    public function stockSummary(): array
    {
        return $this->db->fetchAll(
            "SELECT ii.id, ii.sku, ii.item_name, ii.category, ii.reorder_level, COALESCE(SUM(im.quantity), 0) AS stock_qty,
                    COALESCE(SUM(CASE WHEN im.quantity > 0 THEN im.quantity * im.unit_cost ELSE 0 END), 0) AS stock_value
             FROM inventory_items ii
             LEFT JOIN inventory_movements im ON im.item_id = ii.id
             GROUP BY ii.id, ii.sku, ii.item_name, ii.category, ii.reorder_level
             ORDER BY ii.category, ii.item_name"
        );
    }

    public function lowStock(): array
    {
        return array_values(array_filter(
            $this->stockSummary(),
            fn(array $row): bool => (float) $row['reorder_level'] > 0 && (float) $row['stock_qty'] <= (float) $row['reorder_level']
        ));
    }

    public function monthlyLedger(string $month): array
    {
        $start = preg_match('/^\d{4}-\d{2}$/', $month) ? $month . '-01 00:00:00' : date('Y-m-01 00:00:00');
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
        return $this->db->fetchAll(
            "SELECT ii.sku, ii.item_name, ii.category, w.name AS warehouse_name,
                    COALESCE(SUM(CASE WHEN im.moved_at < ? THEN im.quantity ELSE 0 END), 0) AS opening_qty,
                    COALESCE(SUM(CASE WHEN im.moved_at >= ? AND im.moved_at < ? AND im.movement_type IN ('opening','purchase','return') THEN im.quantity ELSE 0 END), 0) AS inward_qty,
                    COALESCE(SUM(CASE WHEN im.moved_at >= ? AND im.moved_at < ? AND im.movement_type IN ('sale','damage','expired') THEN ABS(im.quantity) ELSE 0 END), 0) AS outward_qty,
                    COALESCE(SUM(CASE WHEN im.moved_at >= ? AND im.moved_at < ? AND im.movement_type = 'transfer_in' THEN im.quantity ELSE 0 END), 0) AS transfer_in_qty,
                    COALESCE(SUM(CASE WHEN im.moved_at >= ? AND im.moved_at < ? AND im.movement_type = 'transfer_out' THEN ABS(im.quantity) ELSE 0 END), 0) AS transfer_out_qty,
                    COALESCE(SUM(CASE WHEN im.moved_at >= ? AND im.moved_at < ? AND im.movement_type = 'adjustment' THEN im.quantity ELSE 0 END), 0) AS adjustment_qty,
                    COALESCE(SUM(CASE WHEN im.moved_at < ? THEN im.quantity ELSE 0 END), 0)
                    + COALESCE(SUM(CASE WHEN im.moved_at >= ? AND im.moved_at < ? THEN im.quantity ELSE 0 END), 0) AS closing_qty
             FROM inventory_items ii
             JOIN inventory_movements im ON im.item_id = ii.id
             JOIN warehouses w ON w.id = im.warehouse_id
             GROUP BY ii.id, w.id
             ORDER BY ii.category, ii.item_name, w.name
             LIMIT 1000",
            [$start, $start, $end, $start, $end, $start, $end, $start, $end, $start, $end, $start, $start, $end]
        );
    }

    public function warehouses(): array
    {
        return $this->db->fetchAll('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name');
    }

    public function items(): array
    {
        return $this->db->fetchAll('SELECT * FROM inventory_items ORDER BY item_name LIMIT 1000');
    }

    public function recentMovements(): array
    {
        return $this->db->fetchAll(
            'SELECT im.*, ii.sku, ii.item_name, w.name AS warehouse_name
             FROM inventory_movements im
             JOIN inventory_items ii ON ii.id = im.item_id
             JOIN warehouses w ON w.id = im.warehouse_id
             ORDER BY im.id DESC LIMIT 120'
        );
    }

    private function cleanupGeneratedSeedNoise(): void
    {
        $this->db->execute(
            "DELETE ii FROM inventory_items ii
             LEFT JOIN inventory_movements im ON im.item_id = ii.id
             WHERE im.id IS NULL AND (ii.sku LIKE '=%' OR ii.item_name LIKE '=%' OR ii.sku IN ('Item Description', 'Item Name', 'Product Name', 'COGS SKU', 'MIS SKU'))"
        );
    }

    private function isUsableItemSeed(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '=')) {
            return false;
        }
        return !in_array(strtolower($value), ['item description', 'item name', 'product name', 'cogs sku', 'mis sku'], true);
    }

    private function signedQuantity(string $type, float $quantity): float
    {
        $quantity = abs($quantity);
        return match ($type) {
            'sale', 'transfer_out', 'damage', 'expired' => -$quantity,
            default => $quantity,
        };
    }
}
