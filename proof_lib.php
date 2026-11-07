<?php
// Payment screenshots: saved privately in uploads/proofs and only shown through proof.php (login needed).

const PROOF_MAX_BYTES = 10 * 1024 * 1024;

function proof_dir(): string
{
    $base = dirname(__DIR__) . '/uploads';
    $dir = $base . '/proofs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    foreach ([$base, $dir] as $d) {
        if (is_dir($d) && !is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
        if (is_dir($d) && !is_file($d . '/index.html')) {
            @file_put_contents($d . '/index.html', '');
        }
    }
    return $dir;
}

/** True when the form really carried a file. */
function proof_uploaded(?array $f): bool
{
    return is_array($f) && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Checks and stores one uploaded screenshot (jpg, png, webp, gif or pdf).
 * Returns the stored file name (relative to uploads/proofs). Throws RuntimeException with a message safe to show.
 */
function save_proof_upload(?array $f): string
{
    if (!proof_uploaded($f)) {
        throw new RuntimeException('Attach the payment screenshot.');
    }
    $err = (int)$f['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('The screenshot is too big. Use a file under 10 MB.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        throw new RuntimeException('The screenshot could not be uploaded. Attach it again.');
    }
    if ((int)$f['size'] <= 0 || (int)$f['size'] > PROOF_MAX_BYTES) {
        throw new RuntimeException('The screenshot must be under 10 MB.');
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, (string)$f['tmp_name']);
        finfo_close($fi);
    } elseif (($info = @getimagesize((string)$f['tmp_name'])) !== false) {
        $mime = (string)$info['mime'];
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][$mime] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Attach a screenshot as a photo (jpg, png, webp) or a PDF.');
    }
    $sub = date('ym');
    $dir = proof_dir() . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = $sub . '/' . bin2hex(random_bytes(14)) . '.' . $ext;
    if (!is_dir($dir) || !@move_uploaded_file((string)$f['tmp_name'], proof_dir() . '/' . $name)) {
        throw new RuntimeException('The screenshot could not be saved on the server. Try again.');
    }
    return $name;
}

function proof_remove_file(?string $name): void
{
    if ($name && preg_match('~^\d{4}/[a-f0-9]{28}\.(jpg|png|webp|gif|pdf)$~', $name)) {
        @unlink(proof_dir() . '/' . $name);
    }
}

function proof_is_image(?string $name): bool
{
    return (bool)$name && !preg_match('~\.pdf$~i', $name);
}

/** Saves the database row. $kind: 'order' (ref = order id) or 'party_payment' (ref = party ledger id). */
function proof_add(PDO $pdo, string $kind, int $refId, ?string $method, ?string $file, int $userId): void
{
    $pdo->prepare('INSERT INTO payment_proofs (kind, ref_id, method, file, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$kind, $refId, $method, $file, $userId]);
}

/** Removes the row(s) and the file(s) of one record. */
function proof_delete_for(PDO $pdo, string $kind, int $refId): void
{
    $st = $pdo->prepare('SELECT id, file FROM payment_proofs WHERE kind = ? AND ref_id = ?');
    $st->execute([$kind, $refId]);
    foreach ($st->fetchAll() as $r) {
        proof_remove_file($r['file']);
    }
    $pdo->prepare('DELETE FROM payment_proofs WHERE kind = ? AND ref_id = ?')->execute([$kind, $refId]);
}
