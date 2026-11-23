<?php
// The Refund cell of one order row on the Orders page (manager only). Used by the page and by the in-place update after a click.

/** $left = what is still refundable on the order. */
function order_refund_cell_html(int $id, string $status, float $left, string $qs): string
{
    if ($status === 'refunded') {
        return '<span class="tag tag-bad">&#8617; Refunded</span>';
    }
    if ($left <= 0.004) {
        return '<span class="mini">-</span>';
    }
    return '<details class="rf-box"><summary class="btn btn-ghost btn-sm" style="cursor:pointer">Refund</summary>'
        . '<form method="post" class="rf-form" data-left="' . e(inr($left)) . '" style="margin-top:6px;display:flex;flex-direction:column;gap:6px;min-width:190px">'
        . csrf_field()
        . '<input type="hidden" name="action" value="refund_full">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="qs" value="' . e($qs) . '">'
        . '<input type="text" name="note" maxlength="200" placeholder="Reason (optional)">'
        . '<button class="btn btn-sm" type="submit" style="background:#B91C1C;border-color:#B91C1C;color:#fff">Refund full ' . e(inr($left)) . '</button>'
        . '<div class="mini">Goes back out of the same accounts the money came into. For a part refund, open the order.</div>'
        . '</form></details>';
}
