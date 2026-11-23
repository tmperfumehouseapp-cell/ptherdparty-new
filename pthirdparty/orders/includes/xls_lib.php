<?php
// Reads an old-format Excel file (.xls, Excel 97-2003 "BIFF8") into rows of strings, with no extra library, so the
// uploads (delivered list, COD from Excel) accept the file as Excel / Shiprocket gives it.
//
// Also copes with the things that are called .xls but are not: an .xlsx renamed, an HTML table or SpreadsheetML
// (what some websites download as ".xls"), and tab / comma / semicolon text.
// Returns rows like read_sheet_rows(): first sheet only, every cell a trimmed string, at most 5000 rows.

const XLS_MAX_ROWS = 5000;

function xls_read_rows(string $path): array
{
    $d = @file_get_contents($path);
    if ($d === false || $d === '') {
        throw new RuntimeException('Could not open the file.');
    }
    if (strncmp($d, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0) {
        return xls_read_biff(xls_ole_stream($d));
    }
    if (strncmp($d, 'PK', 2) === 0) { // really an .xlsx
        return read_sheet_rows($path, 'renamed.xlsx');
    }
    return xls_read_text($d);
}

/* ---------- OLE2 container ---------- */

/** The "Workbook" (or "Book") stream of an OLE2 compound file. */
function xls_ole_stream(string $d): string
{
    $u16 = static fn(int $o): int => unpack('v', substr($d, $o, 2))[1];
    $u32 = static fn(int $o): int => unpack('V', substr($d, $o, 4))[1];
    $ss = 1 << $u16(0x1E);        // sector size (512 or 4096)
    $mss = 1 << $u16(0x20);       // mini sector size (64)
    $firstDir = $u32(0x30);
    $cutoff = $u32(0x38);         // streams smaller than this live in the mini stream
    $firstMini = $u32(0x3C);
    $firstDifat = $u32(0x44);
    $nDifat = $u32(0x48);
    $END = 0xFFFFFFFE;
    $sector = static fn(int $id): string => substr($d, ($id + 1) * $ss, $ss);

    // FAT sector list: 109 entries in the header, the rest in DIFAT sectors
    $fatIds = [];
    for ($i = 0; $i < 109; $i++) {
        $v = $u32(0x4C + $i * 4);
        if ($v < 0xFFFFFFFC) {
            $fatIds[] = $v;
        }
    }
    $next = $firstDifat;
    for ($n = 0; $n < $nDifat && $next < 0xFFFFFFFC; $n++) {
        $s = $sector($next);
        $per = intdiv($ss, 4) - 1;
        for ($i = 0; $i < $per; $i++) {
            $v = unpack('V', substr($s, $i * 4, 4))[1];
            if ($v < 0xFFFFFFFC) {
                $fatIds[] = $v;
            }
        }
        $next = unpack('V', substr($s, $per * 4, 4))[1];
    }
    $fat = [];
    foreach ($fatIds as $fid) {
        foreach (unpack('V*', $sector($fid)) as $v) {
            $fat[] = $v;
        }
    }
    $chain = static function (int $start) use ($fat, $END): array {
        $ids = [];
        $seen = 0;
        for ($id = $start; $id < 0xFFFFFFFA && $seen < 5000000; $id = $fat[$id] ?? $END, $seen++) {
            $ids[] = $id;
        }
        return $ids;
    };
    $readChain = static function (int $start) use ($chain, $sector): string {
        $out = '';
        foreach ($chain($start) as $id) {
            $out .= $sector($id);
        }
        return $out;
    };

    // directory
    $dir = $readChain($firstDir);
    $root = null;
    $found = null;
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $type = ord($dir[$o + 66]);
        if ($type === 0) {
            continue;
        }
        $nameLen = unpack('v', substr($dir, $o + 64, 2))[1];
        $name = @mb_convert_encoding(substr($dir, $o, max(0, $nameLen - 2)), 'UTF-8', 'UTF-16LE');
        $start = unpack('V', substr($dir, $o + 116, 4))[1];
        $size = unpack('V', substr($dir, $o + 120, 4))[1];
        if ($type === 5) {
            $root = ['start' => $start, 'size' => $size];
        } elseif ($type === 2 && ($name === 'Workbook' || $name === 'Book') && $found === null) {
            $found = ['start' => $start, 'size' => $size];
        }
    }
    if ($found === null) {
        throw new RuntimeException('This .xls file has no readable sheet. Save it as .xlsx or .csv and upload that.');
    }
    if ($found['size'] >= $cutoff || $root === null) {
        return substr($readChain($found['start']), 0, $found['size']);
    }
    // small stream: stored in 64-byte pieces of the root "mini stream"
    $miniFat = [];
    foreach (unpack('V*', $readChain($firstMini) ?: "\0\0\0\0") as $v) {
        $miniFat[] = $v;
    }
    $miniStream = substr($readChain($root['start']), 0, $root['size']);
    $out = '';
    for ($id = $found['start'], $n = 0; $id < 0xFFFFFFFA && $n < 1000000; $id = $miniFat[$id] ?? $END, $n++) {
        $out .= substr($miniStream, $id * $mss, $mss);
    }
    return substr($out, 0, $found['size']);
}

