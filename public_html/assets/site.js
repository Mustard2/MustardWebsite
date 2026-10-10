// Site behaviour. Each part only runs on pages that have the matching elements.

// ── Header: show the account button (name) / Admin / Log out when logged in ──────────────
(async () => {
  const box = document.querySelector('[data-account]');
  if (!box) return;
  try {
    // unique address every time: even a CDN rule that caches everything can't hand out someone else's answer
    const quotaBox = document.querySelector('[data-dl-quota]');
    const item = (quotaBox ? `&item=${encodeURIComponent(quotaBox.dataset.dlQuota)}` : '') + (document.querySelector('[data-card], [data-ocard]') ? '&owned=1' : '');
    const r = await fetch(`/auth/status.php?t=${Date.now()}${item}`, { credentials: 'same-origin', cache: 'no-store' });
    if (!r.ok) return;
    const s = await r.json();
    if (!s.loggedIn) return;
    // admin shortcuts first, so nothing below can stop them from appearing
    if (s.admin) document.querySelectorAll('[data-admin-only]').forEach((n) => (n.hidden = false));
    const ret = encodeURIComponent(location.pathname);
    box.innerHTML = '';
    // My account: a button with the person's name (shaped like the Admin one; mustard outline for paying patrons)
    const who = document.createElement('a');
    who.className = 'btn btn-sm btn-who' + (s.access ? ' ok' : '');
    who.href = '/account/';
    who.title = s.access ? 'My account · Patron' : 'My account';
    const nm = document.createElement('span');
    nm.className = 'who-name';
    nm.textContent = s.name;
    who.append(nm);
    // a dot when something they downloaded has a newer version they haven't seen yet on My account (browser storage
    // m3d_seen_updates: the codes "kind:slug@version" already seen; listed in privacy.php)
    let seen = [];
    try { seen = JSON.parse(localStorage.getItem('m3d_seen_updates') || '[]'); } catch { /* no storage: always shown */ }
    const fresh = (Array.isArray(s.updates) ? s.updates : []).filter((c) => !seen.includes(c));
    if (fresh.length && !document.querySelector('[data-updates-seen]')) {
      const dot = document.createElement('span');
      dot.className = 'who-dot';
      who.append(dot);
      who.title += ` · ${fresh.length} new update${fresh.length === 1 ? '' : 's'} for what you downloaded`;
      who.setAttribute('aria-label', who.title);
    }
    // unlocks left under the download limits (only when rules apply to this patron): a person icon (models) and a
    // hanger icon (accessories), each with its number; red when none are left. The title says it in words.
    if (s.left) {
      const L = s.left;
      const ICONS = {
        models: '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>',
        outfits: '<path d="M9.8 6.3a2.2 2.2 0 1 1 3 2.05c-.5.2-.8.6-.8 1.1v.55"/><path d="M12 10 3.6 16.1c-.7.5-.35 1.6.5 1.6h15.8c.85 0 1.2-1.1.5-1.6L12 10z"/>',
      };
      const parts = [['models', 'model'], ['outfits', 'accessory']].filter(([k]) => L[k] !== null);
      const c = document.createElement('a');
      c.className = 'dl-left';
      c.href = '/account/';
      parts.forEach(([k]) => {
        const n = document.createElement('span');
        n.className = 'dl-k' + (L[k] === 0 ? ' is-out' : '');
        n.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">${ICONS[k]}</svg>`;
        n.append(document.createTextNode(String(L[k])));
        c.append(n);
      });
      c.title = parts.map(([k, one]) => `${L[k]} ${one} unlock${L[k] === 1 ? '' : 's'} left`).join(' · ') + (L.next ? ` · next on ${L.next}` : '') + ' · See your unlock limits';
      c.setAttribute('aria-label', c.title);
      box.append(c);
    }
    box.append(who);   // after the unlocks counter
    // download boxes: tick the extra requirements this patron meets (the server checks again on download)
    try { document.querySelectorAll('[data-reqs]').forEach((list) => {
      if (!s.access || !(s.reqPass || s.known)) return;
      const money = list.querySelector('[data-req="total"]');
      const months = list.querySelector('[data-req="months"]');
      // "1/4", "€50/€100": what the patron has / what is needed (people who skip the requirements just get the tick)
      const mark = (li, ok, you) => { li.classList.add(ok ? 'ok' : 'no'); if (!s.reqPass) li.querySelector('.req-val').textContent = `${you}/${li.dataset.need}`; };
      if (months) mark(months, s.reqPass || (s.months ?? 0) >= +list.dataset.reqMonths, s.months ?? 0);
      if (money) mark(money, s.reqPass || s.totalCents >= +list.dataset.reqTotal, s.totalLabel);
    }); } catch { /* ticks are a nicety */ }
    // download box: one line with what the download limits leave for this item (only when rules apply; the server checks
    // again): "0/1 left" from the tightest rule, or Unlocked / Free
    // "Check your status" (My account) next to the download box title, for anyone logged in; not for items that never count
    document.querySelectorAll('[data-dl-status]').forEach((a) => (a.hidden = !!s.quota?.free));
    try { if (quotaBox && s.quota) {
      const q = s.quota;
      const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; };
      const tight = q.lines.reduce((m, l) => (!m || l.left < m.left ? l : m), null);
      // same look as the requirement lines below it: ✓/✗ in front (CSS), the number in colour, then the words
      const good = q.free || q.owned || !!(tight && tight.left);
      const line = el('p', 'quota-line ' + (good ? 'ok' : 'no'));   // met = green, not met = red, whatever the requirements say
      if (q.free || q.owned) line.append(el('b', '', q.free ? 'Free' : 'Unlocked'));
      else if (tight) line.append(el('b', '', `${tight.left}/${tight.max}`), document.createTextNode(` Unlock${tight.left === 1 ? '' : 's'} left`));
      line.title = q.free ? 'Free: this one doesn’t use any of your unlocks' : q.owned ? 'You downloaded it before: downloading it again is free'
        : q.lines.map((l) => `${l.left} of ${l.max} left · ${l.rule}`).join('\n') + (q.next && !q.ok ? `\nAvailable again from ${q.next}` : '');
      quotaBox.append(line);
      if (!q.free && !q.owned && !q.ok && !q.blocked) quotaBox.closest('.box')?.querySelectorAll('.dl-list .dl').forEach((d) => d.classList.add('is-limited'));
      quotaBox.hidden = false;
    } } catch { /* the server checks anyway */ }
    // catalogs: a check on what this patron already downloaded or never counts (free to download) and the Unlocked filter,
    // only when download limits apply to them (s.left)
    try { if (s.left?.owned) {
      const check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      const mark = (sel, kind, holder) => document.querySelectorAll(sel).forEach((c) => {
        const free = (s.left.free?.[kind] || []).includes(c.dataset.slug);   // never counted in the limits
        const own = free || s.left.owned[kind].includes(c.dataset.slug);
        c.dataset.owned = own ? '1' : '0';
        if (!own) return;
        const b = document.createElement('span'); b.className = 'owned-check'; b.innerHTML = check;
        b.title = free ? 'Free: doesn’t use any of your unlocks, download it any time' : 'Unlocked: you downloaded it, downloading it again is free';
        (c.querySelector(holder) || c).append(b);
      });
      mark('[data-card]', 'models', '.cover, .ocard-img');
      mark('[data-ocard]', 'outfits', '.ocard-img');
      document.querySelectorAll('[data-owned-row]').forEach((n) => (n.hidden = false));
    } } catch { /* a nicety */ }
    if (s.admin) {
      const a = document.createElement('a');
      a.className = 'btn btn-primary btn-sm';
      a.href = '/admin/';
      a.textContent = 'Admin';
      box.append(a);
    }
    const out = document.createElement('a');
    out.className = 'btn btn-ghost btn-sm';
    out.href = `/auth/logout.php?return=${ret}`;
    out.textContent = 'Log out';
    box.append(out);
  } catch { /* status endpoint not available */ }
})();

