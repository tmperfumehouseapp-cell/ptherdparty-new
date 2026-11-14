/* New order page: product picker, totals, COD advance, profit and commission preview, Create button.
   Works with the markup of new_order.php and the data in window.ORDER_DATA:
   { products:[{id,name,party,base,hasBase}], stats:{userId:{count,profit}}, groups, selfId, isAdmin, old:[{product_id,qty,sell_price}] }
   The server recalculates everything again when the order is saved, so nothing typed here is trusted. */
(function () {
  'use strict';
  var D = window.ORDER_DATA || {};
  var products = D.products || [];
  var byId = {};
  products.forEach(function (p) { byId[p.id] = p; });
  var isAdmin = !!D.isAdmin;
  var $ = function (id) { return document.getElementById(id); };
  var form = $('order-form');
  var rowsEl = $('rows');
  var addBtn = $('add-row');
  if (!form || !rowsEl || !addBtn) { return; }

  /* ---------- helpers ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(v) { return parseFloat(String(v == null ? '' : v).replace(/[^0-9.]/g, '')) || 0; }
  function inr(n) {
    n = Math.round((+n || 0) * 100) / 100;
    var neg = n < 0; n = Math.abs(n);
    var parts = n.toFixed(2).split('.');
    var last3 = parts[0].slice(-3), rest = parts[0].slice(0, -3);
    if (rest) { rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ','; }
    return (neg ? '-' : '') + '₹' + rest + last3 + (parts[1] === '00' ? '' : '.' + parts[1]);
  }
  function setText(id, t) { var el = $(id); if (el) { el.textContent = t; } }

  /* ---------- styles for the parts this script draws ---------- */
  var css = document.createElement('style');
  css.textContent = [
    '.no-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px 16px;padding:14px;margin:0 0 12px;border:1px solid var(--line,#E2E8F0);border-radius:16px;background:var(--card,#fff)}',
    '.no-name{flex:1 1 220px;min-width:0}',
    '.no-name b{display:block;font-weight:800;word-break:break-word}',
    '.no-name span{display:block;font-size:12px;color:var(--muted,#64748B);font-weight:600}',
    '.no-name .pbuy{color:#0F766E}',
    '.no-f{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--muted,#64748B)}',
    '.no-f input{height:46px;border-radius:12px;border:1px solid var(--field-line,#CBD5E1);background:var(--field,#F8FAFC);padding:0 12px;font:inherit;font-size:16px;color:inherit}',
    '.no-qty input{width:84px;text-align:center}',
    '.no-sell input{width:130px}',
    '.no-fixed input{width:120px;background:#EEF1F5;font-weight:800;color:#0F766E;cursor:not-allowed;text-align:right}',
    '.no-line{min-width:92px;text-align:right;font-weight:800;font-variant-numeric:tabular-nums}',
    '.no-x{height:40px;border:0;background:transparent;color:#B91C1C;font-weight:800;cursor:pointer;padding:0 8px}',
    '.no-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:6vh 14px 14px;background:rgba(15,23,42,.55)}',
    '.no-modal[hidden]{display:none}',
    '.no-dialog{width:100%;max-width:560px;max-height:84vh;display:flex;flex-direction:column;background:var(--card,#fff);border-radius:20px;box-shadow:0 24px 60px rgba(15,23,42,.35);overflow:hidden}',
    '.no-dhead{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 18px 8px;font-weight:800;font-size:18px}',
    '.no-dhead button{border:0;background:transparent;font-size:26px;line-height:1;cursor:pointer;color:inherit}',
    '.no-search{margin:0 18px 10px;height:50px;border-radius:14px;border:1.5px solid var(--field-line,#CBD5E1);background:var(--field,#F8FAFC);padding:0 14px;font:inherit;font-size:16px;color:inherit}',
    '.no-list{overflow:auto;padding:0 10px 14px;-webkit-overflow-scrolling:touch}',
    '.no-item{display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;text-align:left;border:0;background:transparent;padding:12px 10px;border-radius:12px;cursor:pointer;font:inherit;color:inherit}',
    '.no-item:hover,.no-item:focus{background:var(--field,#F1F5F9);outline:none}',
    '.no-item b{display:block;font-weight:700}',
    '.no-item small{display:block;color:var(--muted,#64748B);font-weight:600}',
    '.no-item .no-buy{font-size:13px;font-weight:800;color:#0F766E;white-space:nowrap}',
    '.no-item .no-tag{font-size:12px;font-weight:800;color:#B45309;white-space:nowrap}',
    '.no-empty{padding:22px 12px;text-align:center;color:var(--muted,#64748B);font-weight:600}',
    '.no-stepper{display:contents}.no-step,.no-more,.no-progress,.no-next{display:none}',
    '.no-recent-h{display:none}',
    '@media (max-width:560px){.no-sell input{width:110px}.no-fixed input{width:100px}.no-modal{padding-top:3vh}}'
  ].join('\n');
  document.head.appendChild(css);

  /* ---------- phone view helpers ---------- */
  function isMobile() { return !!(window.matchMedia && window.matchMedia('(max-width: 760px)').matches); }
  function recentGet() { try { return JSON.parse(localStorage.getItem('no_recent') || '[]').filter(function (x) { return byId[x]; }); } catch (e) { return []; } }
  function recentAdd(pid) {
    try {
      var r = recentGet().filter(function (x) { return x !== pid; });
      r.unshift(pid);
      localStorage.setItem('no_recent', JSON.stringify(r.slice(0, 6)));
    } catch (e) { /* private mode: no recent list, nothing else changes */ }
  }

  /* ---------- rows ---------- */
  var rows = [];
  var seq = 0;

  function addRow(pid, qty, sell) {
    var p = byId[pid];
    if (!p) { return null; }
    var existing = rows.filter(function (r) { return r.pid === pid; })[0];
    if (existing && qty == null) { // same product again: one more piece
      existing.qty.value = (parseInt(existing.qty.value, 10) || 0) + 1;
      recalc();
      existing.sell.focus();
      return existing;
    }
    var key = seq++;
    var el = document.createElement('div');
    el.className = 'no-row';
    el.innerHTML =
      '<div class="no-name"><b>' + esc(p.name) + '</b>' +
      (p.party ? '<span>' + esc(p.party) + '</span>' : '') +
      (p.base != null ? '<span class="pbuy">Buying price ' + esc(inr(p.base)) + ' (fixed)</span>' : '') +
      '</div>' +
      '<label class="no-f no-qty">Qty<span class="no-stepper"><button type="button" class="no-step" data-d="-1" aria-label="One less">&minus;</button>' +
      '<input type="text" inputmode="numeric" name="items[' + key + '][qty]" value="' + esc(qty == null ? 1 : qty) + '" aria-label="Quantity">' +
      '<button type="button" class="no-step" data-d="1" aria-label="One more">+</button></span></label>' +
      '<label class="no-f no-fixed">Buying price (fixed)<input type="text" readonly tabindex="-1" aria-readonly="true" value="' + esc(p.base != null ? inr(p.base) : 'Not set') + '" aria-label="Buying price, fixed"></label>' +
      '<label class="no-f no-sell">Sell price (₹)<input type="text" inputmode="decimal" name="items[' + key + '][sell_price]" value="' + esc(sell == null ? '' : sell) + '" placeholder="per piece" aria-label="Sell price"></label>' +
      '<div class="no-line" aria-label="Line total">₹0</div>' +
      '<button type="button" class="no-x" aria-label="Remove ' + esc(p.name) + '">Remove</button>' +
      '<input type="hidden" name="items[' + key + '][product_id]" value="' + esc(pid) + '">';
    rowsEl.appendChild(el);
    var r = { key: key, pid: pid, el: el, qty: el.querySelector('.no-qty input'), sell: el.querySelector('.no-sell input'), line: el.querySelector('.no-line') };
    r.qty.addEventListener('input', recalc);
    el.querySelectorAll('.no-step').forEach(function (b) {
      b.addEventListener('click', function () {
        r.qty.value = Math.max(1, (parseInt(r.qty.value, 10) || 1) + parseInt(b.getAttribute('data-d'), 10));
        recalc();
      });
    });
    r.sell.addEventListener('input', recalc);
    el.querySelector('.no-x').addEventListener('click', function () {
      rows = rows.filter(function (x) { return x !== r; });
      el.remove();
      recalc();
    });
    rows.push(r);
    recalc();
    return r;
  }

  /* ---------- product picker (the dialog opened by "+ Add product") ---------- */
  var modal = null, searchEl = null, listEl = null;

  function buildModal() {
    modal = document.createElement('div');
    modal.className = 'no-modal';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="no-dialog" role="dialog" aria-modal="true" aria-label="Choose a product">' +
      '<div class="no-dhead"><span>Add product</span><button type="button" class="no-close" aria-label="Close">&times;</button></div>' +
      '<input class="no-search" type="search" placeholder="Search product name" autocomplete="off">' +
      '<div class="no-list"></div></div>';
    document.body.appendChild(modal);
    searchEl = modal.querySelector('.no-search');
    listEl = modal.querySelector('.no-list');
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) { closeModal(); } });
    modal.querySelector('.no-close').addEventListener('click', closeModal);
    searchEl.addEventListener('input', renderList);
    searchEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var first = listEl.querySelector('.no-item:not([disabled])');
        if (first) { first.click(); }
      }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal && !modal.hidden) { closeModal(); } });
  }

  function renderList() {
    var q = searchEl.value.trim().toLowerCase();
    var norm = function (t) { return String(t).toLowerCase().replace(/[\u2010-\u2015\-_,.()\/]+/g, ' '); };
    var words = norm(q).split(/\s+/).filter(Boolean);
    // every version (White Box, Tester Piece, Original Box...) is its own product, so all of them match and are listed separately
    var shown = products.filter(function (p) {
      var hay = norm(p.name + ' ' + (p.party || ''));
      return words.every(function (w) { return hay.indexOf(w) !== -1; });
    }).slice(0, 150);
    if (!products.length) {
      listEl.innerHTML = '<div class="no-empty">No products yet.</div>';
      return;
    }
    if (!shown.length) {
      listEl.innerHTML = '<div class="no-empty">No product matches "' + esc(searchEl.value) + '".</div>';
      return;
    }
    var itemHtml = function (p) {
      var blocked = !p.hasBase;
      return '<button type="button" class="no-item" data-id="' + p.id + '"' + (blocked ? ' disabled style="opacity:.55;cursor:not-allowed"' : '') + '>' +
        '<span><b>' + esc(p.name) + '</b>' + (p.party ? '<small>' + esc(p.party) + '</small>' : '') + '</span>' +
        (blocked ? '<span class="no-tag">No buying price</span>' : (p.base != null ? '<span class="no-buy">Buying ' + esc(inr(p.base)) + '</span>' : '')) +
        '</button>';
    };
    var html = '';
    if (isMobile() && !q) { // phones: the products used last come first
      var rec = recentGet().map(function (id) { return byId[id]; }).filter(Boolean);
      if (rec.length) { html += '<div class="no-recent-h">Recent</div>' + rec.map(itemHtml).join('') + '<div class="no-recent-h">All products</div>'; }
    }
    listEl.innerHTML = html + shown.map(itemHtml).join('');
  }

  function openModal() {
    if (!modal) { buildModal(); }
    searchEl.value = '';
    renderList();
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(function () { searchEl.focus(); }, 30);
  }
  function closeModal() {
    if (!modal) { return; }
    modal.hidden = true;
    document.body.style.overflow = '';
    addBtn.focus();
  }

  addBtn.addEventListener('click', function (e) { e.preventDefault(); openModal(); });
  document.addEventListener('click', function (e) {
    var it = e.target.closest ? e.target.closest('.no-item') : null;
    if (!it || !modal || modal.hidden || it.disabled) { return; }
    var pickedId = parseInt(it.getAttribute('data-id'), 10);
    var r = addRow(pickedId);
    recentAdd(pickedId);
    closeModal();
    if (r) {
      r.sell.focus();
      if (isMobile() && r.el.scrollIntoView) { setTimeout(function () { r.el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 60); }
    }
  });

  /* ---------- totals, profit, commission preview ---------- */
  var discountEl = $('discount');
  var advanceEl = $('advance_amount');
  var proofEl = $('pay_proof');
  var salesEl = $('salesperson_id');
  var btn = $('submit-btn');

  function isCod() { var c = $('pm-cod'); return !!(c && c.checked); }
  function statsFor(uid) {
    var s = D.stats || {};
    var v = s[uid] || s[String(uid)] || { count: 0 };
    return { count: +v.count || 0 };
  }

  function recalc() {
    var sub = 0, base = 0, qty = 0, valid = 0, baseKnown = true;
    rows.forEach(function (r) {
      var q = Math.max(0, parseInt(r.qty.value, 10) || 0);
      var s = num(r.sell.value);
      var line = q * s;
      r.line.textContent = inr(line);
      sub += line; qty += q;
      var p = byId[r.pid];
      if (p && p.base != null) { base += q * p.base; } else { baseKnown = false; }
      if (q >= 1 && s > 0) { valid++; }
    });
    sub = Math.round(sub * 100) / 100;
    var disc = Math.min(Math.max(discountEl ? num(discountEl.value) : 0, 0), sub);
    var total = Math.round((sub - disc) * 100) / 100;

    setText('sum-total', inr(total));
    setText('sum-items', qty + (qty === 1 ? ' item' : ' items'));
    setText('sum-sub', inr(sub));
    setText('sum-disc', '-' + inr(disc));

    var cod = isCod();
    var codBox = $('sum-cod');
    if (codBox) { codBox.style.display = cod ? '' : 'none'; }
    var adv = advanceEl ? num(advanceEl.value) : 0;
    setText('sum-adv', inr(cod ? adv : 0));
    setText('sum-bal', inr(cod ? Math.max(0, total - adv) : 0));

    // commission rate: 8%, or 10% once the day's orders reach the number of groups
    var uid = salesEl ? salesEl.value : D.selfId;
    var count = statsFor(uid).count;
    var groups = +D.groups || 0;
    var rateNow = (groups > 0 && count >= groups) ? 0.10 : 0.08;
    var rateThis = (groups > 0 && count + 1 >= groups) ? 0.10 : 0.08;
    if (isAdmin) {
      setText('sum-base', inr(base));
      var profit = total - base;
      setText('sum-profit', (rows.length && baseKnown && valid) ? inr(profit) : '-');
      setText('sum-earn-k', 'You earn (' + Math.round(rateThis * 100) + '%)');
      setText('sum-earn', (rows.length && baseKnown && valid) ? inr(Math.max(profit, 0) * rateThis) : '-');
    }
    setText('meter-rate', Math.round(rateNow * 100) + '%');
    setText('meter-orders', String(count));
    setText('meter-count', groups > 0 ? count + ' / ' + groups : count + ' today');
    var fill = $('meter-fill');
    if (fill) { fill.style.width = (groups > 0 ? Math.min(100, count / groups * 100) : 0) + '%'; }
    setText('meter-msg', groups <= 0 ? 'Add groups to unlock the 10% rate'
      : count >= groups ? "10% unlocked on today's orders"
      : (groups - count) + (groups - count === 1 ? ' more order unlocks' : ' more orders unlock') + ' 10%');

    refreshGate(valid, total, cod, adv);
  }

  /* ---------- what is still missing before Create order works ---------- */
  function missing(valid, total, cod, adv) {
    if (!proofEl || !proofEl.files || !proofEl.files.length) { return 'Attach the payment screenshot to create the order'; }
    if (!document.querySelector('input[name=account_id]')) { return 'Create an account first (Accounts page)'; }
    var rn = $('ref_notes'), ri = $('ref_image');
    if (rn && !rn.value.trim()) { return 'Add the reference notes to create the order'; }
    if (ri && (!ri.files || !ri.files.length)) { return 'Attach the reference image to create the order'; }
    if (!rows.length || !valid) { return 'Add a product with its quantity and sell price'; }
    if (cod) {
      if (adv <= 0) { return 'A COD order needs the advance the customer paid now'; }
      if (adv >= total) { return 'The advance must be less than the order total. For the full amount choose Prepaid'; }
    }
    return '';
  }
  var lastMissing = '';
  function refreshGate(valid, total, cod, adv) {
    lastMissing = missing(valid, total, cod, adv);
    var note = $('proof-note');
    if (note) { note.textContent = lastMissing; note.style.display = lastMissing ? '' : 'none'; }
    if (btn && !btn.getAttribute('data-busy')) { btn.disabled = lastMissing !== ''; }
  }

  /* ---------- payment type, screenshot preview ---------- */
  function syncMode() {
    var cod = isCod();
    var box = $('advance-box');
    if (box) { box.hidden = !cod; }
    setText('account-label', cod ? 'Advance received in' : 'Money received in');
    recalc();
  }
  ['pm-prepaid', 'pm-cod'].forEach(function (id) { var el = $(id); if (el) { el.addEventListener('change', syncMode); } });
  [discountEl, advanceEl].forEach(function (el) { if (el) { el.addEventListener('input', recalc); } });
  if (salesEl) { salesEl.addEventListener('change', recalc); }

  if (proofEl) {
    proofEl.addEventListener('change', function () {
      var img = $('proof-preview');
      var f = proofEl.files && proofEl.files[0];
      if (img) {
        if (f && /^image\//.test(f.type)) {
          img.src = URL.createObjectURL(f);
          img.hidden = false; img.style.display = 'block';
        } else {
          img.removeAttribute('src'); img.hidden = true; img.style.display = 'none';
        }
      }
      recalc();
    });
  }

  /* ---------- reference notes + image (both required) ---------- */
  var refNotes = $('ref_notes'), refImg = $('ref_image');
  if (refNotes) { refNotes.addEventListener('input', recalc); }
  if (refImg) {
    refImg.addEventListener('change', function () {
      var pv = $('ref-preview');
      var f = refImg.files && refImg.files[0];
      if (pv) {
        if (f && /^image\//.test(f.type)) { pv.src = URL.createObjectURL(f); pv.hidden = false; pv.style.display = 'block'; }
        else { pv.removeAttribute('src'); pv.hidden = true; pv.style.display = 'none'; }
      }
      recalc();
    });
  }

  /* ---------- submit ---------- */
  var errBox = $('form-err');
  function showErr(msg) {
    if (!errBox) { return; }
    errBox.textContent = msg;
    errBox.hidden = !msg;
  }
  form.addEventListener('submit', function (e) {
    recalc();
    if (lastMissing !== '') {
      e.preventDefault();
      showErr(lastMissing);
      return;
    }
    showErr('');
    if (btn) {
      btn.setAttribute('data-busy', '1');
      btn.textContent = 'Creating order… please wait';
      setTimeout(function () { btn.disabled = true; }, 0);
    }
  });

  /* ---------- phone view: next step, progress, bottom bar ---------- */
  var summary = document.querySelector('.summary');
  var nextEl = null, progEl = null;
  function digits(id) { var e = $(id); return e ? String(e.value).replace(/\D+/g, '') : ''; }
  function mobileSteps() {
    var cod = isCod();
    var list = [
      { ok: !!(proofEl && proofEl.files && proofEl.files.length), msg: 'Attach the payment screenshot', el: 'pay_proof' }
    ];
    if (cod) { list.push({ ok: num(advanceEl ? advanceEl.value : 0) > 0, msg: 'Enter the advance paid now', el: 'advance_amount' }); }
    list.push(
      { ok: !!($('customer_name') && $('customer_name').value.trim()), msg: 'Add the customer name', el: 'customer_name' },
      { ok: digits('customer_phone').length >= 10, msg: 'Add the customer WhatsApp number', el: 'customer_phone' },
      { ok: digits('pincode').length === 6, msg: 'Add the 6-digit PIN code', el: 'pincode' },
      { ok: !!($('address') && $('address').value.trim().length >= 8), msg: 'Add the full address', el: 'address' },
      { ok: rows.some(function (r) { return (parseInt(r.qty.value, 10) || 0) >= 1 && num(r.sell.value) > 0; }), msg: 'Add a product with its sell price', el: 'add-row' },
      { ok: !!($('ref_notes') && $('ref_notes').value.trim()), msg: 'Write the reference notes', el: 'ref_notes' },
      { ok: !!($('ref_image') && $('ref_image').files && $('ref_image').files.length), msg: 'Attach the reference image', el: 'ref_image' }
    );
    return list;
  }
  var nextTarget = null;
  function mobileUpdate() {
    if (!summary || !isMobile()) { return; }
    if (!nextEl) {
      nextEl = document.createElement('div'); nextEl.className = 'no-next';
      progEl = document.createElement('div'); progEl.className = 'no-progress';
      summary.insertBefore(progEl, summary.firstChild);
      summary.insertBefore(nextEl, summary.firstChild);
      nextEl.addEventListener('click', function () {
        var t = nextTarget && $(nextTarget);
        if (!t) { return; }
        t.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (t.focus && t.tagName !== 'BUTTON') { setTimeout(function () { try { t.focus({ preventScroll: true }); } catch (e) { t.focus(); } }, 350); }
      });
      var payBlock = summary.querySelector(':scope > div:not([class])');
      if (payBlock) { payBlock.addEventListener('click', function () { summary.classList.toggle('is-open'); measureBar(); }); }
      var pb = $('proof-box');
      var extra = [$('pay_proof2'), $('pay_proof3')].map(function (i) { return i && i.closest('.field'); }).filter(Boolean);
      if (pb && extra.length) {
        extra.forEach(function (f) { f.classList.add('no-extra'); });
        var more = document.createElement('button');
        more.type = 'button'; more.className = 'no-more'; more.textContent = '+ Add another screenshot';
        more.addEventListener('click', function () { pb.classList.add('show-more'); more.style.display = 'none'; });
        var hint = $('proof-hint');
        if (hint && hint.parentNode) { hint.parentNode.insertBefore(more, hint.nextSibling); }
      }
    }
    var st = mobileSteps();
    var done = st.filter(function (x) { return x.ok; }).length;
    var first = st.filter(function (x) { return !x.ok; })[0];
    summary.style.setProperty('--no-p', (done / st.length).toFixed(3));
    nextTarget = first ? first.el : null;
    nextEl.textContent = first ? '👉 ' + first.msg + '  (' + done + '/' + st.length + ')' : '✅ All set. Tap Create order';
    nextEl.classList.toggle('is-ready', !first);
    measureBar();
  }
  // keep the content clear of the bar, and above a fixed bottom menu if the panel has one
  function measureBar() {
    var root = document.documentElement;
    if (!summary || !isMobile()) { root.style.removeProperty('--no-bar-h'); root.style.removeProperty('--no-bar-b'); return; }
    var below = 0;
    var side = document.querySelector('.side');
    if (side) {
      var cs = getComputedStyle(side), r = side.getBoundingClientRect();
      if (cs.position === 'fixed' && r.top > window.innerHeight / 2) { below = Math.max(0, Math.round(window.innerHeight - r.top)); }
    }
    root.style.setProperty('--no-bar-b', below + 'px');
    root.style.setProperty('--no-bar-h', Math.round(summary.getBoundingClientRect().height) + 'px');
  }
  document.body.classList.add('no-page');
  ['input', 'change', 'click'].forEach(function (ev) { form.addEventListener(ev, function () { setTimeout(mobileUpdate, 0); }); });
  document.addEventListener('click', function () { setTimeout(mobileUpdate, 0); });
  window.addEventListener('resize', mobileUpdate);
  window.addEventListener('orientationchange', function () { setTimeout(mobileUpdate, 200); });

  /* ---------- start ---------- */
  (D.old || []).forEach(function (o) { addRow(parseInt(o.product_id, 10), o.qty, o.sell_price); });
  syncMode();
  mobileUpdate();
})();