/* ---------- BIFF8 records ---------- */

/** A cell number as text: whole numbers without ".0" or E+ (an AWB like 19041977163094 must stay whole). */
function xls_num(float $f): string
{
    if ($f == floor($f) && abs($f) < 1e15) {
        return sprintf('%.0f', $f);
    }
    $s = rtrim(sprintf('%.10F', $f), '0');
    return rtrim($s, '.');
}

/** RK-encoded number. */
function xls_rk(int $v): float
{
    $f = 0.0;
    if ($v & 2) {
        $i = $v >> 2;
        if ($i & 0x20000000) {   // sign of the 30-bit integer
            $i -= 0x40000000;
        }
        $f = (float)$i;
    } else {
        $f = unpack('d', pack('V2', 0, $v & 0xFFFFFFFC))[1];
    }
    return ($v & 1) ? $f / 100 : $f;
}

/** Decodes characters of a BIFF string: $wide = UTF-16LE, else one byte per character (Windows-1252). */
function xls_chars(string $raw, bool $wide): string
{
    return $wide ? (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
}

/**
 * Reads a BIFF8 unicode string at $pos of $data (char count of $lenBytes bytes, then flags...). Returns [text, new position].
 * Used for LABEL, STRING and BOUNDSHEET names, which are never split over a CONTINUE record in practice.
 */
function xls_ustr(string $data, int $pos, int $lenBytes = 2): array
{
    $len = $lenBytes === 1 ? ord($data[$pos]) : unpack('v', substr($data, $pos, 2))[1];
    $pos += $lenBytes;
    $flags = ord($data[$pos] ?? "\0");
    $pos++;
    $rich = ($flags & 0x08) ? unpack('v', substr($data, $pos, 2))[1] : 0;
    if ($flags & 0x08) {
        $pos += 2;
    }
    $ext = ($flags & 0x04) ? unpack('V', substr($data, $pos, 4))[1] : 0;
    if ($flags & 0x04) {
        $pos += 4;
    }
    $wide = (bool)($flags & 0x01);
    $bytes = $wide ? $len * 2 : $len;
    $text = xls_chars(substr($data, $pos, $bytes), $wide);
    return [$text, $pos + $bytes + $rich * 4 + $ext];
}

/** The shared string table (SST), whose strings may continue in CONTINUE records. $segs = the record's data pieces in order. */
function xls_sst(array $segs): array
{
    $total = 0;
    $unique = 0;
    $si = 0;
    $seg = $segs[0];
    $pos = 8; // skip "total" and "unique" counts
    $unique = unpack('V', substr($seg, 4, 4))[1];
    $out = [];
    $nseg = count($segs);
    for ($n = 0; $n < $unique; $n++) {
        // header of one string, always inside one segment
        while ($pos >= strlen($seg) && $si + 1 < $nseg) {
            $seg = $segs[++$si];
            $pos = 0;
        }
        if ($pos + 3 > strlen($seg)) {
            break;
        }
        $len = unpack('v', substr($seg, $pos, 2))[1];
        $flags = ord($seg[$pos + 2]);
        $pos += 3;
        $rich = 0;
        $ext = 0;
        if ($flags & 0x08) {
            $rich = unpack('v', substr($seg, $pos, 2))[1];
            $pos += 2;
        }
        if ($flags & 0x04) {
            $ext = unpack('V', substr($seg, $pos, 4))[1];
            $pos += 4;
        }
        $wide = (bool)($flags & 0x01);
        $text = '';
        $left = $len;
        while ($left > 0) {
            if ($pos >= strlen($seg)) {           // the characters go on in the next CONTINUE record, which starts with a flags byte
                if ($si + 1 >= $nseg) {
                    break;
                }
                $seg = $segs[++$si];
                $wide = (bool)(ord($seg[0]) & 0x01);
                $pos = 1;
            }
            $bytesPer = $wide ? 2 : 1;
            $can = intdiv(strlen($seg) - $pos, $bytesPer);
            $take = min($left, $can);
            $text .= xls_chars(substr($seg, $pos, $take * $bytesPer), $wide);
            $pos += $take * $bytesPer;
            $left -= $take;
        }
        // formatting runs and phonetic data after the text (they can also sit across segments; skip them by size)
        $skip = $rich * 4 + $ext;
        while ($skip > 0) {
            if ($pos >= strlen($seg)) {
                if ($si + 1 >= $nseg) {
                    break;
                }
                $seg = $segs[++$si];
                $pos = 0;
            }
            $step = min($skip, strlen($seg) - $pos);
            $pos += $step;
            $skip -= $step;
        }
        $out[] = $text;
    }
    return $out;
}

/** Reads the first worksheet of a BIFF8 workbook stream. */
function xls_read_biff(string $wb): array
{
    $len = strlen($wb);
    if ($len < 8) {
        throw new RuntimeException('Could not read this .xls file.');
    }
    $ver = unpack('v', substr($wb, 4, 2))[1];
    if ($ver !== 0x0600) {
        throw new RuntimeException('This .xls file is in a very old Excel format. Open it in Excel, Save As .xlsx or .csv, and upload that.');
    }
    $sst = [];
    $sheetPos = null;
    $pos = 0;
    // ---- workbook globals ----
    while ($pos + 4 <= $len) {
        $id = unpack('v', substr($wb, $pos, 2))[1];
        $rl = unpack('v', substr($wb, $pos + 2, 2))[1];
        $data = substr($wb, $pos + 4, $rl);
        $pos += 4 + $rl;
        if ($id === 0x00FC) { // SST + its CONTINUE records
            $segs = [$data];
            while ($pos + 4 <= $len && unpack('v', substr($wb, $pos, 2))[1] === 0x003C) {
                $cl = unpack('v', substr($wb, $pos + 2, 2))[1];
                $segs[] = substr($wb, $pos + 4, $cl);
                $pos += 4 + $cl;
            }
            $sst = xls_sst($segs);
        } elseif ($id === 0x0085 && $sheetPos === null) { // BOUNDSHEET: first worksheet
            $type = ord($data[5] ?? "\0");
            if ($type === 0) {
                $sheetPos = unpack('V', substr($data, 0, 4))[1];
            }
        } elseif ($id === 0x000A) { // EOF of the globals
            break;
        }
    }
    if ($sheetPos === null || $sheetPos >= $len) {
        throw new RuntimeException('This .xls file has no worksheet to read.');
    }

    // ---- the sheet ----
    $cells = [];
    $pos = $sheetPos;
    $depth = 0;
    $lastFormula = null;
    while ($pos + 4 <= $len) {
        $id = unpack('v', substr($wb, $pos, 2))[1];
        $rl = unpack('v', substr($wb, $pos + 2, 2))[1];
        $d = substr($wb, $pos + 4, $rl);
        $pos += 4 + $rl;
        if ($id === 0x0809) {        // BOF
            $depth++;
            continue;
        }
        if ($id === 0x000A) {        // EOF
            if (--$depth <= 0) {
                break;
            }
            continue;
        }
        if ($depth !== 1) {
            continue;
        }
        switch ($id) {
            case 0x00FD: // LABELSST
                [$r, $c] = array_values(unpack('v2', $d));
                $cells[$r][$c] = $sst[unpack('V', substr($d, 6, 4))[1]] ?? '';
                break;
            case 0x0203: // NUMBER
                [$r, $c] = array_values(unpack('v2', $d));
                $cells[$r][$c] = xls_num(unpack('d', substr($d, 6, 8))[1]);
                break;
            case 0x027E: // RK
                [$r, $c] = array_values(unpack('v2', $d));
                $cells[$r][$c] = xls_num(xls_rk(unpack('V', substr($d, 6, 4))[1]));
                break;
            case 0x00BD: // MULRK: row, first col, then (xf, rk) pairs, last col
                $r = unpack('v', substr($d, 0, 2))[1];
                $c = unpack('v', substr($d, 2, 2))[1];
                $n = intdiv(strlen($d) - 6, 6);
                for ($i = 0; $i < $n; $i++) {
                    $cells[$r][$c + $i] = xls_num(xls_rk(unpack('V', substr($d, 4 + $i * 6 + 2, 4))[1]));
                }
                break;
            case 0x0204: // LABEL (text)
                [$r, $c] = array_values(unpack('v2', $d));
                $cells[$r][$c] = xls_ustr($d, 6)[0];
                break;
            case 0x00D6: // RSTRING
                [$r, $c] = array_values(unpack('v2', $d));
                $cells[$r][$c] = xls_ustr($d, 6)[0];
                break;
            case 0x0006: // FORMULA: the result is a number, or text that follows in a STRING record
                [$r, $c] = array_values(unpack('v2', $d));
                $res = substr($d, 6, 8);
                if (strlen($res) === 8 && substr($res, 6, 2) === "\xFF\xFF") {
                    $t = ord($res[0]);
                    if ($t === 0) {
                        $lastFormula = [$r, $c];
                    } elseif ($t === 1) {
                        $cells[$r][$c] = ord($res[2]) ? 'TRUE' : 'FALSE';
                    }
                } elseif (strlen($res) === 8) {
                    $cells[$r][$c] = xls_num(unpack('d', $res)[1]);
                }
                break;
            case 0x0207: // STRING: text result of the formula just before
                if ($lastFormula !== null) {
                    $cells[$lastFormula[0]][$lastFormula[1]] = xls_ustr($d, 0)[0];
                    $lastFormula = null;
                }
                break;
            case 0x0205: // BOOLERR
                [$r, $c] = array_values(unpack('v2', $d));
                if (ord($d[7] ?? "\0") === 0) {
                    $cells[$r][$c] = ord($d[6]) ? 'TRUE' : 'FALSE';
                }
                break;
        }
    }

    ksort($cells);
    $rows = [];
    foreach ($cells as $r => $row) {
        $max = max(array_keys($row));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = trim((string)($row[$i] ?? ''));
        }
        $rows[] = $line;
        if (count($rows) > XLS_MAX_ROWS) {
            break;
        }
    }
    return $rows;
}

