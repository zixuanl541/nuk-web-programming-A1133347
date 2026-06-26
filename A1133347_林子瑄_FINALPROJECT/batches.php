<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_admin();
$user      = current_user();
$companyId = require_company();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_batch') {
        $date         = $_POST['batch_date'] ?? '';
        $shipping     = (float)($_POST['total_shipping'] ?? 0);
        $rate         = (float)($_POST['exchange_rate'] ?? 1);
        $method       = $_POST['method'] ?? 'equal';
        $note         = trim($_POST['note'] ?? '');
        $batchCurrency = $_POST['batch_currency'] ?? '';
        $pids         = $_POST['product_id'] ?? [];
        $qtys         = $_POST['quantity'] ?? [];

        $selected = [];
        foreach ($pids as $i => $pid) {
            $qty = (int)($qtys[$i] ?? 0);
            if ($pid && $qty > 0) $selected[(int)$pid] = $qty;
        }

        if ($date && $shipping > 0 && $rate > 0 && $selected) {
            $pdo = db();

            // 幣別一致性驗證：所有選中商品必須與批次幣別相同
            $ids     = array_keys($selected);
            $ph      = implode(',', array_fill(0, count($ids), '?'));
            $cstmt   = $pdo->prepare("SELECT currency FROM products WHERE id IN ($ph) AND company_id = ?");
            $cstmt->execute([...$ids, $companyId]);
            $currencies = array_unique(array_column($cstmt->fetchAll(), 'currency'));
            if (!$batchCurrency || count($currencies) !== 1 || $currencies[0] !== $batchCurrency) {
                flash('批次幣別與商品幣別不符，請確認同一批次僅包含相同幣別的商品。', 'red');
                header('Location: ' . BASE_PATH . '/batches.php');
                exit;
            }

            $pdo->beginTransaction();
            try {
                // Load product weight/cost (scoped to this company)
                $ids        = array_keys($selected);
                $placehold  = implode(',', array_fill(0, count($ids), '?'));
                $stmt       = $pdo->prepare("SELECT id, weight, cost FROM products WHERE id IN ($placehold) AND company_id = ?");
                $stmt->execute([...$ids, $companyId]);
                $rows = $stmt->fetchAll();

                $products = [];
                foreach ($rows as $r) {
                    $products[] = [
                        'product_id' => (int)$r['id'],
                        'quantity'   => $selected[$r['id']],
                        'weight'     => (float)$r['weight'],
                        'cost'       => (float)$r['cost'],
                    ];
                }

                $ins = $pdo->prepare(
                    'INSERT INTO batches (user_id, company_id, batch_date, total_shipping, exchange_rate, method, note) VALUES (?,?,?,?,?,?,?)'
                );
                $ins->execute([$user['id'], $companyId, $date, $shipping, $rate, $method, $note]);
                $batchId = (int)$pdo->lastInsertId();

                $allocation = calculateShippingAllocation($products, $shipping, $rate, $method);
                createInventoryItems($batchId, $products, $allocation);

                $pdo->commit();
                flash('批次已建立，庫存已自動分攤！<a href="' . BASE_PATH . '/pricing.php" class="btn btn-ghost btn-sm" style="margin-left:.5rem">前往定價分析 →</a>');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('建立失敗：' . $e->getMessage(), 'red');
            }
        } else {
            flash('請填寫所有必填欄位並選擇至少一個商品。', 'red');
        }
    }

    header('Location: ' . BASE_PATH . '/batches.php');
    exit;
}

// Fetch batches with summary of inventory created from each
$batches = db()->prepare(
    'SELECT b.*, COUNT(DISTINCT ii.product_id) AS product_types, COALESCE(SUM(ii.quantity),0) AS total_qty
     FROM batches b
     LEFT JOIN inventory_items ii ON ii.batch_id = b.id
     WHERE b.company_id = ?
     GROUP BY b.id ORDER BY b.batch_date DESC'
);
$batches->execute([$companyId]);
$batches = $batches->fetchAll();

