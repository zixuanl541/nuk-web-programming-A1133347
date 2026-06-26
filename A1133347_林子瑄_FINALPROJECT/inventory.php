<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_login();
$user      = current_user();
$companyId = require_company();

// Current stock state: one row per inventory_items (batch x product)
$stmt = db()->prepare(
    'SELECT p.name AS product_name, ii.batch_id, b.batch_date,
            ii.quantity, ii.remaining_quantity, ii.unit_cost,
            (ii.remaining_quantity * ii.unit_cost) AS stock_value
     FROM inventory_items ii
     JOIN products p ON p.id = ii.product_id
     JOIN batches b ON b.id = ii.batch_id
     WHERE b.company_id = ?
     ORDER BY p.name ASC, b.batch_date ASC, ii.id ASC'
);
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll();

$totalStockValue = array_sum(array_column($rows, 'stock_value'));
$totalRemaining  = array_sum(array_column($rows, 'remaining_quantity'));

render_head('庫存管理');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('庫存管理', '目前各批次的庫存狀態'); ?>
<div class="content">
<?php render_flash(); ?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">剩餘庫存總數</div>
    <div class="stat-value"><?= (int)$totalRemaining ?> 件</div>
    <div class="stat-sub">所有批次合計</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">庫存總價值</div>
    <div class="stat-value text-blue"><?= fmt((float)$totalStockValue) ?></div>
    <div class="stat-sub">剩餘數量 × 單件成本</div>
  </div>
</div>

<div class="card">
  <div class="card-title"><span class="card-title-dot"></span>批次庫存明細</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>商品</th><th>批次</th><th>批次日期</th><th>進貨數量</th><th>剩餘數量</th><th>單件成本</th><th>庫存價值</th><th>狀態</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-muted" style="text-align:center;padding:2rem">尚無庫存，請先至「進貨批次」建立批次</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['product_name']) ?></td>
            <td class="mono">B<?= str_pad($r['batch_id'], 3, '0', STR_PAD_LEFT) ?></td>
            <td class="mono text-muted"><?= $r['batch_date'] ?></td>
            <td class="mono"><?= (int)$r['quantity'] ?></td>
            <td class="mono"><?= (int)$r['remaining_quantity'] ?></td>
            <td class="mono"><?= fmt((float)$r['unit_cost']) ?></td>
            <td class="mono"><strong><?= fmt((float)$r['stock_value']) ?></strong></td>
            <td>
              <?php if ($r['remaining_quantity'] <= 0): ?>
                <span class="badge badge-green">已售完</span>
              <?php elseif ($r['remaining_quantity'] < $r['quantity'] * 0.2): // <20% of original batch qty left — restock warning ?>
                <span class="badge badge-yellow">即將售完</span>
              <?php else: ?>
                <span class="badge badge-blue">在庫</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
