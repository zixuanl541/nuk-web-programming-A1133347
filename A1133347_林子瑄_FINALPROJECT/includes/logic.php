<?php
// includes/logic.php — Core business logic (schema: companies / exchange_rates / inventory_items batch-level / sale_items FIFO)

require_once __DIR__ . '/../config/db.php';

// ── Exchange Rates ────────────────────────────────────────────────────────────

/**
 * Latest known rate for 1 unit of $currency expressed in TWD.
 * TWD itself is always 1. Falls back to 1.0 if no history exists yet.
 */
function getLatestExchangeRate(string $currency): float {
    if ($currency === 'TWD') return 1.0;

    $stmt = db()->prepare(
        'SELECT rate FROM exchange_rates WHERE currency = ? ORDER BY fetched_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$currency]);
    $rate = $stmt->fetchColumn();
    return $rate !== false ? (float)$rate : 1.0;
}

/**
 * Latest rate row (rate + fetched_at) for $currency, or null if no history yet.
 * Used by the dashboard's exchange-rate widget.
 */
function getLatestExchangeRateInfo(string $currency): ?array {
    $stmt = db()->prepare(
        'SELECT rate, fetched_at FROM exchange_rates WHERE currency = ? ORDER BY fetched_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$currency]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Min / max / latest rate for $currency over the last $days days.
 * Used by reverse pricing to bracket a sell-price range.
 */
function getExchangeRateRange(string $currency, int $days = 90): array {
    $latest = getLatestExchangeRate($currency);

    if ($currency === 'TWD') {
        return ['min' => 1.0, 'max' => 1.0, 'latest' => 1.0];
    }

    $stmt = db()->prepare(
        'SELECT MIN(rate) AS min_rate, MAX(rate) AS max_rate
         FROM exchange_rates
         WHERE currency = ? AND fetched_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)'
    );
    $stmt->execute([$currency, $days]);
    $row = $stmt->fetch();

    $min = $row && $row['min_rate'] !== null ? (float)$row['min_rate'] : $latest;
    $max = $row && $row['max_rate'] !== null ? (float)$row['max_rate'] : $latest;

    // $latest isn't guaranteed to fall inside the 90-day min/max (e.g. it was
    // just synced and is more extreme than anything seen in that window yet).
    // Reverse pricing assumes min <= latest <= max to bracket a sell-price
    // range, so widen the bracket here rather than let the "目前建議售價"
    // come out above the "上限" the user sees.
    $min = min($min, $latest);
    $max = max($max, $latest);

    return ['min' => $min, 'max' => $max, 'latest' => $latest];
}

/**
 * Pull fresh TWD exchange rates from a free public API and append them
 * to exchange_rates (history is never overwritten — always INSERT).
 */
function syncExchangeRates(): array {
    $url = 'https://open.er-api.com/v6/latest/TWD';

    $json = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $json = curl_exec($ch);
        curl_close($ch);
    } else {
        $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
    }

    if (!$json) {
        return ['success' => false, 'message' => '無法連線到匯率 API,請稍後再試。'];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || ($data['result'] ?? '') !== 'success' || empty($data['rates'])) {
        return ['success' => false, 'message' => '匯率 API 回應格式錯誤。'];
    }

    $rates = $data['rates'];
    // open.er-api.com 回傳 1 TWD = X 外幣，需取倒數得到「1 外幣 = ? TWD」
    $map = ['JPY' => 'JPY', 'USD' => 'USD', 'RMB' => 'CNY'];

    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO exchange_rates (currency, rate, fetched_at) VALUES (?, ?, NOW())');

    $synced = [];
    foreach ($map as $currency => $apiCode) {
        if (empty($rates[$apiCode]) || (float)$rates[$apiCode] <= 0) continue;
        $rate = round(1 / (float)$rates[$apiCode], 4);
        $ins->execute([$currency, $rate]);
        $synced[$currency] = $rate;
    }

    if (!$synced) {
        return ['success' => false, 'message' => '匯率 API 未回傳可用的幣別資料。'];
    }

    return ['success' => true, 'message' => '匯率已同步！', 'rates' => $synced];
}

// ── Shipping Allocation ──────────────────────────────────────────────────────

/**
 * Calculate unit_cost (product cost + allocated shipping, in TWD) for each
 * product in a batch.
 *
 * $products: array of ['product_id'=>, 'quantity'=>, 'weight'=>, 'cost'=>]
 *   - cost/weight are in the product's original currency / per-unit weight
 * Returns array keyed by product_id with: unit_cost, cost_twd, shipping_per_unit
 */
function calculateShippingAllocation(array $products, float $totalShipping, float $exchangeRate, string $method): array {
    $totalShippingTWD = $totalShipping * $exchangeRate;
    $allocTotals = [];

    if ($method === 'weight') {
        $totalWeight = array_sum(array_map(fn($p) => $p['weight'] * $p['quantity'], $products));
        foreach ($products as $p) {
            $allocTotals[$p['product_id']] = $totalWeight > 0
                ? ($p['weight'] * $p['quantity'] / $totalWeight) * $totalShippingTWD
                : 0;
        }
    } else {
        $totalQty = array_sum(array_column($products, 'quantity'));
        foreach ($products as $p) {
            $allocTotals[$p['product_id']] = $totalQty > 0
                ? ($p['quantity'] / $totalQty) * $totalShippingTWD
                : 0;
        }
    }

    $out = [];
    foreach ($products as $p) {
        $allocTotal      = $allocTotals[$p['product_id']];
        $shippingPerUnit = $p['quantity'] > 0 ? $allocTotal / $p['quantity'] : 0;
        $costTWD         = $p['cost'] * $exchangeRate;
        $out[$p['product_id']] = [
            'cost_twd'          => round($costTWD, 2),
            'shipping_per_unit' => round($shippingPerUnit, 2),
            'unit_cost'         => round($costTWD + $shippingPerUnit, 2),
        ];
    }

    return $out;
}

/**
 * Insert one inventory_items row per product for a batch.
 * $products: array of ['product_id'=>, 'quantity'=>]
 * $allocationResult: output of calculateShippingAllocation()
 */
function createInventoryItems(int $batchId, array $products, array $allocationResult): void {
    $ins = db()->prepare(
        'INSERT INTO inventory_items (batch_id, product_id, quantity, remaining_quantity, unit_cost)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($products as $p) {
        $unitCost = $allocationResult[$p['product_id']]['unit_cost'] ?? 0;
        $ins->execute([$batchId, $p['product_id'], $p['quantity'], $p['quantity'], $unitCost]);
    }
}

// ── FIFO Sale ─────────────────────────────────────────────────────────────────

/**
 * Consume inventory FIFO (oldest batch first) for $productId within $companyId
 * to cover $quantity units, recording sale_items against $saleId.
 *
 * Returns: ['sale_items' => [...], 'total_cost' => float, 'average_cost' => float]
 * Throws Exception if available stock < $quantity (caller should roll back).
 */
function processFIFOSale(int $companyId, int $productId, int $quantity, int $saleId): array {
    $pdo = db();

    // FOR UPDATE locks these inventory rows until the transaction commits, so
    // two simultaneous sales can't both read the same remaining_quantity and
    // oversell the same batch.
    $stmt = $pdo->prepare(
        "SELECT ii.id, ii.remaining_quantity, ii.unit_cost
         FROM inventory_items ii
         JOIN batches b ON b.id = ii.batch_id
         WHERE ii.product_id = ? AND b.company_id = ? AND ii.remaining_quantity > 0
         ORDER BY b.batch_date ASC, ii.id ASC
         FOR UPDATE"
    );
    $stmt->execute([$productId, $companyId]);
    $batches = $stmt->fetchAll();

    $insItem = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, inventory_item_id, quantity, unit_cost) VALUES (?, ?, ?, ?)'
    );
    $updInv = $pdo->prepare(
        'UPDATE inventory_items SET remaining_quantity = remaining_quantity - ? WHERE id = ?'
    );

    $remainingNeeded = $quantity;
    $totalCost = 0.0;
    $saleItems = [];

    foreach ($batches as $b) {
        if ($remainingNeeded <= 0) break;

        $take = min((int)$b['remaining_quantity'], $remainingNeeded);
        if ($take <= 0) continue;

        $insItem->execute([$saleId, $b['id'], $take, $b['unit_cost']]);
        $updInv->execute([$take, $b['id']]);

        $saleItems[] = [
            'inventory_item_id' => $b['id'],
            'quantity'          => $take,
            'unit_cost'         => (float)$b['unit_cost'],
        ];

        $totalCost       += $take * (float)$b['unit_cost'];
        $remainingNeeded -= $take;
    }

    if ($remainingNeeded > 0) {
        throw new Exception('庫存不足，無法完成此筆銷售。');
    }

    return [
        'sale_items'   => $saleItems,
        'total_cost'   => round($totalCost, 2),
        'average_cost' => $quantity > 0 ? round($totalCost / $quantity, 2) : 0,
    ];
}

