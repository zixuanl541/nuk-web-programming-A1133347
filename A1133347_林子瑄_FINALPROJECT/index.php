<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_login();
$user      = current_user();
$isAdmin   = $user['role'] === 'admin';
$companyId = $user['company_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_rates' && $isAdmin) {
        $result = syncExchangeRates();
        flash($result['message'], $result['success'] ? 'green' : 'red');
    }

    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// No company yet: show a minimal landing state instead of querying company-scoped tables
if (!$companyId) {
    render_head('儀表板');
    ?>
    <div class="app">
    <?php render_sidebar(); ?>
    <div class="main">
    <?php render_topbar('儀表板', '系統總覽'); ?>
    <div class="content">
    <?php render_flash(); ?>
    <?php if ($isAdmin): ?>
      <div class="alert alert-yellow">
        尚未設定工作室，請先到「使用者管理」建立工作室，才能開始使用商品、批次、庫存與銷售功能。
        <a href="<?= BASE_PATH ?>/admin/users.php" class="btn btn-primary btn-sm" style="margin-left:.5rem">前往設定</a>
      </div>
    <?php else: ?>
      <div class="alert alert-yellow">尚未設定工作室，請聯絡管理者完成工作室設定後再使用系統。</div>
    <?php endif; ?>
    </div></div></div>
    <?php render_foot();
    exit;
}

$year   = (int)date('Y');
$stats  = dashboard_stats($user['id'], $companyId, $isAdmin);
$months = monthly_profits($user['id'], $companyId, $year, $isAdmin);

// 匯率中心
$rateInfo = [
    'JPY' => getLatestExchangeRateInfo('JPY'),
    'RMB' => getLatestExchangeRateInfo('RMB'),
    'USD' => getLatestExchangeRateInfo('USD'),
];
$lastUpdated = null;
foreach ($rateInfo as $r) {
    if ($r && (!$lastUpdated || $r['fetched_at'] > $lastUpdated)) $lastUpdated = $r['fetched_at'];
}

// sales_records has no product_id of its own — FIFO can split one sale
// across multiple inventory batches (and thus multiple sale_items rows),
// so product name has to come through sale_items/inventory_items. MIN()
// picks one representative product per sale for this summary list; safe
// here because a single sale always covers one product, just possibly
// multiple batches of it.
if ($isAdmin) {
    $recent = db()->prepare(
        "SELECT sr.*, p.name AS product_name
         FROM sales_records sr
         JOIN users u ON u.id = sr.user_id
         JOIN (
             SELECT si.sale_id, MIN(ii.product_id) AS product_id
             FROM sale_items si JOIN inventory_items ii ON ii.id = si.inventory_item_id
             GROUP BY si.sale_id
         ) sp ON sp.sale_id = sr.id
         JOIN products p ON p.id = sp.product_id
         WHERE u.company_id = ?
         ORDER BY sr.created_at DESC LIMIT 6"
    );
    $recent->execute([$companyId]);
} else {
    $recent = db()->prepare(
        "SELECT sr.*, p.name AS product_name
         FROM sales_records sr
         JOIN (
             SELECT si.sale_id, MIN(ii.product_id) AS product_id
             FROM sale_items si JOIN inventory_items ii ON ii.id = si.inventory_item_id
             GROUP BY si.sale_id
         ) sp ON sp.sale_id = sr.id
         JOIN products p ON p.id = sp.product_id
         WHERE sr.user_id = ?
         ORDER BY sr.created_at DESC LIMIT 6"
    );
    $recent->execute([$user['id']]);
}
$rows = $recent->fetchAll();

render_head('儀表板');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('儀表板', '系統總覽'); ?>
<div class="content">
<?php render_flash(); ?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">總利潤</div>
    <div class="stat-value <?= $stats['total_profit'] >= 0 ? 'text-green' : 'text-red' ?>">
      <?= fmt((float)$stats['total_profit']) ?>
    </div>
    <div class="stat-sub"><?= (int)$stats['total_sales'] ?> 筆銷售</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">平均利潤率</div>
    <div class="stat-value text-blue"><?= pct((float)$stats['avg_margin']) ?></div>
    <div class="stat-sub">整體平均</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">商品數量</div>
    <div class="stat-value"><?= (int)$stats['product_count'] ?></div>
    <div class="stat-sub">已登錄商品</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">目前庫存</div>
    <div class="stat-value"><?= (int)$stats['stock_count'] ?></div>
    <div class="stat-sub">剩餘件數合計</div>
  </div>