// ── Homepage featured slideshow: arrows, dots, swipe, ← → keys, gentle auto-advance ──
(() => {
  const root = document.querySelector('[data-hero]');
  const slides = root ? [...root.querySelectorAll('[data-hero-slide]')] : [];
  if (slides.length < 2) return;
  const dots = [...root.querySelectorAll('[data-hero-dot]')];
  let cur = 0;
  let timer = null;
  const show = (i) => {
    cur = (i + slides.length) % slides.length;
    root.classList.add('is-animated');   // fade only when switching, not on page load
    slides.forEach((s, k) => (s.hidden = k !== cur));
    dots.forEach((d, k) => d.setAttribute('aria-current', String(k === cur)));
    // load the next picture early so it is ready when it slides in
    slides[(cur + 1) % slides.length].querySelector('img')?.setAttribute('loading', 'eager');
  };
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const start = () => { if (!reduced) { clearInterval(timer); timer = setInterval(() => show(cur + 1), 7000); } };
  const stop = () => clearInterval(timer);
  root.querySelector('[data-hero-prev]').addEventListener('click', () => { show(cur - 1); start(); });
  root.querySelector('[data-hero-next]').addEventListener('click', () => { show(cur + 1); start(); });
  dots.forEach((d, k) => d.addEventListener('click', () => { show(k); start(); }));
  root.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') { show(cur - 1); start(); }
    if (e.key === 'ArrowRight') { show(cur + 1); start(); }
  });
  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', start);
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', start);
  let x0 = null;
  root.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; stop(); }, { passive: true });
  root.addEventListener('touchend', (e) => {
    if (x0 !== null) { const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) show(cur + (dx < 0 ? 1 : -1)); }
    x0 = null; start();
  });
  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
  start();
})();

