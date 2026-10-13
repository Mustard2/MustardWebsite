// Site behaviour. Each part only runs on pages that have the matching elements.

// Search boxes: accents and case are ignored, and every typed word must be found.
const searchFold = (t) => String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const searchWords = (q) => searchFold(q).split(/\s+/).filter(Boolean);
const searchHasAll = (hay, words) => { const h = searchFold(hay); return words.every((w) => h.includes(w)); };

// ── Header: show the account button (name) / Admin / Log out when logged in ──────────────
(async () => {
  const box = document.querySelector('[data-account]');
  // data-session: the page was made for someone logged in; nobody else has anything to ask the server for
  if (!box || !('session' in box.dataset)) return;
  try {
    // unique address every time: even a CDN rule that caches everything can't hand out someone else's answer
    // the page carries it (data-me, account_box()); asked from the server only when it doesn't
    let s = null;
    try { s = JSON.parse(box.dataset.me || 'null'); } catch { /* asked below */ }
    if (!s) {
      const r = await fetch(`/auth/status.php?t=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) return;
      s = await r.json();
    }
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
    // catalogs: "↑ Update · V4" on the picture of what this patron downloaded and now has a newer version (s.updates,
    // codes "kind:slug@version"); it stays until they download the new version
    try { (Array.isArray(s.updates) ? s.updates : []).forEach((code) => {
      const kind = code.slice(0, code.indexOf(':')), at = code.indexOf('@');
      const slug = code.slice(kind.length + 1, at), ver = code.slice(at + 1);
      const sel = kind === 'models' ? '[data-card]' : '[data-ocard]';
      document.querySelectorAll(sel).forEach((c) => {
        if (c.dataset.slug !== slug) return;
        const t = document.createElement('span');
        t.className = 'card-upd';
        t.title = `${ver} is out since your download`;
        t.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg>';
        t.append(document.createTextNode(`Update · ${ver}`));
        (c.querySelector('.cover, .ocard-img') || c).append(t);
      });
    }); } catch { /* a nicety */ }
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
    out.textContent = 'Log Out';
    box.append(out);
  } catch { /* status endpoint not available */ }
})();

// ── "?" help next to a label (help_tip() in view.php): one floating box shows its text on hover, focus or tap, kept inside the window ──
(() => {
  if (!document.querySelector('.help-tip')) return;
  let pop = null, cur = null;
  const hide = () => { if (pop) pop.hidden = true; cur = null; };
  const show = (b) => {
    if (!pop) { pop = document.createElement('div'); pop.className = 'help-pop'; pop.setAttribute('role', 'tooltip'); document.body.append(pop); }
    cur = b;
    pop.textContent = b.dataset.help;
    pop.hidden = false;
    const r = b.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
    const below = r.bottom + 8 + h < innerHeight;
    pop.style.left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, innerWidth - w - 8)) + 'px';
    pop.style.top = (below ? r.bottom + 8 : r.top - 8 - h) + 'px';
  };
  document.addEventListener('pointerover', (e) => { const b = e.target.closest?.('.help-tip'); if (b && b !== cur) show(b); else if (!b && cur && document.activeElement !== cur) hide(); });
  document.addEventListener('focusin', (e) => { if (e.target.matches?.('.help-tip')) show(e.target); else hide(); });
  document.addEventListener('focusout', (e) => { if (e.target === cur) hide(); });
  document.addEventListener('click', (e) => { const b = e.target.closest?.('.help-tip'); if (b) show(b); else hide(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
  window.addEventListener('scroll', hide, { passive: true });
})();

// ── Homepage featured names: a name whose longest word doesn't fit the column gets a smaller size (no word cut in two) ──
(() => {
  const titles = [...document.querySelectorAll('[data-hero] .hero-title')];
  if (!titles.length) return;
  const fit = () => {
    titles.forEach((t) => (t.style.fontSize = ''));
    const shown = titles.find((t) => t.offsetWidth);   // every slide has the same column, so one visible name sets the width
    if (!shown) return;
    const words = titles.map((t) => t.textContent.trim().split(/\s+/));
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap';
    shown.append(probe);   // same font and size as the names
    const base = parseFloat(getComputedStyle(shown).fontSize);
    const room = shown.clientWidth;
    probe.style.letterSpacing = (parseFloat(getComputedStyle(shown).letterSpacing) || 0) / base + 'em';   // in em, so it follows the size like the names' own
    const widest = (ws, size) => Math.max(...ws.map((w) => { probe.style.fontSize = size + 'px'; probe.textContent = w; return probe.getBoundingClientRect().width; }));
    titles.forEach((t, i) => {
      const w = widest(words[i], base);
      if (w <= room) return;
      let size = Math.floor(base * room / w);   // close guess, then down until the widest word really fits
      while (size > 16 && widest(words[i], size) > room) size--;
      t.style.fontSize = size + 'px';
    });
    probe.remove();
  };
  fit();
  document.fonts?.ready.then(fit);
  let raf = 0;
  addEventListener('resize', () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(fit); });
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
  // the second picture too, once the page has loaded (so it never slows the first one down): the first switch shows it at once
  const early = () => slides[1].querySelector('img')?.setAttribute('loading', 'eager');
  if (document.readyState === 'complete') early(); else addEventListener('load', early, { once: true });
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

// "See how to join" after "Likes are for patrons": the address from the header (data-join), none when the site has none
const joinLink = () => {
  const u = document.querySelector('[data-account]')?.dataset.join;
  if (!u) return '';
  const a = document.createElement('a');
  a.href = u;
  a.textContent = 'See how to join';
  return ' ' + a.outerHTML + '.';
};

// ── Catalog pages (homepage, Accessories): 25 cards per page after search, filters and sort; ‹ 1 2 … 9 › under the grid,
// ?page= in the address so a link or Back keeps the page. onPage() re-runs the catalog's apply(true) ──
function catalogPager(pager, top, onPage, PER = 25) {
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
        pager.querySelector('[aria-current="page"]')?.focus({ preventScroll: true });   // the clicked button was redrawn: keyboard users stay on the pager
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

// ── The Filters button (filter_toggle() in view.php: catalogs, Gallery, bug reports) opens the panel its aria-controls names ──
document.querySelectorAll('[data-filter-toggle]').forEach((toggle) => toggle.addEventListener('click', () => {
  const panel = document.getElementById(toggle.getAttribute('aria-controls'));
  if (!panel) return;
  panel.hidden = !panel.hidden;
  toggle.setAttribute('aria-expanded', String(!panel.hidden));
}));

// ── Catalog filters (homepage, Accessories, Gallery): inside o.root, the search box [data-cat-search], the Filters panel's chip rows
// (chip_row() in view.php: .chip[data-pick], one value per row, several in a [data-multi] row; [data-default] = no filter; the page
// picks the first ones, e.g. from the address), the badge, [data-cat-count], [data-cat-empty] with its [data-cat-reset] button and
// the pager. o: items (cards or tiles), box (they are put back in it in the sorted order), sorts {value: compare}, text(item) = what
// the search looks in, test(item, sel) = the chip rows' filters, count(shown, all) = the count line, params {sel key: address
// parameter} kept in the address, per (items per page), top (where a page change scrolls to), after(match) once the page is shown ──
function catalogFilters(o) {
  const q = (s) => o.root.querySelector(s);
  const search = q('[data-cat-search]');
  const picks = [...o.root.querySelectorAll('.chip[data-pick]')];
  const badge = q('[data-filter-count]');
  const count = q('[data-cat-count]');
  const empty = q('[data-cat-empty]');
  const multi = (chip) => !!chip.closest('[data-multi]');
  const sel = {};
  const defaults = {};
  picks.forEach((c) => {
    const k = c.dataset.pick;
    const m = multi(c);
    const def = 'default' in c.dataset;
    if (!(k in sel)) { sel[k] = m ? [] : ''; defaults[k] = m ? [] : ''; }
    if (def && !m) defaults[k] = c.dataset.value;
    if (c.getAttribute('aria-pressed') === 'true') { if (!m) sel[k] = c.dataset.value; else if (!def) sel[k].push(c.dataset.value); }
  });
  const order = new Map(o.items.map((c, i) => [c, i]));   // the page's order breaks ties
  const pages = catalogPager(q('[data-pager]'), o.top || o.root, () => apply(true), o.per);
  const same = (k) => String(sel[k]) === String(defaults[k]);

  function apply(keepPage = false) {
    if (!keepPage) pages.reset();
    const words = searchWords(search?.value || '');
    const cmp = o.sorts[sel.sort] || o.sorts[defaults.sort] || (() => 0);
    const sorted = [...o.items].sort((a, b) => cmp(a, b) || order.get(a) - order.get(b));
    sorted.forEach((c) => o.box.append(c));
    const match = sorted.filter((c) => searchHasAll(o.text(c), words) && o.test(c, sel));
    pages.show(o.items, match);
    if (count && o.count) count.textContent = o.count(match.length, o.items.length);
    if (empty) empty.hidden = match.length > 0;
    if (badge) {
      const active = Object.keys(sel).filter((k) => !same(k)).length;
      badge.textContent = active;
      badge.hidden = !active;
    }
    o.after?.(match, sel);
    const u = new URL(location.href);
    Object.entries(o.params || {}).forEach(([k, p]) => (same(k) ? u.searchParams.delete(p) : u.searchParams.set(p, sel[k])));
    const typed = (search?.value || '').trim();   // the search too (?q=): ?page= is a page of the same list after a reload or Back
    if (typed) u.searchParams.set('q', typed); else u.searchParams.delete('q');
    pages.url(u);
    if (u.href !== location.href) history.replaceState(null, '', u);
  }

  const pick = (chip) => {
    const k = chip.dataset.pick;
    const row = picks.filter((c) => c.dataset.pick === k);
    if (multi(chip)) {   // several at once: toggle; All (the default) clears, none left = All
      const on = 'default' in chip.dataset ? [] : chip.getAttribute('aria-pressed') === 'true'
        ? sel[k].filter((v) => v !== chip.dataset.value) : [...sel[k], chip.dataset.value];
      sel[k] = on;
      row.forEach((c) => c.setAttribute('aria-pressed', String('default' in c.dataset ? !on.length : on.includes(c.dataset.value))));
    } else {
      sel[k] = chip.dataset.value;
      row.forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
    }
  };
  // the chips the address names (o.params; a reload, Back or a shared link): picked before the first apply, so ?page= is
  // a page of the same list (several values of a multi row: "a,b")
  const url = new URLSearchParams(location.search);
  if (search && url.get('q')) search.value = url.get('q');
  Object.entries(o.params || {}).forEach(([k, p]) => {
    const v = url.get(p);
    if (v === null || !(k in sel)) return;
    const row = picks.filter((c) => c.dataset.pick === k);
    const want = Array.isArray(sel[k]) ? v.split(',') : [v];
    if (Array.isArray(sel[k])) row.filter((c) => 'default' in c.dataset).forEach(pick);
    row.filter((c) => !('default' in c.dataset) && want.includes(c.dataset.value) && c.getAttribute('aria-pressed') !== 'true').forEach(pick);
  });
  picks.forEach((chip) => chip.addEventListener('click', () => { pick(chip); apply(); }));
  search?.addEventListener('input', () => apply());
  q('[data-cat-reset]')?.addEventListener('click', () => {
    if (search) search.value = '';
    picks.filter((c) => 'default' in c.dataset).forEach(pick);
    apply();
  });
  apply(true);   // first page (or the ?page= one)
}

// Sort orders of the model and accessory cards (data- attributes from model_card() / outfit_card()); ties keep the page's order
const cardNum = (c, k) => +c.dataset[k] || 0;
const cardSorts = {
  order: (a, b) => cardNum(a, 'order') - cardNum(b, 'order'),
  name: (a, b) => a.dataset.name.localeCompare(b.dataset.name),
  gen: (a, b) => cardNum(b, 'generation') - cardNum(a, 'generation') || cardNum(a, 'order') - cardNum(b, 'order'),
  likes: (a, b) => cardNum(b, 'likes') - cardNum(a, 'likes') || cardNum(a, 'order') - cardNum(b, 'order'),
  downloads: (a, b) => cardNum(b, 'downloads') - cardNum(a, 'downloads') || cardNum(a, 'order') - cardNum(b, 'order'),
  updated: (a, b) => cardNum(b, 'updated') - cardNum(a, 'updated') || cardNum(a, 'order') - cardNum(b, 'order'),   // latest version's date
};
// the Unlocked row (only shown when download limits apply; data-owned from the header's status)
// (an ?owned= from the address while the row stays hidden, no limits apply now: no filter)
const ownedOk = (c, sel) => !sel.owned || document.querySelector('[data-owned-row]')?.hidden !== false || (sel.owned === 'yes') === (c.dataset.owned === '1');
// the Liked row (liked_row(): only for a visitor who liked one of these; the liked addresses are on the row)
const likedOk = (root) => {
  let set = new Set();
  try { set = new Set(JSON.parse(root.querySelector('[data-liked-row]')?.dataset.liked || '[]')); } catch { /* none */ }
  return (c, sel) => !sel.liked || set.has(c.dataset.slug);
};

// ── Homepage catalog ──────────────────────────────────────────────────────────
(() => {
  const root = document.querySelector('[data-catalog]');
  const grid = root?.querySelector('[data-grid]');
  if (!grid) return;
  const liked = likedOk(root);
  catalogFilters({
    root, box: grid, top: root,
    items: [...grid.querySelectorAll('[data-card]')],
    sorts: cardSorts,
    text: (c) => c.dataset.name + ' ' + c.dataset.franchise,
    test: (c, sel) => (!sel.franchise || c.dataset.franchise === sel.franchise)
      && (!sel.generation?.length || sel.generation.includes((c.dataset.generation || '').trim() || '~other'))   // no Generation row: none
      && (!sel.status || c.dataset.status === sel.status) && ownedOk(c, sel) && liked(c, sel),
    count: (n, all) => `${n} of ${all} ${all === 1 ? 'model' : 'models'}`,
    params: { sort: 'sort', status: 'status', franchise: 'franchise', generation: 'gen', liked: 'liked', owned: 'owned' },   // ?page= stays a page of the same list
  });
})();

// ── Model/outfit pages: the gallery row; ‹ › scroll it by most of a screen, each shown only when there is more that way ──
(() => {
  document.querySelectorAll('[data-strip]').forEach((strip) => {
    const row = strip.querySelector('[data-strip-row]');
    const prev = strip.querySelector('[data-strip-prev]');
    const next = strip.querySelector('[data-strip-next]');
    const update = () => {
      prev.hidden = row.scrollLeft < 4;
      next.hidden = row.scrollLeft + row.clientWidth > row.scrollWidth - 4;
    };
    const go = (dir) => row.scrollBy({ left: dir * row.clientWidth * 0.8 });
    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));
    row.addEventListener('scroll', update, { passive: true });
    addEventListener('resize', update);
    update();
  });
})();

// ── Image viewer: any [data-slide] inside a [data-slides] set opens it; ← → keys, buttons or swipe ──
(() => {
  const dlg = document.querySelector('[data-lightbox]');
  if (!dlg) return;
  const img = dlg.querySelector('[data-lightbox-img]');
  const cap = dlg.querySelector('[data-lightbox-cap]');
  const count = dlg.querySelector('[data-lightbox-count]');
  const navs = dlg.querySelectorAll('.lb-nav');
  // gallery pictures with a full size file: the "Download full size" row (slides with dl: {href, note, locked})
  const dl = dlg.querySelector('[data-lightbox-dl]');
  const dlNote = dlg.querySelector('[data-lightbox-dl-note]');
  const dlBtn = dlg.querySelector('[data-lightbox-dl-btn]');
  const likeBtn = dlg.querySelector('[data-lightbox-like]');   // gallery pictures (slides with like: id); the Gallery script paints and handles it
  const DL_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg>';
  const LOCK = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
  let slides = [];
  let cur = 0;
  // ── zoom: click (mouse) or double-tap (touch) toggles, the wheel and a pinch zoom around the pointer, drag moves;
  // the picture keeps its fitted layout and gets transform: translate(tx, ty) scale(z) (origin top left) ──
  const zoomBtn = dlg.querySelector('[data-lightbox-zoom]');
  let z = 1, tx = 0, ty = 0;
  const base = () => {   // the fitted box on screen, without the transform (also right in the middle of an animation)
    const d = dlg.getBoundingClientRect();
    return { x: d.left + img.offsetLeft - dlg.scrollLeft, y: d.top + img.offsetTop - dlg.scrollTop, w: img.offsetWidth, h: img.offsetHeight };
  };
  const fullZoom = () => Math.min(6, Math.max(2, (img.naturalWidth || 0) / (img.offsetWidth || 1)));   // 1:1 pixels, 2× at least
  const apply = (anim) => {
    img.classList.toggle('lb-anim', !!anim);
    img.style.transform = z > 1 ? `translate(${tx}px, ${ty}px) scale(${z})` : '';
    dlg.classList.toggle('is-zoomed', z > 1);
    zoomBtn.setAttribute('aria-label', z > 1 ? 'Zoom out' : 'Zoom in');
  };
  // keeps the picture on screen: bigger than the window = its edges can't come inside, smaller = centred on that side
  const clamp = (b) => {
    const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight, w = b.w * z, h = b.h * z;   // without the scrollbar gutter
    tx = w > vw ? Math.min(-b.x, Math.max(vw - b.x - w, tx)) : (vw - w) / 2 - b.x;
    ty = h > vh ? Math.min(-b.y, Math.max(vh - b.y - h, ty)) : (vh - h) / 2 - b.y;
  };
  const zoomAt = (nz, px, py, anim) => {   // to zoom nz, the point under (px, py) stays put
    const b = base();
    nz = Math.min(fullZoom() * 1.5, Math.max(1, nz));
    if (nz <= 1.01) { z = 1; tx = ty = 0; apply(anim); return; }
    const k = nz / z;
    tx = (px - b.x) * (1 - k) + k * tx;
    ty = (py - b.y) * (1 - k) + k * ty;
    z = nz;
    clamp(b);
    apply(anim);
  };
  const unzoom = (anim) => { z = 1; tx = ty = 0; apply(anim); };
  const toggleAt = (px, py) => (z > 1 ? unzoom(true) : zoomAt(fullZoom(), px, py, true));
  zoomBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const b = base();
    toggleAt(b.x + b.w / 2, b.y + b.h / 2);
  });
  img.addEventListener('dragstart', (e) => e.preventDefault());
  img.addEventListener('wheel', (e) => {
    e.preventDefault();
    zoomAt(z * Math.exp(-e.deltaY * (e.deltaMode ? 0.05 : 0.002)), e.clientX, e.clientY);
  }, { passive: false });
  const pts = new Map();   // pointers down on the picture
  let drag = null;         // {x, y, tx, ty, moved}
  let pinch = null;        // {d, z, mx, my}
  let lastTap = 0;
  const mid = () => { const [a, b] = [...pts.values()]; return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, d: Math.hypot(a.x - b.x, a.y - b.y) || 1 }; };
  img.addEventListener('pointerdown', (e) => {
    if (e.button) return;
    img.setPointerCapture(e.pointerId);
    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pts.size === 2) { const m = mid(); pinch = { d: m.d, z }; drag = null; }
    else if (pts.size === 1) drag = { x: e.clientX, y: e.clientY, tx, ty, moved: false };
  });
  img.addEventListener('pointermove', (e) => {
    if (!pts.has(e.pointerId)) return;
    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pinch && pts.size === 2) { const m = mid(); zoomAt(pinch.z * m.d / pinch.d, m.x, m.y); return; }
    if (!drag) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
    if (z > 1 && drag.moved) {
      dlg.classList.add('is-panning');
      tx = drag.tx + dx; ty = drag.ty + dy;
      clamp(base());
      apply();
    }
  });
  const up = (e) => {
    if (!pts.delete(e.pointerId)) return;
    dlg.classList.remove('is-panning');
    if (pinch) {   // the end of a pinch is not a tap; one finger left on the picture moves it on
      drag = null;
      if (pts.size < 2) pinch = null;
      if (pts.size === 1) { const [p] = pts.values(); drag = { x: p.x, y: p.y, tx, ty, moved: true }; }
      return;
    }
    const tap = drag && !drag.moved;
    drag = null;
    if (!tap || e.type === 'pointercancel') return;
    if (e.pointerType === 'mouse') { toggleAt(e.clientX, e.clientY); return; }
    const now = Date.now();   // touch and pen: a double tap
    if (now - lastTap < 300) { lastTap = 0; toggleAt(e.clientX, e.clientY); } else lastTap = now;
  };
  img.addEventListener('pointerup', up);
  img.addEventListener('pointercancel', up);
  dlg.addEventListener('cancel', (e) => { if (z > 1) { e.preventDefault(); unzoom(true); } });   // Esc: zoom out first, then close
  dlg.addEventListener('close', () => unzoom());
  addEventListener('resize', () => { if (dlg.open && z > 1) unzoom(); });

  const show = (i) => {
    unzoom();
    cur = (i + slides.length) % slides.length;
    img.src = slides[cur].src;
    cap.textContent = slides[cur].caption || '';
    (slides[cur].chars || []).forEach((c, k) => {   // gallery pictures: the characters, linked to their model pages
      cap.append(k ? ', ' : (cap.textContent ? ' · ' : ''));
      if (!c.url) { cap.append(c.name); return; }
      const a = document.createElement('a');
      a.href = c.url;
      a.className = 'lb-char';
      a.textContent = c.name;
      cap.append(a);
    });
    count.textContent = slides.length > 1 ? `${cur + 1} / ${slides.length}` : '';
    const d = slides[cur].dl;
    const like = slides[cur].like;
    if (dl) {
      dl.hidden = !d && !like;
      dlg.classList.toggle('has-dl', !!(d || like));
      if (likeBtn) likeBtn.hidden = !like;
      dlNote.hidden = dlBtn.hidden = !d;
      if (d) {
        dlNote.textContent = d.note || '';
        dlBtn.href = d.href;
        dlBtn.className = 'btn btn-sm ' + (d.locked ? 'btn-ghost' : 'btn-primary');
        dlBtn.innerHTML = (d.locked ? LOCK + ' Log In to Download' : DL_ICON + ' Download Full Size');
      }
    }
    [cur + 1, cur - 1].forEach((n) => { const s = slides[(n + slides.length) % slides.length]; if (s) new Image().src = s.src; });
    dlg.dispatchEvent(new CustomEvent('lb-show', { detail: slides[cur] }));
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
  // on the page, not the viewer: zooming hides ‹ › and the focus they had falls back to the page, where the viewer wouldn't hear the keys
  document.addEventListener('keydown', (e) => {
    if (!dlg.open) return;
    // Esc zooms out first: here too, as Chrome stops letting the page cancel a dialog's Esc after a few times
    if (e.key === 'Escape' && z > 1) { e.preventDefault(); unzoom(true); return; }
    if (e.target.closest?.('input, textarea, select')) return;
    if (e.key === 'ArrowLeft') show(cur - 1);
    if (e.key === 'ArrowRight') show(cur + 1);
  });
  let x0 = null;
  dlg.addEventListener('touchstart', (e) => { x0 = e.touches.length === 1 && z === 1 ? e.touches[0].clientX : null; }, { passive: true });   // zoomed or pinching: no swipe
  dlg.addEventListener('touchend', (e) => {
    if (x0 === null || slides.length < 2 || z > 1 || e.touches.length) { if (!e.touches.length) x0 = null; return; }
    const dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 40) show(cur + (dx < 0 ? 1 : -1));
    x0 = null;
  });
})();

// ── Gallery (/gallery/): search + Filters button like the catalogue (sort, franchise, character; one at a time per row); the viewer follows what is shown ──
(() => {
  const wall = document.querySelector('[data-gwall]');
  if (!wall) return;
  const tiles = [...wall.querySelectorAll('[data-tile]')];
  const master = JSON.parse(wall.dataset.slides || '[]');   // every clear picture; data-k = its place in this list
  // likes: everyone sees the counts (in the page); a logged-in visitor's own likes are in the page too (data-glikes)
  const counts = {};
  tiles.forEach((t) => { if (t.dataset.gid) counts[t.dataset.gid] = +t.dataset.likes || 0; });
  const lb = document.querySelector('[data-lightbox]');
  const lbLike = lb?.querySelector('[data-lightbox-like]');
  const HEART = wall.querySelector('[data-glike] svg')?.outerHTML || '';
  let me = null;   // {liked: Set, can, reason, csrf}
  let lbId = '';
  const busy = new Set();
  const paint = (id) => {
    const n = counts[id] || 0;
    const on = !!me?.liked.has(id);
    const title = on ? 'You like this picture (click to remove)' : me?.can ? 'Like this picture' : 'Likes are for patrons';
    wall.querySelectorAll(`[data-glike="${id}"]`).forEach((b) => {
      b.setAttribute('aria-pressed', String(on));
      b.querySelector('[data-glike-count]').textContent = n || '';
      b.title = title;
    });
    wall.querySelectorAll(`[data-gid="${id}"]`).forEach((t) => (t.dataset.likes = n));
    if (lbLike && lbId === id) {
      lbLike.setAttribute('aria-pressed', String(on));
      lbLike.title = title;
      lbLike.innerHTML = `${HEART}<span>${on ? 'Liked' : 'Like'}${n ? ' · ' + n : ''}</span>`;
    }
  };
  let toast = null;
  let toastT = 0;
  const say = (html) => {
    if (!toast) { toast = document.createElement('p'); toast.className = 'g-toast'; toast.setAttribute('role', 'status'); }
    const host = lb?.open ? lb : document.body;   // over the viewer while it is open
    if (toast.parentNode !== host) host.append(toast);
    toast.innerHTML = html;
    toast.hidden = false;
    clearTimeout(toastT);
    toastT = setTimeout(() => (toast.hidden = true), 6000);
  };
  const toggleLike = async (id, btn) => {
    if (!me || busy.has(id)) return;
    if (!me.can) {
      const back = encodeURIComponent(location.pathname + location.search);
      say(me.reason === 'login'
        ? `Likes are for patrons. <a rel="nofollow" href="/auth/login.php?return=${back}">Log in with Patreon</a> to like pictures.`
        : 'Likes are for patrons on any paid tier.' + joinLink());
      return;
    }
    const want = !me.liked.has(id);
    const set = (on, n) => { if (on) me.liked.add(id); else me.liked.delete(id); counts[id] = Math.max(0, n); paint(id); };
    set(want, (counts[id] || 0) + (want ? 1 : -1));   // instant feedback
    if (want) { btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop'); }
    busy.add(id);
    try {
      const r = await fetch('/auth/like.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf || '' }, body: JSON.stringify({ slug: id, kind: 'stills', like: want }) });
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'Something went wrong.');
      set(!!d.liked, d.count);
    } catch (e) {
      set(!want, (counts[id] || 0) + (want ? -1 : 1));
      say(e.message.replace(/[<>&]/g, ''));
    }
    busy.delete(id);
  };
  wall.addEventListener('click', (e) => {   // runs before the viewer's own listener: a heart never opens the picture
    const b = e.target.closest('[data-glike]');
    if (!b) return;
    e.stopPropagation();
    toggleLike(b.dataset.glike, b);
  });
  lb?.addEventListener('lb-show', (e) => { lbId = e.detail?.like || ''; if (lbId) paint(lbId); });
  lbLike?.addEventListener('click', () => { if (lbId) toggleLike(lbId, lbLike); });
  // not logged in (no data-session in the header): nothing to ask, the counts are already in the page
  if (!('session' in (document.querySelector('[data-account]')?.dataset || {}))) me = { liked: new Set(), can: false, reason: 'login' };
  else if (wall.dataset.glikes) {   // a logged-in visitor's own likes, in the page (the counts are on the tiles)
    const d = JSON.parse(wall.dataset.glikes);
    me = { liked: new Set(d.liked || []), can: !!d.can, reason: d.reason, csrf: d.csrf };
    Object.keys(counts).forEach(paint);
  } else fetch(`/auth/like.php?kind=stills&all=1&t=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' })
    .then((r) => r.json()).then((d) => {
      me = { liked: new Set(d.liked || []), can: !!d.can, reason: d.reason, csrf: d.csrf };
      Object.assign(counts, d.counts || {});
      Object.keys(counts).forEach(paint);
    }).catch(() => { me = { liked: new Set(), can: false, reason: 'login' }; });

  const root = document.querySelector('[data-gallery]') || wall.parentNode;
  const place = new Map(tiles.map((t, i) => [t, i]));   // the gallery's order (Admin → Gallery), newest on top
  const charChips = [...root.querySelectorAll('.chip[data-pick="char"]')].filter((b) => b.dataset.value);
  catalogFilters({
    root, box: wall, per: 40,
    items: tiles,
    sorts: {
      new: () => 0,
      old: (a, b) => place.get(b) - place.get(a),
      liked: (a, b) => (counts[b.dataset.gid] || 0) - (counts[a.dataset.gid] || 0),
    },
    text: (t) => t.dataset.search,
    test: (t, sel) => (!sel.char || t.dataset.chars.split(' ').includes(sel.char))
      && (!sel.franchise || t.dataset.franchises.includes('|' + sel.franchise + '|')),
    params: { char: 'c', sort: 'sort', franchise: 'franchise' },   // a reload or a shared link comes back to the character (and ?page= to the same list)
    after: (match, sel) => {
      // the viewer opens the pictures shown, in their order
      const shown = match.filter((t) => !t.hidden && t.dataset.k !== undefined);
      wall.dataset.slides = JSON.stringify(shown.map((t) => master[+t.dataset.k]));
      tiles.forEach((t) => t.removeAttribute('data-slide'));
      shown.forEach((t, i) => (t.dataset.slide = i));
      // a franchise narrows the character row to its characters
      charChips.forEach((b) => (b.hidden = !!sel.franchise && b.dataset.franchise !== sel.franchise && b.dataset.value !== sel.char));
    },
  });
})();

// ── Accessories catalog (the address keeps the model, the type and the order) ──
(() => {
  const root = document.querySelector('[data-outfits]');
  const grid = root?.querySelector('[data-ogrid]');
  if (!grid) return;
  const word = (k) => (k === 1 ? 'accessory' : 'accessories');
  const liked = likedOk(root);
  catalogFilters({
    root, box: grid, top: grid.closest('section') || grid,
    items: [...grid.querySelectorAll('[data-ocard]')],
    sorts: cardSorts,
    text: (c) => c.dataset.name,
    test: (c, sel) => (!sel.model || c.dataset.models.split(' ').includes(sel.model)) && (!sel.type || c.dataset.type === sel.type) && ownedOk(c, sel) && liked(c, sel),
    count: (n, all) => (n === all ? `${all} ${word(all)}` : `${n} of ${all} ${word(all)}`),
    params: { model: 'model', type: 'type', sort: 'sort', liked: 'liked', owned: 'owned' },
  });
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
    orderBtn.querySelector('[data-order-label]').textContent = newestFirst ? 'Oldest First' : 'Newest First';
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
  const text = new Map(items.map((it) => [it, searchFold(it.textContent)]));
  const openHash = () => {
    let id = location.hash.slice(1);
    try { id = decodeURIComponent(id); } catch { /* a stray "%" in the address: the id as written */ }
    const el = id ? document.getElementById(id) : null;
    if (el?.matches('[data-faq-item]')) { el.open = true; el.scrollIntoView({ block: 'start' }); }
  };
  search.addEventListener('input', () => {
    const words = searchWords(search.value);
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
  document.addEventListener('click', (e) => {
    const a = e.target.closest('.faq-link');
    if (!a) return;
    e.preventDefault();
    const item = a.closest('[data-faq-item]');
    history.replaceState(null, '', a.getAttribute('href'));
    item.open = true;
  });
  window.addEventListener('hashchange', openHash);
  openHash();
})();

// ── FAQ questions / docs headings: chain-link button copies the address ─────
document.addEventListener('click', async (e) => {
  const a = e.target.closest('[data-copy-link]');
  if (!a) return;
  try { await navigator.clipboard.writeText(a.href); } catch { return; /* no clipboard: the address bar still has it */ }
  a.classList.add('is-copied');
  clearTimeout(a._copied);
  a._copied = setTimeout(() => a.classList.remove('is-copied'), 1500);
});

// ── "← All models / accessories / bug reports": back to the list view the visitor came from (its filters and page are in its
// address; going back in history also brings back the scroll position). Only when they came straight from that list ──
(() => {
  const a = document.querySelector('a[data-back]');
  if (!a || !document.referrer) return;
  let from;
  try { from = new URL(document.referrer); } catch { return; }
  const list = new URL(a.href, location.href);
  if (from.origin !== location.origin || from.pathname !== list.pathname) return;
  a.href = from.pathname + from.search + list.hash;   // a new tab (no history) still lands on the same filters
  let moved = false;   // a jump inside this page (#changelog…) adds a history step: then the link is used as is
  window.addEventListener('hashchange', () => (moved = true));
  a.addEventListener('click', (e) => {
    if (moved || history.length < 2 || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    history.back();
  });
})();

// ── Code blocks: copy button ──────────────────────────────────────────────────
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-copy-code]');
  if (!b) return;
  const code = b.parentElement.querySelector('code');
  const text = [...code.querySelectorAll('.ln')].map((l) => l.textContent).join('\n') || code.textContent;
  try { await navigator.clipboard.writeText(text); b.textContent = 'Copied ✓'; } catch { b.textContent = 'Select and Copy'; }
  setTimeout(() => (b.textContent = 'Copy'), 1500);
});

// ── Tabs in texts ("=== Manual" / "=== With MustardUI", md_tabs() in view.php): groups with the same tab names switch together,
// and this browser remembers the last choices (localStorage m3d_tabs, only after a click; listed in privacy.php) ──
(() => {
  const groups = [...document.querySelectorAll('[data-md-tabs]')];
  if (!groups.length) return;
  let picks = [];
  try { picks = JSON.parse(localStorage.getItem('m3d_tabs') || '[]'); } catch (e) { /* private mode: nothing remembered */ }
  if (!Array.isArray(picks)) picks = [];
  // the group's own tabs and panels only: a box inside a tab can hold another tab group
  const TABS = ':scope > .md-tab-list > [data-tab]', PANELS = ':scope > [data-tab-panel]';
  const keys = (g) => [...g.querySelectorAll(TABS)].map((b) => b.dataset.tab);
  const show = (g, key) => {
    g.querySelectorAll(TABS).forEach((b) => { const on = b.dataset.tab === key; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
    g.querySelectorAll(PANELS).forEach((p) => (p.hidden = p.dataset.tabPanel !== key));
  };
  const pick = (key, btn) => {
    const top = btn.getBoundingClientRect().top;   // groups above may change height: keep the clicked tab where it is
    groups.forEach((g) => { if (keys(g).includes(key)) show(g, key); });
    const moved = btn.getBoundingClientRect().top - top;
    if (moved) window.scrollBy({ top: moved, behavior: 'instant' });
    picks = [key, ...picks.filter((k) => k !== key)].slice(0, 20);
    try { localStorage.setItem('m3d_tabs', JSON.stringify(picks)); } catch (e) { /* private mode: this page only */ }
  };
  groups.forEach((g, gi) => {
    const tabs = [...g.querySelectorAll(TABS)];
    const panels = [...g.querySelectorAll(PANELS)];
    tabs.forEach((b, i) => {
      b.id = `md-tab-${gi}-${i}`;
      if (panels[i]) { panels[i].id = `md-panel-${gi}-${i}`; b.setAttribute('aria-controls', panels[i].id); panels[i].setAttribute('aria-labelledby', b.id); }
      b.addEventListener('click', () => pick(b.dataset.tab, b));
      b.addEventListener('keydown', (e) => {
        const to = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (to === undefined) return;
        e.preventDefault();
        const t = tabs[(to + tabs.length) % tabs.length];
        t.focus(); pick(t.dataset.tab, t);
      });
    });
    g.querySelector('.md-tab-list').hidden = false;
    g.classList.add('is-tabs');
    show(g, picks.find((k) => keys(g).includes(k)) || tabs[0].dataset.tab);
  });
})();

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
    const words = searchWords(search.value);
    let n = 0;
    for (const it of items) {
      const ok = searchHasAll(it.dataset.k + ' ' + it.textContent, words);
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
  // not logged in (no data-session in the header): nothing to ask, the count is already in the page
  if (!('session' in (document.querySelector('[data-account]')?.dataset || {}))) show({ count: +countEl.textContent || 0, liked: false, can: false, reason: 'login' });
  else if (btn.dataset.likeState) show(JSON.parse(btn.dataset.likeState));   // a logged-in visitor's own state, in the page
  else fetch(`/auth/like.php?slug=${encodeURIComponent(slug)}&kind=${kind}&t=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' })
    .then((r) => r.json()).then(show).catch(() => {});
  btn.addEventListener('click', async () => {
    if (!state) return;
    if (!state.can) {
      const back = encodeURIComponent(location.pathname);
      say(state.reason === 'login'
        ? `Likes are for patrons. <a rel="nofollow" href="/auth/login.php?return=${back}">Log in with Patreon</a> to like this ${what}.`
        : 'Likes are for patrons on any paid tier.' + joinLink());
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

// ── Download waiting page: count down and go (the server checks again). The "Unlock this model?" question is a plain link back
//    to the server, which starts the countdown ──
(() => {
  const box = document.querySelector('[data-dl-wait]');
  if (!box || document.querySelector('.age-gate') || box.querySelector('[data-dl-ask]')) return;   // never to the file behind the 18+ check
  const total = +box.dataset.dlWait || 0;
  const go = box.dataset.dlGo;
  const count = box.querySelector('[data-dl-count]');
  const left = box.querySelector('[data-dl-left]');
  const unit = box.querySelector('[data-dl-unit]');
  const bar = box.querySelector('[data-dl-bar]');
  const now = box.querySelector('[data-dl-now]');
  now.addEventListener('click', (e) => { if (now.getAttribute('aria-disabled') === 'true') e.preventDefault(); });
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
      try { up ? input.stepUp() : input.stepDown(); } catch {   // step="any" (prices): one whole unit, within min / max
      const v = (parseFloat(input.value) || 0) + (up ? 1 : -1);
      input.value = String(Math.min(input.max !== '' ? +input.max : Infinity, Math.max(input.min !== '' ? +input.min : -Infinity, Math.round(v * 100) / 100)));
    }
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

// ── Header search: the magnifier opens a search bar that grows over the menu (✕ or Esc closes it) ──
// It searches models, accessories, docs and FAQ as you type. The list comes once from /search/?index=1; matching =
// search_run() in search.php (accents ignored, every word must be found). Enter opens the highlighted result, or the
// /search/ page; "/" opens the bar from anywhere; a click outside closes it. The bar grows from the magnifier: the panel's
// clip-path starts at the button's box (--hs-from) and opens to the whole header.
(() => {
  const root = document.querySelector('[data-hsearch]');
  if (!root) return;
  const btn = root.querySelector('[data-hsearch-open]');
  const panel = root.querySelector('[data-hsearch-panel]');
  const input = root.querySelector('[data-hsearch-input]');
  const list = root.querySelector('[data-hsearch-list]');
  const closeBtn = root.querySelector('[data-hsearch-close]');
  const MAX = 8;
  const fold = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let index = null;
  let loading = null;
  let active = -1;
  let timer = 0;
  let failed = false;
  // a list that couldn't be loaded says so (not "Nothing found") and is asked for again at the next opening
  const load = () => loading || (loading = fetch('/search/?index=1').then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); }).then((d) => {
    index = (Array.isArray(d) ? d : []).map((en, i) => ({ ...en, i, ft: fold(en.t), hay: fold(`${en.t} ${en.s} ${en.k} ${en.x}`) }));
    failed = false;
    render();
  }).catch(() => { failed = true; loading = null; render(); }));
  // where the bar goes: over the menu only, from its first tab to the magnifier, so the logo and the account buttons stay;
  // when the menu is a second row (phones, a narrow admin), over the header's top row
  const place = () => {
    const box = root.parentElement.getBoundingClientRect();
    const nav = root.parentElement.querySelector(':scope > .nav');
    const b = btn.getBoundingClientRect();
    const row = !nav || getComputedStyle(nav).order === '3';
    const l = row ? 0 : nav.getBoundingClientRect().left - box.left;
    const r = row ? 0 : box.right - b.right;
    const bottom = row ? Math.max(0, box.height - 64) : 0;   // 64 px = a one-row header
    panel.style.inset = `0 ${r - 6}px ${bottom}px ${l - 6}px`;
    list.style.left = l + 'px';
    list.style.right = r + 'px';
    // the panel is see-through (the header's own colour shows): hide what it covers (the tabs, the magnifier; on phones the top row)
    uncover();
    const p = panel.getBoundingClientRect();
    under = [...root.parentElement.children, btn].filter((el) => {
      if (el === root) return false;
      const e = el.getBoundingClientRect();
      return Math.min(e.right, p.right) > Math.max(e.left, p.left) && Math.min(e.bottom, p.bottom) - Math.max(e.top, p.top) > e.height / 2;
    });
    under.forEach((el) => el.classList.add('hs-under'));
  };
  let under = [];
  const uncover = () => { under.forEach((el) => el.classList.remove('hs-under')); under = []; };
  // the magnifier's box inside the panel (top right bottom left), where the bar grows from and shrinks back to
  const from = () => {
    const p = panel.getBoundingClientRect();
    const b = btn.getBoundingClientRect();
    panel.style.setProperty('--hs-from', `${b.top - p.top}px ${p.right - b.right}px ${p.bottom - b.bottom}px ${b.left - p.left}px`);
  };
  const isOpen = () => root.classList.contains('is-open');
  const open = () => {
    if (isOpen()) { input.focus(); return; }
    clearTimeout(timer);
    panel.hidden = false;
    place();
    from();
    panel.getBoundingClientRect();   // start the animation from the magnifier
    root.classList.add('is-open');
    panel.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    input.focus({ preventScroll: true });
    input.select();
    load();
  };
  const close = (focus = false) => {
    if (!isOpen()) return;
    from();
    root.classList.remove('is-open');
    panel.classList.remove('is-open');
    uncover();
    btn.setAttribute('aria-expanded', 'false');
    timer = setTimeout(() => { if (!isOpen()) panel.hidden = true; }, 340);
    if (focus) btn.focus();
  };
  function search(q) {
    const words = fold(q.trim()).split(/\s+/).filter(Boolean);
    if (!words.length || !index) return [];
    const full = words.join(' ');
    const start = new RegExp(`(^|\\W)${words[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`);
    return index.filter((en) => words.every((w) => en.hay.includes(w)))
      .map((en) => [en.ft.startsWith(full) ? 0 : start.test(en.ft) ? 1 : start.test(en.hay) ? 2 : en.ft.includes(words[0]) ? 3 : 4, en])
      .sort((a, b) => a[0] - b[0] || a[1].i - b[1].i)
      .map((x) => x[1]);
  }
  function render() {
    const q = input.value.trim();
    active = -1;
    if (!q) { list.innerHTML = ''; return; }
    if (!index) { list.innerHTML = `<li class="hs-none">${failed ? 'Search isn’t available right now. Try again in a moment.' : 'Searching…'}</li>`; return; }
    const hits = search(q);
    const all = `<li class="hs-all"><a href="/search/?q=${encodeURIComponent(q)}">See all results for “${esc(q)}” →</a></li>`;
    if (!hits.length) { list.innerHTML = `<li class="hs-none">Nothing found for “${esc(q)}”.</li>`; return; }
    list.innerHTML = hits.slice(0, MAX).map((en) => `<li><a href="${esc(en.u)}">${en.p
      ? `<img class="hs-pic" src="${esc(en.p)}" alt="" loading="lazy" />`
      : `<span class="hs-pic" aria-hidden="true">${esc(en.t.slice(0, 1).toUpperCase())}</span>`}<span class="hs-t">${esc(en.t)}</span><span class="hs-s"><span class="hs-k">${esc(en.k)}</span>${en.s ? ' · ' + esc(en.s) : ''}</span></a></li>`).join('')
      + (hits.length > MAX ? all : '');
  }
  const links = () => [...list.querySelectorAll('a')];
  const mark = (i) => {
    const ls = links();
    if (!ls.length) return;
    active = (i + ls.length) % ls.length;
    ls.forEach((a, j) => a.classList.toggle('is-active', j === active));
    ls[active].scrollIntoView({ block: 'nearest' });
  };
  btn.addEventListener('click', (e) => { e.preventDefault(); open(); });
  closeBtn.addEventListener('click', () => close(true));
  list.addEventListener('click', (e) => { if (e.target.closest('a')) close(); });   // a result on this same page (/faq/#…) loads nothing: the bar goes
  input.addEventListener('input', () => { if (failed && !loading) load(); render(); });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); links()[active]?.click(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen()) { close(true); return; }
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || e.target.closest?.('input, textarea, select, [contenteditable]')) return;
    if (document.querySelector('.age-gate, dialog[open]')) return;   // not over the 18+ check, nor behind an open window (viewer, tags)
    e.preventDefault();
    open();
  });
  document.addEventListener('pointerdown', (e) => { if (isOpen() && !root.contains(e.target)) close(); });
  root.addEventListener('focusout', (e) => { if (e.relatedTarget && !root.contains(e.relatedTarget)) close(); });
  window.addEventListener('resize', () => { if (isOpen()) { place(); from(); } });
})();

// ── Searchable "What is it about?" menus (bugs.php, select[data-about-picker]): one field you type into (a combobox), with the
// picked item's picture in front; the list opens under it, filtered as you type (accents ignored, every word must match the name,
// franchise / type or "gen N"), one row per choice, grouped Models / Accessories / Other. Emptying the field picks the select's
// empty option. The select keeps the value and gets "change", so the form works as before (and without JavaScript).
// Options carry data-kind, name, sub, gen, thumb, count.
(() => {
  const sels = document.querySelectorAll('select[data-about-picker]');
  if (!sels.length) return;
  const fold = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const mk = (tag, attrs = {}, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined && v !== false) k === 'text' ? (n.textContent = v) : n.setAttribute(k, v === true ? '' : v);
    n.append(...kids.flat().filter((k) => k !== null && k !== undefined && k !== ''));
    return n;
  };
  const ICONS = {
    site: '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>',
    other: '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.6a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.4"/><path d="M12 16.8v.2"/>',
    none: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  };
  const GROUPS = [['model', 'Models'], ['outfit', 'Accessories'], ['rest', 'Other']];
  let uid = 0;
  sels.forEach((sel) => {
    const items = [...sel.options].map((o) => ({
      value: o.value, kind: o.value === '' ? 'none' : o.dataset.kind || 'other', name: o.dataset.name || o.textContent, sub: o.dataset.sub || '',
      gen: o.dataset.gen || '', thumb: o.dataset.thumb || '', count: o.dataset.count || '', label: o.textContent,
    }));
    for (const i of items) i.text = fold([i.name, i.sub, i.gen ? 'gen ' + i.gen : ''].join(' '));
    const byValue = new Map(items.map((i) => [i.value, i]));
    const empty = byValue.get('');
    const pic = (i) => {
      const box = mk('span', { class: 'ap-pic' + (i.thumb.startsWith('data:') ? ' is-blurred' : '') });
      if (i.thumb) box.append(mk('img', { src: i.thumb, alt: '', loading: 'lazy', decoding: 'async' }));
      else if (i.kind === 'model' || i.kind === 'outfit') box.textContent = i.name.split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
      else box.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[i.kind] || ICONS.other) + '</svg>';
      return box;
    };
    const id = 'ap-list-' + ++uid;
    const q = mk('input', { class: 'ap-q', type: 'text', role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': id,
      'aria-label': sel.getAttribute('aria-label') || 'Search', placeholder: empty ? empty.name : 'Search…', autocomplete: 'off', spellcheck: 'false' });
    const lead = mk('span', { class: 'ap-lead' });
    const gen = mk('span', { class: 'ap-gen', hidden: true });
    const chev = mk('span', { class: 'ap-chev', 'aria-hidden': 'true', text: '▾' });
    const field = mk('div', { class: 'ap-field input' + (sel.classList.contains('input-sm') ? ' input-sm' : '') }, lead, q, gen, chev);
    const list = mk('div', { class: 'ap-list', id, role: 'listbox' });
    const foot = mk('div', { class: 'ap-foot' });
    const pop = mk('div', { class: 'ap-pop', hidden: true }, list, foot);
    const wrap = mk('div', { class: 'ap' }, field, pop);
    sel.hidden = true;
    sel.after(wrap);

    let typed = false;   // false = the field shows the picked name (the list shows everything), true = it holds a search
    const paint = () => {
      const i = byValue.get(sel.value);
      const on = i && i.value !== '';
      lead.replaceChildren(on ? pic(i) : pic({ kind: 'none', thumb: '', name: '' }));
      q.value = on ? i.name : '';
      gen.hidden = !(on && i.gen);
      gen.textContent = on && i.gen ? 'Gen ' + i.gen : '';
      field.title = on ? i.label : '';
      typed = false;
    };
    let act = -1;
    const rowsEls = () => [...list.querySelectorAll('[data-v]')];
    const mark = (n) => {
      const rs = rowsEls();
      rs.forEach((r, k) => r.classList.toggle('act', k === n));
      act = n;
      if (rs[n]) { q.setAttribute('aria-activedescendant', rs[n].id); rs[n].scrollIntoView({ block: 'nearest' }); } else q.removeAttribute('aria-activedescendant');
    };
    const render = () => {
      const words = typed ? fold(q.value).split(/\s+/).filter(Boolean) : [];
      list.replaceChildren();
      let n = 0;
      for (const [g, title] of GROUPS) {
        const rs = items.filter((i) => i.value !== '' && (g === 'rest' ? !['model', 'outfit'].includes(i.kind) : i.kind === g) && words.every((w) => i.text.includes(w)));
        if (!rs.length) continue;
        list.append(mk('div', { class: 'ap-h', text: title, role: 'presentation' }));
        for (const i of rs) {
          list.append(mk('div', { class: 'ap-row' + (i.value === sel.value ? ' on' : ''), 'data-v': i.value, id: id + '-' + n, role: 'option', 'aria-selected': i.value === sel.value ? 'true' : 'false', title: i.label },
            pic(i), mk('span', { class: 'ap-tx' }, i.name, i.sub ? mk('small', { text: i.sub }) : null),
            i.gen ? mk('span', { class: 'ap-gen', text: 'Gen ' + i.gen }) : null, i.count ? mk('span', { class: 'ap-n', text: i.count }) : null));
          n++;
        }
      }
      if (!n) list.append(mk('p', { class: 'ap-none', text: 'Nothing matches “' + q.value.trim() + '”.' }));
      foot.textContent = words.length ? (n ? n + ' found · Enter picks the highlighted one' : '') : 'Type to search, ↑ ↓ and Enter to pick';
      mark(words.length && n ? 0 : -1);
    };
    const isOpen = () => !pop.hidden;
    const open = () => {
      if (isOpen()) return;
      render();
      pop.hidden = false;
      q.setAttribute('aria-expanded', 'true');
      field.classList.add('is-open');
      list.querySelector('.on')?.scrollIntoView({ block: 'center' });
    };
    const close = () => {
      if (!isOpen()) return;
      pop.hidden = true;
      q.setAttribute('aria-expanded', 'false');
      field.classList.remove('is-open');
      q.removeAttribute('aria-activedescendant');
    };
    const pick = (v) => {
      const changed = v !== sel.value;
      sel.value = v;
      paint();
      close();
      if (changed) { sel.dispatchEvent(new Event('input', { bubbles: true })); sel.dispatchEvent(new Event('change', { bubbles: true })); }
    };
    // leaving the field: an emptied field clears the choice (when the select has an empty option), anything else goes back to the picked name
    const settle = () => {
      if (typed && q.value.trim() === '' && empty) pick('');
      else { paint(); close(); }
    };
    q.addEventListener('focus', () => { q.select(); open(); });
    q.addEventListener('mousedown', () => { if (document.activeElement === q && !isOpen()) open(); });
    q.addEventListener('input', () => { typed = true; lead.replaceChildren(pic({ kind: 'none', thumb: '', name: '' })); gen.hidden = true; open(); render(); });
    field.addEventListener('mousedown', (e) => {   // the picture, Gen chip and ▾ act like the field; ▾ also closes an open list
      if (e.target === q) return;
      e.preventDefault();
      if (document.activeElement !== q) q.focus();
      else if (isOpen()) settle();
      else open();
    });
    list.addEventListener('mousedown', (e) => e.preventDefault());   // keep the focus in the field
    list.addEventListener('click', (e) => { const r = e.target.closest('[data-v]'); if (r) pick(r.dataset.v); });
    q.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { if (isOpen() || typed) { e.preventDefault(); paint(); close(); } return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen()) { open(); return; }
        const rs = rowsEls();
        if (rs.length) mark(e.key === 'ArrowDown' ? (act + 1) % rs.length : (act <= 0 ? rs.length : act) - 1);
      } else if (e.key === 'Enter') {
        if (!isOpen()) return;
        e.preventDefault();
        const rs = rowsEls();
        if (rs[act]) pick(rs[act].dataset.v); else if (typed && q.value.trim() === '' && empty) pick('');
      } else if (e.key === 'Tab' && isOpen()) settle();
    });
    q.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== q) settle(); }, 0));
    // a hidden required select can't show the browser's own message: open the list instead
    sel.addEventListener('invalid', (e) => { e.preventDefault(); field.classList.add('ap-need'); field.scrollIntoView({ block: 'center' }); q.focus(); });
    sel.addEventListener('change', () => field.classList.remove('ap-need'));
    paint();
  });
})();

// ── Bug reports (bugs.php): the "pick a model" menu, and the report form (versions of the picked item,
// "Is it one of these?" from /bugs/?index=1, loaded once when a title is typed) ──
(() => {
  document.querySelectorAll('[data-bug-about-pick] select').forEach((s) => s.addEventListener('change', () => s.form.submit()));

  const form = document.querySelector('[data-bug-form]');
  if (!form) return;
  const versions = JSON.parse(form.dataset.versions || '{}');
  const about = form.querySelector('[data-bug-about]');
  const verBox = form.querySelector('[data-bug-ver]');
  const ver = verBox?.querySelector('select');
  about?.addEventListener('change', () => {
    const list = versions[about.value] || [];
    verBox.hidden = !list.length;
    ver.replaceChildren(...list.map((v, i) => new Option(v + (i === 0 ? ' (latest)' : ''), v)), new Option('Not Sure', ''));
    // model and accessory reports must give the Blender / MustardUI versions: drop "optional", make them required
    const need = /^(models|outfits):/.test(about.value);
    form.querySelectorAll('[data-ver-opt]').forEach((o) => (o.hidden = need));
    form.querySelectorAll('[data-ver-in]').forEach((i) => (i.required = need));
  });

  // picked pictures: too many or too big is said at once (a form over the server's limit arrives empty and the text is lost)
  const pics = form.querySelector('[data-bug-pics]');
  pics?.addEventListener('change', () => {
    const files = [...pics.files];
    const mb = (n) => Math.round(n / 1048576) + ' MB';
    const total = files.reduce((t, f) => t + f.size, 0);
    const max = +pics.dataset.bugPics, each = +pics.dataset.each, all = +pics.dataset.total;
    pics.setCustomValidity(files.length > max ? `Pick ${max} pictures at most.`
      : files.some((f) => f.size > each) ? `Each picture can be ${mb(each)} at most.`
      : all && total > all ? `These pictures are ${mb(total)} together: the limit is ${mb(all)}.` : '');
    pics.reportValidity();
  });

  const title = form.querySelector('[data-bug-title]');
  const box = form.querySelector('[data-bug-similar]');
  const out = form.querySelector('[data-bug-similar-list]');
  if (!title || !box) return;
  const fold = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const SKIP = new Set(['the', 'and', 'with', 'when', 'her', 'his', 'not', 'does', 'doesn', 'for', 'from', 'after', 'into', 'are', 'is', 'in', 'on', 'of', 'to', 'a', 'it']);
  let index = null;
  let loading = false;
  const shut = (b) => (b.k === 'fixed' || b.k === 'closed' ? 1 : 0);
  const show = () => {
    const words = fold(title.value).split(/[^a-z0-9]+/).filter((w) => w.length > 2 && !SKIP.has(w));
    if (!index || !words.length) { box.hidden = true; return; }
    const scored = index.map((b) => {
      const t = fold(b.t + ' ' + b.a);
      return [words.filter((w) => t.includes(w)).length, b];
    }).filter(([n]) => n >= Math.min(2, words.length))
      // open reports first: Fixed / Closed ones have no Me Too
      .sort((x, y) => shut(x[1]) - shut(y[1]) || y[0] - x[0] || y[1].m - x[1].m).slice(0, 3);
    box.hidden = !scored.length;
    const tone = { new: 'sky', confirmed: 'amber', progress: 'violet', fixed: 'green', closed: 'grey' };
    out.replaceChildren(...scored.map(([, b]) => {
      const a = document.createElement('a');
      a.href = `/bugs/${b.n}/`;
      a.target = '_blank';
      const t = document.createElement('span'); t.textContent = b.t;
      const s = document.createElement('span'); s.className = 'badge tone-' + (tone[b.k] || 'grey'); s.textContent = b.s;
      const m = document.createElement('small'); m.textContent = `#${b.n} · ${b.a}` + (b.m ? ` · ${b.m === 1 ? '1 person has' : b.m + ' people have'} it too` : '');
      a.append(t, s, m);
      return a;
    }));
  };
  let timer;
  title.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      if (index || loading) return show();
      if (title.value.trim().length < 3) return;
      loading = true;
      fetch('/bugs/?index=1', { credentials: 'same-origin' }).then((r) => r.json()).then((d) => { index = Array.isArray(d) ? d : []; show(); }).catch(() => { loading = false; });   // tried again at the next key
    }, 250);
  });
})();