// ── Profit Calculation ───────────────────────────────────────────────────────

function calculateProfit(float $salePrice, float $platformFee, float $shippingCost, float $totalCost): array {
    $profit = $salePrice - $platformFee - $shippingCost - $totalCost;
    $margin = $salePrice > 0 ? ($profit / $salePrice * 100) : 0;
    return [
        'profit'        => round($profit, 2),
        'profit_margin' => round($margin, 2),
    ];
}

/**
 * Classify a profit margin (%) into the three-tier warning system shown
 * across the dashboard, reports, and sales pages.
 *
 * 商業邏輯：
 * 10% 是工作室設定的最低安全利潤率門檻——低於 0% 代表虧本在賣，
 * 0%~10% 代表雖有賺但利潤太薄、容易被匯率波動或平台抽成吃掉，
 * 因此獨立列為「風險」等級，提醒賣家檢視定價而非等到真正虧損才警示。
 */
function profit_status(float $margin): string {
    if ($margin < 0)  return 'loss';
    if ($margin < 10) return 'risk';
    return 'safe';
}

function profit_label(float $margin): string {
    return match(profit_status($margin)) {
        'loss' => '虧損',
        'risk' => '風險',
        default => '安全',
    };
}

// ── Reverse Pricing ──────────────────────────────────────────────────────────