// ── Catalog pages (homepage, Accessories): 25 cards per page after search, filters and sort; ‹ 1 2 … 9 › under the grid,
// ?page= in the address so a link or Back keeps the page. onPage() re-runs the catalog's apply(true) ──
function catalogPager(pager, top, onPage) {
  const PER = 25;
  let page = Math.max(1, parseInt(new URLSearchParams(location.search).get('page'), 10) || 1);
  const render = (pages) => {
    if (!pager) return;
    pager.hidden = pages < 2;
    pager.replaceChildren();
    if (pages < 2) return;
    const btn = (label, n, aria) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'chip';
      b.textContent = label;
      b.setAttribute('aria-label', aria);
      if (n === page && label === String(n)) b.setAttribute('aria-current', 'page');
      if (n < 1 || n > pages) b.disabled = true;
      else b.addEventListener('click', () => {
        page = n;
        onPage();
        top.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      pager.append(b);
    };
    btn('‹', page - 1, 'Previous page');
    let last = 0;
    for (let n = 1; n <= pages; n++) {   // first, last and the pages around the current one
      if (n !== 1 && n !== pages && Math.abs(n - page) > 1) continue;
      if (n - last > 1) pager.append(Object.assign(document.createElement('span'), { className: 'pager-gap', textContent: '…' }));
      btn(String(n), n, `Page ${n}`);
      last = n;
    }
    btn('›', page + 1, 'Next page');
  };
  return {
    reset() { page = 1; },
    // all = every card, match = the ones that pass search + filters, sorted: shows this page of them, hides the rest
    show(all, match) {
      const pages = Math.max(1, Math.ceil(match.length / PER));
      page = Math.min(page, pages);
      const on = new Set(match.slice((page - 1) * PER, page * PER));
      all.forEach((c) => (c.hidden = !on.has(c)));
      render(pages);
    },
    url(u) { page > 1 ? u.searchParams.set('page', page) : u.searchParams.delete('page'); },
  };
}

// ── Homepage catalog: search, filters, sort ───────────────────────────────────
(() => {
  const grid = document.querySelector('[data-grid]');
  if (!grid || !document.querySelector('[data-catalog]')) return;
  const cards = [...grid.querySelectorAll('[data-card]')];
  const search = document.querySelector('[data-search]');
  // button rows: franchise, status, sort one at a time; generation several at once (All = none). [data-default] = no filter
  const picks = [...document.querySelectorAll('.chip[data-pick]')];
  const sel = { franchise: '', generation: [], status: '', sort: 'order', owned: '' };
  // Filters button: opens the panel (status, sort, franchise, generation); shows how many filters are active
  const toggle = document.querySelector('[data-filter-toggle]');
  const panel = document.querySelector('[data-filter-panel]');
  const badge = document.querySelector('[data-filter-count]');
  toggle?.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    toggle.setAttribute('aria-expanded', String(!panel.hidden));
  });
  const count = document.querySelector('[data-count]');
  const empty = document.querySelector('[data-empty]');

  const pages = catalogPager(document.querySelector('[data-pager]'), document.getElementById('catalog'), () => apply(true));

  function apply(keepPage = false) {
    if (!keepPage) pages.reset();
    const q = search.value.trim().toLowerCase();
    const by = {
      order: (a, b) => +a.dataset.order - +b.dataset.order,
      name: (a, b) => a.dataset.name.localeCompare(b.dataset.name),
      gen: (a, b) => (+b.dataset.generation || 0) - (+a.dataset.generation || 0) || +a.dataset.order - +b.dataset.order,
      likes: (a, b) => (+b.dataset.likes || 0) - (+a.dataset.likes || 0) || +a.dataset.order - +b.dataset.order,
      downloads: (a, b) => (+b.dataset.downloads || 0) - (+a.dataset.downloads || 0) || +a.dataset.order - +b.dataset.order,
    };
    const sorted = [...cards].sort(by[sel.sort] || by.order);
    sorted.forEach((c) => grid.append(c));
    const match = sorted.filter((c) => {
      const d = c.dataset;
      return (!q || d.name.includes(q) || d.franchise.toLowerCase().includes(q)) &&
        (!sel.franchise || d.franchise === sel.franchise) &&
        (!sel.generation.length || sel.generation.includes(d.generation.trim() || '~other')) &&
        (!sel.status || d.status === sel.status) &&
        (!sel.owned || (sel.owned === 'yes') === (d.owned === '1'));   // Unlocked row (only shown when download limits apply)
    });
    pages.show(cards, match);
    count.textContent = `${match.length} of ${cards.length} models`;
    if (badge) {
      const active = [sel.franchise, sel.generation.length, sel.status, sel.sort !== 'order', sel.owned].filter(Boolean).length;
      badge.textContent = active;
      badge.hidden = !active;
    }
    empty.hidden = match.length > 0;
    const u = new URL(location.href);
    pages.url(u);
    if (u.href !== location.href) history.replaceState(null, '', u);
  }

  const pick = (chip) => {
    const row = picks.filter((c) => c.dataset.pick === chip.dataset.pick);
    if (Array.isArray(sel[chip.dataset.pick])) {   // several at once: toggle; All (the default) clears, none left = All
      const on = 'default' in chip.dataset ? [] : chip.getAttribute('aria-pressed') === 'true'
        ? sel[chip.dataset.pick].filter((v) => v !== chip.dataset.value) : [...sel[chip.dataset.pick], chip.dataset.value];
      sel[chip.dataset.pick] = on;
      row.forEach((c) => c.setAttribute('aria-pressed', String('default' in c.dataset ? !on.length : on.includes(c.dataset.value))));
    } else {
      sel[chip.dataset.pick] = chip.dataset.value;
      row.forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
    }
    apply();
  };
  picks.forEach((chip) => chip.addEventListener('click', () => pick(chip)));
  search.addEventListener('input', () => apply());
  document.querySelector('[data-reset]').addEventListener('click', () => {
    search.value = '';
    picks.filter((c) => 'default' in c.dataset).forEach(pick);
  });
  apply(true);   // first page (or the ?page= one)
})();

