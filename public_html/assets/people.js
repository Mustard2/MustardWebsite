// The "Patreon ID or name" fields (input[data-person-pick] = its context: mod | user | '', printed with person_pick_js() in app/view.php): Add Moderator,
// Access → Add User, Personal Data, From Patrons' + Add Render and a render's window, the preview bar. Typing asks /admin/people.php for the matching
// people (picture, name, tags, tier · months · last download, ID); ↑ ↓ Enter / a click picks one: the field then posts "Name · ID" (m3d_find_patrons()
// reads it back) and shows as a card with their picture and ✕. A value already in the field (a saved one, or set by a script that then sends
// `person-sync`) shows as that card when it names one person. Accents and case are ignored by the server; this script only marks the letters.
(() => {
  const fields = document.querySelectorAll('input[data-person-pick]');
  if (!fields.length) return;
  const SRC = '/admin/people.php';
  const fold = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  let uid = 0;

  // the name with the first typed word marked (accents ignored: each letter folded on its own, so the places line up)
  const marked = (name, q) => {
    const w = fold(q).trim().split(/\s+/)[0] || '';
    const frag = document.createDocumentFragment();
    const chars = Array.from(name);
    const folded = chars.map((c) => fold(c));
    const flat = folded.join('');
    const at = w && !/^\d+$/.test(w) ? flat.indexOf(w) : -1;
    if (at < 0) { frag.append(name); return frag; }
    let pos = 0, from = -1, to = -1;
    folded.forEach((f, i) => { if (from < 0 && pos >= at) from = i; pos += f.length; if (to < 0 && pos >= at + w.length) to = i + 1; });
    frag.append(chars.slice(0, from).join(''), el('mark', '', chars.slice(from, to).join('')), chars.slice(to).join(''));
    return frag;
  };
  const avatar = (p, cls) => {
    if (!p.name && !p.pic) return el('span', 'ava pp-ava-id ' + cls, '#');
    const a = el('span', 'ava ' + cls + (p.pic ? ' has-pic' : ''));
    a.setAttribute('aria-hidden', 'true');
    if (p.pic) { const img = el('img'); img.src = p.pic; img.alt = ''; a.append(img); }
    else { a.style.setProperty('--h', p.h); a.textContent = Array.from(p.name)[0].toUpperCase(); }
    return a;
  };

  fields.forEach((inp) => {
    const ctx = inp.dataset.personPick || '';
    const wrap = el('span', 'pp-wrap');
    inp.before(wrap);
    wrap.append(inp);
    const box = el('div', 'pp-box');
    box.id = 'pp-box-' + ++uid;
    box.setAttribute('role', 'listbox');
    box.hidden = true;
    const chip = el('span', 'pp-chip');
    chip.hidden = true;
    wrap.append(box, chip);
    inp.setAttribute('role', 'combobox');
    inp.setAttribute('aria-autocomplete', 'list');
    inp.setAttribute('aria-expanded', 'false');
    inp.setAttribute('aria-controls', box.id);
    let list = [], act = -1, timer = 0, seq = 0;

    const ask = (q, one) => fetch(SRC + '?' + new URLSearchParams({ q, ctx, ...(one ? { one: '1' } : {}) }), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { people: [] })).then((d) => d.people || []).catch(() => []);
    const close = () => { box.hidden = true; inp.setAttribute('aria-expanded', 'false'); inp.removeAttribute('aria-activedescendant'); act = -1; };
    const light = (i) => {
      act = i;
      box.querySelectorAll('.pp-row').forEach((r, j) => { r.classList.toggle('act', j === i); r.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
      const row = box.querySelectorAll('.pp-row')[i];
      if (row) { inp.setAttribute('aria-activedescendant', row.id); row.scrollIntoView({ block: 'nearest' }); }
    };
    const place = () => {   // under the field (above it when there is no room), as wide as it or more, never past the screen's right edge
      box.style.left = box.style.maxHeight = '';
      box.classList.remove('up');
      const r = wrap.getBoundingClientRect(), b = box.getBoundingClientRect();
      if (r.left + b.width > innerWidth - 12) box.style.left = Math.min(0, innerWidth - 12 - b.width - r.left) + 'px';
      const below = innerHeight - r.bottom - 12, above = r.top - 12;
      const up = b.height > below && below < 240 && above > below;
      box.classList.toggle('up', up);
      if (b.height > (up ? above : below)) box.style.maxHeight = Math.max(200, up ? above : below) + 'px';
    };
    const render = (q) => {
      box.replaceChildren();
      if (!list.length) {
        box.append(el('div', 'pp-none', /^\d+$/.test(q.trim()) ? 'No known ID starts with these numbers.' : 'Nobody with this name: type their Patreon user ID.'));
      }
      list.forEach((p, i) => {
        const row = el('div', 'pp-row' + (p.off ? ' off' : ''));
        row.id = box.id + '-' + i;
        row.setAttribute('role', 'option');
        if (p.off) row.setAttribute('aria-disabled', 'true');
        const l1 = el('span', 'pp-l1');
        const nm = el('b');
        nm.append(p.name ? marked(p.name, q) : 'ID ' + p.id);
        l1.append(nm, ...p.tags.map(([t, c]) => el('span', 'pp-tag ' + c, t)));
        const txt = el('span', 'pp-txt');
        txt.append(l1);
        if (p.meta) txt.append(el('span', 'pp-meta', p.meta));
        const right = el('span', 'pp-right');
        if (p.name) right.append(el('span', 'pp-id', p.id));
        if (p.off) right.append(el('small', '', p.off));
        row.append(avatar(p, 'pp-ava'), txt, right);
        row.addEventListener('mousedown', (e) => e.preventDefault());   // keeps the focus in the field
        row.addEventListener('click', () => pick(i));
        row.addEventListener('mousemove', () => { if (act !== i && !p.off) light(i); });
        box.append(row);
      });
      box.hidden = false;
      inp.setAttribute('aria-expanded', 'true');
      place();
      light(list.findIndex((p) => !p.off));
    };
    const card = (p) => {
      chip.replaceChildren(avatar(p, 'pp-cava'), el('b', '', p.name || 'ID ' + p.id));
      if (p.name) chip.append(el('span', 'pp-id', p.id));
      const x = el('button', 'pp-x', '✕');
      x.type = 'button';
      x.title = 'Choose someone else';
      x.setAttribute('aria-label', 'Choose someone else than ' + (p.name || p.id));
      x.addEventListener('click', () => { unpick(); inp.value = ''; inp.dispatchEvent(new Event('change', { bubbles: true })); inp.focus(); });
      chip.append(x);
      chip.style.minHeight = inp.offsetHeight ? inp.offsetHeight + 'px' : '';
      chip.hidden = false;
      wrap.classList.add('is-picked');
    };
    const unpick = () => { chip.hidden = true; wrap.classList.remove('is-picked'); };
    const pick = (i) => {
      const p = list[i];
      if (!p || p.off) return;
      inp.value = p.pick;
      inp.classList.remove('is-miss');
      close();
      card(p);
      inp.dispatchEvent(new Event('change', { bubbles: true }));   // Access → Add User fills the Name field from it
    };
    const sync = () => {   // the value now in the field: a card when it names one person, else the field as it is
      unpick();
      const v = inp.value.trim();
      if (!v) return;
      const n = ++seq;
      ask(v, true).then((ps) => { if (n === seq && ps.length === 1 && inp.value.trim() === v) { list = ps; card(ps[0]); } });
    };

    inp.addEventListener('input', () => {
      clearTimeout(timer);
      const q = inp.value.trim();
      if (!q) { seq++; close(); return; }
      timer = setTimeout(() => {
        const n = ++seq;
        ask(q, false).then((ps) => { if (n !== seq || document.activeElement !== inp) return; list = ps; render(q); });
      }, 140);
    });
    inp.addEventListener('keydown', (e) => {
      if (box.hidden) { if (e.key === 'ArrowDown' && inp.value.trim()) inp.dispatchEvent(new Event('input')); return; }
      const ok = list.map((p, i) => (p.off ? -1 : i)).filter((i) => i >= 0);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!ok.length) return;
        const k = ok.indexOf(act);
        light(e.key === 'ArrowDown' ? ok[(k + 1) % ok.length] : ok[k <= 0 ? ok.length - 1 : k - 1]);
      } else if (e.key === 'Enter' && act >= 0) {
        e.preventDefault();
        pick(act);
      } else if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();   // a window around the field stays open
        close();
      } else if (e.key === 'Tab') close();
    });
    inp.addEventListener('blur', () => setTimeout(close, 120));
    inp.addEventListener('person-sync', sync);
    addEventListener('resize', () => { if (!box.hidden) place(); });
    if (inp.value.trim()) sync();
  });
})();
