// The bell of the admin header and of the creator's public header (notify_bell() in app/notify.php): the panel is filled from
// /admin/notify.php each time it opens (?list=1 → {html, count}); Mark All as Read and Publish post there and show the list again.
// Loaded only where the bell is printed (admins), by both headers, so it stays out of site.js and admin.js.
// Also Quick Add, the "+" printed next to the bell (quick_add() in app/view.php): the second part below.
(() => {
  const root = document.querySelector('[data-bell]');
  if (!root) return;
  const btn = root.querySelector('[data-bell-btn]');
  const panel = root.querySelector('[data-bell-panel]');
  const count = root.querySelector('[data-bell-count]');
  const src = root.dataset.src;
  let tab = 'all';
  let busy = false;

  const setCount = (n) => {
    count.hidden = !n;
    count.textContent = n > 99 ? '99+' : String(n);
    btn.setAttribute('aria-label', 'Notifications' + (n ? ' (' + n + ')' : ''));
  };
  const showTab = () => {
    panel.querySelectorAll('[data-nt-tab]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.ntTab === tab ? 'true' : 'false'));
    panel.querySelectorAll('[data-nt-g]').forEach((el) => {
      el.hidden = tab !== 'all' && !el.dataset.ntG.split(' ').includes(tab);
    });
  };
  const fail = (msg) => {
    panel.innerHTML = '<p class="is-loading"></p>';
    panel.firstChild.textContent = msg;
  };
  const load = async () => {
    if (!panel.innerHTML) panel.innerHTML = '<p class="is-loading">Loading…</p>';
    try {
      const r = await fetch(src + '?list=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (!d.ok) return fail(d.error || 'Couldn’t load the notifications.');
      panel.innerHTML = d.html;
      setCount(d.count);
      if (!panel.querySelector('[data-nt-tab="' + tab + '"]')) tab = 'all';
      showTab();
    } catch (e) {
      fail('Couldn’t load the notifications. Check the connection and open the bell again.');
    }
  };
  const post = async (data) => {
    if (busy) return;
    busy = true;
    try {
      const body = new URLSearchParams({ csrf: root.dataset.csrf, ...data });
      let d = {};
      try {
        const r = await fetch(src, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        d = await r.json();
      } catch (e) { /* no answer: said below */ }
      await load();
      if (!d.ok) {   // said in the panel, under its title
        const m = document.createElement('p');
        m.className = 'nt-err';
        m.textContent = 'Not saved: ' + (d.error || 'reload the page and try again.');
        const head = panel.querySelector('.nt-head');
        if (head) head.after(m); else panel.prepend(m);
      }
    } finally {
      busy = false;
    }
  };

  // on phones the panel is a sheet under the header, whatever its height (one row or two), ending above the screen's bottom
  const place = () => {
    const head = root.closest('.site-header');
    const top = window.matchMedia('(max-width: 720px)').matches && head ? head.getBoundingClientRect().height + 6 : 0;
    panel.style.top = top ? top + 'px' : '';
    panel.style.maxHeight = top ? 'calc(100dvh - ' + (top + 12) + 'px)' : '';
  };
  const open = () => {
    place();
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    load();
  };
  const close = () => {
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  };

  btn.addEventListener('click', () => (panel.hidden ? open() : close()));
  document.addEventListener('click', (e) => {
    if (!panel.hidden && !root.contains(e.target)) close();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !panel.hidden) { close(); btn.focus(); }
  });
  window.addEventListener('resize', () => { if (!panel.hidden) place(); });
  panel.addEventListener('click', (e) => {
    const t = e.target.closest('[data-nt-tab]');
    if (t) { tab = t.dataset.ntTab; showTab(); return; }
    if (e.target.closest('[data-nt-readall]')) { post({ do: 'read-all' }); return; }
    const p = e.target.closest('[data-nt-publish]');
    if (p) { p.disabled = true; post({ do: 'publish', id: p.dataset.ntPublish }); }
  });
})();

// Quick Add: the "+" opens a short menu (links: a new model, accessory, blog post). Add Changelog Version swaps it for a list
// of the models and accessories (/admin/jump.php?items=1, loaded once), newest version first, with a search box; a click
// opens that item's editor with a new version added on top (edit.php ?add=version).
(() => {
  const root = document.querySelector('[data-qa]');
  if (!root) return;
  const btn = root.querySelector('[data-qa-btn]');
  const panel = root.querySelector('[data-qa-panel]');
  const menu = root.querySelector('[data-qa-menu]');
  const pickView = root.querySelector('[data-qa-pickview]');
  const find = root.querySelector('[data-qa-find]');
  const list = root.querySelector('[data-qa-list]');
  const fold = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  let items = null;   // null = not loaded yet

  const msg = (text) => {
    const li = document.createElement('li');
    li.className = 'qa-msg';
    li.textContent = text;
    list.replaceChildren(li);
  };
  const render = () => {
    if (!items) return;
    const words = fold(find.value).split(/\s+/).filter(Boolean);
    const hits = items.filter((it) => words.every((w) => it.f.includes(w)));
    if (!hits.length) return msg(items.length ? 'Nothing found.' : 'No models or accessories yet.');
    list.replaceChildren(...hits.map((it) => {
      const a = document.createElement('a');
      a.href = it.u + '&add=version';
      const pic = document.createElement(it.p ? 'img' : 'span');
      if (it.p) { pic.src = it.p; pic.alt = ''; pic.loading = 'lazy'; } else pic.className = 'qa-noimg';
      const b = document.createElement('b');
      b.textContent = it.t;
      const sm = document.createElement('small');
      sm.textContent = [it.v ? 'Latest: ' + it.v : 'No versions yet', it.k === 'Model' ? it.s : it.k].filter(Boolean).join(' · ');
      a.append(pic, b, sm);
      const li = document.createElement('li');
      li.append(a);
      return li;
    }));
  };
  const load = async () => {
    if (items) return render();
    msg('Loading…');
    try {
      const r = await fetch('/admin/jump.php?items=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (!d.ok) return msg(d.error || 'Couldn’t load the list.');
      items = d.items.map((it) => ({ ...it, f: fold([it.t, it.s, it.k, it.x].join(' ')) }))
        .sort((a, b) => (b.d || 0) - (a.d || 0) || a.t.localeCompare(b.t));   // the latest updated first: the likely next one
      render();
    } catch (e) {
      msg('Couldn’t load the list. Check the connection and try again.');
    }
  };
  const showMenu = () => { pickView.hidden = true; menu.hidden = false; };
  const showPick = () => {
    menu.hidden = true;
    pickView.hidden = false;
    find.focus();
    load();
  };
  // on phones the panel is a sheet under the header (like the bell's)
  const place = () => {
    const head = root.closest('.site-header');
    panel.style.top = window.matchMedia('(max-width: 720px)').matches && head ? head.getBoundingClientRect().height + 6 + 'px' : '';
  };
  const open = () => {
    showMenu();
    place();
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    menu.querySelector('.qa-row')?.focus({ preventScroll: true });
  };
  const close = () => {
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  };
  // ↑ ↓ go through the rows (menu) or from the search box through the list; Enter in the search box opens the first match
  const moveFocus = (e) => {
    const rows = [...(menu.hidden ? [find, ...list.querySelectorAll('a')] : menu.querySelectorAll('.qa-row'))];
    const i = rows.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault();
    rows[Math.max(0, Math.min(rows.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))].focus();
  };

  btn.addEventListener('click', () => (panel.hidden ? open() : close()));
  root.querySelector('[data-qa-pick]').addEventListener('click', showPick);
  root.querySelector('[data-qa-back]').addEventListener('click', () => { showMenu(); menu.querySelector('[data-qa-pick]').focus(); });
  find.addEventListener('input', render);
  find.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); list.querySelector('a')?.click(); }
  });
  document.addEventListener('click', (e) => {
    if (!panel.hidden && !root.contains(e.target)) close();
  });
  document.addEventListener('keydown', (e) => {
    if (panel.hidden) return;
    if (e.key === 'Escape') { close(); btn.focus(); }
    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') moveFocus(e);
  });
  window.addEventListener('resize', () => { if (!panel.hidden) place(); });
})();
