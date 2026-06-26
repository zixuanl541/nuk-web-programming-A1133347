<?php
// includes/layout.php

require_once __DIR__ . '/auth.php';

function render_head(string $title = 'CrossProfit Pro'): void { ?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — CrossProfit Pro</title>
<script>if(localStorage.getItem('sidebarCollapsed')==='1')document.documentElement.classList.add('sidebar-collapsed');</script>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="https://www.gstatic.com/charts/loader.js"></script>
<link rel="stylesheet" href="<?= BASE_PATH ?>/public/css/app.css?v=<?= filemtime(__DIR__ . '/../public/css/app.css') ?>">
</head>
<body>
<?php } ?>

<?php
function render_sidebar(): void {
    $user = current_user();
    $role = $user['role'] ?? 'seller';
    $page = basename($_SERVER['PHP_SELF'], '.php');

    // Admins can own/switch between several companies (see admin/users.php),
    // so the active one needs to be visible everywhere, not just on the
    // workspace-switcher page itself — otherwise it's easy to forget which
    // company's products/sales you're currently looking at.
    $companyName = null;
    if (!empty($user['company_id'])) {
        $stmt = db()->prepare('SELECT name FROM companies WHERE id = ?');
        $stmt->execute([$user['company_id']]);
        $companyName = $stmt->fetchColumn() ?: null;
    }
?>
<aside class="sidebar">
  <div class="logo">
    <div class="logo-text">
      <div class="logo-title">CrossProfit Pro</div>
      <div class="logo-sub"><?= $companyName ? htmlspecialchars($companyName) : '跨境利潤決策系統' ?></div>
    </div>
    <button id="sidebar-toggle" class="sidebar-toggle" type="button" title="收合側邊欄">
      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M15 19l-7-7 7-7" stroke-width="1.5" fill="none" stroke="currentColor"/></svg>
    </button>
  </div>
  <nav class="nav">
    <div class="nav-group">
      <div class="nav-label">主要功能</div>
      <?php nav_item('index',     'dashboard', '儀表板',    $page); ?>
      <?php if ($role === 'admin'): ?>
      <?php nav_item('products',  'box',       '商品管理',  $page); ?>
      <?php nav_item('batches',   'clipboard', '進貨批次',  $page); ?>
      <?php endif; ?>
      <?php nav_item('inventory', 'inventory', '庫存管理',  $page); ?>
      <?php nav_item('sales',     'dollar',    '銷售紀錄',  $page); ?>
    </div>
    <div class="nav-group">
      <div class="nav-label">決策工具</div>
      <?php if ($role === 'admin'): ?>
      <?php nav_item('calculator', 'calculator', '運費分攤', $page); ?>
      <?php nav_item('pricing',    'tag',        '售價決策', $page); ?>
      <?php endif; ?>
      <?php nav_item('reports',    'chart',      '利潤報表', $page); ?>
    </div>
    <?php if ($role === 'admin'): ?>
    <div class="nav-group">
      <div class="nav-label">管理者</div>
      <?php nav_item('admin/users',    'users',    '使用者管理', $page); ?>
      <?php nav_item('admin/monitor',  'monitor',  '全站監控',   $page); ?>
    </div>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="footer-email" style="font-size:12px;color:var(--text3);margin-bottom:.5rem"><?= htmlspecialchars($user['email'] ?? '') ?></div>
    <div class="flex gap-2">
      <span class="role-badge"><?= $role === 'admin' ? 'Admin' : 'Seller' ?></span>
      <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-ghost btn-sm" title="登出"><?= icon_svg('logout') ?><span class="nav-text">登出</span></a>
    </div>
  </div>
</aside>
<?php }

function nav_item(string $href, string $icon, string $label, string $current): void {
    $base   = explode('/', $href)[0];
    $active = ($current === basename($href, '.php') || $current === $base) ? ' active' : '';
    echo "<a href=\"" . BASE_PATH . "/{$href}.php\" class=\"nav-item{$active}\">";
    echo icon_svg($icon);
    echo "<span class=\"nav-text\">" . htmlspecialchars($label) . "</span></a>\n";
}

function icon_svg(string $name): string {
    $icons = [
        'dashboard'  => '<path d="M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'box'        => '<path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'clipboard'  => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'inventory'  => '<path d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7M3 7l9 4m0 0l9-4m-9 4v10" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'dollar'     => '<path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'calculator' => '<path d="M9 7H6a2 2 0 00-2 2v9a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'tag'        => '<path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'chart'      => '<path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'users'      => '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'monitor'    => '<path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-width="1.5" fill="none" stroke="currentColor"/>',
        'logout'     => '<path d="M17 16l4-4m0 0l-4-4m4 4H7m6 5v1a3 3 0 01-3 3H6a3 3 0 01-3-3V6a3 3 0 013-3h4a3 3 0 013 3v1" stroke-width="1.5" fill="none" stroke="currentColor"/>',
    ];
    $path = $icons[$name] ?? '';
    return "<svg class=\"nav-icon\" viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\">$path</svg>";
}

function render_topbar(string $title, string $sub = ''): void { ?>
<div class="topbar">
  <div>
    <div class="page-title"><?= htmlspecialchars($title) ?></div>
    <?php if ($sub): ?><div class="page-sub"><?= htmlspecialchars($sub) ?></div><?php endif; ?>
  </div>
  <slot id="topbar-actions"></slot>
</div>
<?php }

function render_flash(): void {
    session_start_once();
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            echo "<div class=\"alert alert-{$f['type']}\" id=\"flash-msg\">{$f['msg']}</div>";
        }
        $_SESSION['flash'] = [];
    }
}

function flash(string $msg, string $type = 'green'): void {
    session_start_once();
    $_SESSION['flash'][] = compact('msg', 'type');
}

function fmt(float $n): string {
    return 'NT$' . number_format($n, 0);
}

function pct(float $n): string {
    return number_format($n, 1) . '%';
}

// Same 0% / 10% thresholds as profit_status() in includes/logic.php — kept
// separate because this returns ready-to-render HTML for table cells rather
// than a plain status string.
function status_badge(float $margin): string {
    if ($margin < 0)  return '<span class="badge badge-red"><span class="badge-dot"></span>虧損</span>';
    if ($margin < 10) return '<span class="badge badge-yellow"><span class="badge-dot"></span>風險</span>';
    return '<span class="badge badge-green"><span class="badge-dot"></span>安全</span>';
}

function render_foot(): void { ?>
<script src="<?= BASE_PATH ?>/public/js/app.js?v=<?= filemtime(__DIR__ . '/../public/js/app.js') ?>"></script>
</body></html>
<?php }
