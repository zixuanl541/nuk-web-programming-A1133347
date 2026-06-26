<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_login();
$user      = current_user();
$companyId = require_company();
$isAdmin   = $user['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_sale') {
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $platFeePct = (float)($_POST['platform_fee_pct'] ?? 0);
        $soldDate   = trim($_POST['sold_date'] ?? '');

        // 1. 過濾空列，建立有效商品列
        $lines = [];
        foreach ($productIds as $i => $rawPid) {
            $pid = (int)$rawPid;
            $qty = (int)($quantities[$i] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $lines[] = ['pid' => $pid, 'qty' => $qty];
            }
        }

        // 2. 基本驗證
        if (empty($lines) || !$soldDate) {
            flash('請填寫日期並至少選擇一件商品。', 'red');
            header('Location: ' . BASE_PATH . '/sales.php');
            exit;
        }

        // 3. 重複商品偵測
        $pids = array_column($lines, 'pid');
        if (count($pids) !== count(array_unique($pids))) {
            flash('同一張單不可重複選擇相同商品，請確認後再試。', 'red');
            header('Location: ' . BASE_PATH . '/sales.php');
            exit;
        }

        // 4. 後端重新查詢 selling_price，不信任前端 hidden unit_price[]
        $ph       = implode(',', array_fill(0, count($pids), '?'));
        $prodStmt = db()->prepare(
            "SELECT p.id, p.selling_price
             FROM products p
             WHERE p.id IN ($ph) AND p.company_id = ? AND p.selling_price IS NOT NULL"
        );
        $prodStmt->execute([...$pids, $companyId]);
        $priceMap = [];
        foreach ($prodStmt->fetchAll() as $row) {
            $priceMap[(int)$row['id']] = (float)$row['selling_price'];
        }

        // 驗證所有商品都存在、屬於本公司、已定價
        if (count($priceMap) !== count($pids)) {
            flash('部分商品未定價或不屬於目前工作室，無法建立銷售紀錄。', 'red');
            header('Location: ' . BASE_PATH . '/sales.php');
            exit;
        }

        // 5. 逐商品開 transaction，每商品建立一筆 sales_records
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO sales_records (user_id, sale_price, platform_fee, shipping_cost, profit, sold_date) VALUES (?,?,?,?,?,?)'
            );
            $upd = $pdo->prepare('UPDATE sales_records SET profit = ? WHERE id = ?');

            $totalProfit  = 0.0;
            $totalRevenue = 0.0;

            foreach ($lines as $line) {
                $price       = $priceMap[$line['pid']];
                $salePrice   = round($price * $line['qty'], 2);
                $platformFee = round($salePrice * $platFeePct / 100, 2);

                // shipping_cost 固定寫 0（v1.0：客人自付運費，不納入商品利潤）
                $ins->execute([$user['id'], $salePrice, $platformFee, 0, 0, $soldDate]);
                $saleId = (int)$pdo->lastInsertId();

                $fifo = processFIFOSale($companyId, $line['pid'], $line['qty'], $saleId);
                $profitData = calculateProfit($salePrice, $platformFee, 0, $fifo['total_cost']);

                $upd->execute([$profitData['profit'], $saleId]);

                $totalProfit  += $profitData['profit'];
                $totalRevenue += $salePrice;
            }

            $pdo->commit();

            $count = count($lines);
            flash("已建立 {$count} 筆銷售紀錄！總收入 " . fmt($totalRevenue) . "，總利潤 " . fmt($totalProfit) . '。');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('建立失敗：' . $e->getMessage(), 'red');
        }
    }

    header('Location: ' . BASE_PATH . '/sales.php');
    exit;
}

// 可售商品：有庫存 + 已定價（selling_price IS NOT NULL）
$availStmt = db()->prepare(
    'SELECT p.id, p.name, p.selling_price,
            COALESCE(SUM(ii.remaining_quantity),0) AS stock_qty,
            CASE WHEN COALESCE(SUM(ii.remaining_quantity),0) > 0
                 THEN SUM(ii.remaining_quantity * ii.unit_cost) / SUM(ii.remaining_quantity)
                 ELSE 0 END AS avg_cost
     FROM products p
     LEFT JOIN inventory_items ii ON ii.product_id = p.id AND ii.remaining_quantity > 0
     WHERE p.company_id = ? AND p.selling_price IS NOT NULL
     GROUP BY p.id
     HAVING stock_qty > 0
     ORDER BY p.name'
);
$availStmt->execute([$companyId]);
$available = $availStmt->fetchAll();