// The creator's ⋯ menus on a bug report (<details class="pmenu">): one open at a time, closed by a click elsewhere or Esc.
(() => {
  const menus = [...document.querySelectorAll('details.pmenu')];
  if (!menus.length) return;
  const close = (except) => menus.forEach((m) => { if (m !== except) m.open = false; });
  menus.forEach((m) => m.addEventListener('toggle', () => { if (m.open) close(m); }));
  document.addEventListener('click', (e) => { if (!e.target.closest('details.pmenu')) close(); });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = menus.find((m) => m.open);
    if (open) { open.open = false; open.querySelector('summary').focus(); }
  });
})();

// ── 3D preview (model / accessory pages, app/model3d.php): nothing 3D loads until "View in 3D" is pressed; then the main
// picture makes way for the viewer (assets/vendor/model-viewer.min.js, this site's own copy) and the .glb file ──
(() => {
  const btn = document.querySelector('[data-v3d]');
  if (!btn) return;
  const ico = (d) => `<svg viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
  const tool = (attr, label, d, pressed) => `<button type="button" ${attr}${pressed ? ' aria-pressed="true"' : ''} aria-label="${label}" title="${label}">${ico(d)}</button>`;
  btn.addEventListener('click', async () => {
    const media = btn.closest('.media');
    const pics = [...media.children];
    const box = document.createElement('div');
    box.className = 'v3d';
    box.innerHTML = `<div class="v3d-load" role="status">Loading 3D preview…<small>${btn.querySelector('.v3d-size').textContent}</small></div><div class="v3d-bar"><i></i></div>`
      + '<div class="v3d-tools">'
      + tool('data-v3d-close', 'Back to pictures', '<path d="M6 6l12 12M18 6 6 18"/>')
      + tool('data-v3d-spin', 'Turn by itself', '<path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5"/>', true)
      + tool('data-v3d-reset', 'Reset view', '<circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/>')
      + (document.fullscreenEnabled ? tool('data-v3d-full', 'Full screen', '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>') : '')
      + '</div><span class="v3d-hint">Drag to turn · scroll or pinch to zoom</span>';
    pics.forEach((n) => (n.hidden = true));
    media.append(box);
    const load = box.querySelector('.v3d-load');
    const close = () => {
      if (document.fullscreenElement === box) document.exitFullscreen();
      box.remove();
      pics.forEach((n) => (n.hidden = false));
      btn.focus();
    };
    box.querySelector('[data-v3d-close]').addEventListener('click', close);
    box.querySelector('[data-v3d-close]').focus();
    try {
      // decoders the viewer would otherwise fetch from other websites point at this site (files that need them are refused at upload)
      self.ModelViewerElement = Object.assign(self.ModelViewerElement || {}, { dracoDecoderLocation: '/assets/vendor/none/', ktx2TranscoderLocation: '/assets/vendor/none/', lottieLoaderLocation: '/assets/vendor/none/' });
      await import(btn.dataset.v3dLib || '/assets/vendor/model-viewer.min.js');   // ?v= from the page: .js is cached for a year
    } catch {
      load.textContent = 'The 3D viewer could not be loaded.';
      return;
    }
    if (!box.isConnected) return;   // closed while it loaded
    const mv = document.createElement('model-viewer');
    for (const [k, v] of Object.entries({ src: btn.dataset.v3d, alt: `${btn.dataset.v3dName} in 3D`, 'camera-controls': '', 'touch-action': 'pan-y',
      'auto-rotate': '', 'shadow-intensity': '1', exposure: btn.dataset.v3dExposure || '1.1', 'interaction-prompt': 'none' })) mv.setAttribute(k, v);   // exposure: the item's Light (m3d_exposure())
    box.prepend(mv);
    const bar = box.querySelector('.v3d-bar');
    mv.addEventListener('progress', (e) => { bar.firstChild.style.width = Math.round(e.detail.totalProgress * 100) + '%'; });
    mv.addEventListener('load', () => { load.remove(); bar.remove(); });
    mv.addEventListener('error', () => { load.textContent = 'This 3D file could not be shown.'; bar.remove(); });
    const spin = box.querySelector('[data-v3d-spin]');
    spin.addEventListener('click', () => { mv.autoRotate = !mv.autoRotate; spin.setAttribute('aria-pressed', String(mv.autoRotate)); });
    box.querySelector('[data-v3d-reset]').addEventListener('click', () => { mv.cameraOrbit = 'auto auto auto'; mv.cameraTarget = 'auto auto auto'; mv.fieldOfView = 'auto'; });
    box.querySelector('[data-v3d-full]')?.addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : box.requestFullscreen()));
  });
})();

// The creator's tick boxes on the bug list (/bugs/): the bar at the bottom applies one change to every ticked report (admin/bugs.php).
(() => {
  const form = document.querySelector('form[data-bug-batch]');
  if (!form) return;
  const bar = form.querySelector('[data-bug-bar]');
  const all = document.querySelector('[data-bug-all]');
  const boxes = [...form.querySelectorAll('input[name="ns[]"]')];
  const ticked = () => boxes.filter((b) => b.checked).length;
  const update = () => {
    const n = ticked();
    boxes.forEach((b) => b.closest('li').classList.toggle('is-sel', b.checked));
    bar.hidden = n === 0;
    form.querySelector('[data-bug-count]').textContent = n;
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
  };
  boxes.forEach((b) => b.addEventListener('change', update));
  all?.addEventListener('change', () => { boxes.forEach((b) => (b.checked = all.checked)); update(); });
  form.querySelector('[data-bug-none]').addEventListener('click', () => { boxes.forEach((b) => (b.checked = false)); update(); });
  form.addEventListener('submit', (e) => {
    const n = ticked();
    if (form.elements.op.value === 'delete' && !confirm(`Delete ${n === 1 ? 'this report and its comments' : `these ${n} reports and their comments`}? This can't be undone.`)) e.preventDefault();
  });
  update();   // ticks the browser kept after going back
})();

