<?php
// includes/auth.php

require_once __DIR__ . '/../config/db.php';

// BASE_PATH lets the app run from either the domain root or a subfolder
// (e.g. local XAMPP htdocs subfolder vs. a hosting account's web root)
// without hardcoding the install location into every redirect/link.
if (!defined('BASE_PATH')) {
    $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
    define('BASE_PATH', rtrim(substr($appRoot, strlen($docRoot)), '/'));
}

function session_start_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function current_user(): ?array {
    session_start_once();
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (current_user()['role'] !== 'admin') {
        header('Location: ' . BASE_PATH . '/index.php?error=forbidden');
        exit;
    }
}

function require_role(string $role): void {
    require_login();
    if (current_user()['role'] !== $role && current_user()['role'] !== 'admin') {
        header('Location: ' . BASE_PATH . '/index.php?error=forbidden');
        exit;
    }
}

/**
 * Pages that touch products/batches/inventory/sales need a company.
 * If the logged-in user has no company yet, send them to the
 * company setup step (admin/users.php) instead of hitting NOT NULL FK errors.
 */
function require_company(): int {
    require_login();
    $user = current_user();
    if (empty($user['company_id'])) {
        header('Location: ' . BASE_PATH . '/admin/users.php?error=no_company');
        exit;
    }
    return (int)$user['company_id'];
}

function login(string $email, string $password): bool {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    // password_verify compares against the bcrypt hash already stored in
    // the DB; the plaintext password itself is never persisted anywhere.
    if ($user && password_verify($password, $user['password'])) {
        session_start_once();
        $_SESSION['user'] = [
            'id'         => $user['id'],
            'username'   => $user['username'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'company_id' => $user['company_id'],
        ];
        return true;
    }
    return false;
}

/**
 * Self-service signup: creates a brand-new admin account that immediately
 * owns its own company, then logs them straight in.
 *
 * Deliberately does NOT run the orphan-account-claiming step that
 * admin/users.php's setup_company action does — that behavior only makes
 * sense for the original pre-multi-tenant seed accounts. Auto-claiming any
 * leftover company_id IS NULL accounts here would let a random stranger's
 * signup sweep up unrelated orphaned accounts now that signup is public.
 *
 * @return array{success: bool, message: string}
 */
function register_admin(string $username, string $email, string $password, string $companyName): array {
    if (!$username || !$email || !$companyName) {
        return ['success' => false, 'message' => '請填寫所有欄位。'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => '密碼至少需要 6 個字元。'];
    }

    $pdo = db();

    $check = $pdo->prepare('SELECT username, email FROM users WHERE username = ? OR email = ?');
    $check->execute([$username, $email]);
    foreach ($check->fetchAll() as $row) {
        if ($row['username'] === $username) return ['success' => false, 'message' => '此使用者名稱已被使用。'];
        if ($row['email'] === $email)       return ['success' => false, 'message' => '此信箱已被註冊。'];
    }

    $pdo->beginTransaction();
    try {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, "admin")')
            ->execute([$username, $email, $hashed]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO companies (owner_id, name) VALUES (?, ?)')
            ->execute([$userId, $companyName]);
        $companyId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE users SET company_id = ? WHERE id = ?')->execute([$companyId, $userId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => '註冊失敗，請稍後再試。'];
    }

    session_start_once();
    $_SESSION['user'] = [
        'id'         => $userId,
        'username'   => $username,
        'email'      => $email,
        'role'       => 'admin',
        'company_id' => $companyId,
    ];
    return ['success' => true, 'message' => '註冊成功！'];
}

function logout(): void {
    session_start_once();
    session_destroy();
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

// One token per session (not per form) is enough here since every POST
// page re-checks it against the same $_SESSION value before mutating data.
function csrf_token(): string {
    session_start_once();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}
