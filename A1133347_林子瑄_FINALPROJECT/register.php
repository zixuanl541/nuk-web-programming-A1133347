<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

session_start_once();
if (current_user()) { header('Location: ' . BASE_PATH . '/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $result = register_admin(
        trim($_POST['username'] ?? ''),
        trim($_POST['email'] ?? ''),
        $_POST['password'] ?? '',
        trim($_POST['company_name'] ?? '')
    );
    if ($result['success']) {
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
    $error = $result['message'];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>建立工作室帳號 — CrossProfit Pro</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/public/css/app.css?v=<?= filemtime(__DIR__ . '/public/css/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">CrossProfit Pro</div>
    <div class="login-sub">建立你的工作室管理帳號</div>

    <?php if ($error): ?>
      <div class="alert alert-red" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div class="form-group">
        <label>工作室名稱 *</label>
        <input type="text" name="company_name" placeholder="例：小明代購工作室" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>使用者名稱 *</label>
        <input type="text" name="username" placeholder="boss01" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>信箱 *</label>
        <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>密碼（至少 6 字元）*</label>
        <input type="password" name="password" placeholder="••••••••" required minlength="6">
      </div>
      <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem">建立帳號並開始使用</button>
    </form>

    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);font-size:12px;color:var(--text3);text-align:center">
      已經有帳號？<a href="<?= BASE_PATH ?>/login.php" style="color:var(--accent)">回到登入頁</a>
    </div>
  </div>
</div>
</body></html>
