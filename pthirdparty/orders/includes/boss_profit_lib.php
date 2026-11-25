<?php
// Boss profit (manager only): the profit that is yours after the dealers' share, and a record of what you passed on to your other company.
// Every entry is paid out of your own QR ("My QR"): it also writes one 'out' row in the account ledger (type Other / transfer), so the My QR balance goes down.
// The dealer ledgers are not touched.
require_once __DIR__ . '/ops_lib.php';
require_once __DIR__ . '/vendor_lib.php';
require_once __DIR__ . '/order_dealer_lib.php';

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
                ledger_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_date (entry_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        if (!$pdo->query("SHOW COLUMNS FROM boss_profit_log LIKE 'ledger_id'")->fetch()) { // table made by the first version
            $pdo->exec('ALTER TABLE boss_profit_log ADD COLUMN ledger_id INT UNSIGNED NULL');
        }
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

function boss_profit_note(string $company, string $note): string
{
    return 'Boss profit to ' . $company . ($note !== '' ? ' - ' . $note : '');
}

function boss_profit_add(PDO $pdo, array $user, string $company, float $amount, string $date, string $note): void
{
    [$c, $a, $d, $n] = boss_profit_clean($company, $amount, $date, $note);
    $qr = my_qr_id($pdo);
    $dup = $pdo->prepare('SELECT COUNT(*) FROM boss_profit_log WHERE entry_date = ? AND amount = ? AND company = ? AND created_at >= ?');
    $dup->execute([$d, $a, $c, date('Y-m-d H:i:s', time() - 120)]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('This entry was just saved. Not added twice.');
    }
    in_tx($pdo, function () use ($pdo, $user, $qr, $c, $a, $d, $n) {
        $lid = post_account_entry($pdo, (int)$user['id'], $qr, $d, 'out', $a, 'other', null, boss_profit_note($c, $n));
        $pdo->prepare('INSERT INTO boss_profit_log (entry_date, amount, company, note, created_by, ledger_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$d, $a, $c, $n !== '' ? $n : null, (int)$user['id'], $lid]);
    });
}

function boss_profit_edit(PDO $pdo, array $user, int $id, string $company, float $amount, string $date, string $note): void
{
    [$c, $a, $d, $n] = boss_profit_clean($company, $amount, $date, $note);
    $qr = my_qr_id($pdo);
    in_tx($pdo, function () use ($pdo, $user, $id, $qr, $c, $a, $d, $n) {
        $st = $pdo->prepare('SELECT id, ledger_id FROM boss_profit_log WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('That entry was not found. Refresh the page.');
        }
        $lid = $row['ledger_id'] !== null ? (int)$row['ledger_id'] : 0;
        $upd = $pdo->prepare("UPDATE account_ledger SET account_id = ?, entry_date = ?, amount = ?, note = ? WHERE id = ? AND entry_type = 'other' AND direction = 'out'");
        $upd->execute([$qr, $d, $a, boss_profit_note($c, $n), $lid]);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM account_ledger WHERE id = ? AND entry_type = 'other' AND direction = 'out'");
        $chk->execute([$lid]);
        if ($lid === 0 || (int)$chk->fetchColumn() === 0) { // the ledger row is gone (deleted on the Ledger page): make it again
            $lid = post_account_entry($pdo, (int)$user['id'], $qr, $d, 'out', $a, 'other', null, boss_profit_note($c, $n));
        }
        $pdo->prepare('UPDATE boss_profit_log SET entry_date = ?, amount = ?, company = ?, note = ?, ledger_id = ? WHERE id = ?')->execute([$d, $a, $c, $n !== '' ? $n : null, $lid, $id]);
    });
}

function boss_profit_delete(PDO $pdo, int $id): void
{
    in_tx($pdo, function () use ($pdo, $id) {
        $st = $pdo->prepare('SELECT ledger_id FROM boss_profit_log WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('That entry was not found. Refresh the page.');
        }
        if ($row['ledger_id'] !== null) {
            $pdo->prepare("DELETE FROM account_ledger WHERE id = ? AND entry_type = 'other' AND direction = 'out'")->execute([(int)$row['ledger_id']]);
        }
        $pdo->prepare('DELETE FROM boss_profit_log WHERE id = ?')->execute([$id]);
    });
}
