<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_admin();
$user      = current_user();
$companyId = require_company();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'apply_price') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $price     = (float)($_POST['new_price'] ?? 0);
        if ($productId && $price > 0) {
            db()->prepare('UPDATE products SET selling_price = ? WHERE id = ? AND company_id = ?')
               ->execute([$price, $productId, $companyId]);
            flash('商品售價已更新。');
        }
    }
    header('Location: ' . BASE_PATH . '/pricing.php');
    exit;
}

$preselect = (int)($_GET['product_id'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.id, p.name, p.cost, p.currency, p.selling_price,
            COALESCE(SUM(ii.remaining_quantity), 0) AS stock_qty,
            CASE WHEN COALESCE(SUM(ii.remaining_quantity), 0) > 0
                 THEN SUM(ii.remaining_quantity * ii.unit_cost) / SUM(ii.remaining_quantity)
                 ELSE NULL END AS avg_unit_cost,
            EXISTS(SELECT 1 FROM inventory_items ii2 WHERE ii2.product_id = p.id) AS has_batches,
            (SELECT ii3.unit_cost FROM inventory_items ii3 WHERE ii3.product_id = p.id ORDER BY ii3.id DESC LIMIT 1) AS last_unit_cost
     FROM products p
     LEFT JOIN inventory_items ii ON ii.product_id = p.id AND ii.remaining_quantity > 0
     WHERE p.company_id = ?
     GROUP BY p.id
     ORDER BY p.name'
);
$stmt->execute([$companyId]);
$products = $stmt->fetchAll();

// 近 90 天匯率區間 — 僅用於「下次進貨成本風險參考」，不混入當前成本計算
$rateRanges = [
    'RMB' => getExchangeRateRange('RMB', 90),
    'USD' => getExchangeRateRange('USD', 90),
    'JPY' => getExchangeRateRange('JPY', 90),
    'TWD' => ['min' => 1.0, 'max' => 1.0, 'latest' => 1.0],
];

render_head('售價決策');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('售價決策', '依商品生命週期，提供首次定價或售價安全分析'); ?>
<div class="content">
<?php render_flash(); ?>

<?php if (!$products): ?>
  <div class="alert alert-yellow">尚無商品，請先至「商品管理」新增商品。</div>
<?php else: ?>

<div class="grid-2" style="align-items:start">

  <!-- 左側：輸入參數 -->
  <div>
    <div class="card mb-2">
      <div class="card-title"><span class="card-title-dot"></span>選擇商品</div>
      <div style="font-size:12px;padding:.35rem .75rem;border-radius:6px;margin-bottom:.75rem;background:var(--bg3);color:var(--text3)" id="mode-badge">—</div>
      <div class="form-grid">
        <div class="form-group">
          <select id="pr-product" onchange="analyzePrice()">
            <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>"
                      data-selling="<?= $p['selling_price'] !== null ? number_format((float)$p['selling_price'], 2, '.', '') : '' ?>"
                      data-avg-cost="<?= $p['avg_unit_cost'] !== null ? number_format((float)$p['avg_unit_cost'], 4, '.', '') : '' ?>"
                      data-last-unit-cost="<?= $p['last_unit_cost'] !== null ? number_format((float)$p['last_unit_cost'], 4, '.', '') : '' ?>"
                      data-stock="<?= (int)$p['stock_qty'] ?>"
                      data-has-batches="<?= (int)$p['has_batches'] ?>"
                      data-cost="<?= number_format((float)$p['cost'], 4, '.', '') ?>"
                      data-currency="<?= htmlspecialchars($p['currency']) ?>">
                <?= htmlspecialchars($p['name']) ?>
                <?php if (!(int)$p['has_batches']): ?>
                  （尚未進貨）
                <?php elseif ($p['avg_unit_cost'] !== null): ?>
                  （庫存 <?= (int)$p['stock_qty'] ?> 件，平均成本 <?= fmt((float)$p['avg_unit_cost']) ?>）
                <?php else: ?>
                  （庫存已售罄）
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card mb-2">
      <div class="card-title"><span class="card-title-dot"></span>分析參數</div>
      <div class="form-grid">
        <div class="form-group">
          <label>平台費率（%）</label>
          <input type="number" id="pr-platform" value="5" min="0" max="50" step="0.5" oninput="analyzePrice()">
          <span style="font-size:11px;color:var(--text3)">Shopee ≈ 3–5%、露天 ≈ 3.5%</span>
        </div>
        <div class="form-group">
          <label>目標利潤率（%）</label>
          <input type="range" id="pr-target-range" min="1" max="60" value="20"
            oninput="document.getElementById('pr-target').value=this.value;analyzePrice()"
            style="width:100%;accent-color:var(--accent)">
          <div class="flex items-center gap-2" style="margin-top:.25rem">
            <input type="number" id="pr-target" value="20" min="1" max="99" step="1"
              oninput="document.getElementById('pr-target-range').value=this.value;analyzePrice()"
              style="width:80px">
            <span style="font-size:12px;color:var(--text3)">% 目標利潤率</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 下次進貨成本風險參考 — 與目前成本計算分開 -->
    <div class="card">
      <div class="card-title"><span class="card-title-dot"></span>下次進貨成本風險參考（近 90 天匯率）</div>
      <div id="rate-risk-section">
        <div class="text-muted" style="font-size:13px;padding:1rem 0;text-align:center">請先選擇商品</div>
      </div>
      <div style="font-size:11px;color:var(--text3);margin-top:.5rem;line-height:1.6">
        此區為下次進貨的估算參考。目前庫存成本已於進貨批次建立時鎖定，不受匯率波動影響。
      </div>
    </div>
  </div>

  <!-- 右側：分析結果 -->
  <div>
    <div class="card" style="position:sticky;top:72px">
      <div class="card-title"><span class="card-title-dot"></span><span id="result-title">售價決策</span></div>
      <div id="analysis-result">
        <div class="text-muted" style="font-size:13px;padding:2rem 0;text-align:center">自動分析中…</div>
      </div>

      <form method="POST" id="apply-form" style="margin-top:.75rem;display:none">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="apply_price">
        <input type="hidden" name="product_id" id="apply-product-id">
        <div class="divider"></div>
        <div class="form-group" style="margin-bottom:.5rem">
          <label style="font-size:12px;color:var(--text3)">更新商品售價為（TWD）</label>
          <input type="number" name="new_price" id="apply-price-input"
                 min="0.01" step="1" class="mono" style="width:130px">
        </div>
        <button type="submit" class="btn btn-primary btn-full">更新商品售價</button>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:1rem">
  <div class="card-title"><span class="card-title-dot"></span>分析公式說明</div>
  <div style="background:var(--bg3);border-radius:8px;padding:1rem 1.25rem;font-family:var(--mono);font-size:12px;color:var(--text3);line-height:2.2">
    <div>成本基礎 = 目前庫存加權平均 unit_cost（已含進貨匯率與運費分攤，不重新計算）</div>
    <div>每件出貨總成本 = 庫存平均成本 + 出貨運費</div>
    <div style="color:var(--accent)">建議安全售價 = 出貨總成本 ÷ (1 − 平台費率%) ÷ (1 − 目標利潤率%)</div>
    <div>目前利潤率 = (目前售價 × (1 − 平台費率%) − 庫存平均成本 − 出貨運費) ÷ 目前售價</div>
  </div>
</div>

<?php endif; ?>
</div></div></div>
<?php render_foot(); ?>
<script>
const rateRanges  = <?= json_encode($rateRanges) ?>;
const preselect   = <?= (int)($preselect ?? 0) ?>;

function fmt(n)     { return 'NT$' + Math.round(n).toLocaleString(); }
function fmtCost(n) { return 'NT$' + Number(n.toFixed(2)).toLocaleString(); }
function fmtPct(n)  { return n.toFixed(1) + '%'; }

function fillPrice(amount) {
  document.getElementById('apply-price-input').value = amount;
}

function calcSuggestedPrice(costTWD, outship, platRate, target) {
  const totalCost = costTWD + outship;
  const p = 1 - platRate / 100;
  const m = 1 - target / 100;
  if (p <= 0 || m <= 0) return 0;
  return totalCost / (p * m);
}

function analyzePrice() {
  const sel = document.getElementById('pr-product');
  if (!sel || !sel.options.length) return;

  const opt          = sel.options[sel.selectedIndex];
  const productId    = sel.value;
  const hasBatches   = parseInt(opt.dataset.hasBatches) === 1;
  const avgCost      = parseFloat(opt.dataset.avgCost);
  const lastUnitCost = parseFloat(opt.dataset.lastUnitCost);
  const stockQty     = parseInt(opt.dataset.stock) || 0;
  const selling      = parseFloat(opt.dataset.selling);
  const prodCost     = parseFloat(opt.dataset.cost) || 0;
  const currency     = opt.dataset.currency;
  const outship      = 0;
  const platRate     = parseFloat(document.getElementById('pr-platform').value) || 0;
  const target       = parseFloat(document.getElementById('pr-target').value) || 20;

  const resultDiv = document.getElementById('analysis-result');
  const applyForm = document.getElementById('apply-form');
  const modeBadge = document.getElementById('mode-badge');
  const resultTitle = document.getElementById('result-title');

  if (!hasBatches) {
    // ── 模式一：首次售價估算 ──────────────────────────────
    modeBadge.textContent  = '模式：首次售價估算 — 協助第一次建立售價';
    modeBadge.style.color  = 'var(--accent)';
    resultTitle.textContent = '首次售價估算';
    applyForm.style.display = 'none';

    const range   = rateRanges[currency] ?? { min: 1, max: 1, latest: 1 };
    const isTWD   = currency === 'TWD';

    const todayCostTWD      = prodCost * range.latest;
    const conserveCostTWD   = prodCost * range.max;
    const optimisticCostTWD = prodCost * range.min;

    const todayPrice      = calcSuggestedPrice(todayCostTWD,      outship, platRate, target);
    const conservePrice   = calcSuggestedPrice(conserveCostTWD,   outship, platRate, target);
    const optimisticPrice = calcSuggestedPrice(optimisticCostTWD, outship, platRate, target);

    resultDiv.innerHTML = `
      <div class="result-box" style="margin-bottom:.75rem">
        <div style="font-size:11px;color:var(--text3);margin-bottom:.4rem;font-family:var(--mono)">成本估算基礎</div>
        <div class="result-row"><span class="result-label">商品進價</span><span class="mono">${prodCost} ${currency}</span></div>
        ${!isTWD ? `<div class="result-row"><span class="result-label">目前匯率</span><span class="mono">1 ${currency} = ${range.latest} TWD</span></div>` : ''}
      </div>

      <div class="result-box" style="margin-bottom:.75rem">
        <div style="font-size:11px;color:var(--text3);margin-bottom:.4rem;font-family:var(--mono)">
          建議首次售價（目標利潤率 ${fmtPct(target)}）
        </div>
        <div class="result-row">
          <span class="result-label">今日建議售價</span>
          <span class="result-total text-blue">${fmt(todayPrice)}</span>
        </div>
        ${!isTWD ? `
        <div class="result-row">
          <span class="result-label">保守建議售價 <span class="text-muted" style="font-size:11px">（最高匯率 ${range.max}）</span></span>
          <span class="mono text-red">${fmt(conservePrice)}</span>
        </div>
        <div class="result-row">
          <span class="result-label">樂觀建議售價 <span class="text-muted" style="font-size:11px">（最低匯率 ${range.min}）</span></span>
          <span class="mono text-green">${fmt(optimisticPrice)}</span>
        </div>` : ''}
      </div>

      <div style="font-size:12px;color:var(--text3);padding:.5rem;background:var(--bg3);border-radius:6px;line-height:1.6;margin-bottom:.75rem">
        以上為首次上架參考售價，尚未包含進貨運費分攤（批次建立後才能精算）。建議以今日售價上架，進貨後再至「售價安全分析」確認是否需要調整。
      </div>

      <div style="font-size:12px;color:var(--text3);margin-bottom:.4rem">快速帶入售價：</div>
      <div class="flex gap-2" style="flex-wrap:wrap">
        <button type="button" onclick="fillPrice(${Math.round(todayPrice)})"
                class="btn btn-ghost btn-sm">今日 ${fmt(todayPrice)}</button>
        ${!isTWD ? `
        <button type="button" onclick="fillPrice(${Math.round(conservePrice)})"
                class="btn btn-ghost btn-sm" style="color:var(--red,#ef4444)">保守 ${fmt(conservePrice)}</button>
        <button type="button" onclick="fillPrice(${Math.round(optimisticPrice)})"
                class="btn btn-ghost btn-sm" style="color:var(--green,#22c55e)">樂觀 ${fmt(optimisticPrice)}</button>
        ` : ''}
      </div>
    `;

    document.getElementById('apply-product-id').value  = productId;
    document.getElementById('apply-price-input').value = Math.round(todayPrice);
    applyForm.style.display = 'block';

    updateRateRisk(currency, prodCost, NaN);
    return;
  }

  // ── 模式二：售價安全分析 ──────────────────────────────
  modeBadge.textContent  = '模式：售價安全分析 — 判斷目前售價是否仍然安全';
  modeBadge.style.color  = 'var(--text2,#ccc)';
  resultTitle.textContent = '售價安全分析';

  // 庫存已售罄（有批次但 avg_unit_cost 為 NaN）
  if (isNaN(avgCost)) {
    const refCost = isNaN(lastUnitCost) ? null : lastUnitCost;
    resultDiv.innerHTML = `
      <div class="alert alert-yellow" style="margin-bottom:.75rem">
        <div class="alert-title">庫存已售罄</div>
        目前無剩餘庫存，無法計算加權平均成本。${refCost ? `最後一批進貨單位成本為 ${fmtCost(refCost)}，僅供參考。` : ''}
      </div>
      <div style="font-size:12px;color:var(--text3)">建立新進貨批次後，本頁將自動切換為正式售價安全分析。</div>
    `;
    applyForm.style.display = 'none';
    updateRateRisk(currency, prodCost, NaN);
    return;
  }

  const totalCost            = avgCost + outship;
  const netRateAfterPlatform = 1 - platRate / 100;
  const marginFactor         = 1 - target / 100;
  const suggested            = (netRateAfterPlatform > 0 && marginFactor > 0)
    ? totalCost / (netRateAfterPlatform * marginFactor) : 0;

  const hasSelling    = !isNaN(selling) && selling > 0;
  const currentProfit = hasSelling ? selling * netRateAfterPlatform - avgCost - outship : null;
  const currentMargin = hasSelling ? (currentProfit / selling * 100) : null;
  const diff          = (hasSelling && suggested > 0) ? selling - suggested : null;

  let statusBadge, reasonText;
  if (!hasSelling) {
    statusBadge = '<span class="badge badge-blue">尚未設定售價</span>';
    reasonText  = '此商品尚未設定售價，建議參考下方安全售價後進行設定。';
  } else if (currentMargin >= target) {
    statusBadge = '<span class="badge badge-green">安全</span>';
    reasonText  = `目前售價高於建議安全售價 ${fmt(Math.abs(diff))}，利潤率 ${fmtPct(currentMargin)} 達到目標 ${fmtPct(target)}，定價合理。`;
  } else if (currentMargin >= 0) {
    statusBadge = '<span class="badge badge-yellow">建議觀察</span>';
    reasonText  = `目前售價低於建議安全售價 ${fmt(Math.abs(diff))}，利潤率 ${fmtPct(currentMargin)} 未達目標 ${fmtPct(target)}，仍有利潤但建議評估是否調整。`;
  } else {
    statusBadge = '<span class="badge badge-red">建議調整</span>';
    reasonText  = `目前售價低於出貨總成本，每件虧損 ${fmt(Math.abs(currentProfit))}，建議立即調整售價。`;
  }

  resultDiv.innerHTML = `
    <div class="result-box" style="margin-bottom:.75rem">
      <div style="font-size:11px;color:var(--text3);margin-bottom:.4rem;font-family:var(--mono)">成本基礎（庫存加權平均 FIFO）</div>
      <div class="result-row"><span class="result-label">庫存平均成本</span><span class="mono">${fmtCost(avgCost)}</span></div>
      <div class="result-row"><span class="result-label">出貨運費</span><span class="mono">${fmt(outship)}</span></div>
      <div class="result-row" style="border-top:1px solid var(--border2);padding-top:.4rem;margin-top:.2rem">
        <span class="result-label">每件出貨總成本</span>
        <span class="mono text-blue">${fmtCost(totalCost)}</span>
      </div>
    </div>

    <div class="result-box" style="margin-bottom:.75rem">
      <div style="font-size:11px;color:var(--text3);margin-bottom:.4rem;font-family:var(--mono)">目前定價分析</div>
      <div class="result-row">
        <span class="result-label">目前售價</span>
        <span class="mono">${hasSelling ? fmt(selling) : '尚未設定'}</span>
      </div>
      ${currentMargin !== null ? `
      <div class="result-row">
        <span class="result-label">目前利潤率</span>
        <span class="mono ${currentMargin < 0 ? 'text-red' : currentMargin < target ? 'text-yellow' : 'text-green'}">
          ${fmtPct(currentMargin)}
        </span>
      </div>` : ''}
      <div class="result-row"><span class="result-label">定價狀態</span>${statusBadge}</div>
      <div style="font-size:12px;color:var(--text3);margin-top:.5rem;line-height:1.6;padding:.5rem;background:var(--bg3);border-radius:6px">
        ${reasonText}
      </div>
    </div>

    <div class="result-box">
      <div style="font-size:11px;color:var(--text3);margin-bottom:.4rem;font-family:var(--mono)">
        建議安全售價（目標利潤率 ${fmtPct(target)}）
      </div>
      <div class="result-row">
        <span class="result-label">建議售價</span>
        <span class="result-total text-blue">${fmt(suggested)}</span>
      </div>
      ${diff !== null ? `
      <div class="result-row">
        <span class="result-label">與目前售價差異</span>
        <span class="mono ${diff >= 0 ? 'text-green' : 'text-red'}">${diff > 0 ? '+' : ''}${fmt(diff)}</span>
      </div>` : ''}
    </div>
  `;

  document.getElementById('apply-product-id').value  = productId;
  document.getElementById('apply-price-input').value = Math.round(suggested);
  applyForm.style.display = 'block';

  updateRateRisk(currency, prodCost, avgCost);
}

function updateRateRisk(currency, prodCost, avgCost) {
  const section = document.getElementById('rate-risk-section');
  if (currency === 'TWD' || !prodCost) {
    section.innerHTML = '<div class="text-muted" style="font-size:12px;padding:.5rem 0">台幣商品無匯率波動風險。</div>';
    return;
  }
  const range = rateRanges[currency];
  if (!range) { section.innerHTML = ''; return; }

  const costMin    = prodCost * range.min;
  const costMax    = prodCost * range.max;
  const costLatest = prodCost * range.latest;
  const hasAvg     = !isNaN(avgCost) && avgCost > 0;
  const latestDiff = hasAvg ? ((costLatest - avgCost) / avgCost * 100) : null;

  section.innerHTML = `
    <div class="result-box">
      <div style="font-size:11px;color:var(--text3);margin-bottom:.4rem">
        商品進價 ${prodCost} ${currency}，以近 90 天匯率換算（不含運費分攤）
      </div>
      <div class="result-row">
        <span class="result-label">最低匯率（${range.min}）</span>
        <span class="mono">NT$${costMin.toFixed(2)}</span>
      </div>
      <div class="result-row">
        <span class="result-label">最高匯率（${range.max}）</span>
        <span class="mono">NT$${costMax.toFixed(2)}</span>
      </div>
      <div class="result-row">
        <span class="result-label">最新匯率（${range.latest}）</span>
        <span class="mono">NT$${costLatest.toFixed(2)}</span>
      </div>
      ${latestDiff !== null ? `
      <div style="font-size:11px;margin-top:.4rem">
        以最新匯率估算進價較目前庫存平均成本
        <span class="${latestDiff >= 0 ? 'text-red' : 'text-green'}">
          ${latestDiff >= 0 ? '高' : '低'} ${Math.abs(latestDiff).toFixed(1)}%
        </span>（不含運費分攤）
      </div>` : ''}
    </div>
  `;
}

// 若從商品管理頁帶入 product_id，自動選中該商品
if (preselect) {
  const sel = document.getElementById('pr-product');
  for (let i = 0; i < sel.options.length; i++) {
    if (parseInt(sel.options[i].value) === preselect) {
      sel.selectedIndex = i;
      break;
    }
  }
}

analyzePrice();
</script>