$myProducts = db()->prepare('SELECT id, name, currency, cost, weight FROM products WHERE company_id = ? ORDER BY name');
$myProducts->execute([$companyId]);
$myProducts = $myProducts->fetchAll();

// 批次明細：若 URL 帶有 ?detail=ID，載入該批次的 inventory_items
$detailBatch   = null;
$batchDetail   = null;
$detailBatchId = isset($_GET['detail']) ? (int)$_GET['detail'] : 0;
if ($detailBatchId) {
    $dBatch = db()->prepare('SELECT * FROM batches WHERE id = ? AND company_id = ?');
    $dBatch->execute([$detailBatchId, $companyId]);
    $detailBatch = $dBatch->fetch() ?: null;
    if ($detailBatch) {
        $dItems = db()->prepare(
            'SELECT p.name, ii.quantity, ii.remaining_quantity, ii.unit_cost
             FROM inventory_items ii
             JOIN products p ON p.id = ii.product_id
             WHERE ii.batch_id = ?
             ORDER BY p.name'
        );
        $dItems->execute([$detailBatchId]);
        $batchDetail = $dItems->fetchAll();
    }
}

// Latest exchange rate per currency, used to prefill the batch form
$latestRates = [
    'RMB' => getLatestExchangeRate('RMB'),
    'USD' => getLatestExchangeRate('USD'),
    'JPY' => getLatestExchangeRate('JPY'),
    'TWD' => 1.0,
];

render_head('進貨批次');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('進貨批次', '批次進貨管理與成本分攤'); ?>
<div class="content">
<?php render_flash(); ?>

