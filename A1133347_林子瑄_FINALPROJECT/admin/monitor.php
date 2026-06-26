<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/logic.php';

require_admin();
$companyId = require_company();
$user      = current_user();
$pdo       = db();

// isAdmin=true here means "whole company", not just this admin's own sales —
// matches how reports.php/index.php use the same flag.
$stats = dashboard_stats($user['id'], $companyId, true);

// An admin can own several companies (see admin/users.php's workspace
// switcher) but every other query on this page only sees whichever one is
// currently active. "全站" in the page title should mean across all of an
// admin's workspaces, not just the one they happen to be switched into right
// now — otherwise comparing two businesses means switching back and forth.
$myCompaniesStmt = $pdo->prepare('SELECT id, name FROM companies WHERE owner_id = ? ORDER BY created_at');
$myCompaniesStmt->execute([$user['id']]);
$myCompanies = $myCompaniesStmt->fetchAll();
foreach ($myCompanies as &$co) {
    $co['stats'] = dashboard_stats($user['id'], (int)$co['id'], true);
}
unset($co);

// Per-staff performance, so an admin can see who is selling at healthy
// margins vs who needs a pricing/cost check, without opening each person's
// filtered view of 銷售紀錄 one by one.
$staffStmt = $pdo->prepare(
    'SELECT u.id, u.username, u.role, u.status,
            COUNT(sr.id) AS sale_count,
            COALESCE(SUM(sr.sale_price),0) AS revenue,
            COALESCE(SUM(sr.profit),0) AS profit,
            CASE WHEN COALESCE(SUM(sr.sale_price),0) > 0 THEN SUM(sr.profit) / SUM(sr.sale_price) * 100 ELSE 0 END AS avg_margin
     FROM users u
     LEFT JOIN sales_records sr ON sr.user_id = u.id
     WHERE u.company_id = ?
     GROUP BY u.id
     ORDER BY profit DESC'
);
$staffStmt->execute([$companyId]);
$staff = $staffStmt->fetchAll();

// Exchange rates are shared platform-wide (no company_id) and only updated
// when an admin clicks "同步最新匯率" on the dashboard, so they can go
// stale silently. Flagging anything older than 3 days here gives admins a
// reason to check without having to remember to look at the dashboard.
$rateInfo = [
    'JPY' => getLatestExchangeRateInfo('JPY'),
    'RMB' => getLatestExchangeRateInfo('RMB'),
    'USD' => getLatestExchangeRateInfo('USD'),
];
$staleDays = 3;

// Same "<20% of original batch quantity left" restock threshold as
// inventory.php's badge, surfaced here as a flat list so an admin can scan
// every low-stock batch across all products in one place.
$lowStockStmt = $pdo->prepare(
    'SELECT p.name AS product_name, ii.batch_id, ii.quantity, ii.remaining_quantity
     FROM inventory_items ii
     JOIN products p ON p.id = ii.product_id
     JOIN batches b ON b.id = ii.batch_id
     WHERE b.company_id = ? AND ii.remaining_quantity > 0
           AND ii.remaining_quantity < ii.quantity * 0.2
     ORDER BY ii.remaining_quantity ASC'
);
$lowStockStmt->execute([$companyId]);
$lowStock = $lowStockStmt->fetchAll();

render_head('全站監控');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('全站監控', '工作室整體營運狀態與成員表現'); ?>
<div class="content">
<?php render_flash(); ?>

<?php if (count($myCompanies) > 1): ?>
<!-- Cross-workspace overview -->
<div class="card mb-2">
  <div class="card-title"><span class="card-title-dot"></span>我的工作室總覽</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>工作室</th><th>總利潤</th><th>銷售筆數</th><th>虧損</th><th>風險</th><th>安全</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($myCompanies as $co): $s = $co['stats']; ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($co['name']) ?></strong>
              <?php if ((int)$co['id'] === $companyId): ?>
                <span class="badge badge-blue" style="margin-left:6px">目前使用中</span>
              <?php endif; ?>
            </td>
            <td class="mono <?= $s['total_profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= fmt((float)$s['total_profit']) ?></td>
            <td class="mono"><?= (int)$s['total_sales'] ?></td>
            <td class="mono text-red"><?= (int)$s['loss_count'] ?></td>
            <td class="mono text-yellow"><?= (int)$s['risk_count'] ?></td>
            <td class="mono text-green"><?= (int)$s['safe_count'] ?></td>
            <td>
              <?php if ((int)$co['id'] !== $companyId): ?>
                <form method="POST" action="<?= BASE_PATH ?>/admin/users.php" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="switch_company">
                  <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">切換到這個工作室</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Company overview -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">總利潤</div>
    <div class="stat-value <?= $stats['total_profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= fmt((float)$stats['total_profit']) ?></div>
    <div class="stat-sub">全公司累計</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">平均利潤率</div>
    <div class="stat-value text-blue"><?= pct((float)$stats['avg_margin']) ?></div>
    <div class="stat-sub">全公司平均</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">銷售總筆數</div>
    <div class="stat-value"><?= (int)$stats['total_sales'] ?></div>
    <div class="stat-sub">所有成員合計</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">商品 / 批次數</div>
    <div class="stat-value"><?= (int)$stats['product_count'] ?> / <?= (int)$stats['batch_count'] ?></div>
    <div class="stat-sub">已登錄商品 / 進貨批次</div>
  </div>
