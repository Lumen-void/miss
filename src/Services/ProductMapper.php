<?php

declare(strict_types=1);

namespace MisTool\Services;

use MisTool\Database;

final class ProductMapper
{
    public function __construct(private Database $db)
    {
    }

    public function resolveMapping(string $product, string $cogs, string $mis): array
    {
        $guessed = $product !== '' ? $this->guessCostByProduct($product) : null;
        if ($guessed) {
            return $guessed;
        }

        if ($product !== '') {
            $row = $this->db->fetch('SELECT * FROM sku_mappings WHERE lower(product_name) = lower(?)', [$product]);
            if ($row && ($row['category'] ?? '') !== 'Unmapped') {
                return $row;
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

    public function guessCostByProduct(string $product): ?array
    {
        $specific = $this->specificProductCost($product);
        if ($specific) {
            return $specific;
        }

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
        return $this->mappingFromCost($best);
    }

    private function specificProductCost(string $product): ?array
    {
        $text = strtolower($product);
        $compact = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;
        $size = $this->packSize($compact);

        if (str_contains($compact, 'diabetes') || str_contains($compact, 'catechu')) {
            return $this->costByCategory('Diabetes Care Cold Brew with Acacia Catechu');
        }
        if (str_contains($compact, 'hair oil') || str_contains($compact, 'jatamansi') || str_contains($compact, 'rosemary')) {
            return $this->costByCategory('Naturesum Jatamansi & Rosemary Hair Oil for Hair Growth');
        }
        if (str_contains($compact, 'sea buckthorn oil') || str_contains($compact, 'buckthorn berry oil')) {
            return $this->costByItem($size === 30 ? 'Sea Buckthorn Berry Oil 30 ML' : 'Sea Buckthorn Berry Oil 15 ML');
        }
        if (str_contains($compact, 'powder')) {
            return $this->costByItem(match ($size) {
                500 => 'Pure Sea Buckthorn Dry Berries Powder 500 GM',
                250 => 'Pure Sea Buckthorn Dry Berries Powder 250 GM',
                default => 'Pure Sea Buckthorn Berries Powder 100 GM',
            });
        }
        if (str_contains($compact, 'sea buckthorn') && (str_contains($compact, 'berries') || str_contains($compact, 'berry'))) {
            return $this->costByItem(match ($size) {
                500 => 'Pure Sea Buckthorn Dry Berries 500 GM',
                250 => 'Pure Sea Buckthorn Dry Berries 250 GM',
                default => 'Pure Sea Buckthorn Dry Berries 100 GM',
            });
        }

        return null;
    }

    private function packSize(string $text): int
    {
        if (preg_match('/\b(15|30)\s*ml\b/i', $text, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/\b(100|250|500)\s*(?:g|gm|gram|grams)\b/i', $text, $match)) {
            return (int) $match[1];
        }
        return 0;
    }

    private function costByItem(string $itemName): ?array
    {
        $row = $this->db->fetch('SELECT item_name, category FROM product_costs WHERE lower(item_name) = lower(?) LIMIT 1', [$itemName]);
        return $row ? $this->mappingFromCost($row) : null;
    }

    private function costByCategory(string $category): ?array
    {
        $row = $this->db->fetch('SELECT item_name, category FROM product_costs WHERE lower(category) = lower(?) ORDER BY item_name LIMIT 1', [$category]);
        return $row ? $this->mappingFromCost($row) : null;
    }

    private function mappingFromCost(array $cost): array
    {
        return [
            'cogs_sku' => $cost['item_name'],
            'mis_sku' => $cost['category'],
            'category' => $cost['category'],
        ];
    }

    private function tokens(string $value): array
    {
        $stop = ['naturesum', 'nature', 'sum', 'organic', 'pure', 'with', 'for', 'and', 'the', 'high', 'rich', 'vitamin', 'boosts', 'rare', 'omega', 'skin', 'glow'];
        $parts = preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [];
        return array_values(array_unique(array_filter($parts, fn($part) => strlen($part) > 2 && !in_array($part, $stop, true))));
    }
}
