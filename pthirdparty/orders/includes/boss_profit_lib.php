<?php
// Boss profit (manager only): the profit that is yours after the dealers' share, and a record of what you passed on to your other company.
// It is a record only: entries here do not move money in any account, QR or dealer ledger.
require_once __DIR__ . '/ops_lib.php';

function boss_profit_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS boss_profit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_date DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                company VARCHAR(120) NOT NULL,
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_date (entry_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        throw new RuntimeException('Could not prepare the Boss profit table.');
    }
    $done = true;
}

/**
 * The profit of the panel's orders: earned = delivered orders (the sale minus what the dealers get), coming = orders still open.
 * Refunded and returned orders are not counted. Returns ['earned', 'coming', 'months' => [month => earned]].
 */
function boss_profit_summary(PDO $pdo): array
{
    $earned = (float)$pdo->query("SELECT COALESCE(SUM(profit), 0) FROM orders WHERE status = 'delivered'")->fetchColumn();
    $coming = (float)$pdo->query("SELECT COALESCE(SUM(profit), 0) FROM orders WHERE status IN ('created','dispatched')")->fetchColumn();
    $months = [];
    foreach ($pdo->query("SELECT SUBSTR(COALESCE(delivered_at, order_date), 1, 7) AS m, COALESCE(SUM(profit), 0) AS p, COUNT(*) AS n FROM orders WHERE status = 'delivered' GROUP BY m")->fetchAll() as $r) {
        $months[(string)$r['m']] = ['earned' => (float)$r['p'], 'orders' => (int)$r['n']];
    }
    return ['earned' => round($earned, 2), 'coming' => round($coming, 2), 'months' => $months];
}

/** Everything passed on, newest first. */
function boss_profit_entries(PDO $pdo, int $limit = 500): array
{
    return $pdo->query('SELECT id, entry_date, amount, company, COALESCE(note, \'\') AS note FROM boss_profit_log ORDER BY entry_date DESC, id DESC LIMIT ' . (int)$limit)->fetchAll();
}

function boss_profit_clean(string $company, float $amount, string $date, string $note): array
{
    $company = trim(preg_replace('/\s+/', ' ', $company));
    if ($company === '') {
        throw new RuntimeException('Write the name of the company the profit went to.');
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter an amount more than 0.');
    }
    $d = valid_date($date);
    if ($d === null) {
        throw new RuntimeException('Choose a valid date.');
    }
    return [mb_substr($company, 0, 120), $amount, $d, mb_substr(trim($note), 0, 255)];
}

function boss_profit_add(PDO $pdo, array $user, string $company, float $amount, string $date, string $note): void
{
    [$c, $a, $d, $n] = boss_profit_clean($company, $amount, $date, $note);
    $dup = $pdo->prepare('SELECT COUNT(*) FROM boss_profit_log WHERE entry_date = ? AND amount = ? AND company = ? AND created_at >= ?');
    $dup->execute([$d, $a, $c, date('Y-m-d H:i:s', time() - 120)]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('This entry was just saved. Not added twice.');
    }
    $pdo->prepare('INSERT INTO boss_profit_log (entry_date, amount, company, note, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$d, $a, $c, $n !== '' ? $n : null, (int)$user['id']]);
}

function boss_profit_edit(PDO $pdo, int $id, string $company, float $amount, string $date, string $note): void
{
    [$c, $a, $d, $n] = boss_profit_clean($company, $amount, $date, $note);
    $st = $pdo->prepare('UPDATE boss_profit_log SET entry_date = ?, amount = ?, company = ?, note = ? WHERE id = ?');
    $st->execute([$d, $a, $c, $n !== '' ? $n : null, $id]);
    $chk = $pdo->prepare('SELECT COUNT(*) FROM boss_profit_log WHERE id = ?');
    $chk->execute([$id]);
    if ((int)$chk->fetchColumn() === 0) {
        throw new RuntimeException('That entry was not found. Refresh the page.');
    }
}

function boss_profit_delete(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('DELETE FROM boss_profit_log WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('That entry was not found. Refresh the page.');
    }
}