// ── Image viewer: any [data-slide] inside a [data-slides] set opens it; ← → keys, buttons or swipe ──
(() => {
  const dlg = document.querySelector('[data-lightbox]');
  if (!dlg) return;
  const img = dlg.querySelector('[data-lightbox-img]');
  const cap = dlg.querySelector('[data-lightbox-cap]');
  const count = dlg.querySelector('[data-lightbox-count]');
  const navs = dlg.querySelectorAll('.lb-nav');
  let slides = [];
  let cur = 0;
  const show = (i) => {
    cur = (i + slides.length) % slides.length;
    img.src = slides[cur].src;
    cap.textContent = slides[cur].caption || '';
    count.textContent = slides.length > 1 ? `${cur + 1} / ${slides.length}` : '';
    [cur + 1, cur - 1].forEach((n) => { const s = slides[(n + slides.length) % slides.length]; if (s) new Image().src = s.src; });
  };
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-slide]');
    const set = t?.closest('[data-slides]');
    if (!t || !set) return;
    e.preventDefault();
    slides = JSON.parse(set.dataset.slides || '[]');
    if (!slides.length) return;
    navs.forEach((n) => (n.hidden = slides.length < 2));
    show(+t.dataset.slide || 0);
    dlg.showModal();
  });
  dlg.querySelector('[data-lb-prev]').addEventListener('click', (e) => { e.stopPropagation(); show(cur - 1); });
  dlg.querySelector('[data-lb-next]').addEventListener('click', (e) => { e.stopPropagation(); show(cur + 1); });
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg || e.target.dataset.lightboxClose !== undefined) dlg.close();
  });
  dlg.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') show(cur - 1);
    if (e.key === 'ArrowRight') show(cur + 1);
  });
  let x0 = null;
  dlg.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
  dlg.addEventListener('touchend', (e) => {
    if (x0 === null || slides.length < 2) return;
    const dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 40) show(cur + (dx < 0 ? 1 : -1));
    x0 = null;
  });
})();