</div>

<!-- Risk warning strip (same 0% / 10% thresholds as the dashboard) -->
<div class="card mb-2">
  <div class="card-title"><span class="card-title-dot"></span>全公司利潤預警</div>
  <div class="warn-strip">
    <div class="warn-item" style="background:var(--red-bg);color:var(--red)">
      <strong><?= (int)$stats['loss_count'] ?></strong> 筆
      <span>虧損（&lt;0%）</span>
    </div>
    <div class="warn-item" style="background:var(--yellow-bg);color:var(--yellow)">
      <strong><?= (int)$stats['risk_count'] ?></strong> 筆
      <span>風險（&lt;10%）</span>
    </div>
    <div class="warn-item" style="background:var(--green-bg);color:var(--green)">
      <strong><?= (int)$stats['safe_count'] ?></strong> 筆
      <span>安全（≥10%）</span>
    </div>
  </div>
</div>

<!-- Staff performance -->
<div class="card mb-2">
  <div class="card-title"><span class="card-title-dot"></span>成員銷售表現</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>使用者</th><th>角色</th><th>帳號狀態</th><th>銷售筆數</th><th>總營收</th><th>總利潤</th><th>平均利潤率</th><th>狀態</th></tr></thead>
      <tbody>
        <?php if (!$staff): ?>
          <tr><td colspan="8" class="text-muted" style="text-align:center;padding:2rem">尚無成員資料</td></tr>
        <?php else: foreach ($staff as $s): ?>
          <tr>
            <td><strong><?= htmlspecialchars($s['username']) ?></strong></td>
            <td><span class="badge <?= $s['role'] === 'admin' ? 'badge-blue' : 'badge-green' ?>"><?= $s['role'] ?></span></td>
            <td><span class="badge <?= $s['status'] === 'active' ? 'badge-green' : 'badge-red' ?>"><?= $s['status'] === 'active' ? '啟用' : '停權' ?></span></td>
            <td class="mono"><?= (int)$s['sale_count'] ?></td>
            <td class="mono"><?= fmt((float)$s['revenue']) ?></td>
            <td class="mono <?= $s['profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= fmt((float)$s['profit']) ?></td>
            <td class="mono <?= $s['avg_margin'] < 0 ? 'text-red' : ($s['avg_margin'] < 10 ? 'text-yellow' : 'text-green') ?>"><?= pct((float)$s['avg_margin']) ?></td>
            <td><?= $s['sale_count'] > 0 ? status_badge((float)$s['avg_margin']) : '<span class="text-muted">—</span>' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid-2">
  <!-- Exchange rate freshness -->
  <div class="card">
    <div class="card-title"><span class="card-title-dot"></span>匯率資料狀態</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>幣別</th><th>最新匯率</th><th>更新日期</th><th>狀態</th></tr></thead>
        <tbody>
          <?php foreach ($rateInfo as $code => $info):
            $isStale = !$info || (strtotime('today') - strtotime($info['fetched_at'])) / 86400 >= $staleDays;
          ?>
            <tr>
              <td><?= $code ?></td>
              <td class="mono"><?= $info ? number_format((float)$info['rate'], 4) : '—' ?></td>
              <td class="mono text-muted"><?= $info ? htmlspecialchars(date('Y-m-d H:i', strtotime($info['fetched_at']))) : '—' ?></td>
              <td>
                <?php if ($isStale): ?>
                  <span class="badge badge-yellow">已過期</span>
                <?php else: ?>
                  <span class="badge badge-green">最新</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Low stock alerts -->
  <div class="card">
    <div class="card-title"><span class="card-title-dot"></span>低庫存警示（剩餘 &lt; 20%）</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>商品</th><th>批次</th><th>剩餘 / 進貨</th></tr></thead>
        <tbody>
          <?php if (!$lowStock): ?>
            <tr><td colspan="3" class="text-muted" style="text-align:center;padding:1.5rem">目前沒有低庫存商品</td></tr>
          <?php else: foreach ($lowStock as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['product_name']) ?></td>
              <td class="mono">B<?= str_pad($r['batch_id'], 3, '0', STR_PAD_LEFT) ?></td>
              <td class="mono"><span class="text-yellow"><?= (int)$r['remaining_quantity'] ?></span> / <?= (int)$r['quantity'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
