<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

session_start_once();
if (current_user()) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
    $error = '信箱或密碼錯誤，請重新輸入。';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登入 — CrossProfit Pro</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/public/css/app.css?v=<?= filemtime(__DIR__ . '/public/css/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">CrossProfit Pro</div>
    <div class="login-sub">跨境代購利潤決策系統</div>

    <?php if ($error): ?>
      <div class="alert alert-red" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-grid">
      <div class="form-group">
        <label>信箱</label>
        <input type="email" name="email" placeholder="admin@crossprofit.tw" required autofocus>
      </div>
      <div class="form-group">
        <label>密碼</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem">登入系統</button>
    </form>

    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);font-size:11px;color:var(--text3);font-family:var(--mono)">
      測試帳號：admin@crossprofit.tw / admin123
    </div>
    <div style="margin-top:1rem;font-size:12px;color:var(--text3);text-align:center">
      還沒有帳號？<a href="<?= BASE_PATH ?>/register.php" style="color:var(--accent)">建立工作室帳號</a>
    </div>
  </div>
</div>
</body></html>