// 傳給 JS 的商品資料（明確 cast 型別，確保 JSON 為數字而非字串）
$availableForJS = array_map(fn($a) => [
    'id'            => (int)$a['id'],
    'name'          => $a['name'],
    'selling_price' => (float)$a['selling_price'],
    'avg_cost'      => (float)$a['avg_cost'],
    'stock_qty'     => (int)$a['stock_qty'],
], $available);

// 銷售列表（Admin 看全公司，Seller 看自己）
if ($isAdmin) {
    $salesStmt = db()->prepare(
        "SELECT sr.*, p.name AS product_name,
                SUM(si.quantity) AS quantity,
                SUM(si.quantity * si.unit_cost) AS total_cost
         FROM sales_records sr
         JOIN sale_items si ON si.sale_id = sr.id
         JOIN inventory_items ii ON ii.id = si.inventory_item_id
         JOIN products p ON p.id = ii.product_id
         JOIN users u ON u.id = sr.user_id
         WHERE u.company_id = ?
         GROUP BY sr.id
         ORDER BY sr.sold_date DESC, sr.created_at DESC"
    );
    $salesStmt->execute([$companyId]);
} else {
    $salesStmt = db()->prepare(
        "SELECT sr.*, p.name AS product_name,
                SUM(si.quantity) AS quantity,
                SUM(si.quantity * si.unit_cost) AS total_cost
         FROM sales_records sr
         JOIN sale_items si ON si.sale_id = sr.id
         JOIN inventory_items ii ON ii.id = si.inventory_item_id
         JOIN products p ON p.id = ii.product_id
         WHERE sr.user_id = ?
         GROUP BY sr.id
         ORDER BY sr.sold_date DESC, sr.created_at DESC"
    );
    $salesStmt->execute([$user['id']]);
}
$sales = $salesStmt->fetchAll();

render_head('銷售紀錄');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('銷售紀錄', '記錄銷售，系統用 FIFO 自動扣庫存並計算利潤'); ?>
<div class="content">
<?php render_flash(); ?>

<div class="section-header">
  <div class="section-title">銷售列表</div>
  <button class="btn btn-primary" onclick="document.getElementById('sale-modal').style.display='flex'">+ 新增銷售</button>
</div>

<!-- 銷售列表：移除「運費」欄，v1.0 一律寫 0 -->
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>商品</th><th>數量</th><th>售價</th><th>平台費</th><th>成本</th><th>利潤</th><th>利潤率</th><th>日期</th><th>狀態</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$sales): ?>
          <tr><td colspan="9" class="text-muted" style="text-align:center;padding:2rem">尚無銷售紀錄</td></tr>
        <?php else: foreach ($sales as $s):
          $margin = $s['sale_price'] > 0 ? ($s['profit'] / $s['sale_price'] * 100) : 0;
        ?>
          <tr>
            <td><?= htmlspecialchars($s['product_name']) ?></td>
            <td class="mono"><?= (int)$s['quantity'] ?></td>
            <td class="mono"><?= fmt($s['sale_price']) ?></td>
            <td class="mono"><?= fmt($s['platform_fee']) ?></td>
            <td class="mono"><?= fmt($s['total_cost']) ?></td>
            <td class="mono <?= $s['profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= fmt($s['profit']) ?></td>
            <td class="mono <?= $margin < 0 ? 'text-red' : ($margin < 10 ? 'text-yellow' : 'text-green') ?>"><?= pct($margin) ?></td>
            <td class="mono text-muted"><?= $s['sold_date'] ?></td>
            <td><?= status_badge($margin) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal：多商品輸入 -->
