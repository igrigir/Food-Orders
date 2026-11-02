<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Zagreb');
ini_set('session.use_strict_mode', '1');
$sessionDirectory = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessionDirectory)) mkdir($sessionDirectory, 0700, true);
session_save_path($sessionDirectory);
session_name('food_orders_' . substr(hash('sha256', __DIR__), 0, 12));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');

$databasePath = getenv('FOOD_ORDERS_DB') ?: dirname(__DIR__) . '/storage/food_orders.sqlite';
if (!is_dir(dirname($databasePath))) mkdir(dirname($databasePath), 0700, true);
$db = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));

function query(string $sql, array $params = []): PDOStatement {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function token_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function verify_csrf(): void {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf(), $_POST['csrf'])) throw new RuntimeException('Your session token expired. Refresh the page and try again.');
}
function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $user = query('SELECT id, name, phone, role, password_hash FROM users WHERE id = ?', [$_SESSION['user_id']])->fetch();
    if (!$user || !hash_equals(hash('sha256',$user['password_hash']), $_SESSION['auth_hash'] ?? '')) {
        unset($_SESSION['user_id'],$_SESSION['auth_hash']);
        return null;
    }
    unset($user['password_hash']);
    return $user;
}
function set_user_password(int $id, string $hash): void {
    query('UPDATE users SET password_hash=? WHERE id=?',[$hash,$id]);
    if ((int)($_SESSION['user_id'] ?? 0) === $id) {
        $_SESSION['auth_hash'] = hash('sha256',$hash);
        session_regenerate_id(true);
    }
}
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }
function require_admin(): void { if (!is_admin()) throw new RuntimeException('Administrator access is required.'); }
function field(string $key, int $max = 200, bool $required = false): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) throw new RuntimeException('Invalid field: ' . $key);
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) throw new RuntimeException('Please enter a valid ' . str_replace('_', ' ', $key) . ' (maximum ' . $max . ' characters).');
    return $value;
}
function positive_id(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new RuntimeException('Invalid record identifier.');
    return $id;
}
function cents(string $value): int {
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value)) throw new RuntimeException('Enter a price between 0 and 9999999.99 with up to two decimals.');
    $parts = explode('.', $value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}
function money(int $value): string { return number_format($value / 100, 2, '.', ',') . ' KM'; }
function password_value(bool $required = true): ?string {
    $value = $_POST['password'] ?? '';
    if (!is_string($value)) throw new RuntimeException('Invalid password.');
    if ($value === '' && !$required) return null;
    if (strlen($value) < 10 || strlen($value) > 72) throw new RuntimeException('Use a password between 10 and 72 bytes.');
    return password_hash($value, PASSWORD_DEFAULT);
}
function order_access(int $id): array {
    $order = query('SELECT * FROM orders WHERE id = ?', [$id])->fetch();
    if (!$order || (!is_admin() && (int)$order['user_id'] !== (int)current_user()['id'])) throw new RuntimeException('Order not found or access denied.');
    return $order;
}
function flash(string $message): void { $_SESSION['flash'] = $message; }
