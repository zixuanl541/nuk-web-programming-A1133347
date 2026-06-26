<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/logic.php';

require_admin();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'setup_company') {
        $name = trim($_POST['company_name'] ?? '');
        if ($name) {
            $pdo = db();
            // Only the very first company in the *entire system* should sweep
            // up leftover company_id IS NULL accounts (the original seed
            // accounts, from before any company existed). Checking this
            // admin's own company_id isn't enough — workspace deletion can
            // put any admin back to company_id NULL, and re-running the
            // orphan sweep at that point could scoop up unrelated accounts
            // that happen to be orphaned for some other reason.
            $isVeryFirstCompany = ((int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0);

            $pdo->prepare('INSERT INTO companies (owner_id, name) VALUES (?, ?)')->execute([$user['id'], $name]);
            $companyId = (int)$pdo->lastInsertId();

            if ($isVeryFirstCompany) {
                $pdo->prepare('UPDATE users SET company_id = ? WHERE company_id IS NULL')->execute([$companyId]);
            } else {
                // Switch the admin straight into the workspace they just
                // created, same as clicking "切換" on it.
                $pdo->prepare('UPDATE users SET company_id = ? WHERE id = ?')->execute([$companyId, $user['id']]);
            }

            $_SESSION['user']['company_id'] = $companyId;
            $user['company_id'] = $companyId;
            flash("工作室「{$name}」已建立！");
        } else {
            flash('請輸入工作室名稱。', 'red');
        }
    }

    if ($action === 'switch_company') {
        $targetId = (int)($_POST['company_id'] ?? 0);
        // Admins may only switch into workspaces they themselves own — this
        // is the only access check standing between an admin and any
        // company's data, since every other page just trusts company_id.
        $owns = db()->prepare('SELECT id FROM companies WHERE id = ? AND owner_id = ?');
        $owns->execute([$targetId, $user['id']]);
        if ($owns->fetch()) {
            db()->prepare('UPDATE users SET company_id = ? WHERE id = ?')->execute([$targetId, $user['id']]);
            $_SESSION['user']['company_id'] = $targetId;
            $user['company_id'] = $targetId;
            flash('已切換工作室。');
        } else {
            flash('找不到這個工作室，或你不是它的擁有者。', 'red');
        }
    }

    if ($action === 'delete_company') {
        $targetId = (int)($_POST['company_id'] ?? 0);
        $owns = db()->prepare('SELECT id FROM companies WHERE id = ? AND owner_id = ?');
        $owns->execute([$targetId, $user['id']]);

        if (!$owns->fetch()) {
            flash('找不到這個工作室，或你不是它的擁有者。', 'red');
        } else {
            // users.company_id -> companies.id is ON DELETE CASCADE, so
            // deleting a company wipes every account still pointing at it —
            // including the admin's own, if this happens to be their active
            // workspace. Only allow it when the workspace is genuinely
            // empty: no other members, and no products/batches (which also
            // rules out sales, since a sale can't exist without inventory
            // from a batch).
            $memberCount = db()->prepare('SELECT COUNT(*) FROM users WHERE company_id = ? AND id != ?');
            $memberCount->execute([$targetId, $user['id']]);
            $productCount = db()->prepare('SELECT COUNT(*) FROM products WHERE company_id = ?');
            $productCount->execute([$targetId]);
            $batchCount = db()->prepare('SELECT COUNT(*) FROM batches WHERE company_id = ?');
            $batchCount->execute([$targetId]);

            if ((int)$memberCount->fetchColumn() > 0) {
                flash('此工作室還有其他成員帳號，請先在使用者管理移除或轉移後再刪除。', 'red');
            } elseif ((int)$productCount->fetchColumn() > 0 || (int)$batchCount->fetchColumn() > 0) {
                flash('此工作室已有商品或進貨紀錄，無法刪除。', 'red');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $wasActive = ((int)$user['company_id'] === $targetId);
                    if ($wasActive) {
                        // Detach first so the cascade triggered by deleting
                        // the company below doesn't also delete this admin's
                        // own account.
                        $pdo->prepare('UPDATE users SET company_id = NULL WHERE id = ?')->execute([$user['id']]);
                    }
                    $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$targetId]);

                    if ($wasActive) {
                        $fallback = $pdo->prepare(
                            'SELECT id FROM companies WHERE owner_id = ? AND id != ? ORDER BY created_at LIMIT 1'
                        );
                        $fallback->execute([$user['id'], $targetId]);
                        $fallbackId = $fallback->fetchColumn() ?: null;
                        if ($fallbackId) {
                            $pdo->prepare('UPDATE users SET company_id = ? WHERE id = ?')->execute([$fallbackId, $user['id']]);
                        }
                        $_SESSION['user']['company_id'] = $fallbackId;
                        $user['company_id'] = $fallbackId;
                    }
                    $pdo->commit();
                    flash('工作室已刪除。', 'yellow');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash('刪除失敗，請稍後再試。', 'red');
                }
            }
        }
    }

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'], ['admin','seller']) ? $_POST['role'] : 'seller';
        if (empty($user['company_id'])) {
            flash('請先建立工作室後再新增使用者。', 'red');
        } elseif ($username && $email && strlen($password) >= 6) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            try {
                db()->prepare('INSERT INTO users (company_id, username, email, password, role) VALUES (?,?,?,?,?)')
                     ->execute([$user['company_id'], $username, $email, $hashed, $role]);
                flash("使用者 {$username} 已新增。");
            } catch (Exception $e) {
                flash('新增失敗：信箱或使用者名稱已存在。', 'red');
            }
        } else {
            flash('請填寫所有欄位，密碼至少 6 字元。', 'red');
        }
    }

    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        // An admin is blocked from deactivating/deleting their own account so
        // a company can never end up with zero active admins to manage it.
        if ($id !== (int)$user['id']) {
            $stmt = db()->prepare('SELECT status FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u) {
                $newStatus = $u['status'] === 'active' ? 'inactive' : 'active';
                db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
                flash("使用者狀態已更新為「{$newStatus}」。");
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$user['id']) {
            // batches.user_id and sales_records.user_id are both
            // ON DELETE CASCADE, so deleting a user who already created
            // purchase batches or sales would wipe that history along with
            // the account. Block it the same way product deletion is.
            $batchCount = db()->prepare('SELECT COUNT(*) FROM batches WHERE user_id = ?');
            $batchCount->execute([$id]);
            $saleCount = db()->prepare('SELECT COUNT(*) FROM sales_records WHERE user_id = ?');
            $saleCount->execute([$id]);
            if ((int)$batchCount->fetchColumn() > 0 || (int)$saleCount->fetchColumn() > 0) {
                flash('此使用者已有進貨批次或銷售紀錄，無法刪除，請改用「停權」。', 'red');
            } else {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash('使用者已刪除。', 'yellow');
            }
        } else {
            flash('無法刪除自己的帳號。', 'red');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

if ($user['company_id']) {
    $users = db()->prepare(
        'SELECT u.*, COUNT(sr.id) AS sale_count
         FROM users u
         LEFT JOIN sales_records sr ON sr.user_id = u.id
         WHERE u.company_id = ?
         GROUP BY u.id ORDER BY u.created_at DESC'
    );
    $users->execute([$user['company_id']]);
    $users = $users->fetchAll();
} else {
    $users = [];
}

$myCompanies = db()->prepare('SELECT id, name FROM companies WHERE owner_id = ? ORDER BY created_at');
$myCompanies->execute([$user['id']]);
$myCompanies = $myCompanies->fetchAll();

render_head('使用者管理');
?>
<div class="app">
<?php render_sidebar(); ?>
<div class="main">
<?php render_topbar('使用者管理', 'Admin — 管理所有帳號'); ?>
<div class="content">
<?php render_flash(); ?>

<div class="card mb-2">
  <div class="card-title"><span class="card-title-dot"></span>我的工作室</div>
  <?php if (empty($user['company_id'])): ?>
    <div class="text-muted" style="font-size:13px;margin-bottom:1rem">尚未設定工作室，請先建立工作室才能新增商品、批次與員工帳號。</div>
  <?php elseif ($myCompanies): ?>
    <div class="text-muted" style="font-size:13px;margin-bottom:1rem">目前正在操作的工作室會套用到商品、批次、庫存、銷售等所有頁面。</div>
    <div class="table-wrap" style="margin-bottom:1rem">
      <table>
        <thead><tr><th>工作室名稱</th><th>狀態</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($myCompanies as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['name']) ?></td>
              <td>
                <?php if ((int)$c['id'] === (int)$user['company_id']): ?>
                  <span class="badge badge-blue">目前使用中</span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$c['id'] !== (int)$user['company_id']): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="switch_company">
                    <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">切換到這個工作室</button>
                  </form>
                <?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('確定刪除此工作室？此操作不可還原，且只有完全沒有商品/進貨/其他成員時才能刪除。')">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete_company">
                  <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">刪除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <form method="POST" class="form-row" style="align-items:flex-end">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="setup_company">
    <div class="form-group" style="flex:1">
      <label>工作室名稱 *</label>
      <input type="text" name="company_name" placeholder="例：小明代購工作室" required>
    </div>
    <button type="submit" class="btn btn-primary"><?= empty($user['company_id']) ? '建立工作室' : '新增工作室' ?></button>
  </form>
</div>

<div class="section-header">
  <div class="section-title">帳號列表（<?= count($users) ?> 位）</div>
  <button class="btn btn-primary" onclick="document.getElementById('add-modal').style.display='flex'">+ 新增使用者</button>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>使用者名稱</th><th>信箱</th><th>角色</th><th>銷售筆數</th><th>狀態</th><th>新增時間</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong>
              <?php if ($u['id'] == $user['id']): ?><span class="badge badge-blue" style="margin-left:6px">自己</span><?php endif; ?>
            </td>
            <td class="mono"><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-blue' : 'badge-green' ?>"><?= $u['role'] ?></span></td>
            <td class="mono"><?= (int)$u['sale_count'] ?></td>
            <td><span class="badge <?= $u['status'] === 'active' ? 'badge-green' : 'badge-red' ?>"><?= $u['status'] === 'active' ? '啟用' : '停權' ?></span></td>
            <td class="mono text-muted"><?= substr($u['created_at'], 0, 10) ?></td>
            <td>
              <?php if ($u['id'] != $user['id']): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">
                    <?= $u['status'] === 'active' ? '停權' : '啟用' ?>
                  </button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('確定刪除此帳號？此操作不可還原。')">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">刪除</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Modal -->
<div id="add-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border2);border-radius:12px;padding:1.75rem;width:440px;max-width:95vw">
    <div class="flex items-center" style="justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-size:15px;font-weight:500">新增使用者</div>
      <button onclick="document.getElementById('add-modal').style.display='none'" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:20px">×</button>
    </div>
    <form method="POST" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>使用者名稱 *</label><input type="text" name="username" placeholder="seller02" required></div>
      <div class="form-group"><label>信箱 *</label><input type="email" name="email" placeholder="user@example.com" required></div>
      <div class="form-group"><label>密碼（至少 6 字元）*</label><input type="password" name="password" placeholder="••••••••" required minlength="6"></div>
      <div class="form-group">
        <label>角色</label>
        <select name="role">
          <option value="seller">Seller 賣家</option>
          <option value="admin">Admin 管理者</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-full">新增使用者</button>
    </form>
  </div>
</div>

</div></div></div>
<?php render_foot(); ?>