/**
 * Minimum sell price covering $unitCost + $shippingCost, the platform fee
 * rate, and a target profit margin.
 *
 * Formula: minPrice = (unitCost + shippingCost) / ((1 - platformRate) * (1 - targetMargin))
 */
function reversePricing(float $unitCost, float $platformRatePct, float $shippingCost, float $targetMarginPct): float {
    $totalCost = $unitCost + $shippingCost;
    $p = 1 - ($platformRatePct / 100);
    $m = 1 - ($targetMarginPct / 100);
    if ($p <= 0 || $m <= 0) return 0;
    return round($totalCost / ($p * $m), 2);
}

// ── Dashboard Stats ──────────────────────────────────────────────────────────

function dashboard_stats(int $userId, int $companyId, bool $isAdmin = false): array {
    $pdo = db();

    if ($isAdmin) {
        $where  = 'WHERE u.company_id = ?';
        $params = [$companyId];
    } else {
        $where  = 'WHERE sr.user_id = ?';
        $params = [$userId];
    }

    $stmt = $pdo->prepare(
        "SELECT
           COUNT(*)                                  AS total_sales,
           COALESCE(SUM(sr.sale_price),0)            AS total_revenue,
           COALESCE(SUM(sr.profit),0)                AS total_profit,
           CASE WHEN COALESCE(SUM(sr.sale_price),0) > 0 THEN SUM(sr.profit) / SUM(sr.sale_price) * 100 ELSE 0 END AS avg_margin,
           SUM(sr.profit < 0)                        AS loss_count,
           SUM(sr.profit >= 0 AND (sr.profit / sr.sale_price * 100) < 10) AS risk_count,
           SUM((sr.profit / sr.sale_price * 100) >= 10) AS safe_count
         FROM sales_records sr
         JOIN users u ON u.id = sr.user_id
         $where"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    $productCount = $pdo->prepare('SELECT COUNT(*) FROM products WHERE company_id = ?');
    $productCount->execute([$companyId]);
    $productCount = $productCount->fetchColumn();

    if ($isAdmin) {
        $batchCount = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE company_id = ?');
        $batchCount->execute([$companyId]);
    } else {
        $batchCount = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE user_id = ?');
        $batchCount->execute([$userId]);
    }
    $batchCount = $batchCount->fetchColumn();

    $stockCount = $pdo->prepare(
        'SELECT COALESCE(SUM(ii.remaining_quantity),0)
         FROM inventory_items ii
         JOIN batches b ON b.id = ii.batch_id
         WHERE b.company_id = ?'
    );
    $stockCount->execute([$companyId]);
    $stockCount = $stockCount->fetchColumn();

    return array_merge($row, [
        'product_count' => (int)$productCount,
        'batch_count'   => (int)$batchCount,
        'stock_count'   => (int)$stockCount,
    ]);
}

function monthly_profits(int $userId, int $companyId, int $year, bool $isAdmin = false): array {
    $pdo = db();

    if ($isAdmin) {
        $where  = 'WHERE u.company_id = ? AND YEAR(sr.sold_date) = ?';
        $params = [$companyId, $year];
    } else {
        $where  = 'WHERE sr.user_id = ? AND YEAR(sr.sold_date) = ?';
        $params = [$userId, $year];
    }

    $stmt = $pdo->prepare(
        "SELECT MONTH(sr.sold_date) AS month, SUM(sr.profit) AS profit
         FROM sales_records sr
         JOIN users u ON u.id = sr.user_id
         $where
         GROUP BY MONTH(sr.sold_date)
         ORDER BY month"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Pre-fill all 12 months so the dashboard chart always has a fixed-length
    // series to plot, even for months with zero sales.
    $months = array_fill(1, 12, 0);
    foreach ($rows as $r) $months[(int)$r['month']] = (float)$r['profit'];
    return $months;
}
