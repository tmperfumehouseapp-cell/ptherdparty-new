<?php
// Shared pieces of the "Upload delivered list" and "Upload COD from Excel" result pages.
// Goal: drop the whole Shiprocket report in and see at once how many parcels are done, how many are not found in the panel, and which orders have no Shiprocket Order ID.

/** Big plain numbers. $cards = [[icon, label, value, sub, colour (good|bad|warn|'')], ...] */
function ui_big_numbers(array $cards): string
{
    $col = ['good' => '#15803D', 'bad' => '#B91C1C', 'warn' => '#B45309', '' => 'inherit'];
    $h = '<div class="stats" style="margin-bottom:14px">';
    foreach ($cards as [$icon, $label, $value, $sub, $c]) {
        $h .= '<div class="stat"><div class="k">' . $icon . ' ' . e($label) . '</div><div class="v" style="font-size:34px;color:' . ($col[$c] ?? 'inherit') . '">' . e((string)$value) . '</div>'
            . ($sub !== '' ? '<div class="mini">' . e($sub) . '</div>' : '') . '</div>';
    }
    return $h . '</div>';
}

/** One plain sentence under the numbers. */
function ui_sentence(string $text): string
{
    return '<p style="margin:0 0 14px;font-size:16px;font-weight:700">' . e($text) . '</p>';
}

/**
 * The report rows that no order matches, each with "maybe this order" buttons.
 * $mode: 'delivered' (the one-click fix writes the Shiprocket Order ID and marks the order delivered)
 *        'cod'       (it also saves the COD money of the row into account $accId)
 */
function ui_unmatched_table(array $results, string $mode, int $accId = 0): string
{
    $rows = array_values(array_filter($results, static fn($r) => ($r['kind'] ?? '') === 'unmatched'));
    if (!$rows) {
        return '';
    }
    $cod = $mode === 'cod';
    $h = '<h3 style="margin:0 0 4px;color:#B91C1C">Not found in the panel (' . count($rows) . ')</h3>'
       . '<p class="hint" style="margin:0 0 8px">These parcels are in the report, but no order in the panel has their Shiprocket Order ID. The ID may be missing or mistyped on the order. '
       . 'Where the panel can see a likely order, press <b>Use this order</b>: it writes the report\'s Shiprocket Order ID on that order'
       . ($cod ? ', saves the COD money' : '') . ' and marks it delivered.</p>'
       . '<div class="table-wrap" style="margin-bottom:16px"><table class="tight" style="min-width:640px"><thead><tr><th>Row</th><th>Shiprocket Order ID (report)</th><th>AWB</th><th>Delivered</th>'
       . ($cod ? '<th class="num">Amount</th>' : '') . '<th>Maybe this order</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $d = $r['delivered'] ?? ($r['date'] ?? null);
        $h .= '<tr><td>' . (int)$r['row'] . '</td><td><b>' . e($r['srid'] !== '' ? $r['srid'] : '-') . '</b>' . (!empty($r['name']) ? '<div class="mini">' . e(trim($r['name'])) . '</div>' : '') . '</td>'
            . '<td>' . e($r['awb'] !== '' ? $r['awb'] : '-') . '</td><td>' . ($d ? e(date('d M Y', strtotime($d))) : '-') . '</td>'
            . ($cod ? '<td class="num">' . e(inr($r['amount'] ?? 0)) . '</td>' : '') . '<td class="lk-cell">';
        if (empty($r['suggest']) || $r['srid'] === '') {
            $h .= '<span class="mini">No similar order found. Check the order in the panel, or ignore it if it is not from this panel.</span>';
        }
        foreach ($r['suggest'] ?? [] as $sg) {
            $o = $sg['order'];
            $h .= '<form method="post" class="lk-form" style="margin:0 0 8px;padding:8px 10px;border:1px solid var(--line,#E2E8F0);border-radius:10px">'
                . csrf_field()
                . '<input type="hidden" name="action" value="link"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '">'
                . '<input type="hidden" name="srid" value="' . e($r['srid']) . '"><input type="hidden" name="awb" value="' . e($r['awb']) . '">'
                . '<input type="hidden" name="delivered" value="' . e((string)($d ?? '')) . '">'
                . ($cod ? '<input type="hidden" name="amount" value="' . e((string)$r['amount']) . '"><input type="hidden" name="remit" value="' . e((string)($r['date'] ?? '')) . '"><input type="hidden" name="account_id" value="' . (int)$accId . '">' : '')
                . '<div><b>' . e(order_no($o['id'])) . '</b> · ' . e($o['customer_name']) . ' · ' . e(date('d M', strtotime($o['order_date']))) . ' · ' . e(inr($o['total']))
                . ' · <span class="tag ' . e(status_tag_class($o['status'])) . '">' . e(status_label($o['status'])) . '</span></div>'
                . '<div class="mini">Because: ' . e($sg['why']) . '</div>'
                . '<button class="btn btn-accent btn-sm" type="submit" style="margin-top:6px">Use ' . e(order_no($o['id'])) . '</button></form>';
        }
        $h .= '</td></tr>';
    }
    return $h . '</tbody></table></div>';
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

/** The "Use this order" buttons save in the background and change only their cell. */
function ui_link_script(): string
{
    return '<script>
(function () {
  document.addEventListener("submit", function (ev) {
    var f = ev.target;
    if (!f || !f.classList || !f.classList.contains("lk-form") || !window.fetch) { return; }
    ev.preventDefault();
    var cell = f.closest(".lk-cell"), btns = f.querySelectorAll("button");
    btns.forEach(function (b) { b.disabled = true; });
    var fd = new FormData(f); fd.set("ajax", "1");
    fetch(location.pathname, { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { btns.forEach(function (b) { b.disabled = false; }); window.alert(d.msg || "Could not save."); return; }
        cell.innerHTML = "<span class=\"tag tag-ok\">&#10003; Done</span> <span class=\"mini\"></span>";
        cell.querySelector(".mini").textContent = d.msg;
      })
      .catch(function () { btns.forEach(function (b) { b.disabled = false; }); window.alert("Could not save. Check your internet and try again."); });
  });
})();
</script>';
}
