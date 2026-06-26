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

    if ($action === 'add') {
        $name         = trim($_POST['name'] ?? '');
        $weight       = (float)($_POST['weight'] ?? 0);
        $cost         = (float)($_POST['cost'] ?? 0);
        $currency     = $_POST['currency'] ?? 'RMB';
        $sellingPrice = $_POST['selling_price'] ?? '';
        $sellingPrice = $sellingPrice !== '' ? (float)$sellingPrice : null;

        if ($name && $weight > 0 && $cost > 0) {
            $stmt = db()->prepare(
                'INSERT INTO products (company_id, name, weight, cost, currency, selling_price) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$companyId, $name, $weight, $cost, $currency, $sellingPrice]);
            flash('商品已新增成功！');
        } else {
            flash('請填寫所有必填欄位。', 'red');
        }
    }

    if ($action === 'update_price') {
        $id           = (int)($_POST['id'] ?? 0);
        $sellingPrice = $_POST['selling_price'] ?? '';
        $sellingPrice = $sellingPrice !== '' ? (float)$sellingPrice : null;

        $stmt = db()->prepare('UPDATE products SET selling_price = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([$sellingPrice, $id, $companyId]);
        flash('建議售價已更新。');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // inventory_items/product_id has ON DELETE CASCADE down through
        // sale_items, so deleting a product that's already been purchased or
        // sold would silently wipe its batch/sale history while leaving the
        // sales_records totals (which don't cascade) out of sync with what
        // the UI can still join to. Block it instead of letting that happen.
        $hasHistory = db()->prepare('SELECT COUNT(*) FROM inventory_items WHERE product_id = ?');
        $hasHistory->execute([$id]);
        if ((int)$hasHistory->fetchColumn() > 0) {
            flash('此商品已有進貨或銷售紀錄，無法刪除。', 'red');
        } else {
            $stmt = db()->prepare('DELETE FROM products WHERE id = ? AND company_id = ?');
            $stmt->execute([$id, $companyId]);
            flash('商品已刪除。', 'yellow');
        }
    }

    header('Location: ' . BASE_PATH . '/products.php');
    exit;
}

// Fetch products with current stock (sum of remaining_quantity across all batches)
$stmt = db()->prepare(
    'SELECT p.*, COALESCE(SUM(ii.remaining_quantity),0) AS stock_qty,
            EXISTS(SELECT 1 FROM inventory_items ii2 WHERE ii2.product_id = p.id) AS has_batches
     FROM products p
     LEFT JOIN inventory_items ii ON ii.product_id = p.id
     WHERE p.company_id = ?
     GROUP BY p.id
     ORDER BY
       CASE WHEN p.selling_price IS NULL THEN 0
            WHEN COALESCE(SUM(ii.remaining_quantity), 0) = 0 THEN 1
            ELSE 2 END ASC,
       p.name ASC'
);
$stmt->execute([$companyId]);
$products = $stmt->fetchAll();

render_head('商品管理');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('商品管理', '管理商品基本資料'); ?>
<div class="content">
<?php render_flash(); ?>

<div class="section-header">
  <div class="section-title">商品列表</div>
  <button class="btn btn-primary" onclick="document.getElementById('add-modal').style.display='flex'">+ 新增商品</button>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>商品名稱</th>
          <th>售價（TWD）</th>
          <th>庫存</th>
          <th style="color:var(--text3);font-weight:400;font-size:12px">進貨規格</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="5" class="text-muted" style="text-align:center;padding:2rem">尚無商品，點右上角「新增商品」開始使用</td></tr>
        <?php else: foreach ($products as $p): ?>
          <tr style="vertical-align:middle">
            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>

            <!-- 售價欄：正常狀態顯示文字，點「修改售價」才切換 input -->
            <td>
              <div id="price-display-<?= $p['id'] ?>" style="display:flex;align-items:center;gap:.5rem;white-space:nowrap">
                <?php if ($p['selling_price'] === null): ?>
                  <span class="badge badge-yellow">尚未定價</span>
                <?php else: ?>
                  <span class="mono"><?= fmt((float)$p['selling_price']) ?></span>
                <?php endif; ?>
                <button type="button" onclick="showPriceEdit(<?= $p['id'] ?>)"
                        style="font-size:11px;color:var(--text3);background:none;border:none;cursor:pointer;padding:0;text-decoration:underline;white-space:nowrap">
                  修改售價
                </button>
              </div>
              <div id="price-edit-<?= $p['id'] ?>" style="display:none">
                <form method="POST" class="flex gap-1 items-center">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="update_price">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <input type="number" name="selling_price" class="mono"
                         style="width:80px;font-size:12px"
                         value="<?= $p['selling_price'] !== null ? (int)$p['selling_price'] : '' ?>"
                         min="0" step="1" placeholder="售價">
                  <button type="submit" class="btn btn-primary btn-sm">儲存</button>
                  <button type="button" onclick="hidePriceEdit(<?= $p['id'] ?>)" class="btn btn-ghost btn-sm">取消</button>
                </form>
              </div>
            </td>

            <!-- 庫存欄：只顯示狀態，操作在 CTA 欄 -->
            <td>
              <?php if ((int)$p['stock_qty'] === 0): ?>
                <span class="badge badge-red">尚無庫存</span>
              <?php else: ?>
                <span class="mono"><?= (int)$p['stock_qty'] ?> 件</span>
              <?php endif; ?>
            </td>

            <!-- 次要資訊欄：壓縮成一行 muted 小字 -->
            <td class="mono text-muted" style="font-size:11px;white-space:nowrap">
              <?= number_format((float)$p['weight'], 1) ?>g &middot;
              <?= number_format((float)$p['cost'], 2) ?> <?= htmlspecialchars($p['currency']) ?> &middot;
              <?= substr($p['created_at'], 0, 7) ?>
            </td>

            <!-- 操作欄：單一主要 CTA + 次要刪除，水平排列靠右 -->
            <td>
              <div style="display:flex;align-items:center;justify-content:flex-end;gap:12px;white-space:nowrap">
                <?php if (!(int)$p['has_batches']): ?>
                  <a href="<?= BASE_PATH ?>/pricing.php?product_id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">首次售價估算</a>
                <?php else: ?>
                  <a href="<?= BASE_PATH ?>/pricing.php?product_id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">售價安全分析</a>
                <?php endif; ?>
                <form method="POST" style="margin:0" onsubmit="return confirm('確定刪除此商品？')">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button type="submit" style="font-size:11px;color:var(--red,#ef4444);background:none;border:none;cursor:pointer;padding:0;white-space:nowrap">刪除</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Modal -->
<div id="add-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border2);border-radius:12px;padding:1.75rem;width:460px;max-width:95vw">
    <div class="flex items-center" style="justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-size:15px;font-weight:500">新增商品</div>
      <button onclick="document.getElementById('add-modal').style.display='none'" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:20px">×</button>
    </div>
    <form method="POST" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label>商品名稱 *</label>
        <input type="text" name="name" placeholder="例：iPhone 15 手機殼" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>重量（g）*</label>
          <input type="number" name="weight" placeholder="80" min="0.1" step="0.1" required>
        </div>
        <div class="form-group">
          <label>進價（原幣）*</label>
          <input type="number" name="cost" placeholder="120" min="0.01" step="0.01" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>計價幣別</label>
          <select name="currency">
            <option value="RMB">RMB 人民幣</option>
            <option value="USD">USD 美元</option>
            <option value="JPY">JPY 日圓</option>
            <option value="TWD">TWD 台幣</option>
          </select>
        </div>
        <div class="form-group">
          <label>建議售價（TWD，選填）</label>
          <input type="number" name="selling_price" placeholder="可稍後用反向定價計算" min="0" step="0.01">
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-full">新增商品</button>
    </form>
  </div>
</div>

</div></div></div>
<script>
function showPriceEdit(id) {
  document.getElementById('price-display-' + id).style.display = 'none';
  document.getElementById('price-edit-' + id).style.display = 'block';
  document.querySelector('#price-edit-' + id + ' input[name="selling_price"]').focus();
}
function hidePriceEdit(id) {
  document.getElementById('price-edit-' + id).style.display = 'none';
  document.getElementById('price-display-' + id).style.display = 'block';
}
</script>
<?php render_foot(); ?>
