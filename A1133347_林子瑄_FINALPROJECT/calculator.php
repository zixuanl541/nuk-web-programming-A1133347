<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_admin();
$latestRates = [
    'RMB' => getLatestExchangeRate('RMB'),
    'USD' => getLatestExchangeRate('USD'),
    'JPY' => getLatestExchangeRate('JPY'),
    'TWD' => 1.0,
];
render_head('運費分攤計算器');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('運費分攤計算器', '均分法 / 權重法即時試算'); ?>
<div class="content">

<div class="grid-2" style="align-items:start">
  <!-- Input Panel -->
  <div>
    <div class="card mb-2">
      <div class="card-title"><span class="card-title-dot"></span>批次參數</div>
      <div class="form-grid">
        <div class="form-row">
          <div class="form-group">
            <label>總運費（原幣）</label>
            <input type="number" id="c-shipping" value="800" min="0" step="0.01" oninput="calculate()">
          </div>
          <div class="form-group">
            <label>進貨幣別</label>
            <select id="c-currency" onchange="applyCalcRate()">
              <option value="RMB">RMB 人民幣</option>
              <option value="USD">USD 美元</option>
              <option value="JPY">JPY 日圓</option>
              <option value="TWD">TWD 台幣</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>匯率</label>
          <div id="calc-rate-readonly" style="display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--bg3);border:1px solid var(--border);border-radius:6px;font-size:13px">
            <span id="calc-rate-display">1 RMB = <?= $latestRates['RMB'] ?> TWD</span>
            <button type="button" onclick="showCalcRateEdit()" style="font-size:11px;color:var(--accent);background:none;border:none;cursor:pointer;padding:0;margin-left:auto">手動調整</button>
          </div>
          <input type="number" id="c-rate" value="<?= $latestRates['RMB'] ?>" min="0.001" step="0.001" style="display:none" oninput="calculate()">
          <span id="calc-rate-back" style="display:none;font-size:11px;color:var(--text3)">手動輸入模式 · <a href="#" onclick="hideCalcRateEdit();return false" style="color:var(--accent)">還原為自動帶入</a></span>
        </div>
        <div class="form-group">
          <label>分攤方式</label>
          <select id="c-method" onchange="calculate()">
            <option value="equal">均分法（依商品數量平均分攤）</option>
            <option value="weight">權重法（依商品重量比例分攤）</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><span class="card-title-dot"></span>商品清單</div>
      <div style="font-size:11px;font-family:var(--mono);color:var(--text3);display:grid;grid-template-columns:2fr 1fr 1fr 1fr 36px;gap:6px;padding:.4rem 0;border-bottom:1px solid var(--border);margin-bottom:.5rem">
        <span>商品名稱</span><span>進價</span><span>重量(g)</span><span>數量</span><span></span>
      </div>
      <div id="item-list"></div>
      <button class="btn btn-ghost btn-sm" style="margin-top:.75rem" onclick="addItem()">+ 新增商品</button>
    </div>
  </div>

  <!-- Result Panel -->
  <div class="card" style="position:sticky;top:72px">
    <div class="card-title"><span class="card-title-dot"></span>分攤結果</div>
    <div id="calc-result">
      <div class="text-muted" style="font-size:13px;padding:2rem 0;text-align:center">填入商品資料後自動計算</div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:1rem">
  <div class="card-title"><span class="card-title-dot"></span>公式說明</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div>
      <div style="font-size:12px;font-weight:500;color:var(--text2);margin-bottom:.5rem">均分法</div>
      <div style="font-family:var(--mono);font-size:12px;color:var(--text3);line-height:2;background:var(--bg3);padding:.75rem;border-radius:6px">
        單件運費 = 總運費(TWD) ÷ 所有商品總數量
      </div>
    </div>
    <div>
      <div style="font-size:12px;font-weight:500;color:var(--text2);margin-bottom:.5rem">權重法</div>
      <div style="font-family:var(--mono);font-size:12px;color:var(--text3);line-height:2;background:var(--bg3);padding:.75rem;border-radius:6px">
        單件運費 = (該品重量 × 數量 ÷ 總重量) × 總運費(TWD) ÷ 數量
      </div>
    </div>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
<script>
const calcRates = <?= json_encode($latestRates) ?>;

function applyCalcRate() {
  const cur = document.getElementById('c-currency').value;
  const rate = calcRates[cur] ?? 1;
  document.getElementById('c-rate').value = rate;
  document.getElementById('calc-rate-display').textContent = `1 ${cur} = ${rate} TWD`;
  document.getElementById('calc-rate-readonly').style.display = 'flex';
  document.getElementById('c-rate').style.display = 'none';
  document.getElementById('calc-rate-back').style.display = 'none';
  calculate();
}

function showCalcRateEdit() {
  document.getElementById('calc-rate-readonly').style.display = 'none';
  document.getElementById('c-rate').style.display = 'block';
  document.getElementById('calc-rate-back').style.display = 'block';
  document.getElementById('c-rate').focus();
}

