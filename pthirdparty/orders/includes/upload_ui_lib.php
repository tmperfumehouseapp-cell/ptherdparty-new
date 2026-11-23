<?php
// Shared pieces of the "Upload delivered list" and "Upload COD from Excel" result pages.

/** The rows of the report that no order of the panel matches (kind = unmatched). */
function ui_unmatched_table(array $results, bool $withAmount): string
{
    $rows = array_values(array_filter($results, static fn($r) => ($r['kind'] ?? '') === 'unmatched'));
    if (!$rows) {
        return '';
    }
    $h = '<h3 style="margin:0 0 8px">Unmatched: in the report, but no order in the panel has this Shiprocket Order ID or AWB (' . count($rows) . ')</h3>'
       . '<div class="table-wrap" style="margin-bottom:16px"><table class="tight" style="min-width:520px"><thead><tr><th>Row</th><th>Shiprocket Order ID</th><th>AWB</th><th>Delivered</th>'
       . ($withAmount ? '<th class="num">Amount</th>' : '') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $d = $r['delivered'] ?? ($r['date'] ?? null);
        $h .= '<tr><td>' . (int)$r['row'] . '</td><td><b>' . e($r['srid'] !== '' ? $r['srid'] : '-') . '</b></td><td>' . e($r['awb'] !== '' ? $r['awb'] : '-') . '</td>'
            . '<td>' . ($d ? e(date('d M Y', strtotime($d))) : '-') . '</td>'
            . ($withAmount ? '<td class="num">' . e(inr($r['amount'] ?? 0)) . '</td>' : '') . '</tr>';
    }
    return $h . '</tbody></table></div>'
        . '<p class="hint" style="margin:-8px 0 16px">To match these: open the order in the panel and type its Shiprocket Order ID, then upload the report again. Nothing is counted twice.</p>';
}

/** Orders that have no Shiprocket Order ID saved (so no report can match them). $d = dl_orders_without_sr(). */
function ui_without_sr_card(?array $d, string $title, string $note): string
{
    if ($d === null || $d['count'] === 0) {
        return '';
    }
    $h = '<section class="card" style="margin-top:16px"><h2>' . e($title) . ' <span class="tag tag-warn">' . (int)$d['count'] . '</span></h2>'
       . '<p class="hint" style="margin:-6px 0 10px">' . e($note) . '</p>'
       . '<div class="table-wrap"><table class="tight" style="min-width:520px"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>';
    foreach ($d['rows'] as $o) {
        $h .= '<tr><td><a class="rowlink" href="order_view.php?id=' . (int)$o['id'] . '">' . e(order_no($o['id'])) . '</a></td><td>' . e(date('d M', strtotime($o['order_date']))) . '</td>'
            . '<td>' . e($o['customer_name']) . '</td><td><span class="tag ' . e(status_tag_class($o['status'])) . '">' . e(status_label($o['status'])) . '</span></td>'
            . '<td class="num">' . e(inr($o['total'])) . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
    if ($d['count'] > count($d['rows'])) {
        $h .= '<div class="hint">Showing the newest ' . count($d['rows']) . ' of ' . (int)$d['count'] . '.</div>';
    }
    return $h . '</section>';
}