// ── Accessories catalog: search + Filters button (sort, type, unlocked, model; one at a time per row) ──
(() => {
  const grid = document.querySelector('[data-ogrid]');
  if (!grid) return;
  const cards = [...grid.querySelectorAll('[data-ocard]')];
  const search = document.querySelector('[data-osearch]');
  const picks = [...document.querySelectorAll('.chip[data-pick]')];   // [data-default] = no filter
  const sel = { sort: 'order', type: '', owned: '', model: '' };
  const toggle = document.querySelector('[data-filter-toggle]');
  const panel = document.querySelector('[data-filter-panel]');
  const badge = document.querySelector('[data-filter-count]');
  toggle?.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    toggle.setAttribute('aria-expanded', String(!panel.hidden));
  });
  const num = (c, k) => +c.dataset[k] || 0;
  const orders = {
    order: (a, b) => num(a, 'order') - num(b, 'order'),
    name: (a, b) => a.dataset.name.localeCompare(b.dataset.name),
    likes: (a, b) => num(b, 'likes') - num(a, 'likes') || num(a, 'order') - num(b, 'order'),
    downloads: (a, b) => num(b, 'downloads') - num(a, 'downloads') || num(a, 'order') - num(b, 'order'),
  };
  const count = document.querySelector('[data-ocount]');
  const empty = document.querySelector('[data-oempty]');
  const pages = catalogPager(document.querySelector('[data-opager]'), grid.closest('section') || grid, () => apply(true));
  const apply = (keepPage = false) => {
    if (!keepPage) pages.reset();
    const q = search.value.trim().toLowerCase();
    const sorted = [...cards].sort(orders[sel.sort] || orders.order);
    sorted.forEach((c) => grid.append(c));
    const match = sorted.filter((c) => (!q || c.dataset.name.includes(q)) && (!sel.model || c.dataset.models.split(' ').includes(sel.model)) && (!sel.type || c.dataset.type === sel.type)
      && (!sel.owned || (sel.owned === 'yes') === (c.dataset.owned === '1')));   // Unlocked row (only shown when download limits apply)
    pages.show(cards, match);
    const n = match.length;
    const word = (k) => (k === 1 ? 'accessory' : 'accessories');
    count.textContent = n === cards.length ? `${cards.length} ${word(cards.length)}` : `${n} of ${cards.length} ${word(cards.length)}`;
    empty.hidden = n > 0;
    if (badge) {
      const active = [sel.model, sel.type, sel.owned, sel.sort !== 'order'].filter(Boolean).length;
      badge.textContent = active;
      badge.hidden = !active;
    }
    const u = new URL(location.href);
    sel.model ? u.searchParams.set('model', sel.model) : u.searchParams.delete('model');
    sel.type ? u.searchParams.set('type', sel.type) : u.searchParams.delete('type');
    sel.sort !== 'order' ? u.searchParams.set('sort', sel.sort) : u.searchParams.delete('sort');
    pages.url(u);
    history.replaceState(null, '', u);
  };
  const pick = (chip, run = true) => {
    sel[chip.dataset.pick] = chip.dataset.value;
    picks.filter((c) => c.dataset.pick === chip.dataset.pick).forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
    if (run) apply();
  };
  picks.forEach((chip) => chip.addEventListener('click', () => pick(chip)));
  search.addEventListener('input', () => apply());
  document.querySelector('[data-oreset]')?.addEventListener('click', () => {
    search.value = '';
    picks.filter((c) => 'default' in c.dataset).forEach((c) => pick(c, false));
    apply();
  });
  // the address can ask for a model (?model=), a type (?type=) and an order (?sort=)
  const asked = new URL(location.href).searchParams;
  picks.filter((c) => (c.dataset.pick === 'model' && c.dataset.value === asked.get('model'))
    || (c.dataset.pick === 'type' && c.dataset.value === (asked.get('type') || '').toLowerCase())
    || (c.dataset.pick === 'sort' && c.dataset.value === asked.get('sort'))).forEach((c) => pick(c, false));
  apply(true);   // keeps the ?page= one
})();