function hideCalcRateEdit() {
  const cur = document.getElementById('c-currency').value;
  const rate = calcRates[cur] ?? 1;
  document.getElementById('c-rate').value = rate;
  document.getElementById('calc-rate-display').textContent = `1 ${cur} = ${rate} TWD`;
  document.getElementById('calc-rate-readonly').style.display = 'flex';
  document.getElementById('c-rate').style.display = 'none';
  document.getElementById('calc-rate-back').style.display = 'none';
  calculate();
}

// Pre-filled with sample rows so the live preview has something to show
// before the user enters their own batch's products.
const items = [
  { name: 'iPhone 手機殼', cost: 120, weight: 80, qty: 3 },
  { name: '藍牙耳機', cost: 350, weight: 220, qty: 2 },
];

function fmt(n) { return 'NT$' + Math.round(n * 100) / 100; }

function renderItems() {
  const list = document.getElementById('item-list');
  list.innerHTML = items.map((item, i) => `
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 36px;gap:6px;margin-bottom:6px;align-items:center">
      <input type="text" value="${item.name}" placeholder="商品名稱"
        oninput="items[${i}].name=this.value;calculate()">
      <input type="number" value="${item.cost}" placeholder="進價" min="0" step="0.01"
        oninput="items[${i}].cost=parseFloat(this.value)||0;calculate()">
      <input type="number" value="${item.weight}" placeholder="g" min="0.1" step="0.1"
        oninput="items[${i}].weight=parseFloat(this.value)||1;calculate()">
      <input type="number" value="${item.qty}" placeholder="數量" min="1"
        oninput="items[${i}].qty=parseInt(this.value)||1;calculate()">
      <button class="btn btn-danger btn-sm" onclick="removeItem(${i})" style="padding:.3rem .5rem">✕</button>
    </div>
  `).join('');
  calculate();
}

function addItem() {
  items.push({ name: '', cost: 0, weight: 100, qty: 1 });
  renderItems();
}

function removeItem(i) {
  items.splice(i, 1);
  renderItems();
}

// Mirrors calculateShippingAllocation() in includes/logic.php so users get
// an instant preview here, before a real batch (and its DB rows) is created.
function calculate() {
  const shipping = parseFloat(document.getElementById('c-shipping').value) || 0;
  const rate     = parseFloat(document.getElementById('c-rate').value) || 1;
  const method   = document.getElementById('c-method').value;
  const shippingTWD = shipping * rate;

  if (!items.length || shippingTWD <= 0) {
    document.getElementById('calc-result').innerHTML =
      '<div class="text-muted" style="font-size:13px;padding:2rem 0;text-align:center">請填入有效的運費與商品資料</div>';
    return;
  }

  const totalWeight = items.reduce((s, it) => s + it.weight * it.qty, 0);
  const totalQty    = items.reduce((s, it) => s + it.qty, 0);

  const results = items.map(item => {
    let allocTotal;
    if (method === 'weight') {
      allocTotal = totalWeight > 0 ? (item.weight * item.qty / totalWeight) * shippingTWD : 0;
    } else {
      allocTotal = totalQty > 0 ? (item.qty / totalQty) * shippingTWD : 0;
    }
    const perUnit    = item.qty > 0 ? allocTotal / item.qty : 0;
    const costTWD    = item.cost * rate;
    const totalCost  = costTWD + perUnit;
    return { ...item, allocTotal, perUnit, costTWD, totalCost };
  });

  const html = `
    <div class="result-box" style="margin-bottom:.75rem">
      <div class="result-row"><span class="result-label">總運費（TWD）</span><span class="mono">${fmt(shippingTWD)}</span></div>
      <div class="result-row"><span class="result-label">分攤方式</span><span class="badge badge-blue">${method === 'weight' ? '權重法' : '均分法'}</span></div>
      <div class="result-row"><span class="result-label">商品總數</span><span class="mono">${totalQty} 件</span></div>
    </div>
    ${results.map(r => `
      <div class="result-box" style="margin-bottom:.75rem">
        <div style="font-size:13px;font-weight:500;margin-bottom:.5rem;color:var(--text)">${r.name || '（未命名）'} <span class="text-muted">×${r.qty}</span></div>
        <div class="result-row"><span class="result-label">進價（TWD）</span><span class="mono">${fmt(r.costTWD)}</span></div>
        <div class="result-row"><span class="result-label">分攤運費（本品合計）</span><span class="mono">${fmt(r.allocTotal)}</span></div>
        <div class="result-row"><span class="result-label">單件分攤運費</span><span class="mono text-yellow">${fmt(r.perUnit)}</span></div>
        <div class="result-row" style="border-top:1px solid var(--border2);padding-top:.6rem;margin-top:.2rem">
          <span class="result-label">單件總成本</span>
          <span class="result-total text-green">${fmt(r.totalCost)}</span>
        </div>
      </div>
    `).join('')}
  `;
  document.getElementById('calc-result').innerHTML = html;
}

renderItems();
</script>
