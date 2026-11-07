<?php
// Shared product helpers (used by products.php now, and by the order screen next).

/**
 * Saves a new base price version for a product.
 * Returns false (and saves nothing) if the price is the same as the current one.
 * Old orders are never touched: each order item keeps its own copy of the price.
 */
function set_base_price(PDO $pdo, int $productId, float $price, int $userId): bool
{
    $st = $pdo->prepare('SELECT base_price FROM product_prices WHERE product_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$productId]);
    $current = $st->fetchColumn();
    if ($current !== false && abs((float)$current - $price) < 0.005) {
        return false;
    }
    $pdo->prepare('INSERT INTO product_prices (product_id, base_price, created_by) VALUES (?, ?, ?)')
        ->execute([$productId, $price, $userId]);
    return true;
}

/** Returns the party id if it exists, otherwise null. */
function valid_party_id($raw): ?int
{
    $id = (int)$raw;
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT id FROM parties WHERE id = ?');
    $st->execute([$id]);
    return $st->fetchColumn() ? $id : null;
}

if (!defined('DEFAULT_WEBSITE_URL')) {
    define('DEFAULT_WEBSITE_URL', 'https://fourpirates.tmfragrance.com/visitor/product-api/get_products.php');
}

/** Downloads one URL (server to server) and returns the decoded JSON. */
function http_get_json(string $url, array $headers = [], int $timeout = 40): array
{
    $headers[] = 'User-Agent: OrderDesk/1.0';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new RuntimeException('Could not reach the website: ' . $err);
        }
        if ($code >= 400) {
            throw new RuntimeException('The website answered with error ' . $code . '.');
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => implode("\r\n", $headers) . "\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('Could not reach the website.');
        }
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['success'])) {
        throw new RuntimeException('The website did not return the expected data.');
    }
    return $data;
}

/** Reads ALL products from the website, page after page. */
function fetch_website_products(string $url): array
{
    $all = [];
    for ($page = 1; $page <= 40; $page++) {
        $data = http_get_json($url . (strpos($url, '?') === false ? '?' : '&') . 'page=' . $page);
        if (!isset($data['products']) || !is_array($data['products'])) {
            throw new RuntimeException('The website did not return a product list.');
        }
        foreach ($data['products'] as $p) {
            $all[] = $p;
        }
        if (empty($data['hasMore']) || !$data['products']) {
            break;
        }
    }
    return $all;
}

/** Reads buying prices from the private website endpoint. Returns [website product id => price]. */
function fetch_website_costs(string $url, string $key, int $timeout = 40): array
{
    $data = http_get_json($url, ['X-API-Key: ' . $key], $timeout);
    $out = [];
    foreach (($data['costs'] ?? []) as $c) {
        $out[(string)(int)($c['id'] ?? 0)] = (float)($c['cost_price'] ?? 0);
    }
    return $out;
}

/**
 * Brings the website's buying prices into this panel. The website is the only place a buying price is set:
 * nobody can type or override it here. A new price version is saved only when the website value changed.
 * Runs at most once per $maxAge seconds (every attempt counts, so a slow or broken website is not hammered).
 * Returns ['ran' => bool, 'changed' => int, 'error' => string, 'at' => unix time of the last good sync or 0].
 */
function sync_website_costs(PDO $pdo, array $config, int $userId, int $maxAge = 300, int $timeout = 8): array
{
    $get = static function (string $k) use ($pdo): int {
        try {
            $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
            $st->execute([$k]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };
    $put = static function (string $k, int $v) use ($pdo): void {
        try {
            $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, (string)$v]);
        } catch (Throwable $e) {
            // settings table missing: the sync still works, it just cannot remember when it last ran
        }
    };
    $out = ['ran' => false, 'changed' => 0, 'error' => '', 'at' => $get('costs_synced_at')];
    $key = (string)($config['website_costs_key'] ?? '');
    if ($key === '') {
        $out['error'] = 'website_costs_key is not in config.php';
        return $out;
    }
    if ($maxAge > 0 && time() - $get('costs_try_at') < $maxAge) {
        return $out; // synced a moment ago
    }
    $put('costs_try_at', time());
    $out['ran'] = true;
    try {
        $base = (string)($config['website_products_url'] ?? DEFAULT_WEBSITE_URL);
        $costUrl = (string)($config['website_costs_url'] ?? str_replace('get_products.php', 'get_costs.php', $base));
        $costs = fetch_website_costs($costUrl, $key, $timeout);
        $map = $pdo->query('SELECT external_id, id FROM products WHERE external_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
        $byExt = [];
        foreach ($map as $ext => $id) {
            $byExt[(string)$ext] = (int)$id;
        }
        $pdo->beginTransaction();
        try {
            foreach ($costs as $ext => $cost) {
                if (isset($byExt[(string)$ext]) && $cost > 0 && set_base_price($pdo, $byExt[(string)$ext], round($cost, 2), $userId)) {
                    $out['changed']++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $put('costs_synced_at', time());
        $out['at'] = time();
    } catch (Throwable $e) {
        $out['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not read the website prices.';
    }
    return $out;
}