/* ---------- files called .xls that are really text / HTML / XML ---------- */

function xls_read_text(string $d): array
{
    $d = preg_replace('/^\xEF\xBB\xBF/', '', $d);
    if (preg_match('/^\xFF\xFE/', $d) || preg_match('/^\xFE\xFF/', $d)) { // UTF-16 text
        $d = (string)mb_convert_encoding($d, 'UTF-8', 'UTF-16');
    }
    $head = strtolower(substr(ltrim($d), 0, 400));
    $rows = [];
    if (strpos($head, '<') === 0 && (strpos(strtolower($d), '<table') !== false || strpos(strtolower($d), '<row') !== false)) {
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $isXml = strpos($head, '<?xml') === 0 && strpos(strtolower($d), '<worksheet') !== false; // SpreadsheetML
        if ($isXml) {
            $doc->loadXML($d);
            foreach ($doc->getElementsByTagName('Row') as $tr) {
                $line = [];
                $col = 0;
                foreach ($tr->getElementsByTagName('Cell') as $td) {
                    $idx = $td->getAttribute('ss:Index');
                    if ($idx !== '') {
                        while ($col < (int)$idx - 1) {
                            $line[] = '';
                            $col++;
                        }
                    }
                    $line[] = trim($td->textContent);
                    $col++;
                }
                $rows[] = $line;
            }
        } else {
            $doc->loadHTML('<?xml encoding="UTF-8">' . $d);
            foreach ($doc->getElementsByTagName('tr') as $tr) {
                $line = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                        $line[] = trim(html_entity_decode($td->textContent, ENT_QUOTES, 'UTF-8'));
                    }
                }
                if ($line) {
                    $rows[] = $line;
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return array_slice($rows, 0, XLS_MAX_ROWS + 1);
    }
    // delimited text: tab, semicolon or comma, whichever the first line uses most
    $first = strtok($d, "\r\n");
    $best = ',';
    $bestN = -1;
    foreach (["\t", ';', ','] as $sep) {
        $n = substr_count((string)$first, $sep);
        if ($n > $bestN) {
            $best = $sep;
            $bestN = $n;
        }
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $d);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $best)) !== false) {
        $rows[] = array_map(static fn($v) => trim((string)$v), $r);
        if (count($rows) > XLS_MAX_ROWS) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}