<div id="sale-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border2);border-radius:12px;padding:1.75rem;width:580px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div class="flex items-center" style="justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-size:15px;font-weight:500">新增銷售紀錄</div>
      <button onclick="document.getElementById('sale-modal').style.display='none'" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:20px">×</button>
    </div>

    <?php if (!$available): ?>
      <div class="alert alert-yellow">目前無可銷售商品（需有庫存且已完成定價）。</div>
    <?php else: ?>

    <form method="POST" class="form-grid" id="sale-form" action="<?= BASE_PATH ?>/sales.php">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_sale">

      <!-- 訂單 Header：日期 + 平台費率 -->
      <div class="form-row">
        <div class="form-group">
          <label>銷售日期 *</label>
          <input type="date" name="sold_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>平台費率（%）</label>
          <input type="number" name="platform_fee_pct" id="plat-pct"
                 min="0" max="100" step="0.1" value="0" placeholder="0"
                 oninput="updateOrderSummary()">
          <span style="font-size:11px;color:var(--text3)">Shopee ≈ 7.5%、蝦皮商城 ≈ 10%、IG ≈ 0%</span>
        </div>
      </div>

      <div class="divider"></div>

      <!-- 商品明細欄位標題 -->
      <div style="display:grid;grid-template-columns:1fr 64px 80px 80px 24px;gap:.5rem;
                  font-size:11px;color:var(--text3);padding-bottom:.25rem;font-family:var(--mono)">
        <span>商品</span><span>數量</span>
        <span style="text-align:right">單價</span>
        <span style="text-align:right">小計</span>
        <span></span>
      </div>

      <!-- 商品列（第一列固定，不可移除） -->
      <div id="product-lines">
        <div class="product-line" style="display:grid;grid-template-columns:1fr 64px 80px 80px 24px;
             gap:.5rem;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--border)">
          <select name="product_id[]" class="line-product" onchange="onLineProductChange(this)" required>
            <option value="">— 請選擇 —</option>
            <?php foreach ($available as $a): ?>
              <option value="<?= $a['id'] ?>"
                      data-price="<?= number_format((float)$a['selling_price'], 2, '.', '') ?>"
                      data-cost="<?= number_format((float)$a['avg_cost'], 4, '.', '') ?>"
                      data-stock="<?= (int)$a['stock_qty'] ?>">
                <?= htmlspecialchars($a['name']) ?>（<?= (int)$a['stock_qty'] ?> 件）
              </option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="quantity[]" class="line-qty mono"
                 min="1" step="1" value="1" style="width:100%" required
                 oninput="onLineQtyChange(this)">
          <input type="hidden" name="unit_price[]" class="line-price-hidden">
          <div class="line-price-display mono text-muted" style="text-align:right;font-size:12px">—</div>
          <div class="line-subtotal mono" style="text-align:right;font-size:13px;font-weight:500">—</div>
          <span></span><!-- 佔位：第一列無移除按鈕 -->
        </div>
      </div>

      <button type="button" onclick="addProductLine()" id="add-line-btn"
              class="btn btn-ghost btn-sm" style="margin-top:.5rem;align-self:flex-start">
        ＋ 新增商品
      </button>

      <!-- 即時訂單摘要 -->
      <div id="order-summary" style="display:none;margin-top:.75rem;padding:.875rem;
           background:var(--bg);border:1px solid var(--border2);border-radius:8px">
        <div style="font-size:11px;color:var(--text3);margin-bottom:.5rem;font-family:var(--mono)">訂單摘要</div>
        <div class="result-row">
          <span class="result-label">商品種類</span>
          <span class="mono" id="sum-count">—</span>
        </div>
        <div class="result-row">
          <span class="result-label">總收入</span>
          <span class="mono text-blue" id="sum-revenue">—</span>
        </div>
        <div class="result-row">
          <span class="result-label">平台費</span>
          <span class="mono" id="sum-fee">—</span>
        </div>
        <?php if ($isAdmin): ?>
        <div class="result-row">
          <span class="result-label">預估成本（加權平均，僅供參考）</span>
          <span class="mono" id="sum-cost">—</span>
        </div>
        <div class="result-row">
          <span class="result-label">預估利潤</span>
          <span class="mono" id="sum-profit">—</span>
        </div>
        <div class="result-row">
          <span class="result-label">預估利潤率</span>
          <span class="mono" id="sum-margin">—</span>
        </div>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--text3);margin-top:.5rem" id="sum-note"></div>
      </div>

      <button type="submit" class="btn btn-primary btn-full" style="margin-top:.75rem">記錄銷售</button>
    </form>

    <?php endif; ?>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
<script>
const availableProducts = <?= json_encode(array_values($availableForJS)) ?>;
const isAdmin           = <?= $isAdmin ? 'true' : 'false' ?>;
const MAX_LINES         = 10;

function fmtNum(n) {
  return 'NT$' + Math.round(n).toLocaleString();
}

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function buildSelectOptions() {
  let html = '<option value="">— 請選擇 —</option>';
  availableProducts.forEach(p => {
    html += `<option value="${p.id}"
      data-price="${p.selling_price.toFixed(2)}"
      data-cost="${p.avg_cost.toFixed(4)}"
      data-stock="${p.stock_qty}">${escHtml(p.name)}（${p.stock_qty} 件）</option>`;
  });
  return html;
}