// The creator's "Edit Tags" window on the bug pages (bug_tags_dialog()): add, delete, recolour and drag tags, then Save posts them (admin/bugs.php).
// Closed without saving (✕, Cancel, Esc): the next opening starts again from the saved tags.
(() => {
  const dlg = document.querySelector('dialog[data-tags-dlg]');
  if (!dlg) return;
  const form = dlg.querySelector('form');
  const saved = form.innerHTML;
  const colors = JSON.parse(dlg.dataset.colors || '[]');
  const $ = (q) => form.querySelector(q);
  const rowsOf = () => [...$('[data-tg-rows]').querySelectorAll('[data-tg-row]')];
  // Save stays grey until the tags differ from the saved ones (rows without a name don't count: they aren't saved)
  const tagState = () => JSON.stringify(rowsOf().map((r) => [r.querySelector('input[name="tag_id[]"]').value, r.querySelector('input[name="tag_name[]"]').value.trim(), r.querySelector('input[name="tag_color[]"]').value]).filter((x) => x[1] !== ''));
  const start = tagState();
  const dirty = () => setTimeout(() => { const b = $('[data-tg-save]'); if (b) b.disabled = tagState() === start; });
  const sync = () => { $('[data-tg-empty]').hidden = rowsOf().length > 0; dirty(); };
  const add = () => {
    const row = $('[data-tg-template]').content.firstElementChild.cloneNode(true);
    if (colors.length) row.querySelector('input[type=color]').value = colors[rowsOf().length % colors.length];
    $('[data-tg-rows]').append(row);
    sync();
    row.querySelector('input[name="tag_name[]"]').focus();
  };
  document.querySelectorAll('[data-tags-open]').forEach((b) => b.addEventListener('click', () => {
    dlg.showModal();
    if (!rowsOf().length) add();
  }));
  dlg.addEventListener('close', () => { form.innerHTML = saved; dirty(); });
  ['input', 'change', 'click', 'keydown', 'pointerup'].forEach((t) => form.addEventListener(t, dirty));
  dirty();
  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-tags-close]')) dlg.close();
    else if (e.target.closest('[data-tg-add]')) add();
    else if (e.target.closest('[data-tg-del]')) { e.target.closest('[data-tg-row]').remove(); sync(); }
  });
  // reorder: drag the ⠿ handle (mouse or touch), or focus it and use the arrow keys
  form.addEventListener('keydown', (e) => {
    const h = e.target.closest('[data-tg-drag]');
    if (!h || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    const row = h.closest('[data-tg-row]');
    const to = e.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
    if (!to) return;
    if (e.key === 'ArrowUp') to.before(row); else to.after(row);
    h.focus();
  });
  form.addEventListener('pointerdown', (e) => {
    const h = e.target.closest('[data-tg-drag]');
    if (!h) return;
    e.preventDefault();
    const row = h.closest('[data-tg-row]');
    row.classList.add('is-drag');
    const move = (ev) => {
      for (const other of rowsOf()) {
        if (other === row) continue;
        const r = other.getBoundingClientRect();
        if (ev.clientY > r.top && ev.clientY < r.bottom) {
          if (ev.clientY < r.top + r.height / 2) other.before(row); else other.after(row);
          break;
        }
      }
    };
    const up = () => {
      row.classList.remove('is-drag');
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      window.removeEventListener('pointercancel', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
  });
})();

// Creator Tools on a bug report: a new status Fixed / Closed switches Lock Comments on, opening it again switches it off (as the server does).
(() => {
  const form = document.querySelector('form.ctools');
  const lock = form?.querySelector('input[name=locked]');
  if (!lock) return;
  const done = (s) => s === 'fixed' || s === 'closed';
  const was = form.querySelector('input[name=status]:checked')?.value;
  const wasLocked = lock.checked;
  const set = form.querySelector('input[name=lock_set]');
  lock.addEventListener('change', () => { if (set) set.value = '1'; });   // flipped by hand: the server keeps this choice over the status's own
  form.addEventListener('change', (e) => {
    if (e.target.name !== 'status') return;
    const st = e.target.value;
    if (st === was) lock.checked = wasLocked;
    else if (done(st)) lock.checked = true;
    else if (done(was)) lock.checked = false;
  });
})();

// Forms marked [data-dirty]: their Save button stays grey until a field differs from how the page came (back to grey when it's put back).
(() => {
  const forms = document.querySelectorAll('form[data-dirty]');
  if (!forms.length) return;
  const state = (f) => JSON.stringify([...new FormData(f)].filter(([k]) => k !== 'csrf').map(([k, v]) => [k, typeof v === 'string' ? v : v.name]));
  forms.forEach((f) => {
    const btn = f.querySelector('button.btn-primary:not([type=button])');
    if (!btn) return;
    const start = state(f);
    const sync = () => setTimeout(() => { btn.disabled = state(f) === start; });   // after other listeners (Creator Tools sets the lock switch)
    btn.disabled = true;
    f.addEventListener('input', sync);
    f.addEventListener('change', sync);
    f.addEventListener('reset', sync);
    f.addEventListener('m3d-sync', sync);
  });
})();

// The creator edits a bug report's title in place (like GitHub): the pencil swaps the title for its field, Cancel / Esc puts it back.
(() => {
  const show = document.querySelector('[data-title-show]');
  const form = document.querySelector('[data-title-form]');
  if (!show || !form) return;
  const input = form.querySelector('input[name=title]');
  const saved = input.value;
  const open = (on) => {
    show.hidden = on;
    form.hidden = !on;
    if (on) { input.focus(); input.select(); } else { input.value = saved; form.dispatchEvent(new Event('m3d-sync')); show.querySelector('[data-title-edit]').focus(); }
  };
  show.querySelector('[data-title-edit]').addEventListener('click', (e) => { e.preventDefault(); open(true); });
  form.querySelector('[data-title-cancel]').addEventListener('click', (e) => { e.preventDefault(); open(false); });
  input.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); open(false); } });
})();

// Forms that send something (a bug report, a comment, Me Too, Follow…): one click only. A second click while the first is on its
// way would post the report or comment twice, or take Me Too / Follow back. The buttons aren't disabled (that would drop the
// pressed button's name from what is sent); a form a script stopped from sending stays usable, and going back to the page resets it.
(() => {
  const forms = document.querySelectorAll('form[method="post" i]');
  if (!forms.length) return;
  forms.forEach((f) => f.addEventListener('submit', (e) => {
    if (f.dataset.sending) { e.preventDefault(); return; }
    f.dataset.sending = '1';
    setTimeout(() => { if (e.defaultPrevented) delete f.dataset.sending; });
  }));
  window.addEventListener('pageshow', () => forms.forEach((f) => delete f.dataset.sending));
})();