// ── Changelog: show/hide older versions, newest/oldest first ─────────────────
(() => {
  const log = document.querySelector('[data-log]');
  if (!log) return;
  const toggles = document.querySelectorAll('[data-log-toggle]');
  const bottom = document.querySelector('.log-bottom');
  const orderBtn = document.querySelector('[data-log-order]');
  let open = false;
  let newestFirst = true;
  const setOpen = (v) => {
    open = v;
    log.querySelectorAll(':scope > li.old').forEach((li) => (li.hidden = !v));
    toggles.forEach((t) => {
      t.setAttribute('aria-expanded', String(v));
      t.querySelector('[data-when="closed"]')?.toggleAttribute('hidden', v);
      t.querySelector('[data-when="open"]')?.toggleAttribute('hidden', !v);
    });
    if (bottom) bottom.hidden = !v;
  };
  toggles.forEach((t) =>
    t.addEventListener('click', () => {
      const wasBottom = t === bottom;
      setOpen(!open);
      if (wasBottom) document.getElementById('changelog')?.scrollIntoView({ behavior: 'smooth' });
    }),
  );
  orderBtn?.addEventListener('click', () => {
    newestFirst = !newestFirst;
    [...log.children].reverse().forEach((li) => log.append(li));
    log.classList.toggle('reversed', !newestFirst);
    orderBtn.querySelector('[data-order-label]').textContent = newestFirst ? 'Oldest first' : 'Newest first';
    if (!newestFirst && !open) setOpen(true);
  });
})();

// ── FAQ: search, open the question in the address (#…), copy a question's link ──
(() => {
  const items = [...document.querySelectorAll('[data-faq-item]')];
  if (!items.length) return;
  const search = document.querySelector('[data-faq-search]');
  const empty = document.querySelector('[data-faq-empty]');
  const secs = [...document.querySelectorAll('[data-faq-sec]')];
  const text = new Map(items.map((it) => [it, it.textContent.toLowerCase()]));
  const openHash = () => {
    const el = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    if (el?.matches('[data-faq-item]')) { el.open = true; el.scrollIntoView({ block: 'start' }); }
  };
  search.addEventListener('input', () => {
    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    let n = 0;
    for (const it of items) {
      const ok = words.every((w) => text.get(it).includes(w));
      it.hidden = !ok;
      it.open = ok && words.length > 0 && words.join('').length >= 3;
      if (ok) n++;
    }
    secs.forEach((s) => (s.hidden = !s.querySelector('[data-faq-item]:not([hidden])')));
    empty.hidden = n > 0;
  });
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('.faq-link');
    if (!a) return;
    e.preventDefault();
    const item = a.closest('[data-faq-item]');
    history.replaceState(null, '', a.getAttribute('href'));
    item.open = true;
    try { await navigator.clipboard.writeText(location.href); a.textContent = '✓'; setTimeout(() => (a.textContent = '#'), 1500); } catch { /* no clipboard */ }
  });
  window.addEventListener('hashchange', openHash);
  openHash();
})();

// ── Code blocks: copy button ──────────────────────────────────────────────────
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-copy-code]');
  if (!b) return;
  const code = b.parentElement.querySelector('code');
  const text = [...code.querySelectorAll('.ln')].map((l) => l.textContent).join('\n') || code.textContent;
  try { await navigator.clipboard.writeText(text); b.textContent = 'Copied ✓'; } catch { b.textContent = 'Select and copy'; }
  setTimeout(() => (b.textContent = 'Copy'), 1500);
});

// ── Docs: page list open on wide screens (a menu on phones), search, "On this page" highlight ──
(() => {
  const nav = document.querySelector('[data-docs-nav]');
  if (!nav) return;
  const wide = matchMedia('(min-width: 861px)');
  const fit = () => (nav.open = wide.matches);
  fit();
  wide.addEventListener('change', fit);

  const search = nav.querySelector('[data-docs-search]');
  const items = [...nav.querySelectorAll('[data-docs-item]')];
  const groups = [...nav.querySelectorAll('[data-docs-group]')];
  const none = nav.querySelector('[data-docs-none]');
  search.addEventListener('input', () => {
    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    let n = 0;
    for (const it of items) {
      const ok = words.every((w) => (it.dataset.k + ' ' + it.textContent.toLowerCase()).includes(w));
      it.hidden = !ok;
      if (ok) n++;
    }
    groups.forEach((g) => (g.hidden = !g.querySelector('[data-docs-item]:not([hidden])')));
    none.hidden = n > 0;
  });
  search.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const first = nav.querySelector('[data-docs-item]:not([hidden]) a');
    if (first) location.href = first.href;
  });

  const toc = document.querySelector('[data-docs-toc]');
  if (!toc || !('IntersectionObserver' in window)) return;
  const links = new Map([...toc.querySelectorAll('a')].map((a) => [decodeURIComponent(a.hash.slice(1)), a]));
  const heads = [...links.keys()].map((id) => document.getElementById(id)).filter(Boolean);
  const visible = new Set();
  const mark = () => {
    let cur = heads.find((h) => visible.has(h));
    if (!cur) cur = [...heads].reverse().find((h) => h.getBoundingClientRect().top < 120) || heads[0];
    links.forEach((a) => a.classList.toggle('active', a === links.get(cur.id)));
  };
  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => (en.isIntersecting ? visible.add(en.target) : visible.delete(en.target)));
    mark();
  }, { rootMargin: '-80px 0px -60% 0px' });
  heads.forEach((h) => io.observe(h));
})();