</div>

<!-- Exchange Rate Center -->
<div class="card mb-2">
  <div class="flex items-center" style="justify-content:space-between">
    <div class="card-title"><span class="card-title-dot"></span>🌐 匯率中心</div>
    <?php if ($isAdmin): ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="sync_rates">
        <button type="submit" class="btn btn-ghost btn-sm">同步最新匯率</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="stats-grid" style="margin-top:.75rem">
    <?php foreach (['JPY' => 'JPY 日圓', 'RMB' => 'RMB 人民幣', 'USD' => 'USD 美元'] as $code => $label): ?>
      <div class="stat-card">
        <div class="stat-label"><?= $label ?></div>
        <div class="stat-value text-blue">
          <?= $rateInfo[$code] ? number_format((float)$rateInfo[$code]['rate'], 4) : '—' ?>
        </div>
        <div class="stat-sub">1 <?= $code ?> = <?= $rateInfo[$code] ? number_format((float)$rateInfo[$code]['rate'], 4) : '—' ?> TWD</div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="text-muted" style="font-size:11px;margin-top:.5rem">
    最後更新：<?= $lastUpdated ? htmlspecialchars(date('Y-m-d H:i', strtotime($lastUpdated))) : '尚無資料，請按「同步最新匯率」' ?>
  </div>
</div>

<!-- Warn & Chart -->
<div class="grid-2">
  <div class="card">
    <div class="card-title"><span class="card-title-dot"></span>利潤預警狀態</div>
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
    <?php if ($stats['loss_count'] > 0): ?>
      <div class="alert alert-red"><div class="alert-title">⚠ 利潤預警</div>有 <?= (int)$stats['loss_count'] ?> 筆銷售處於虧損，請檢視成本或定價策略。</div>
    <?php elseif ($stats['risk_count'] > 0): ?>
      <div class="alert alert-yellow"><div class="alert-title">注意</div>有 <?= (int)$stats['risk_count'] ?> 筆銷售利潤率低於 10%。</div>
    <?php elseif ($stats['total_sales'] > 0): ?>
      <div class="alert alert-green"><div class="alert-title">✓ 狀態良好</div>所有銷售均達目標利潤率。</div>
    <?php endif; ?>

    <div class="card-title" style="margin-top:.75rem"><span class="card-title-dot"></span>最近銷售</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>商品</th><th>售價</th><th>利潤率</th><th>狀態</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-muted" style="text-align:center;padding:1rem">尚無銷售紀錄</td></tr>
        <?php else: foreach ($rows as $r):
            $margin = $r['sale_price'] > 0 ? ($r['profit'] / $r['sale_price'] * 100) : 0;
        ?>
          <tr>
            <td><?= htmlspecialchars($r['product_name']) ?></td>
            <td class="mono"><?= fmt($r['sale_price']) ?></td>
            <td class="mono <?= $margin < 0 ? 'text-red' : ($margin < 10 ? 'text-yellow' : 'text-green') ?>"><?= pct($margin) ?></td>
            <td><?= status_badge($margin) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><span class="card-title-dot"></span>月度利潤曲線（<?= $year ?>）</div>
    <div class="chart-wrap">
      <div id="dashChart" style="width:100%;height:100%"></div>
    </div>
  </div>
</div>
</div><!-- content -->
</div><!-- main -->
</div><!-- app -->
<?php render_foot(); ?>
<script>
const months = <?= json_encode(array_values($months)) ?>;
const monthLabels = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];

google.charts.load('current', { packages: ['corechart'] });
google.charts.setOnLoadCallback(drawDashChart);
window.addEventListener('resize', drawDashChart);

function drawDashChart() {
  const data = new google.visualization.DataTable();
  data.addColumn('string', '月份');
  data.addColumn('number', '利潤');
  data.addRows(monthLabels.map((l, i) => [l, months[i]]));

  const chart = new google.visualization.LineChart(document.getElementById('dashChart'));
  chart.draw(data, {
    legend: 'none',
    curveType: 'function',
    backgroundColor: 'transparent',
    chartArea: { width: '85%', height: '75%' },
    colors: ['#3b82f6'],
    pointSize: 4,
    hAxis: { textStyle: { color: '#4d5566', fontSize: 11 } },
    vAxis: { textStyle: { color: '#4d5566', fontSize: 11 }, format: 'NT$#,###' },
  });
}
</script>
