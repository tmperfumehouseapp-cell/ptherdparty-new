<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';

date_default_timezone_set($config['timezone']);
ini_set('display_errors', $config['debug'] ? '1' : '0');
error_reporting(E_ALL);

if (!defined('NO_SESSION')) { // wa_webhook.php is called by WhatsApp, not by a browser
    session_name('ordersess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo === null) {
        // Hostinger sometimes refuses a connection for a moment ("Operation not permitted").
        // Try the configured host a few times, then 127.0.0.1 as a second route.
        $hosts = [$config['db_host'], $config['db_host'], $config['db_host']];
        if ($config['db_host'] === 'localhost') {
            $hosts[] = '127.0.0.1';
            $hosts[] = '127.0.0.1';
        }
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $last = null;
        foreach ($hosts as $i => $host) {
            try {
                $dsn = 'mysql:host=' . $host . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $opts);
                break;
            } catch (PDOException $ex) {
                $last = $ex;
                if ((string)$ex->getCode() !== '2002') {
                    throw $ex; // wrong password, missing database etc.: do not retry
                }
                usleep(250000);
            }
        }
        if ($pdo === null) {
            throw $last;
        }
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}

/* ---------- business day ----------
 * The "day" of the whole panel can start at any time, e.g. 14:00 means day 27 runs from
 * 27 Oct 2:00 PM to 28 Oct 2:00 PM. Stored in settings (k = 'day_start'), default 00:00 = normal midnight day.
 */

function day_start(): string
{
    static $v = null;
    if ($v === null) {
        $v = '00:00';
        try {
            $st = db()->prepare("SELECT v FROM settings WHERE k = 'day_start'");
            $st->execute();
            $got = $st->fetchColumn();
            if (is_string($got) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $got)) {
                $v = $got;
            }
        } catch (Throwable $e) {
            // settings table missing: keep the normal day
        }
    }
    return $v;
}

function day_start_minutes(): int
{
    [$h, $m] = array_map('intval', explode(':', day_start()));
    return $h * 60 + $m;
}

/** Business date (Y-m-d) a moment belongs to. */
function biz_date(?int $ts = null): string
{
    return date('Y-m-d', ($ts ?? time()) - day_start_minutes() * 60);
}

function biz_today(): string
{
    return biz_date();
}

/** [start, end] of a business date as 'Y-m-d H:i:s' strings; the end is the last second before the next day starts. */
function biz_range(string $date): array
{
    $start = strtotime($date . ' ' . day_start() . ':00');
    return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 86400 - 1)];
}

/* ---------- output helpers ---------- */

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Indian style money: ₹1,23,456.50
function inr($n): string
{
    $n = round((float)$n, 2);
    $neg = $n < 0;
    $n = abs($n);
    [$int, $dec] = explode('.', number_format($n, 2, '.', ''));
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',';
    }
    return ($neg ? '-' : '') . '₹' . $rest . $last3 . ($dec === '00' ? '' : '.' . $dec);
}

// "1,200.50" -> 1200.5
function num($v): float
{
    return (float)str_replace([',', '₹', ' '], '', (string)$v);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/* ---------- flash messages ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_pull(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Session expired. Go back, refresh the page and try again.');
    }
}

/* ---------- auth ---------- */

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, name, username, role FROM users WHERE id = ? AND is_active = 1');
            $st->execute([(int)$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    // an order whose confirmation was not sent to the customer keeps the panel on that order
    require_once __DIR__ . '/order_auto_lib.php';
    confirm_lock_check($u);
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Admin only.');
    }
    return $u;
}