function addProductLine() {
  const container = document.getElementById('product-lines');
  if (container.querySelectorAll('.product-line').length >= MAX_LINES) return;

  const div = document.createElement('div');
  div.className = 'product-line';
  div.style.cssText = 'display:grid;grid-template-columns:1fr 64px 80px 80px 24px;gap:.5rem;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--border)';
  div.innerHTML = `
    <select name="product_id[]" class="line-product" onchange="onLineProductChange(this)" required>
      ${buildSelectOptions()}
    </select>
    <input type="number" name="quantity[]" class="line-qty mono"
           min="1" step="1" value="1" style="width:100%" required
           oninput="onLineQtyChange(this)">
    <input type="hidden" name="unit_price[]" class="line-price-hidden">
    <div class="line-price-display mono text-muted" style="text-align:right;font-size:12px">—</div>
    <div class="line-subtotal mono" style="text-align:right;font-size:13px;font-weight:500">—</div>
    <button type="button" onclick="removeProductLine(this)"
            style="color:var(--red,#ef4444);background:none;border:none;cursor:pointer;padding:0;font-size:18px;line-height:1">×</button>
  `;
  container.appendChild(div);
  updateOrderSummary();

  if (container.querySelectorAll('.product-line').length >= MAX_LINES) {
    document.getElementById('add-line-btn').style.display = 'none';
  }
}

function removeProductLine(btn) {
  btn.closest('.product-line').remove();
  document.getElementById('add-line-btn').style.display = '';
  updateOrderSummary();
}

function onLineProductChange(sel) {
  const opt   = sel.options[sel.selectedIndex];
  const line  = sel.closest('.product-line');
  const price = parseFloat(opt.dataset.price) || 0;
  const stock = parseInt(opt.dataset.stock) || 0;

  line.querySelector('.line-price-hidden').value          = price > 0 ? price : '';
  line.querySelector('.line-price-display').textContent   = price > 0 ? fmtNum(price) : '—';
  line.querySelector('.line-qty').max                     = stock > 0 ? stock : '';

  updateLineSub(line);
  updateOrderSummary();
}

function onLineQtyChange(input) {
  const line = input.closest('.product-line');
  updateLineSub(line);
  updateOrderSummary();
}

function updateLineSub(line) {
  const price = parseFloat(line.querySelector('.line-price-hidden').value) || 0;
  const qty   = parseInt(line.querySelector('.line-qty').value) || 0;
  line.querySelector('.line-subtotal').textContent =
    (price > 0 && qty > 0) ? fmtNum(price * qty) : '—';
}

function updateOrderSummary() {
  const pct   = parseFloat(document.getElementById('plat-pct').value) || 0;
  const lines = [...document.querySelectorAll('.product-line')];

  let totalRevenue = 0;
  let totalCost    = 0;
  let validLines   = 0;

  lines.forEach(line => {
    const price = parseFloat(line.querySelector('.line-price-hidden').value) || 0;
    const qty   = parseInt(line.querySelector('.line-qty').value) || 0;
    if (price > 0 && qty > 0) {
      totalRevenue += price * qty;
      if (isAdmin) {
        const sel     = line.querySelector('.line-product');
        const opt     = sel.options[sel.selectedIndex];
        const avgCost = parseFloat(opt?.dataset.cost) || 0;
        totalCost += avgCost * qty;
      }
      validLines++;
    }
  });

  const summary = document.getElementById('order-summary');
  if (validLines === 0) { summary.style.display = 'none'; return; }
  summary.style.display = 'block';

  const totalFee = totalRevenue * pct / 100;

  document.getElementById('sum-count').textContent   = validLines + ' 種商品';
  document.getElementById('sum-revenue').textContent = fmtNum(totalRevenue);
  document.getElementById('sum-fee').textContent     =
    fmtNum(totalFee) + (pct > 0 ? `（${pct}%）` : '');

  if (isAdmin) {
    const totalProfit = totalRevenue - totalFee - totalCost;
    const margin      = totalRevenue > 0 ? (totalProfit / totalRevenue * 100) : 0;
    const pp = document.getElementById('sum-profit');
    const pm = document.getElementById('sum-margin');

    document.getElementById('sum-cost').textContent = fmtNum(totalCost);
    pp.textContent = fmtNum(totalProfit);
    pp.className   = 'mono ' + (totalProfit >= 0 ? 'text-green' : 'text-red');
    pm.textContent = margin.toFixed(1) + '%';
    pm.className   = 'mono ' + (margin < 0 ? 'text-red' : margin < 10 ? 'text-yellow' : 'text-green');
  }

  document.getElementById('sum-note').textContent =
    `送出後將建立 ${validLines} 筆商品銷售紀錄`;
}

// 送出前驗證：至少一件商品、不可重複
document.getElementById('sale-form')?.addEventListener('submit', function(e) {
  const selected = [...document.querySelectorAll('.line-product')]
    .map(s => s.value).filter(v => v !== '');

  if (selected.length === 0) {
    alert('請至少選擇一件商品。');
    e.preventDefault();
    return;
  }
  if (new Set(selected).size !== selected.length) {
    alert('同一張單不可選擇重複的商品，請確認後再送出。');
    e.preventDefault();
  }
});
</script>