// ── Likes on model and outfit pages (paying patrons; others are told how to get there) ──
(() => {
  const btn = document.querySelector('[data-like]');
  if (!btn) return;
  const slug = btn.dataset.like;
  const kind = btn.dataset.likeKind === 'outfits' ? 'outfits' : 'models';
  const what = kind === 'outfits' ? 'accessory' : 'model';
  const countEl = btn.querySelector('[data-like-count]');
  const msg = document.querySelector('[data-like-msg]');
  let state = null;
  const show = (s) => {
    state = { ...state, ...s };
    countEl.textContent = state.count;
    btn.setAttribute('aria-pressed', state.liked ? 'true' : 'false');
    btn.title = state.liked ? `You like this ${what} (click to remove)` : state.can ? `Like this ${what}` : 'Likes are for patrons';
  };
  const say = (html) => { msg.innerHTML = html; msg.hidden = !html; };
  fetch(`/auth/like.php?slug=${encodeURIComponent(slug)}&kind=${kind}&t=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' })
    .then((r) => r.json()).then(show).catch(() => {});
  btn.addEventListener('click', async () => {
    if (!state) return;
    if (!state.can) {
      const back = encodeURIComponent(location.pathname);
      say(state.reason === 'login'
        ? `Likes are for patrons. <a href="/auth/login.php?return=${back}">Log in with Patreon</a> to like this ${what}.`
        : 'Likes are for patrons on any paid tier. <a href="/#access">See how to join</a>.');
      return;
    }
    const want = !state.liked;
    show({ liked: want, count: state.count + (want ? 1 : -1) });   // instant feedback
    if (want) { btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop'); }
    btn.disabled = true;
    try {
      const r = await fetch('/auth/like.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf || '' }, body: JSON.stringify({ slug, kind, like: want }) });
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'Something went wrong.');
      show(d); say('');
    } catch (e) {
      show({ liked: !want, count: state.count + (want ? -1 : 1) });
      say(e.message);
    }
    btn.disabled = false;
  });
})();

// ── Site notice: the ✕ hides it and this browser remembers it (until the notice text changes) ──
(() => {
  const n = document.querySelector('[data-notice]');
  const btn = n?.querySelector('[data-notice-close]');
  if (!btn) return;
  btn.addEventListener('click', () => {
    n.hidden = true;
    try { localStorage.setItem('m3d_notice', n.dataset.notice); } catch (e) { /* private mode: closed for this page view only */ }
  });
})();

// ── Download waiting page: (if asked) confirm using one of the downloads, then count down and go (the server checks again) ──
(() => {
  const box = document.querySelector('[data-dl-wait]');
  if (!box) return;
  const total = +box.dataset.dlWait || 0;
  const go = box.dataset.dlGo;
  const count = box.querySelector('[data-dl-count]');
  const left = box.querySelector('[data-dl-left]');
  const unit = box.querySelector('[data-dl-unit]');
  const bar = box.querySelector('[data-dl-bar]');
  const now = box.querySelector('[data-dl-now]');
  now.addEventListener('click', (e) => { if (now.getAttribute('aria-disabled') === 'true') e.preventDefault(); });
  const start = () => {
    const end = Date.now() + total * 1000;
    const tick = () => {
      const ms = Math.max(0, end - Date.now());
      const s = Math.ceil(ms / 1000);
      left.textContent = s;
      unit.textContent = s === 1 ? 'second' : 'seconds';
      bar.style.width = (total ? 100 - (ms / (total * 10)) : 100) + '%';
      if (ms > 0) return void setTimeout(tick, 200);
      now.removeAttribute('aria-disabled');
      count.querySelector('.lead').textContent = 'Your download is starting…';
      location.href = go;
    };
    tick();
  };
  const ask = box.querySelector('[data-dl-ask]');
  if (!ask) return start();
  ask.querySelector('[data-dl-yes]').addEventListener('click', (e) => {
    e.preventDefault();
    ask.hidden = true;
    count.hidden = false;
    start();
  });
})();

// ── My account: a click on a #section link (the "left" boxes → Unlock limits) opens it if it folds, scrolls there and lights it up ──
(() => {
  const reveal = (id, smooth) => {
    const d = id && document.getElementById(id);
    if (!d || !d.matches('.acc-fold, .acc-limits')) return false;
    if (d.tagName === 'DETAILS') d.open = true;
    d.scrollIntoView({ behavior: smooth && !matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto', block: 'start' });
    d.classList.remove('is-flash');
    void d.offsetWidth;   // restart the animation when clicked again
    d.classList.add('is-flash');
    d.addEventListener('animationend', () => d.classList.remove('is-flash'), { once: true });
    return true;
  };
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#"]');   // only clicks on this page (the "left" boxes): arriving here never animates
    if (a && reveal(a.getAttribute('href').slice(1), true)) e.preventDefault();   // the address stays clean
  });
})();

// ── My account: the updates listed in My Models count as seen (the dot on the account button goes away) ──
(() => {
  const box = document.querySelector('[data-updates-seen]');
  if (!box) return;
  try {
    const now = JSON.parse(box.dataset.updatesSeen || '[]');
    if (now.length) localStorage.setItem('m3d_seen_updates', JSON.stringify(now));   // only the current ones: stays small
  } catch { /* no storage: the dot simply stays */ }
})();

// ── My account → My Models: All / Models / Accessories / Available / Updates (rows carry data-models or data-accessories, data-avail, data-upd) ──
(() => {
  const bar = document.querySelector('[data-acc-filters]');
  if (!bar) return;
  const table = bar.parentElement.querySelector('.table-wrap');
  const empty = bar.parentElement.querySelector('[data-acc-empty]');
  bar.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-acc-show]');
    if (!btn) return;
    bar.querySelectorAll('[data-acc-show]').forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
    const show = btn.dataset.accShow;
    let n = 0;
    table.querySelectorAll('tr.upd-row').forEach(tr => { tr.hidden = true; });   // open changelogs close
    table.querySelectorAll('[data-upd-toggle]').forEach(b => b.setAttribute('aria-expanded', 'false'));
    table.querySelectorAll('tbody tr:not(.upd-row)').forEach(tr => {
      const on = !show || tr.hasAttribute('data-' + show);
      tr.hidden = !on;
      if (on) n++;
    });
    table.hidden = !n;
    empty.hidden = !!n;
  });
})();

// ── My account → My Models: the Update tag opens the versions released since the patron's download (the row below) ──
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('[data-upd-toggle]');
  if (!btn) return;
  const tr = btn.closest('tr');
  const panel = tr && tr.nextElementSibling;
  if (!panel || !panel.classList.contains('upd-row')) return;
  panel.hidden = !panel.hidden;
  tr.querySelectorAll('[data-upd-toggle]').forEach(b => b.setAttribute('aria-expanded', String(!panel.hidden)));   // the column tag and the phone one
});

// ── Number fields: two step buttons (▲▼) instead of the browser's own arrows; holding a button keeps counting ──
(() => {
  const numStepper = (input) => {
    if (input.dataset.stepper || input.closest('.num-field')) return;
    input.dataset.stepper = '1';
    const svg = (d) => `<svg viewBox="0 0 12 12" aria-hidden="true"><path d="${d}"/></svg>`;
    const wrap = document.createElement('span');
    wrap.className = 'num-field';
    input.replaceWith(wrap);
    const box = document.createElement('span');
    box.className = 'num-step';
    const step = (up) => {
      if (input.disabled || input.readOnly) return;
      const before = input.value;
      try { up ? input.stepUp() : input.stepDown(); } catch { return; }
      if (input.value !== before) { input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true })); }
    };
    [[true, 'Increase', 'M2.5 7.5 6 4l3.5 3.5'], [false, 'Decrease', 'M2.5 4.5 6 8l3.5-3.5']].forEach(([up, label, d]) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.tabIndex = -1; // keyboard: the arrow keys in the field
      b.setAttribute('aria-label', label);
      b.innerHTML = svg(d);
      let t = 0;
      const stop = () => { clearTimeout(t); clearInterval(t); };
      b.addEventListener('pointerdown', (e) => {
        if (e.button) return;
        e.preventDefault(); // keep the focus where it is
        step(up);
        stop();
        t = setTimeout(() => (t = setInterval(() => step(up), 70)), 400);
      });
      ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => b.addEventListener(ev, stop));
      b.addEventListener('click', (e) => { if (e.detail === 0) step(up); }); // keyboard / assistive "click"
      box.append(b);
    });
    wrap.append(input, box);
  };
  document.querySelectorAll('input[type=number]').forEach(numStepper);
})();
