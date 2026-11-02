<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/actions.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { handle_action(is_string($_POST['action'] ?? null) ? $_POST['action'] : ''); }
    catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        if ($exception instanceof PDOException) {
            error_log($exception->getMessage());
            $error = str_contains($exception->getMessage(),'UNIQUE') ? 'That user name is already in use.' : (str_contains($exception->getMessage(),'FOREIGN KEY') ? 'This record is in use. Remove its related records first.' : 'The database operation failed. Please try again.');
        } elseif ($exception instanceof RuntimeException || $exception instanceof JsonException) $error = $exception->getMessage();
        else { error_log((string)$exception); $error = 'An unexpected error occurred. Please reload and try again.'; }
        if (($_POST['action'] ?? '') === 'quick_item') {
            http_response_code(422); header('Content-Type: application/json'); echo json_encode(['error'=>$error]); exit;
        }
    }
}
$user = current_user();
$setup = (int)query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
$pages = ['dashboard'=>'Overview','orders'=>'Orders','order'=>'Order editor','order_summary'=>'Order summary','suppliers'=>'Suppliers','items'=>'Supplier items','users'=>'Users','register'=>'Register'];
if (!isset($pages[$page])) $page = 'dashboard';
if ($user && $page === 'register') redirect('index.php');
$registration = !$setup && $page === 'register';
if ($page === 'users' && $user && !is_admin()) { http_response_code(403); $page = 'dashboard'; $error = 'Administrator access is required.'; }
function input_value(array $record, string $key, mixed $default = ''): string { return e($_POST[$key] ?? $record[$key] ?? $default); }
function delete_form(string $entity, int $id): void { ?>
<form method="post" class="inline" data-confirm="Delete this record? This action cannot be undone."><?= token_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="entity" value="<?= e($entity) ?>"><input type="hidden" name="id" value="<?= $id ?>"><button class="button danger small" type="submit">Delete</button></form>
<?php }
function order_links(int $id): void { ?>
<a class="button small" href="index.php?page=order_summary&id=<?= $id ?>">View</a> <a class="button small" href="index.php?page=order&id=<?= $id ?>">Edit</a>
<?php }
function password_reset_link(int $id): void { ?>
<a class="button small" href="index.php?page=users&reset=<?= $id ?>#password-reset">Reset password</a>
<?php }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $user ? e($pages[$page]) : ($registration ? 'Register' : 'Sign in') ?> · Food Orders</title><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head>
<body>
<?php if (!$user): ?>
<main class="auth-layout"><section class="auth-story"><a class="brand" href="index.php"><span class="brand-icon">F</span> Food Orders</a><div><span class="eyebrow">THE LUNCH PLAN, SORTED</span><h1>Good food.<br>Better organized.</h1><p>Keep suppliers, menus and every order together in one simple workspace.</p><div class="story-tags"><span>One workspace</span><span>Every order</span><span>Less effort</span></div></div><small>Your team's daily ordering companion.</small></section><section class="auth-panel"><div class="auth-card"><?php if($registration): require __DIR__.'/app/register-view.php'; else: ?><span class="eyebrow"><?= $setup ? 'LET’S GET STARTED' : 'WELCOME BACK' ?></span><h2><?= $setup ? 'Create your workspace' : 'Sign in to your account' ?></h2><p class="muted"><?= $setup ? 'Create the first administrator. There is no default password.' : 'Use the account provided by your administrator.' ?></p>
<?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="stack"><?= token_field() ?><input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>"><label>Name<input name="name" maxlength="100" required autocomplete="username" value="<?= e($_POST['name'] ?? '') ?>" autofocus></label><?php if ($setup): ?><label>Phone<input type="tel" name="phone" maxlength="50" autocomplete="tel"></label><?php endif; ?><label>Password<input type="password" name="password" <?= $setup ? 'minlength="10" maxlength="72"' : '' ?> required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"></label><?php if ($setup): ?><small class="muted">Use 10–72 bytes. Save this password securely.</small><?php endif; ?><button class="button primary" type="submit"><?= $setup ? 'Create administrator' : 'Sign in' ?> <span aria-hidden="true">→</span></button></form><?php if(!$setup): ?><p class="auth-switch">Need an account? <a class="text-link" href="register.php">Register</a></p><?php endif; ?><?php endif; ?></div></section></main>
<?php else: ?>
<aside class="sidebar"><a class="brand" href="index.php"><span class="brand-icon">F</span> Food Orders</a><span class="nav-caption">WORKSPACE</span><nav><?php foreach (['dashboard'=>'Overview','orders'=>'Orders','suppliers'=>'Suppliers','items'=>'Supplier items','users'=>'Users'] as $key=>$label): if ($key==='users' && !is_admin()) continue; ?><a class="<?= ($page===$key || (in_array($page,['order','order_summary'],true) && $key==='orders')) ? 'selected' : '' ?>" href="index.php?page=<?= $key ?>"><span class="nav-dot" aria-hidden="true"></span><?= $label ?></a><?php endforeach; ?></nav><div class="sidebar-bottom"><div class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'],0,1))) ?></div><div><strong><?= e($user['name']) ?></strong><small><?= e(ucfirst($user['role'])) ?></small></div><form method="post"><?= token_field() ?><input type="hidden" name="action" value="logout"><button class="logout" title="Sign out" aria-label="Sign out">↪</button></form></div></aside>
<main class="workspace"><header class="topbar"><span>Workspace <span class="slash">/</span> <?= e($pages[$page]) ?></span><span class="today"><?= date('d M Y') ?></span></header><div class="page-content">
<?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_SESSION['flash'])): ?><div class="alert success" role="status"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php try { require __DIR__.'/app/views.php'; } catch (RuntimeException $exception) { http_response_code(404); ?><div class="alert error"><?= e($exception->getMessage()) ?></div><a href="index.php?page=orders">Back to orders</a><?php } ?>
</div><footer>Food Orders <span>Made for a smoother lunch break.</span></footer></main>
<?php endif; ?>
</body></html>