<?php if ($detailBatch): ?>
<div class="card mb-2" style="border:1px solid var(--accent)">
  <div class="flex items-center" style="justify-content:space-between;margin-bottom:.6rem">
    <div class="card-title" style="margin-bottom:0">
      <span class="card-title-dot"></span>
      批次 B<?= str_pad($detailBatch['id'], 3, '0', STR_PAD_LEFT) ?> 明細
      <span class="text-muted" style="font-size:12px;font-weight:400;margin-left:.5rem"><?= htmlspecialchars($detailBatch['batch_date']) ?></span>
    </div>
    <a href="<?= BASE_PATH ?>/batches.php" class="btn btn-ghost btn-sm">關閉 ×</a>
  </div>
  <div style="font-size:12px;color:var(--text3);margin-bottom:.75rem;font-family:var(--mono)">
    總運費 <?= fmt((float)$detailBatch['total_shipping'] * (float)$detailBatch['exchange_rate']) ?>
    （<?= number_format((float)$detailBatch['total_shipping'], 2) ?> × <?= $detailBatch['exchange_rate'] ?>）·
    <?= $detailBatch['method'] === 'weight' ? '權重法' : '均分法' ?>
    <?= $detailBatch['note'] ? ' · ' . htmlspecialchars($detailBatch['note']) : '' ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>商品名稱</th><th>進貨數量</th><th>剩餘庫存</th><th>已售出</th><th>單位成本（unit_cost）</th><th>庫存剩餘價值</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$batchDetail): ?>
          <tr><td colspan="6" class="text-muted" style="text-align:center;padding:1.5rem">此批次尚無庫存紀錄</td></tr>
        <?php else:
          $totalValue = 0;
          foreach ($batchDetail as $d):
            $value = (float)$d['remaining_quantity'] * (float)$d['unit_cost'];
            $totalValue += $value;
        ?>
          <tr>
            <td><?= htmlspecialchars($d['name']) ?></td>
            <td class="mono"><?= (int)$d['quantity'] ?></td>
            <td class="mono">
              <?= (int)$d['remaining_quantity'] ?>
              <?php if ((int)$d['remaining_quantity'] === 0): ?>
                <span class="badge badge-red" style="font-size:9px;margin-left:4px">售完</span>
              <?php elseif ($d['remaining_quantity'] < $d['quantity'] * 0.2): ?>
                <span class="badge badge-yellow" style="font-size:9px;margin-left:4px">偏低</span>
              <?php endif; ?>
            </td>
            <td class="mono text-muted"><?= (int)$d['quantity'] - (int)$d['remaining_quantity'] ?></td>
            <td class="mono">NT$<?= number_format((float)$d['unit_cost'], 2) ?></td>
            <td class="mono text-blue">NT$<?= number_format($value, 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($batchDetail): ?>
      <tfoot>
        <tr style="border-top:2px solid var(--border2)">
          <td colspan="5" style="text-align:right;font-size:12px;color:var(--text3)">批次庫存剩餘總價值</td>
          <td class="mono text-blue" style="font-weight:600">NT$<?= number_format($totalValue, 2) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="section-header">
  <div class="section-title">批次列表</div>
  <div class="flex gap-2">
    <a href="<?= BASE_PATH ?>/inventory.php" class="btn btn-ghost">查看庫存狀態</a>
    <button class="btn btn-primary" onclick="document.getElementById('batch-modal').style.display='flex';filterProductsByCurrency()">+ 新增批次</button>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>批次編號</th><th>日期</th><th>總運費（TWD）</th><th>匯率</th><th>分攤方式</th><th>商品種類</th><th>進貨總數量</th><th>備註</th><th></th></tr></thead>
      <tbody>
        <?php if (!$batches): ?>
          <tr><td colspan="9" class="text-muted" style="text-align:center;padding:2rem">尚無批次紀錄</td></tr>
        <?php else: foreach ($batches as $b): ?>
          <tr <?= $detailBatch && (int)$detailBatch['id'] === (int)$b['id'] ? 'style="background:var(--bg3)"' : '' ?>>
            <td class="mono">B<?= str_pad($b['id'], 3, '0', STR_PAD_LEFT) ?></td>
            <td class="mono"><?= $b['batch_date'] ?></td>
            <td class="mono"><?= fmt($b['total_shipping'] * $b['exchange_rate']) ?>
              <span class="text-muted"> (<?= number_format($b['total_shipping'],2) ?> × <?= $b['exchange_rate'] ?>)</span>
            </td>
            <td class="mono"><?= $b['exchange_rate'] ?></td>
            <td><span class="badge badge-blue"><?= $b['method'] === 'weight' ? '權重法' : '均分法' ?></span></td>
            <td class="mono"><?= (int)$b['product_types'] ?></td>
            <td class="mono"><?= (int)$b['total_qty'] ?></td>
            <td class="text-muted"><?= htmlspecialchars($b['note'] ?? '') ?></td>
            <td>
              <?php if ($detailBatch && (int)$detailBatch['id'] === (int)$b['id']): ?>
                <a href="<?= BASE_PATH ?>/batches.php" class="btn btn-ghost btn-sm" style="color:var(--accent)">收起 ↑</a>
              <?php else: ?>
                <a href="?detail=<?= $b['id'] ?>" class="btn btn-ghost btn-sm">查看明細</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Batch Modal -->
<div id="batch-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border2);border-radius:12px;padding:1.75rem;width:560px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="flex items-center" style="justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-size:15px;font-weight:500">新增進貨批次</div>
      <button onclick="document.getElementById('batch-modal').style.display='none'" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:20px">×</button>
    </div>
    <form method="POST" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_batch">
      <div class="form-row">
        <div class="form-group">
          <label>進貨日期 *</label>
          <input type="date" name="batch_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>進貨幣別（帶入最新匯率）</label>
          <select id="batch-currency" name="batch_currency" onchange="applyLatestRate()">
            <option value="RMB">RMB 人民幣</option>
            <option value="USD">USD 美元</option>
            <option value="JPY">JPY 日圓</option>
            <option value="TWD">TWD 台幣</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>匯率</label>
          <div id="rate-readonly" style="display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--bg3);border:1px solid var(--border);border-radius:6px;font-size:13px">
            <span id="rate-display-text">1 RMB = <?= $latestRates['RMB'] ?> TWD</span>
            <button type="button" onclick="showRateEdit()" style="font-size:11px;color:var(--accent);background:none;border:none;cursor:pointer;padding:0;margin-left:auto">手動調整</button>
          </div>
          <input type="number" name="exchange_rate" id="batch-rate" value="<?= $latestRates['RMB'] ?>" step="0.0001" min="0.0001" required style="display:none" oninput="updateShippingPreview()">
          <span id="rate-back-wrap" style="display:none;font-size:11px;color:var(--text3)">手動輸入模式 · <a href="#" onclick="hideRateEdit();return false" style="color:var(--accent)">還原為自動帶入</a></span>
        </div>
        <div class="form-group">
          <label>總運費（原幣）*</label>
          <input type="number" name="total_shipping" placeholder="800" step="0.01" min="0" required>
        </div>
      </div>
      <div class="form-group">
        <label>分攤方式</label>
        <select name="method">
          <option value="equal">均分法（依數量）</option>
          <option value="weight">權重法（依重量）</option>
        </select>
      </div>
      <div class="form-group">
        <label>備註</label>
        <input type="text" name="note" placeholder="例：3月春節備貨">
      </div>
      <div class="divider"></div>
      <div style="font-size:12px;color:var(--text3);margin-bottom:.25rem;font-family:var(--mono)">選擇商品與數量（可多選）</div>
      <div style="font-size:11px;color:var(--yellow,#f59e0b);margin-bottom:.5rem">同一批次僅支援相同幣別商品，請先選擇進貨幣別，系統將自動篩選。</div>
      <?php if (!$myProducts): ?>
        <div class="alert alert-yellow">請先至「商品管理」新增商品後再建立批次。</div>
      <?php else: foreach ($myProducts as $p): ?>
        <div class="flex gap-2 items-center product-row"
             data-id="<?= $p['id'] ?>"
             data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
             data-cost="<?= $p['cost'] ?>"
             data-weight="<?= $p['weight'] ?>"
             data-currency="<?= $p['currency'] ?>"
             style="padding:.4rem 0;border-bottom:1px solid var(--border)">
          <input type="hidden" name="product_id[]" value="<?= $p['id'] ?>">
          <span style="flex:1;font-size:13px"><?= htmlspecialchars($p['name']) ?> <span class="text-muted">(<?= $p['currency'] ?>)</span></span>
          <input type="number" name="quantity[]" value="0" min="0" style="width:80px" placeholder="數量" oninput="updateShippingPreview()">
        </div>
      <?php endforeach; endif; ?>
      <div id="no-currency-products" style="display:none" class="alert alert-yellow">目前無此幣別商品，請先至「商品管理」新增對應幣別的商品。</div>
      <div id="shipping-preview" style="display:none;margin-top:.75rem;padding:.875rem;background:var(--bg);border:1px solid var(--border2);border-radius:8px">
        <div style="font-size:11px;color:var(--text3);margin-bottom:.5rem;font-family:var(--mono)">試算預覽（送出前確認）</div>
        <div class="table-wrap">
          <table style="font-size:12px">
            <thead><tr><th>商品</th><th>數量</th><th>商品成本(TWD)</th><th>分攤運費/件</th><th>最終單位成本</th></tr></thead>
            <tbody id="preview-body"></tbody>
          </table>
        </div>
        <div id="preview-summary" style="font-size:11px;color:var(--text3);margin-top:.5rem;font-family:var(--mono)"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem">建立批次並分攤庫存</button>
    </form>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
<script>
const latestRates = <?= json_encode($latestRates) ?>;

function applyLatestRate() {
  const cur = document.getElementById('batch-currency').value;
  const rate = latestRates[cur] ?? 1;
  document.getElementById('batch-rate').value = rate;
  document.getElementById('rate-display-text').textContent = `1 ${cur} = ${rate} TWD`;
  document.getElementById('rate-readonly').style.display = 'flex';
  document.getElementById('batch-rate').style.display = 'none';
  document.getElementById('rate-back-wrap').style.display = 'none';
  filterProductsByCurrency(); // 切換幣別時同步篩選商品，內部會呼叫 updateShippingPreview
}

function filterProductsByCurrency() {
  const cur = document.getElementById('batch-currency').value;
  let visible = 0;
  document.querySelectorAll('.product-row').forEach(row => {
    if (row.dataset.currency === cur) {
      row.style.display = 'flex';
      visible++;
    } else {
      row.style.display = 'none';
      row.querySelector('input[name="quantity[]"]').value = 0;
    }
  });
  const msg = document.getElementById('no-currency-products');
  if (msg) msg.style.display = visible === 0 ? 'block' : 'none';
  updateShippingPreview();
}

function showRateEdit() {
  document.getElementById('rate-readonly').style.display = 'none';
  document.getElementById('batch-rate').style.display = 'block';
  document.getElementById('rate-back-wrap').style.display = 'block';
  document.getElementById('batch-rate').focus();
}

function hideRateEdit() {
  const cur = document.getElementById('batch-currency').value;
  const rate = latestRates[cur] ?? 1;
  document.getElementById('batch-rate').value = rate;
  document.getElementById('rate-display-text').textContent = `1 ${cur} = ${rate} TWD`;
  document.getElementById('rate-readonly').style.display = 'flex';
  document.getElementById('batch-rate').style.display = 'none';
  document.getElementById('rate-back-wrap').style.display = 'none';
  updateShippingPreview();
}

function updateShippingPreview() {
  const rate     = parseFloat(document.getElementById('batch-rate').value) || 0;
  const shipping = parseFloat(document.querySelector('[name=total_shipping]').value) || 0;
  const method   = document.querySelector('[name=method]').value;
  const shippingTWD = shipping * rate;

  const products = [];
  document.querySelectorAll('.product-row').forEach(row => {
    const qty = parseInt(row.querySelector('input[name="quantity[]"]').value) || 0;
    if (qty > 0) {
      products.push({
        name:   row.dataset.name,
        cost:   parseFloat(row.dataset.cost) || 0,
        weight: parseFloat(row.dataset.weight) || 0,
        qty,
      });
    }
  });

  const preview = document.getElementById('shipping-preview');
  if (products.length === 0 || shippingTWD <= 0) { preview.style.display = 'none'; return; }
  preview.style.display = 'block';

  // 鏡像 PHP calculateShippingAllocation() 的邏輯
  const allocPerProduct = products.map(p => {
    if (method === 'weight') {
      const totalW = products.reduce((s, x) => s + x.weight * x.qty, 0);
      return totalW > 0 ? (p.weight * p.qty / totalW) * shippingTWD : 0;
    } else {
      const totalQ = products.reduce((s, x) => s + x.qty, 0);
      return totalQ > 0 ? (p.qty / totalQ) * shippingTWD : 0;
    }
  });

  document.getElementById('preview-body').innerHTML = products.map((p, i) => {
    const shippingPerUnit = p.qty > 0 ? allocPerProduct[i] / p.qty : 0;
    const costTWD         = p.cost * rate;
    const unitCost        = costTWD + shippingPerUnit;
    return `<tr>
      <td>${p.name}</td>
      <td class="mono">${p.qty}</td>
      <td class="mono">NT$${costTWD.toFixed(2)}</td>
      <td class="mono">NT$${shippingPerUnit.toFixed(2)}</td>
      <td class="mono" style="font-weight:500">NT$${unitCost.toFixed(2)}</td>
    </tr>`;
  }).join('');

  document.getElementById('preview-summary').textContent =
    `總運費 TWD：NT$${shippingTWD.toFixed(2)}（${shipping} × ${rate}）`;
}

// 匯率和運費欄位變動時也即時更新
document.getElementById('batch-rate').addEventListener('input', updateShippingPreview);
document.querySelector('[name=total_shipping]').addEventListener('input', updateShippingPreview);
document.querySelector('[name=method]').addEventListener('change', updateShippingPreview);
</script>
