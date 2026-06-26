<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/logic.php';

require_login();
$user      = current_user();
$companyId = require_company();
$year      = (int)($_GET['year'] ?? date('Y'));
$isAdmin   = $user['role'] === 'admin';

$stats   = dashboard_stats($user['id'], $companyId, $isAdmin);
$months  = monthly_profits($user['id'], $companyId, $year, $isAdmin);

// Same "one sale = one product, possibly multiple FIFO batches" subquery
// pattern as index.php's recent-sales query: sale_items/inventory_items is
// the only path from a sale to its product, and grouping by sale_id first
// avoids double-counting sale_price/profit when a sale spans batches.
$pdo = db();
$where  = $isAdmin ? 'WHERE u.company_id = ?' : 'WHERE sr.user_id = ?';
$params = $isAdmin ? [$companyId] : [$user['id']];
$prodStmt = $pdo->prepare(
    "SELECT p.name, SUM(sr.sale_price) AS revenue, SUM(sr.profit) AS profit,
            CASE WHEN SUM(sr.sale_price) > 0 THEN SUM(sr.profit) / SUM(sr.sale_price) * 100 ELSE 0 END AS avg_margin, COUNT(*) AS sales
     FROM sales_records sr
     JOIN users u ON u.id = sr.user_id
     JOIN (
         SELECT si.sale_id, MIN(ii.product_id) AS product_id
         FROM sale_items si JOIN inventory_items ii ON ii.id = si.inventory_item_id
         GROUP BY si.sale_id
     ) sp ON sp.sale_id = sr.id
     JOIN products p ON p.id = sp.product_id
     $where
     GROUP BY p.id ORDER BY profit DESC LIMIT 8"
);
$prodStmt->execute($params);
$productRanking = $prodStmt->fetchAll();

render_head('利潤報表');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('利潤報表', '月度 / 年度視覺化分析'); ?>
<div class="content">

<!-- Year selector -->
<div class="flex items-center gap-2 mb-3">
  <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
    <a href="?year=<?= $y ?>" class="btn <?= $y === $year ? 'btn-primary' : 'btn-ghost' ?>"><?= $y ?> 年</a>
  <?php endfor; ?>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">總收入</div>
    <div class="stat-value text-blue"><?= fmt((float)$stats['total_revenue']) ?></div>
    <div class="stat-sub">累計銷售金額</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">總利潤</div>
    <div class="stat-value <?= $stats['total_profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= fmt((float)$stats['total_profit']) ?></div>
    <div class="stat-sub">扣除所有成本後</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">平均利潤率</div>
    <div class="stat-value text-blue"><?= pct((float)$stats['avg_margin']) ?></div>
    <div class="stat-sub">所有銷售平均</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">銷售筆數</div>
    <div class="stat-value"><?= (int)$stats['total_sales'] ?></div>
    <div class="stat-sub">歷史累計</div>
  </div>
</div>

<!-- Charts -->
<div class="grid-2 mb-2">
  <div class="card">
    <div class="card-title"><span class="card-title-dot"></span>月度利潤（<?= $year ?>）</div>
    <div class="chart-wrap">
      <div id="monthChart" style="width:100%;height:100%"></div>
    </div>
  </div>
  <div class="card">
    <div class="card-title"><span class="card-title-dot"></span>商品利潤排行（前 8 名）</div>
    <div class="chart-wrap">
      <div id="productChart" style="width:100%;height:100%"></div>
    </div>
  </div>
</div>

<!-- Product table -->
<div class="card">
  <div class="card-title"><span class="card-title-dot"></span>商品利潤明細</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>商品名稱</th><th>銷售筆數</th><th>總收入</th><th>總利潤</th><th>平均利潤率</th><th>狀態</th></tr></thead>
      <tbody>
        <?php if (!$productRanking): ?>
          <tr><td colspan="6" class="text-muted" style="text-align:center;padding:2rem">尚無銷售資料</td></tr>
        <?php else: foreach ($productRanking as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td class="mono"><?= (int)$r['sales'] ?></td>
            <td class="mono"><?= fmt($r['revenue']) ?></td>
            <td class="mono <?= $r['profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= fmt($r['profit']) ?></td>
            <td class="mono <?= $r['avg_margin'] < 0 ? 'text-red' : ($r['avg_margin'] < 10 ? 'text-yellow' : 'text-green') ?>"><?= pct($r['avg_margin']) ?></td>
            <td><?= status_badge($r['avg_margin']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
<script>
const monthData  = <?= json_encode(array_values($months)) ?>;
const monthLabels = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];
const prodLabels = <?= json_encode(array_column($productRanking, 'name')) ?>;
const prodData   = <?= json_encode(array_map(fn($r) => round($r['avg_margin'], 1), $productRanking)) ?>;

google.charts.load('current', { packages: ['corechart'] });
google.charts.setOnLoadCallback(drawCharts);
window.addEventListener('resize', drawCharts);

function drawCharts() {
  drawMonthChart();
  drawProductChart();
}

function drawMonthChart() {
  const data = new google.visualization.DataTable();
  data.addColumn('string', '月份');
  data.addColumn('number', '月度利潤');
  data.addColumn({ type: 'string', role: 'style' });
  data.addRows(monthLabels.map((l, i) => {
    const v = monthData[i];
    return [l, v, v >= 0 ? '#22c55e' : '#ef4444'];
  }));

  new google.visualization.ColumnChart(document.getElementById('monthChart')).draw(data, {
    legend: 'none',
    backgroundColor: 'transparent',
    chartArea: { width: '85%', height: '75%' },
    hAxis: { textStyle: { color: '#4d5566', fontSize: 11 } },
    vAxis: { textStyle: { color: '#4d5566', fontSize: 11 }, format: 'NT$#,###' },
  });
}

function drawProductChart() {
  const data = new google.visualization.DataTable();
  data.addColumn('string', '商品');
  data.addColumn('number', '平均利潤率 %');
  data.addColumn({ type: 'string', role: 'style' });
  data.addRows(prodLabels.map((l, i) => {
    const v = prodData[i];
    const color = v >= 10 ? '#22c55e' : v >= 0 ? '#f59e0b' : '#ef4444';
    return [l, v, color];
  }));

  new google.visualization.BarChart(document.getElementById('productChart')).draw(data, {
    legend: 'none',
    backgroundColor: 'transparent',
    chartArea: { width: '70%', height: '80%' },
    hAxis: { textStyle: { color: '#4d5566', fontSize: 11 }, format: "#'%'" },
    vAxis: { textStyle: { color: '#8892a4', fontSize: 11 } },
  });
}
</script>
