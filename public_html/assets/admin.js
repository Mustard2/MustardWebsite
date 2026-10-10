// Admin panel
const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';

// ── Small helpers ─────────────────────────────────────────────────────────────
function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class') n.className = v;
    else if (k === 'text') n.textContent = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else if (k === 'value') n.value = v;
    else n.setAttribute(k, v === true ? '' : v);
  }
  for (const c of kids.flat()) if (c !== null && c !== undefined && c !== false) n.append(c.nodeType ? c : document.createTextNode(c));
  return n;
}

function toast(msg, kind = 'ok') {
  const t = document.querySelector('[data-toast]');
  if (!t) return alert(msg);
  t.textContent = msg;
  t.className = 'toast ' + kind;
  t.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (t.hidden = true), kind === 'err' ? 8000 : 3000);
}

async function post(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(body),
  });
  let data = null;
  try { data = await r.json(); } catch { /* not JSON */ }
  if (!r.ok || !data?.ok) throw Object.assign(new Error(data?.error || `Server error (${r.status})`), { data });
  return data;
}

async function upload(file, model) {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('model', model || 'misc');
  fd.append('csrf', CSRF);
  const r = await fetch('/admin/upload.php', { method: 'POST', credentials: 'same-origin', body: fd });
  let data = null;
  try { data = await r.json(); } catch { /* not JSON */ }
  if (!r.ok || !data?.ok) throw new Error(data?.error || `Upload failed (${r.status})`);
  return data.url;
}

function autoGrow(ta) {
  const fit = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 2 + 'px'; };
  ta.addEventListener('input', fit);
  requestAnimationFrame(fit);
  return ta;
}

// "Box" menu for the text editors: wraps the selected lines (or a placeholder) in "> [!TYPE]".
const BOX_TYPES = [['NOTE', 'Note'], ['TIP', 'Tip'], ['IMPORTANT', 'Important'], ['WARNING', 'Warning'], ['CAUTION', 'Caution']];
function boxMenu(getTextarea, cls = 'tb') {
  const sel = el('select', { class: cls + ' box-menu', title: 'Add a Note / Tip / Warning box', 'aria-label': 'Add a box' },
    el('option', { value: '' }, 'Box…'), BOX_TYPES.map(([v, l]) => el('option', { value: v }, l)));
  sel.addEventListener('change', () => {
    const type = sel.value;
    sel.value = '';
    if (!type) return;
    const ta = getTextarea();
    const v = ta.value;
    let a = ta.selectionStart, b = ta.selectionEnd;
    if (a !== b) { a = v.lastIndexOf('\n', a - 1) + 1; const e = v.indexOf('\n', b); b = e < 0 ? v.length : e; }
    const text = v.slice(a, b).trim() || 'Text of the box';
    const before = v.slice(0, a), after = v.slice(b);
    const pre = before === '' || before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
    const post = after === '' || after.startsWith('\n\n') ? '' : after.startsWith('\n') ? '\n' : '\n\n';
    const box = `> [!${type}]\n` + text.split('\n').map((l) => (l.trim() ? '> ' + l : '>')).join('\n');
    ta.setRangeText(pre + box + post, a, b, 'end');
    const start = a + pre.length + `> [!${type}]\n> `.length;
    if (text === 'Text of the box') ta.setSelectionRange(start, start + text.length);
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  });
  return sel;
}

function iconBtn(label, title, onclick, cls = '') {
  return el('button', { type: 'button', class: 'icon-btn ' + cls, title, 'aria-label': title, onclick }, label);
}

// ── Cropper: drag a frame over the picture (move it, resize from the handles), pick a shape. ──
// Resolves with {x, y, w, h} in the image's own pixels, 'skip' (Keep as is) or null (Cancel). The server crops (images.php).
const CROP_RATIOS = [['Free', 0], ['4:5', 4 / 5], ['1:1', 1], ['16:10', 16 / 10], ['16:9', 16 / 9]];
function cropImage(src, { aspect = 0, title = 'Crop image', skip = false } = {}) {
  return new Promise((resolve) => {
    const img = el('img', { src, alt: '', draggable: 'false' });
    const box = el('div', { class: 'crop-box' }, ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].map((h) => el('span', { class: 'crop-h crop-' + h, 'data-h': h })));
    // the frame is placed over the picture (crop-area); the stage around it leaves room for the handles on its edges
    const area = el('div', { class: 'crop-area' }, img, box);
    const stage = el('div', { class: 'crop-stage' }, area);
    const info = el('span', { class: 'muted small' }, 'Loading…');
    let ratio = aspect, r = { x: 0, y: 0, w: 0, h: 0 }, W = 0, H = 0;
    const ratioBtns = CROP_RATIOS.map(([label, v]) => el('button', { type: 'button', class: 'chip', 'aria-pressed': String(v === ratio), onclick: () => setRatio(v) }, label));
    const close = (v) => { removeEventListener('resize', onResize); dlg.close(); dlg.remove(); resolve(v); };
    const result = () => { const s = img.naturalWidth / W; return { x: Math.round(r.x * s), y: Math.round(r.y * s), w: Math.round(r.w * s), h: Math.round(r.h * s) }; };
    const dlg = el('dialog', { class: 'cropper', 'aria-label': title },
      el('div', { class: 'crop-head' }, el('b', {}, title), el('div', { class: 'crop-ratios', role: 'group', 'aria-label': 'Shape' }, ratioBtns)),
      stage,
      el('div', { class: 'crop-foot' }, info, el('div', { class: 'row-btns' },
        skip ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => close('skip') }, 'Keep as is') : null,
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => close(null) }, 'Cancel'),
        el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => close(result()) }, 'Crop'))));
    const draw = () => {
      Object.assign(box.style, { left: r.x + 'px', top: r.y + 'px', width: r.w + 'px', height: r.h + 'px' });
      box.classList.toggle('locked', !!ratio);
      const s = img.naturalWidth / W;
      info.textContent = `${Math.round(r.w * s)} × ${Math.round(r.h * s)} px`;
    };
    const fit = () => {   // the biggest centred frame of the chosen shape
      let w = W, h = H;
      if (ratio) { h = w / ratio; if (h > H) { h = H; w = h * ratio; } }
      r = { x: (W - w) / 2, y: (H - h) / 2, w, h };
      draw();
    };
    const setRatio = (v) => { ratio = v; ratioBtns.forEach((b, i) => b.setAttribute('aria-pressed', String(CROP_RATIOS[i][1] === v))); fit(); };
    const measure = () => { W = img.clientWidth; H = img.clientHeight; };
    const onResize = () => { const k = img.clientWidth / W; measure(); r = { x: r.x * k, y: r.y * k, w: r.w * k, h: r.h * k }; draw(); };
    const clamp = (v, a, b) => Math.min(Math.max(v, a), b);
    const MIN = 24;
    stage.addEventListener('pointerdown', (e) => {
      const handle = e.target.dataset.h || (e.target === box ? 'move' : null);
      if (!handle || !W) return;
      e.preventDefault();
      const s0 = { ...r }, px = e.clientX, py = e.clientY;
      const right = s0.x + s0.w, bottom = s0.y + s0.h;
      const move = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        const dx = ev.clientX - px, dy = ev.clientY - py;
        let { x, y, w, h } = s0;
        if (handle === 'move') { x = clamp(s0.x + dx, 0, W - s0.w); y = clamp(s0.y + dy, 0, H - s0.h); }
        else if (ratio) {   // corners only: the opposite corner stays put, the shape is kept
          const west = handle.includes('w'), north = handle.includes('n');
          const maxW = Math.min(west ? right : W - s0.x, (north ? bottom : H - s0.y) * ratio);
          w = clamp(s0.w + (west ? -dx : dx), MIN, maxW);
          h = w / ratio;
          x = west ? right - w : s0.x;
          y = north ? bottom - h : s0.y;
        } else {
          if (handle.includes('e')) w = clamp(s0.w + dx, MIN, W - s0.x);
          if (handle.includes('w')) { x = clamp(s0.x + dx, 0, right - MIN); w = right - x; }
          if (handle.includes('s')) h = clamp(s0.h + dy, MIN, H - s0.y);
          if (handle.includes('n')) { y = clamp(s0.y + dy, 0, bottom - MIN); h = bottom - y; }
        }
        r = { x, y, w, h };
        draw();
      };
      const end = (ev) => { if (ev.pointerId !== e.pointerId) return; removeEventListener('pointermove', move); removeEventListener('pointerup', end); removeEventListener('pointercancel', end); };
      addEventListener('pointermove', move);
      addEventListener('pointerup', end);
      addEventListener('pointercancel', end);
    });
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(null); });
    addEventListener('resize', onResize);
    document.body.append(dlg);
    dlg.showModal();
    const ready = () => { measure(); fit(); };
    if (img.complete && img.naturalWidth) ready(); else img.addEventListener('load', ready, { once: true });
    img.addEventListener('error', () => { info.textContent = 'This image could not be loaded.'; }, { once: true });
  });
}

// Drag handle (⠿) that reorders an item (getItem(handle)) among its siblings of the same kind (same first class): mouse, touch,
// or the arrow keys on the focused handle. Works for lists and grids. After a move, onDone(item) runs if given
// (to update a data model), otherwise the form gets an "input" event (unsaved changes).
function wireDrag(h, getItem, onDone) {
  const peers = (item) => [...item.parentNode.children].filter((c) => c !== item && c.classList[0] === item.classList[0]);
  const done = (item) => (onDone ? onDone(item) : item.closest('form')?.dispatchEvent(new Event('input', { bubbles: true })));
  // moving the item in the page would cancel pointer capture on the handle, so the drag listens on the window
  h.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    e.preventDefault();
    const item = getItem(h);
    let moved = false;
    item.classList.add('dragging');
    document.body.classList.add('is-dragging');
    const move = (ev) => {
      if (ev.pointerId !== e.pointerId) return;
      ev.preventDefault();
      const me = item.getBoundingClientRect();
      for (const s of peers(item)) {
        const r = s.getBoundingClientRect();
        if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) continue;
        const after = !!(item.compareDocumentPosition(s) & Node.DOCUMENT_POSITION_FOLLOWING);
        const sameRow = Math.abs(r.top - me.top) < r.height / 2;   // grids (gallery): compare sideways
        const past = sameRow
          ? (after ? ev.clientX > r.left + r.width / 2 : ev.clientX < r.left + r.width / 2)
          : (after ? ev.clientY > r.top + r.height / 2 : ev.clientY < r.top + r.height / 2);
        if (past) { if (after) s.after(item); else s.before(item); moved = true; }
        break;
      }
      if (ev.clientY < 70) scrollBy(0, -14); else if (ev.clientY > innerHeight - 70) scrollBy(0, 14);
    };
    const end = (ev) => {
      if (ev.pointerId !== e.pointerId) return;
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', end);
      removeEventListener('pointercancel', end);
      item.classList.remove('dragging');
      document.body.classList.remove('is-dragging');
      // the click that follows a drag must not open/close a <details> or press a button underneath
      const swallow = (c) => { c.preventDefault(); c.stopPropagation(); };
      addEventListener('click', swallow, { capture: true, once: true });
      setTimeout(() => removeEventListener('click', swallow, true), 0);
      if (moved) done(item);
    };
    addEventListener('pointermove', move, { passive: false });
    addEventListener('pointerup', end);
    addEventListener('pointercancel', end);
  });
  h.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); });   // inside <summary>: don't open/close
  h.addEventListener('keydown', (e) => {
    const dir = { ArrowUp: -1, ArrowLeft: -1, ArrowDown: 1, ArrowRight: 1 }[e.key];
    if (!dir) return;
    e.preventDefault();
    const item = getItem(h);
    const sib = dir < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sib || sib.classList[0] !== item.classList[0]) return;
    if (dir < 0) sib.before(item); else sib.after(item);
    h.focus();
    done(item);
  });
  return h;
}
function dragHandle(getItem, onDone) {
  return wireDrag(el('button', { type: 'button', class: 'drag-handle drag-sm', title: 'Drag to reorder (or focus and use the arrow keys)', 'aria-label': 'Drag to reorder' }, '⠿'), getItem, onDone);
}

// ── Dashboard: drag rows to reorder (mouse, touch, or the arrow keys on a focused handle) ──
const sortable = document.querySelector('[data-sortable]');
if (sortable) {
  const orderOf = () => [...sortable.querySelectorAll('tr[data-slug]')].map((r) => r.dataset.slug);
  let saved = orderOf().join();
  const persist = async () => {
    const slugs = orderOf();
    if (slugs.join() === saved) return;
    sortable.classList.add('saving');
    try { await post('/admin/save.php', { kind: sortable.dataset.kind || 'models', action: 'reorder', slugs }); saved = slugs.join(); toast('Order saved ✓'); }
    catch (e) { toast(e.message, 'err'); }
    sortable.classList.remove('saving');
  };

  let drag = null;
  sortable.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('.drag-handle');
    if (!handle || e.button > 0) return;
    e.preventDefault();
    const row = handle.closest('tr');
    drag = { row, id: e.pointerId };
    handle.setPointerCapture(e.pointerId);
    row.classList.add('dragging');
    document.body.classList.add('is-dragging');
  });
  sortable.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const rows = [...sortable.querySelectorAll('tr[data-slug]')].filter((r) => r !== drag.row);
    const target = rows.find((r) => { const b = r.getBoundingClientRect(); return e.clientY < b.top + b.height / 2; });
    if (target) { if (target.previousElementSibling !== drag.row) sortable.insertBefore(drag.row, target); }
    else if (sortable.lastElementChild !== drag.row) sortable.append(drag.row);
    // scroll the page when dragging near the top/bottom edge
    if (e.clientY < 90) window.scrollBy(0, -12); else if (e.clientY > innerHeight - 50) window.scrollBy(0, 12);
  });
  const end = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    drag.row.classList.remove('dragging');
    document.body.classList.remove('is-dragging');
    drag = null;
    persist();
  };
  sortable.addEventListener('pointerup', end);
  sortable.addEventListener('pointercancel', end);
  sortable.addEventListener('keydown', (e) => {
    const handle = e.target.closest('.drag-handle');
    if (!handle || !['ArrowUp', 'ArrowDown'].includes(e.key)) return;
    e.preventDefault();
    const row = handle.closest('tr');
    if (e.key === 'ArrowUp' && row.previousElementSibling) sortable.insertBefore(row, row.previousElementSibling);
    if (e.key === 'ArrowDown' && row.nextElementSibling) sortable.insertBefore(row.nextElementSibling, row);
    handle.focus();
    clearTimeout(sortable.t);
    sortable.t = setTimeout(persist, 600);   // save once you stop pressing
  });
}

// ── Image library: filter, upload, copy address, delete ──────────────────────
const lib = document.querySelector('[data-lib]');
const libUpload = document.querySelector('[data-upload-files]');
if (libUpload) {
  libUpload.addEventListener('change', async () => {
    const folder = document.querySelector('[data-upload-folder]').value;
    const files = [...libUpload.files];
    const label = libUpload.closest('label');
    let done = 0;
    for (const f of files) {
      label.firstChild.textContent = `Uploading ${done + 1}/${files.length}…`;
      try { await upload(f, folder); done++; } catch (e) { toast(`${f.name}: ${e.message}`, 'err'); }
    }
    libUpload.value = '';
    if (done) location.reload(); else label.firstChild.textContent = 'Upload images';
  });
}
if (lib) {
  const cards = [...lib.querySelectorAll('.lib-card')];
  const search = document.querySelector('[data-lib-search]');
  const folder = document.querySelector('[data-lib-folder]');
  const unused = document.querySelector('[data-lib-unused]');
  const count = document.querySelector('[data-lib-count]');
  const empty = document.querySelector('[data-lib-empty]');
  const apply = () => {
    const q = search.value.trim().toLowerCase();
    let n = 0;
    for (const c of cards) {
      const ok = (!q || c.dataset.name.includes(q)) && (!folder.value || c.dataset.folder === folder.value) && (!unused.checked || c.dataset.used === '0');
      c.hidden = !ok;
      if (ok) n++;
    }
    count.textContent = n === cards.length ? '' : `${n} shown`;
    empty.hidden = n > 0;
  };
  [search, folder, unused].forEach((x) => x.addEventListener('input', apply));
  if (unused.checked) apply();   // opened with ?unused=1
  lib.addEventListener('click', async (e) => {
    const card = e.target.closest('.lib-card');
    if (!card) return;
    if (e.target.closest('[data-copy]')) {
      try { await navigator.clipboard.writeText(card.dataset.url); toast('Address copied ✓'); }
      catch { prompt('Copy this address:', card.dataset.url); }
    }
    const cropBtn = e.target.closest('[data-crop]');
    if (cropBtn) {
      const used = +cropBtn.dataset.usedCount;
      const box = await cropImage(card.dataset.url, { title: 'Crop ' + card.dataset.url.split('/').pop() });
      if (!box) return;
      cropBtn.disabled = true; cropBtn.textContent = 'Cropping…';
      try {
        const r = await post('/admin/images.php', { action: 'crop', url: card.dataset.url, replace: true, ...box });
        toast(used ? `Cropped ✓ Replaced in ${r.replaced || used} ${used === 1 ? 'place' : 'places'}.` : 'Cropped ✓ Saved as a new image; the original is kept.');
        setTimeout(() => location.reload(), 1200);
      } catch (err) { toast(err.message, 'err'); cropBtn.disabled = false; cropBtn.textContent = 'Crop'; }
    }
    if (e.target.closest('[data-del]')) {
      if (!confirm(`Delete ${card.dataset.url.split('/').pop()}? This can't be undone.`)) return;
      try { await post('/admin/images.php', { action: 'delete', url: card.dataset.url }); card.remove(); cards.splice(cards.indexOf(card), 1); apply(); toast('Image deleted'); }
      catch (err) { toast(err.message, 'err'); }
    }
  });
}

// ── Editor ────────────────────────────────────────────────────────────────────
const form = document.querySelector('[data-editor]');
if (form) {
  const data = JSON.parse(document.getElementById('editor-data').textContent);
  if (data.kind === 'faq') initFaqEditor(data);
  else if (data.kind === 'docs') initDocsEditor(data);
  else initEditor(data);
}

// ── Docs editor: sections and pages on the left, the selected page on the right ──
function initDocsEditor(data) {
  const docs = data.docs;
  docs.sections = docs.sections || [];
  let dirty = false;
  const mark = () => (dirty = true);
  let cur = null;   // { s: section index, p: page index }
  const allPages = () => docs.sections.flatMap((x) => x.pages);
  const slugify = (t) => t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
  const uniqueSlug = (base, except) => {
    const used = new Set(allPages().filter((p) => p !== except).map((p) => p.slug));
    let s = base || 'page', n = 2;
    while (used.has(s)) s = `${base || 'page'}-${n++}`;
    return s;
  };
  // the docs home (/docs/) is the first page that isn't a draft
  const homePage = () => allPages().find((p) => !p.draft) || allPages()[0];
  const urlOf = (p) => (homePage() === p ? '/docs/' : `/docs/${p.slug}/`);
  const viewLink = document.querySelector('[data-view-page]');

  const tree = el('nav', { class: 'dt', 'aria-label': 'Pages' });
  const pane = el('div', { class: 'dp' });
  form.append(tree, pane);

  const select = (s, p) => {
    cur = { s, p };
    renderTree(); renderPane();
    const page = docs.sections[s].pages[p];
    history.replaceState(null, '', `?page=${encodeURIComponent(page.slug)}`);
    if (innerWidth < 1000) pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  // after a drag in the tree: the new DOM order (data-<key> = old index) becomes the order in the data
  const reorder = (owner, prop, box, sel, key) => {
    const page = currentPage();
    const old = owner[prop];
    owner[prop] = [...box.querySelectorAll(':scope > ' + sel)].map((n) => old[+n.dataset[key]]);
    cur = page ? locate(page) : null;
    mark(); renderTree(); renderPane();
  };
  const locate = (page) => { for (const [si, sec] of docs.sections.entries()) { const pi = sec.pages.indexOf(page); if (pi >= 0) return { s: si, p: pi }; } return null; };
  const currentPage = () => (cur ? docs.sections[cur.s]?.pages[cur.p] : null);
  const delSec = (s) => {
    const n = docs.sections[s].pages.length;
    if (!confirm(n ? `Delete this section and its ${n} page${n > 1 ? 's' : ''}?` : 'Delete this empty section?')) return;
    const page = currentPage();
    docs.sections.splice(s, 1);
    cur = page ? locate(page) : null;
    mark(); renderTree(); renderPane();
  };
  const addSec = () => {
    docs.sections.push({ title: 'New section', pages: [] });
    mark(); renderTree();
    const inputs = tree.querySelectorAll('.dt-sec-title');
    inputs[inputs.length - 1].select();
  };
  const addPage = (s) => {
    const page = { slug: uniqueSlug('new-page'), title: 'New page', summary: '', body: '', isNew: true };
    docs.sections[s].pages.push(page);
    mark();
    select(s, docs.sections[s].pages.length - 1);
    pane.querySelector('input').select();
  };

  function renderTree() {
    tree.replaceChildren(
      ...docs.sections.map((sec, si) => {
        const title = el('input', { class: 'input dt-sec-title', type: 'text', value: sec.title, placeholder: 'Section title', maxlength: 80, 'aria-label': 'Section title' });
        title.addEventListener('input', () => { sec.title = title.value; mark(); const opt = pane.querySelector(`option[value="${si}"]`); if (opt) opt.textContent = title.value || 'Untitled section'; });
        const pages = sec.pages.map((p, pi) => el('li', { class: 'dt-page' + (cur && cur.s === si && cur.p === pi ? ' active' : ''), 'data-pi': pi },
          dragHandle((h) => h.closest('.dt-page'), (item) => reorder(sec, 'pages', item.parentNode, '.dt-page', 'pi')),
          el('button', { type: 'button', class: 'dt-link', onclick: () => select(si, pi) }, p.title || 'Untitled page', homePage() === p ? el('span', { class: 'dt-home' }, 'home') : null, p.draft ? el('span', { class: 'dt-draft' }, 'draft') : null)));
        return el('div', { class: 'dt-sec', 'data-si': si },
          el('div', { class: 'dt-sec-head' }, dragHandle((h) => h.closest('.dt-sec'), (item) => reorder(docs, 'sections', item.parentNode, '.dt-sec', 'si')), title,
            iconBtn('✕', 'Delete section', () => delSec(si), 'del')),
          pages.length ? el('ul', { class: 'dt-pages' }, pages) : el('p', { class: 'muted small dt-empty' }, 'No pages yet.'),
          el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => addPage(si) }, '+ Page'));
      }),
      el('button', { type: 'button', class: 'btn btn-ghost btn-sm add dt-add-sec', onclick: addSec }, '+ Section'));
  }

  // Text helpers for the toolbar
  const replaceSel = (ta, fn) => {
    const a = ta.selectionStart, b = ta.selectionEnd;
    const [text, selA, selB] = fn(ta.value.slice(a, b));
    ta.setRangeText(text, a, b, 'end');
    if (selA !== undefined) ta.setSelectionRange(a + selA, a + selB);
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  };
  const wrap = (ta, left, right, ph) => replaceSel(ta, (t) => [left + (t || ph) + right, left.length, left.length + (t || ph).length]);
  const lines = (ta, fn) => {
    // extend the selection to whole lines
    const v = ta.value;
    ta.selectionStart = v.lastIndexOf('\n', ta.selectionStart - 1) + 1;
    const e = v.indexOf('\n', ta.selectionEnd);
    ta.selectionEnd = e < 0 ? v.length : e;
    replaceSel(ta, (t) => { const out = t.split('\n').map(fn).join('\n'); return [out, 0, out.length]; });
  };
  const block = (ta, text) => {
    const before = ta.value.slice(0, ta.selectionStart);
    const pre = before === '' || before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
    replaceSel(ta, () => [pre + text + '\n\n']);
  };

  function renderPane() {
    const page = currentPage();
    if (!page) {
      pane.replaceChildren(el('p', { class: 'muted dp-empty' }, 'Select a page on the left, or add one with + Page.'));
      viewLink.href = '/docs/';
      return;
    }
    viewLink.href = urlOf(page);
    const title = el('input', { class: 'input', type: 'text', value: page.title, maxlength: 120, required: true });
    const slug = el('input', { class: 'input', type: 'text', value: page.slug, maxlength: 64, pattern: '[a-z0-9][a-z0-9-]*', spellcheck: 'false' });
    const sum = el('input', { class: 'input', type: 'text', value: page.summary || '', maxlength: 300, placeholder: 'One line under the title, also used in the docs home and by search engines' });
    const secSel = el('select', { class: 'input' }, docs.sections.map((sec, si) => el('option', { value: si, selected: si === cur.s }, sec.title || 'Untitled section')));
    let slugTouched = !page.isNew;
    title.addEventListener('input', () => {
      page.title = title.value;
      if (!slugTouched) { page.slug = uniqueSlug(slugify(title.value) || 'page', page); slug.value = page.slug; }
      tree.querySelector('.dt-page.active .dt-link').firstChild.textContent = title.value || 'Untitled page';
    });
    slug.addEventListener('input', () => { slugTouched = true; page.slug = slug.value.trim(); });
    slug.addEventListener('change', () => { page.slug = slugify(slug.value) || uniqueSlug('page', page); slug.value = page.slug; viewLink.href = urlOf(page); history.replaceState(null, '', `?page=${encodeURIComponent(page.slug)}`); });
    sum.addEventListener('input', () => (page.summary = sum.value));
    const draft = el('input', { type: 'checkbox', checked: !!page.draft });
    draft.addEventListener('change', () => { page.draft = draft.checked; mark(); renderTree(); viewLink.href = urlOf(page); });
    secSel.addEventListener('change', () => {
      const to = +secSel.value;
      docs.sections[cur.s].pages.splice(cur.p, 1);
      docs.sections[to].pages.push(page);
      mark(); select(to, docs.sections[to].pages.length - 1);
    });

    const body = el('textarea', { class: 'input dp-body', spellcheck: 'true', placeholder: 'Write the page here. See "How to format pages" above.' });
    body.value = page.body || '';
    body.addEventListener('input', () => (page.body = body.value));
    body.addEventListener('keydown', (e) => {
      if (!(e.ctrlKey || e.metaKey)) return;
      if (e.key === 'b') { e.preventDefault(); wrap(body, '**', '**', 'bold text'); }
      if (e.key === 'i') { e.preventDefault(); wrap(body, '*', '*', 'italic text'); }
      if (e.key === 'k') { e.preventDefault(); tools.link(); }
    });

    const file = el('input', { type: 'file', accept: 'image/*', multiple: true, hidden: true });
    const imgBtn = el('button', { type: 'button', class: 'tb', title: 'Upload pictures (several = side-by-side gallery)', onclick: () => file.click() }, 'Image');
    file.addEventListener('change', async () => {
      if (!file.files.length) return;
      imgBtn.disabled = true; imgBtn.textContent = 'Uploading…';
      const urls = [];
      try { for (const f of file.files) urls.push(await upload(f, 'docs')); } catch (e) { toast(e.message, 'err'); }
      if (urls.length) block(body, urls.map((u) => `![](${u})`).join('\n'));
      imgBtn.disabled = false; imgBtn.textContent = 'Image'; file.value = '';
    });
    const tools = {
      link: () => { const url = prompt('Link address (https://… or a site page like /docs/requirements/):'); if (url) wrap(body, '[', `](${url.trim()})`, 'link text'); },
    };
    const tb = (label, title, fn) => el('button', { type: 'button', class: 'tb', title, onclick: fn }, label);
    const toolbar = el('div', { class: 'dp-toolbar', role: 'toolbar', 'aria-label': 'Formatting' },
      tb('B', 'Bold (Ctrl+B)', () => wrap(body, '**', '**', 'bold text')),
      tb('I', 'Italic (Ctrl+I)', () => wrap(body, '*', '*', 'italic text')),
      tb('Link', 'Link (Ctrl+K)', tools.link),
      tb('H2', 'Heading', () => lines(body, (l) => '## ' + l.replace(/^#+\s*/, ''))),
      tb('H3', 'Smaller heading', () => lines(body, (l) => '### ' + l.replace(/^#+\s*/, ''))),
      tb('• List', 'Bullet list', () => lines(body, (l) => '- ' + l.replace(/^(-|\d+\.)\s+/, ''))),
      tb('1. Steps', 'Numbered steps', () => { let n = 0; lines(body, (l) => `${++n}. ` + l.replace(/^(-|\d+\.)\s+/, '')); }),
      boxMenu(() => body),
      tb('Code', 'Code block', () => replaceSel(body, (t) => ['\n```\n' + (t || 'code') + '\n```\n'])),
      tb('Table', 'Table', () => block(body, '| Column | Column |\n|---|---|\n| Text | Text |')),
      imgBtn, file);

    const preview = el('div', { class: 'md docs-body dp-preview', hidden: true });
    const tabWrite = el('button', { type: 'button', class: 'dp-tab active' }, 'Write');
    const tabPrev = el('button', { type: 'button', class: 'dp-tab' }, 'Preview');
    const show = async (prev) => {
      tabWrite.classList.toggle('active', !prev); tabPrev.classList.toggle('active', prev);
      body.hidden = prev; toolbar.hidden = prev; preview.hidden = !prev;
      if (prev) {
        preview.textContent = 'Loading…';
        try { preview.innerHTML = (await post('/admin/preview.php', { text: body.value })).html || '<p class="muted">Empty page.</p>'; } catch (e) { preview.textContent = e.message; }
      }
    };
    tabWrite.addEventListener('click', () => show(false));
    tabPrev.addEventListener('click', () => show(true));

    const isHome = homePage() === page;
    pane.replaceChildren(
      el('div', { class: 'dp-fields' },
        el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Title'), title),
        el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Section'), secSel),
        el('label', { class: 'field' }, lbl('Address', isHome ? 'The first non-draft page: shown at /docs/.' : ''), el('span', { class: 'prefixed' }, el('span', { class: 'muted' }, '/docs/'), slug, el('span', { class: 'muted' }, '/'))),
        el('label', { class: 'field check dp-draft' }, draft, lbl('Draft (only admins see it)')),
        el('label', { class: 'field dp-wide' }, el('span', { class: 'lbl' }, 'Summary'), sum)),
      el('div', { class: 'dp-tabs' }, tabWrite, tabPrev),
      toolbar, body, preview,
      el('div', { class: 'dp-foot' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm dp-del', onclick: () => {
          if (!confirm(`Delete the page "${page.title}"? It is removed when you save.`)) return;
          docs.sections[cur.s].pages.splice(cur.p, 1);
          cur = null; mark(); renderTree(); renderPane();
        } }, 'Delete page')));
  }

  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const saveBtn = document.querySelector('[data-save]');
  const save = async () => {
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    try {
      const out = { sections: docs.sections.map((sec) => ({ title: sec.title.trim(), pages: sec.pages.map((p) => ({ slug: p.slug, title: p.title.trim(), summary: (p.summary || '').trim(), body: p.body || '', draft: !!p.draft })) })) };
      await post('/admin/save.php', { kind: 'docs', docs: out });
      allPages().forEach((p) => delete p.isNew);
      dirty = false;
      toast('Saved ✓ The docs are updated.');
      const page = currentPage();
      if (page) viewLink.href = urlOf(page);
    } catch (e) { toast(e.message, 'err'); }
    saveBtn.disabled = false; saveBtn.textContent = 'Save';
  };
  saveBtn.addEventListener('click', save);
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } });

  // open the page from ?page=…, otherwise the first one
  let start = null;
  docs.sections.forEach((sec, si) => sec.pages.forEach((p, pi) => { if (p.slug === data.page) start = { s: si, p: pi }; }));
  if (!start && allPages().length) start = { s: docs.sections.findIndex((x) => x.pages.length), p: 0 };
  cur = start;
  renderTree(); renderPane();
}

// ── FAQ editor: topics → questions (answer in simple text formatting) ─────────
function initFaqEditor(data) {
  let dirty = false;
  const mark = () => (dirty = true);
  const growArea = (value, rows, placeholder) => { const t = autoGrow(el('textarea', { class: 'input', rows, placeholder })); t.value = value || ''; return t; };
  const insertAt = (ta, text) => {
    const a = ta.selectionStart ?? ta.value.length, b = ta.selectionEnd ?? a;
    ta.value = ta.value.slice(0, a) + text + ta.value.slice(b);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  };
  const card = (cls, head, body, onRemove, confirmText) => {
    const item = el('details', { class: 'rep-item ' + cls }, head, body);
    head.prepend(dragHandle(() => item));
    head.querySelector('.row-btns').append(   // reorder by dragging ⠿ (or focus it and use the arrow keys)
      iconBtn('✕', 'Remove', (e) => { e.preventDefault(); if (confirm(confirmText)) { item.remove(); mark(); } }, 'del'));
    return item;
  };
  const question = (it = {}) => {
    const q = el('input', { class: 'input', type: 'text', value: it.q || '', placeholder: 'The question', maxlength: 300 });
    const a = growArea(it.a, 4, 'The answer…');
    const file = el('input', { type: 'file', accept: 'image/*', hidden: true });
    const imgBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => file.click() }, 'Add image');
    file.addEventListener('change', async () => {
      if (!file.files[0]) return;
      imgBtn.disabled = true; imgBtn.textContent = 'Uploading…';
      try { const url = await upload(file.files[0], 'faq'); insertAt(a, `\n\n![](${url})\n\n`); } catch (e) { toast(e.message, 'err'); }
      imgBtn.disabled = false; imgBtn.textContent = 'Add image'; file.value = '';
    });
    const title = el('span', { class: 'rep-title' }, it.q || 'New question');
    q.addEventListener('input', () => (title.textContent = q.value || 'New question'));
    const head = el('summary', { class: 'rep-head' }, title, el('span', { class: 'row-btns' }));
    const node = card('faq-q', head, el('div', { class: 'rep-body' },
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Question'), q),
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Answer'), a),
      el('div', { class: 'row-btns' }, imgBtn, file, boxMenu(() => a, 'input input-sm'))), 'Remove this question?');
    node.get = () => ({ q: q.value.trim(), a: a.value.trim() });
    return node;
  };
  const topic = (s = {}, open = false) => {
    const t = el('input', { class: 'input', type: 'text', value: s.title || '', placeholder: 'Topic title, e.g. Cycles', maxlength: 120 });
    const list = el('div', { class: 'repeat' }, (s.items || []).map((it) => question(it)));
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const n = question(); n.open = true; list.append(n); n.querySelector('input').focus(); mark(); } }, '+ Add question');
    const title = el('span', { class: 'rep-title' }, `${s.title || 'New topic'} (${(s.items || []).length})`);
    const refresh = () => (title.textContent = `${t.value || 'New topic'} (${list.children.length})`);
    t.addEventListener('input', refresh);
    list.addEventListener('click', () => setTimeout(refresh));
    add.addEventListener('click', refresh);
    const head = el('summary', { class: 'rep-head' }, title, el('span', { class: 'row-btns' }));
    const node = card('faq-topic', head, el('div', { class: 'rep-body' }, el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Topic'), t), list, add), 'Remove this whole topic and its questions?');
    node.open = open;
    node.get = () => ({ title: t.value.trim(), items: [...list.children].map((c) => c.get()) });
    return node;
  };

  const intro = growArea(data.faq.intro, 2, 'Short text under the page title (optional)');
  const topics = el('div', { class: 'repeat' }, (data.faq.sections || []).map((s) => topic(s)));
  const addTopic = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const n = topic({}, true); topics.append(n); n.querySelector('input').focus(); mark(); } }, '+ Add topic');
  form.append(
    el('fieldset', { class: 'sec' }, el('legend', {}, 'Introduction'), intro),
    el('fieldset', { class: 'sec' }, el('legend', {}, 'Topics and questions'), topics, addTopic));
  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const saveBtn = document.querySelector('[data-save]');
  const save = async () => {
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    try {
      await post('/admin/save.php', { kind: 'faq', faq: { intro: intro.value.trim(), sections: [...topics.children].map((n) => n.get()) } });
      dirty = false;
      toast('Saved ✓ The FAQ is updated.');
    } catch (e) { toast(e.message, 'err'); }
    saveBtn.disabled = false; saveBtn.textContent = 'Save';
  };
  saveBtn.addEventListener('click', save);
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } });
}

// Help texts behind a "?": tip(text) is the button, lbl(label, hint) a field label with one. One floating box shows the text
// on hover or focus (a tap on phones), kept inside the window
function tip(text) {
  return el('button', { type: 'button', class: 'tip', 'data-tip': text, 'aria-label': 'Help: ' + text }, '?');
}
function lbl(label, hint) {
  return el('span', { class: 'lbl' }, label, hint && tip(hint));
}
(() => {
  let pop = null, cur = null;
  const hide = () => { if (pop) pop.hidden = true; cur = null; };
  const show = (b) => {
    pop ??= document.body.appendChild(el('div', { class: 'tip-pop', role: 'tooltip' }));
    cur = b;
    pop.textContent = '';
    if (b.dataset.tipLinks) {   // Links column of the lists: the link names, grouped by who gets them
      const names = JSON.parse(b.dataset.tipLinks);
      for (const [k, head] of [['patrons', 'Patrons'], ['public', 'Public']]) {
        if (!names[k]?.length) continue;
        pop.append(el('div', { class: 'tp-head tp-' + k }, head), el('ul', { class: 'tp-links' }, names[k].map((n) => el('li', {}, n))));
      }
    } else pop.textContent = b.dataset.tip;
    pop.hidden = false;
    const r = b.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
    const below = r.bottom + 8 + h < innerHeight;
    pop.style.left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, innerWidth - w - 8)) + 'px';
    pop.style.top = (below ? r.bottom + 8 : r.top - 8 - h) + 'px';
  };
  const TIPS = '.tip, [data-tip-links]';
  document.addEventListener('pointerover', (e) => { const b = e.target.closest?.(TIPS); if (b && b !== cur) show(b); else if (!b && cur && document.activeElement !== cur) hide(); });
  document.addEventListener('focusin', (e) => { if (e.target.matches?.(TIPS)) show(e.target); else hide(); });
  document.addEventListener('focusout', (e) => { if (e.target === cur) hide(); });
  document.addEventListener('click', (e) => { const b = e.target.closest?.(TIPS); if (b) { e.preventDefault(); show(b); } else hide(); });   // preventDefault: inside a <label>, not a click on its field
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
  window.addEventListener('scroll', hide, { passive: true });
})();

function initEditor(data) {
  const m = data.item;
  const isOutfit = data.kind === 'outfits';
  const what = isOutfit ? 'accessory' : 'model';
  const fields = {};
  let dirty = false;
  // uploads go to media/<model>/ or media/outfit-<outfit>/
  const slugNow = () => { const sl = (data.isNew ? fields.slug.get() : m.slug) || ''; return sl ? (isOutfit ? 'outfit-' + sl : sl) : 'misc'; };

  // Field builders. Each returns { node, get }. Help texts (o.hint) sit behind a "?" next to the label (tip(), shown on hover / tap)
  const text = (label, value, o = {}) => {
    const input = el('input', { class: 'input', type: o.type || 'text', value: value ?? '', placeholder: o.placeholder, list: o.list, maxlength: o.max });
    return { node: el('label', { class: 'field ' + (o.cls || '') }, lbl(label, o.hint), input), get: () => input.value.trim(), input };
  };
  const area = (label, value, o = {}) => {
    const ta = autoGrow(el('textarea', { class: 'input', rows: o.rows || 3, placeholder: o.placeholder }));
    ta.value = value ?? '';
    return { node: el('label', { class: 'field ' + (o.cls || '') }, lbl(label, o.hint), ta), get: () => ta.value.trim() };
  };
  const select = (label, value, options, o = {}) => {
    const s = el('select', { class: 'input' }, Object.entries(options).map(([k, v]) => el('option', { value: k, selected: k === value }, v)));
    return { node: el('label', { class: 'field ' + (o.cls || '') }, lbl(label, o.hint), s), get: () => s.value };
  };
  const check = (label, value, hint) => {
    const c = el('input', { type: 'checkbox', checked: !!value });
    return { node: el('label', { class: 'field check' }, c, lbl(label, hint)), get: () => c.checked };
  };

  // Image: preview + address + upload + crop. o.crop: open the cropper right after an upload (shape o.aspect).
  const image = (label, value, hint, o = {}) => {
    const input = el('input', { class: 'input', type: 'text', value: value || '', placeholder: '/media/… or https://…' });
    const prev = el('div', { class: 'img-prev' });
    const show = () => { prev.innerHTML = ''; if (input.value.trim()) prev.append(el('img', { src: input.value.trim(), alt: '' })); else prev.append(el('span', { class: 'muted small' }, 'No image')); };
    input.addEventListener('input', show);
    show();
    const file = el('input', { type: 'file', accept: 'image/*', hidden: true });
    const btn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => file.click() }, 'Upload…');
    const cropBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => crop(false) }, 'Crop…');
    const crop = async (afterUpload) => {
      const src = input.value.trim();
      if (!src) return;
      if (!src.startsWith('/media/')) return toast('Only images on this site can be cropped: upload it here first.', 'err');
      const box = await cropImage(src, { aspect: o.aspect || 0, title: (afterUpload ? 'Crop the new ' : 'Crop: ') + label.toLowerCase(), skip: afterUpload });
      if (!box || box === 'skip') return;
      cropBtn.disabled = true; cropBtn.textContent = 'Cropping…';
      try { input.value = (await post('/admin/images.php', { action: 'crop', url: src, ...box })).url; show(); form.dispatchEvent(new Event('input')); toast('Cropped ✓ Save to keep it.'); }
      catch (e) { toast(e.message, 'err'); }
      cropBtn.disabled = false; cropBtn.textContent = 'Crop…';
    };
    file.addEventListener('change', async () => {
      if (!file.files[0]) return;
      btn.disabled = true; btn.textContent = 'Uploading…';
      let ok = false;
      try { input.value = await upload(file.files[0], slugNow()); show(); form.dispatchEvent(new Event('input')); ok = true; }
      catch (e) { toast(e.message, 'err'); }
      btn.disabled = false; btn.textContent = 'Upload…'; file.value = '';
      if (ok && o.crop && !/\.gif$/i.test(input.value)) crop(true);
    });
    const clear = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { input.value = ''; show(); form.dispatchEvent(new Event('input')); } }, 'Remove');
    return { node: el('div', { class: 'field image-field' }, lbl(label, hint), el('div', { class: 'img-row' }, prev, el('div', { class: 'img-ctl' }, input, el('div', { class: 'row-btns' }, btn, cropBtn, clear, file)))), get: () => input.value.trim() };
  };

  // List of lines: Enter = new line, Shift+Enter = line break inside the same item
  const list = (label, items, o = {}) => {
    const wrap = el('div', { class: 'list' });
    const addRow = (value = '', after = null, focus = false) => {
      const ta = autoGrow(el('textarea', { class: 'input', rows: 1, placeholder: o.placeholder || '' }));
      ta.value = value;
      const row = el('div', { class: 'list-row' }, dragHandle(() => row), ta,
        el('div', { class: 'row-btns' }, iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      ta.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); addRow('', row, true); }
        if (e.key === 'Backspace' && ta.value === '' && wrap.children.length > 1) { e.preventDefault(); const prev = row.previousElementSibling; row.remove(); prev?.querySelector('textarea')?.focus(); }
      });
      ta.addEventListener('paste', (e) => {
        const t = e.clipboardData.getData('text');
        if (!t.includes('\n') || ta.value !== '') return;
        e.preventDefault();
        const lines = t.split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
        ta.value = lines.shift() || '';
        let last = row;
        for (const l of lines) last = addRow(l, last);
        ta.dispatchEvent(new Event('input'));
      });
      if (after) after.after(row); else wrap.append(row);
      if (focus) ta.focus();
      return row;
    };
    (items || []).forEach((v) => addRow(v));
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => addRow('', null, true) }, '+ ' + (o.addLabel || 'Add line'));
    return {
      node: el('div', { class: 'field' }, label && lbl(label, o.hint), wrap, add),
      get: () => [...wrap.querySelectorAll('textarea')].map((t) => t.value.trim()).filter(Boolean),
      count: () => wrap.children.length, add: () => addRow('', null, true),
    };
  };

  // Gallery: image cards with caption, reorder, remove; multi-upload
  const gallery = (items, hint = '', label = 'Gallery') => {
    const grid = el('div', { class: 'gal' });
    const addCard = (src, caption = '') => {
      const cap = el('input', { class: 'input', type: 'text', value: caption, placeholder: 'Caption / artist (optional)' });
      const card = el('div', { class: 'gal-card', 'data-src': src }, el('img', { src, alt: '', loading: 'lazy' }), cap,
        el('div', { class: 'row-btns' }, dragHandle(() => card), iconBtn('✕', 'Remove', () => { card.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      grid.append(card);
    };
    (items || []).forEach((g) => (typeof g === 'string' ? addCard(g) : addCard(g.src, g.caption || '')));
    const file = el('input', { type: 'file', accept: 'image/*', multiple: true, hidden: true });
    const up = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => file.click() }, 'Upload images…');
    file.addEventListener('change', async () => {
      const files = [...file.files];
      for (let i = 0; i < files.length; i++) {
        up.disabled = true; up.textContent = `Uploading ${i + 1}/${files.length}…`;
        try { addCard(await upload(files[i], slugNow())); form.dispatchEvent(new Event('input')); }
        catch (e) { toast(`${files[i].name}: ${e.message}`, 'err'); }
      }
      up.disabled = false; up.textContent = 'Upload images…'; file.value = '';
    });
    const byUrl = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { const u = prompt('Image address (/media/… or https://…)'); if (u) { addCard(u.trim()); form.dispatchEvent(new Event('input')); } } }, 'Add by address');
    return {
      node: el('div', { class: 'field' }, lbl(label, hint), grid, el('div', { class: 'row-btns' }, up, byUrl, file)),
      get: () => [...grid.children].map((c) => { const cap = c.querySelector('input').value.trim(); return cap ? { src: c.dataset.src, caption: cap } : c.dataset.src; }),
    };
  };

  // Repeating blocks (notes, changelog versions, extra sections)
  const repeat = (o) => {
    const wrap = el('div', { class: 'repeat' });
    const getters = new Map();
    const addItem = (value, top = false, open = true) => {
      const { body, get, title } = o.build(value || {});
      const head = el('summary', { class: 'rep-head' }, dragHandle(() => item), el('span', { class: 'rep-title' }, title()),
        el('span', { class: 'row-btns' }, iconBtn('✕', 'Remove', (e) => { e.preventDefault(); if (confirm(o.confirmRemove || 'Remove this block?')) { item.remove(); form.dispatchEvent(new Event('input')); } }, 'del')));
      const item = el('details', { class: 'rep-item', open }, head, body);
      body.addEventListener('input', () => (head.querySelector('.rep-title').textContent = title()));
      getters.set(item, get);
      if (top) wrap.prepend(item); else wrap.append(item);
      return item;
    };
    (o.items || []).forEach((v, i) => addItem(v, false, o.openFirst === undefined || i < o.openFirst));
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const it = addItem(o.blank ? o.blank() : {}, o.addTop); it.querySelector('input,textarea')?.focus(); form.dispatchEvent(new Event('input')); } }, '+ ' + o.addLabel);
    return { node: el('div', { class: 'field' }, o.label && lbl(o.label), o.addTop ? add : null, wrap, o.addTop ? null : add), get: () => [...wrap.children].map((it) => getters.get(it)()), count: () => wrap.children.length, add: () => add.click() };
  };

  const section = (title, hint, ...kids) => el('fieldset', { class: 'sec' }, el('legend', {}, title, hint && tip(hint)), ...kids);
  const grid2 = (...kids) => el('div', { class: 'grid2' }, ...kids);
  // Optional blocks (the Sidebar's lists): each shows only once it has an item; until then a "+ …" button for it in one shared row.
  // Removing the last item hides the block again (removals send an input event on the form)
  const optionalBlocks = (blocks) => {
    const row = el('div', { class: 'row-btns opt-add' });
    const syncs = blocks.map(([f, label]) => {
      const btn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '+ ' + label);
      row.append(btn);
      const sync = () => { const has = f.count() > 0; f.node.hidden = !has; btn.hidden = has; };
      btn.addEventListener('click', () => { f.node.hidden = false; btn.hidden = true; f.add(); syncAll(); });
      return sync;
    });
    const syncAll = () => { syncs.forEach((x) => x()); row.hidden = [...row.children].every((b) => b.hidden); };
    form.addEventListener('input', syncAll);
    syncAll();
    return row;
  };

  // Download links: label + address + who can use it
  const links = (items) => {
    const wrap = el('div', { class: 'links' });
    const addRow = (l = {}) => {
      const label = el('input', { class: 'input', type: 'text', value: l.label || '', placeholder: 'Label, e.g. Mega', maxlength: 60 });
      const url = el('input', { class: 'input', type: 'url', value: l.url || '', placeholder: 'https://…' });
      const access = el('select', { class: 'input' }, el('option', { value: 'patrons', selected: (l.access || 'patrons') === 'patrons' }, 'Patrons only'), el('option', { value: 'public', selected: l.access === 'public' }, 'Public'));
      const row = el('div', { class: 'link-row' }, label, url, access,
        el('div', { class: 'row-btns' }, dragHandle(() => row), iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      wrap.append(row);
      return row;
    };
    (items || []).forEach(addRow);
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { addRow({ label: wrap.children.length ? '' : 'Mega' }).querySelector('input').focus(); form.dispatchEvent(new Event('input')); } }, '+ Add download link');
    return {
      node: el('div', { class: 'field' }, lbl('Download links', 'Patrons only: the address is never shown on the site. Public: free for everyone.'), wrap, add),
      get: () => [...wrap.children].map((r) => { const [lb, u, a] = r.querySelectorAll('input, select'); return { label: lb.value.trim(), url: u.value.trim(), access: a.value }; }).filter((l) => l.url || l.label),
    };
  };

  // Add-ons required: name + download address, shown in the download box
  const addonList = (items) => {
    const wrap = el('div', { class: 'links' });
    const addRow = (a = {}) => {
      const label = el('input', { class: 'input', type: 'text', value: a.label || '', placeholder: 'Add-on name', maxlength: 60 });
      const url = el('input', { class: 'input', type: 'text', value: a.url || '', placeholder: 'https://… or a page of this site' });
      const row = el('div', { class: 'link-row addon-row' }, label, url,
        el('div', { class: 'row-btns' }, dragHandle(() => row), iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      wrap.append(row);
      return row;
    };
    (items || []).forEach(addRow);
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { addRow().querySelector('input').focus(); form.dispatchEvent(new Event('input')); } }, '+ Add required add-on');
    return {
      node: el('div', { class: 'field' }, lbl('Add-ons required', 'Shown in the download box with a link to get them (public, for everyone).'), wrap, add),
      get: () => [...wrap.children].map((r) => { const [lb, u] = r.querySelectorAll('input'); return { label: lb.value.trim(), url: u.value.trim() }; }).filter((a) => a.url || a.label),
    };
  };

  // Compatible models: tick the models an accessory is optimized for
  const modelPicker = (selected) => {
    const sel = new Set(selected || []);
    const q = el('input', { class: 'input', type: 'search', placeholder: 'Filter models…' });
    const grid = el('div', { class: 'mpick' }, data.models.map((mm) => el('label', { class: 'mpick-item', 'data-name': mm.name.toLowerCase() },
      el('input', { type: 'checkbox', value: mm.slug, checked: sel.has(mm.slug) }),
      mm.thumb ? el('img', { src: mm.thumb, alt: '', loading: 'lazy' }) : el('span', { class: 'no-img' }),
      el('span', {}, mm.name))));
    q.addEventListener('input', () => { const v = q.value.trim().toLowerCase(); grid.querySelectorAll('.mpick-item').forEach((it) => (it.hidden = v && !it.dataset.name.includes(v))); });
    return { node: el('div', { class: 'field' }, q, grid), get: () => [...grid.querySelectorAll('input:checked')].map((c) => c.value) };
  };

  // On top of a paid tier (never instead of it): minimum months as a patron, minimum total paid. Empty or 0 = none.
  const requirementFields = () => {
    fields.requireMonths = text('Months as a patron (at least)', m.requireMonths || '', { type: 'number', placeholder: '0 = no requirement', hint: 'From the start of their current membership.' });
    fields.requireTotal = text(`Total support (at least, ${data.currency})`, m.requireTotalCents ? String(m.requireTotalCents / 100) : '', { type: 'number', placeholder: '0 = no requirement' });
    fields.requireMonths.input.min = 0; fields.requireMonths.input.step = 1;
    // with both set: both needed (and) or either one enough (or)
    fields.requireMode = select('', m.requireMode === 'or' ? 'or' : 'and', { and: 'and', or: 'or' }, { cls: 'req-mode' });
    fields.requireMode.node.querySelector('select').setAttribute('aria-label', 'Both requirements, or either one');
    fields.requireTotal.input.min = 0; fields.requireTotal.input.step = 'any';
    fields.noLimits = check('Not counted in the download limits', m.noLimits);
  };

  // Who sees it: public, blurred pictures, password, draft. The password is never sent back: empty = keep the current one.
  // one bar of icon buttons (radio inputs, so arrow keys work) instead of a menu
  const VIS_ICONS = {
    public: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    blurred: '<path d="m3 3 18 18M10.6 6.1A10 10 0 0 1 12 6c5 0 9 6 9 6a17 17 0 0 1-2.6 3.2M6.6 6.6C4.3 8.1 3 12 3 12s4 6 9 6c1.6 0 3-.4 4.3-1.1"/>',
    'blurred-patrons': '<circle cx="9" cy="8" r="4"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 11l2 2 4-4"/>',
    password: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
    draft: '<path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/>',
  };
  const VIS_SHORT = { public: 'Public', blurred: 'Teaser', 'blurred-patrons': 'Clear for patrons', password: 'Password', draft: 'Draft' };
  const visBar = (value, options) => {
    const name = 'vis-' + Math.random().toString(36).slice(2, 8);
    const bar = el('div', { class: 'vis-seg', role: 'radiogroup', 'aria-label': 'Visibility' });
    for (const [k, label] of Object.entries(options)) {
      const r = el('input', { type: 'radio', name, value: k, checked: k === value });
      const ic = el('span', { class: 'vis-ic' });
      ic.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${VIS_ICONS[k] || ''}</svg>`;
      bar.append(el('label', { class: 'vis-opt', title: label }, r, ic, el('span', {}, VIS_SHORT[k] || label)));
    }
    return { node: el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Visibility'), bar), get: () => bar.querySelector('input:checked')?.value || 'public' };
  };
  const visibilitySection = () => {
    fields.visibility = visBar(m.visibility || 'public', data.visibility);
    fields.password = text('Password', '', { placeholder: m.hasPassword ? 'A password is set: leave empty to keep it' : 'At least 4 characters', hint: 'A new password locks out everyone who used the old one.' });
    fields.password.input.autocomplete = 'off';
    fields.unlisted = check('Hide from the catalog', m.unlisted);
    const hints = {
      public: 'Everyone can see it.',
      blurred: 'Every picture is blurred (a teaser). Downloads work as usual.',
      'blurred-patrons': 'Blurred, except for logged-in patrons on a paid tier.',
      password: 'The page and its downloads need the password.',
      draft: 'Only admins can see it.',
    };
    const hint = el('p', { class: 'hint vis-hint' });
    const pwBox = el('div', { class: 'grid2' }, fields.password.node, fields.unlisted.node);
    const sync = () => { const v = fields.visibility.get(); hint.textContent = hints[v] || ''; pwBox.hidden = v !== 'password'; };
    fields.visibility.node.addEventListener('change', sync);
    sync();
    form.append(section('Visibility', null, fields.visibility.node, hint, pwBox));
  };

  const changelogFields = (hintWhat) => {
    fields.changelogNote = text('Text above the changelog (optional)', m.changelogNote, { placeholder: 'If not specified near the version, you do not need to redownload textures.' });
    fields.changelog = repeat({
      items: m.changelog || [],
      addLabel: 'Add new version (goes on top)',
      addTop: true,
      openFirst: 0,   // all versions start collapsed; a newly added one opens
      confirmRemove: 'Remove this version from the changelog?',
      blank: () => ({ version: '', date: new Date().toLocaleDateString('en-GB'), changes: [] }),
      build: (en) => {
        const v = text('Version', en.version, { placeholder: 'e.g. Beta 9' });
        const d = text('Date', en.date, { placeholder: 'dd/mm/yyyy' });
        const h = text('Label (optional)', en.highlight, { list: 'highlights', hint: 'Mentions of textures, a first release or the last version for a Blender release become a badge.' });
        const notes = list('Notes (optional)', en.notes, { addLabel: 'Add paragraph' });
        const ch = list('Changes', en.changes, { addLabel: 'Add change', placeholder: 'Fix: …   /   New: …   /   Enhancement: …', hint: 'New:, Fix:, Bug:, Enhancement:, Improvement:, Optimization:, Removal: or UI: at the start get a coloured tag.' });
        const img = image('Image (optional)', en.image);
        // release stage: Standard / α Alpha / β Beta (one at a time), a coloured badge on the site for alpha and beta
        let stage = ['alpha', 'beta'].includes(en.stage) ? en.stage : 'standard';
        const STAGE = { standard: ['Standard', '<path d="m5 12.5 4.5 4.5L19 7.5"/>'], alpha: ['Alpha', '<path d="M18.5 6.5c-1.6 5-2.7 8.4-1.4 10.8.5.9 1.4.9 2.4.2"/><path d="M16.6 12.2C15.4 8.4 13.4 6.5 11 6.5c-3 0-5 2.6-5 5.8s1.9 5.7 4.8 5.7c2.6 0 4.6-2.2 5.8-5.8z"/>'], beta: ['Beta', '<path d="M7.5 21.5V8c0-2.7 1.9-4.5 4.5-4.5 2.3 0 4 1.5 4 3.6 0 2-1.5 3.4-3.6 3.6 2.8.2 4.8 1.9 4.8 4.4 0 2.6-2.1 4.4-4.8 4.4-2 0-3.6-.9-4.9-2.3"/>'] };
        const stageBtns = Object.entries(STAGE).map(([k, [label, icon]]) => {
          const b = el('button', { type: 'button', class: 'stage-btn stage-' + k, 'aria-pressed': String(k === stage) }, label);
          b.insertAdjacentHTML('afterbegin', `<svg viewBox="0 0 24 24" aria-hidden="true">${icon}</svg>`);
          b.addEventListener('click', () => { stage = k; stageBtns.forEach((x) => x.setAttribute('aria-pressed', String(x === b))); b.dispatchEvent(new Event('input', { bubbles: true })); });   // bubbles: the title and the unsaved-changes flag update
          return b;
        });
        const stageNode = el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Release'), el('div', { class: 'stage-pick', role: 'group', 'aria-label': 'Release' }, stageBtns));
        return {
          body: el('div', { class: 'rep-body' }, el('div', { class: 'grid-ver' }, v.node, d.node, h.node, stageNode), ch.node, notes.node, img.node),
          get: () => ({ version: v.get(), date: d.get(), highlight: h.get(), stage, notes: notes.get(), changes: ch.get(), image: img.get() }),
          title: () => [(stage !== 'standard' ? STAGE[stage][0] + ' ' : '') + (v.get() || 'New version'), d.get()].filter(Boolean).join(' · '),   // "Beta 2 · 16/08/2026"
        };
      },
    });
    form.append(section('Changelog', null, fields.changelogNote.node, fields.changelog.node));
  };

  // ── Build the form ──
  form.append(el('datalist', { id: 'franchises' }, data.franchises.map((f) => el('option', { value: f }))));
  if (isOutfit) form.append(el('datalist', { id: 'acc-types' }, (data.types || []).map((t) => el('option', { value: t }))));
  form.append(el('datalist', { id: 'highlights' }, ['New Textures!', 'First release for patrons.', 'Last Blender 4.5 compatible version'].map((f) => el('option', { value: f }))));

  const base = isOutfit ? '/accessories/' : '/models/';
  fields.name = text('Name', m.name, { placeholder: isOutfit ? 'e.g. Summer dress' : 'e.g. Tifa Lockhart', max: 120 });
  if (data.isNew) {
    fields.slug = text('Page address', '', { placeholder: isOutfit ? 'e.g. summer-dress' : 'e.g. lara-croft-v2', hint: `${location.host}${base}<address>/ · lowercase letters, numbers and dashes.` });
    let touched = false;
    fields.slug.input.addEventListener('input', () => (touched = true));
    fields.name.input.addEventListener('input', () => {
      if (!touched) fields.slug.input.value = fields.name.get().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    });
  }
  const slugNode = fields.slug?.node ?? el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Page address'), el('code', { class: 'slug' }, `${base}${m.slug}/`));

  if (isOutfit) {
    // type: free text with suggestions (like a model's franchise); empty = Outfit. Catalog filter and the line above the name
    fields.type = text('Type', m.type || 'Outfit', { list: 'acc-types', placeholder: 'e.g. Hair', max: 40, hint: 'Outfit, Hair, Necklace… Pick one in the list or type a new one: the Accessories page gets a filter per type.' });
    fields.description = area('Description (optional)', m.description, { rows: 2 });
    form.append(section('Basics', null, el('div', { class: 'grid-acc' }, fields.name.node, fields.type.node, slugNode), fields.description.node));
    visibilitySection();
    fields.models = modelPicker(m.models);
    form.append(section('Optimized for', null, fields.models.node));
    fields.images = gallery(m.images, 'The first picture is the preview.', 'Pictures');
    form.append(section('Images', null, fields.images.node));
    fields.links = links(m.links);
    fields.addons = addonList(m.addons);
    requirementFields();
    form.append(section('Downloads', null, fields.links.node, lbl('Extra requirements for patron downloads', 'On top of a paid tier, never instead of it. Empty or 0 = none.'), el('div', { class: 'req-grid' }, fields.requireMonths.node, fields.requireMode.node, fields.requireTotal.node), fields.noLimits.node, fields.addons.node));
    changelogFields();
  } else {
    fields.franchise = text('Franchise', m.franchise, { list: 'franchises', placeholder: 'e.g. Final Fantasy' });
    fields.game = text('Game (optional)', m.game, { placeholder: 'e.g. Rise of the Tomb Raider' });
    fields.generation = text('Generation', m.generation ?? '', { placeholder: 'e.g. 4', cls: 'small-field' });
    fields.majorVersion = text('Major version', m.majorVersion ?? '', { type: 'number', placeholder: 'e.g. 2', cls: 'small-field', hint: 'Models with the same name and a major version are shown as versions of each other (the older ones say a newer version exists). Empty = not part of a series.' });
    fields.note = text('Rig note (optional)', m.note, { placeholder: 'e.g. ARP based model' });
    fields.status = select('Status', m.status, data.statuses);
    fields.featured = check('Featured on the homepage', m.featured);
    fields.description = area('Short description (optional)', m.description, { rows: 2 });
    form.append(section('Basics', null,
      grid2(fields.name.node, slugNode),
      el('div', { class: 'grid-gen' }, fields.franchise.node, fields.game.node, fields.generation.node, fields.majorVersion.node),
      el('div', { class: 'grid-status' }, fields.status.node, fields.note.node, fields.featured.node),
      fields.description.node));
    visibilitySection();

    fields.links = links(m.links);
    fields.addons = addonList(m.addons);
    requirementFields();
    form.append(section('Downloads', null, fields.links.node, lbl('Extra requirements for patron downloads', 'On top of a paid tier, never instead of it. Empty or 0 = none.'), el('div', { class: 'req-grid' }, fields.requireMonths.node, fields.requireMode.node, fields.requireTotal.node), fields.noLimits.node, fields.addons.node));

    // catalog cards and the model page picture are 4:5 (.cover-card / .cover-hero): the cropper starts with that shape
    fields.thumb = image('Catalog image', m.thumb, 'Catalog card picture (4:5).', { crop: true, aspect: 4 / 5 });
    fields.cover = image('Main image', m.cover, 'Top of the model page (4:5). Empty = the catalog image.', { crop: true, aspect: 4 / 5 });
    fields.gallery = gallery(m.gallery, '');
    form.append(section('Images', null, grid2(fields.thumb.node, fields.cover.node), fields.gallery.node));

    fields.blender = text('Blender supported', m.blender, { placeholder: 'e.g. 4.5 or above' });
    fields.features = list('Features', m.features, { addLabel: 'Add feature', hint: 'Pasting several lines makes one item per line.' });
    form.append(section('Blender & features', 'The latest version is the top entry of the Changelog below.', el('div', { class: 'grid3' }, fields.blender.node), fields.features.node));

    fields.notes = repeat({
      items: (m.notes || []).map((n) => (typeof n === 'string' ? { text: n } : n)),
      addLabel: 'Add note',
      build: (n) => {
        const t = area('Text', n.text, { rows: 2 });
        const img = image('Image (optional)', n.image);
        return { body: el('div', { class: 'rep-body' }, t.node, img.node), get: () => ({ text: t.get(), image: img.get() }), title: () => (t.get().split('\n')[0] || 'New note').slice(0, 80) };
      },
    });
    form.append(section('Notes for this model', null, fields.notes.node));

    changelogFields();

    fields.futureIdeas = list('Future ideas', m.futureIdeas, { addLabel: 'Add idea' });
    fields.knownIssues = list('Known issues', m.knownIssues, { addLabel: 'Add issue' });
    fields.credits = list('Credits', m.credits, { addLabel: 'Add credit' });
    fields.extra = repeat({
      items: m.extra || [],
      label: 'Other sections',
      addLabel: 'Add section',
      openFirst: 0,
      build: (sec) => {
        const t = text('Title', sec.title, { placeholder: 'e.g. Statistic and Legacy Models' });
        const groups = repeat({
          items: sec.groups || [],
          addLabel: 'Add paragraph + list',
          build: (g) => {
            const intro = area('Paragraph', g.intro, { rows: 2 });
            const items = list('List', g.items, { addLabel: 'Add item' });
            return { body: el('div', { class: 'rep-body' }, intro.node, items.node), get: () => ({ intro: intro.get(), items: items.get() }), title: () => (intro.get() || 'Group').slice(0, 60) };
          },
        });
        return { body: el('div', { class: 'rep-body' }, t.node, groups.node), get: () => ({ title: t.get(), groups: groups.get() }), title: () => t.get() || 'New section' };
      },
    });
    form.append(section('Sidebar', 'Shown next to the changelog on the model page. Each part appears once you add something to it.', fields.futureIdeas.node, fields.knownIssues.node, fields.credits.node, fields.extra.node,
      optionalBlocks([[fields.futureIdeas, 'Future ideas'], [fields.knownIssues, 'Known issues'], [fields.credits, 'Credits'], [fields.extra, 'Other section']])));
  }

  // ── Menu above the form: a button per section; it scrolls there (below the header that stays on top) and the
  // section in view is marked as you scroll ──
  const menu = document.querySelector('[data-ed-menu]');
  if (menu) {
    const head = document.querySelector('.sticky-head');
    const offset = () => (head && getComputedStyle(head).position === 'sticky' ? head.getBoundingClientRect().bottom : 0) + 12;
    const secs = [...form.querySelectorAll(':scope > fieldset.sec')];
    const links = secs.map((sec) => {
      const name = sec.querySelector('legend').firstChild.textContent.replace(/ for this (model|outfit)$/, '');
      return el('a', { href: '#', onclick: (e) => {
        e.preventDefault();
        window.scrollTo({ top: sec.getBoundingClientRect().top + window.scrollY - offset(), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      } }, name);
    });
    menu.append(...links);
    menu.hidden = false;
    const mark = () => {
      let cur = -1;
      secs.forEach((s, i) => { if (s.getBoundingClientRect().top <= offset() + 40) cur = i; });
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4 && secs[secs.length - 1].getBoundingClientRect().top < window.innerHeight) cur = secs.length - 1;   // at the bottom the last one can't reach the top
      links.forEach((a, i) => (i === cur ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current')));
    };
    window.addEventListener('scroll', mark, { passive: true });
    mark();
  }

  // ── Save / delete ──
  const collect = () => {
    const out = {};
    for (const [k, f] of Object.entries(fields)) out[k] = f.get();
    return out;
  };
  form.addEventListener('input', () => (dirty = true));
  form.addEventListener('change', () => (dirty = true));
  form.addEventListener('submit', (e) => e.preventDefault());
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const listUrl = isOutfit ? '/admin/outfits.php' : '/admin/';
  const saveBtn = document.querySelector('[data-save]');
  const save = async () => {
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    try {
      const r = await post('/admin/save.php', { kind: data.kind, action: 'save', originalSlug: data.isNew ? '' : m.slug, item: collect() });
      dirty = false;
      if (fields.password.get()) { fields.password.input.value = ''; fields.password.input.placeholder = 'A password is set: leave empty to keep it'; }
      if (r.created) { location.href = `/admin/edit.php?kind=${data.kind}&slug=${encodeURIComponent(r.slug)}&saved=1`; return; }
      toast('Saved ✓ The site is updated.');
    } catch (e) { toast(e.message, 'err'); }
    saveBtn.disabled = false; saveBtn.textContent = 'Save';
  };
  saveBtn.addEventListener('click', save);
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } });
  if (new URLSearchParams(location.search).has('saved')) toast(`${isOutfit ? 'Accessory' : 'Model'} created ✓`);

  document.querySelector('[data-delete]')?.addEventListener('click', async () => {
    const answer = prompt(`To delete "${m.name}", type its name:`);
    if (answer === null) return;
    if (answer.trim() !== m.name) return toast('The name didn’t match, nothing was deleted.', 'err');
    try { await post('/admin/save.php', { kind: data.kind, action: 'delete', slug: m.slug }); dirty = false; location.href = listUrl; }
    catch (e) { toast(e.message, 'err'); }
  });
}

// ── Settings → Social links: add, remove, reorder rows; icon follows the chosen network ──
(() => {
  const formEl = document.querySelector('[data-social-form]');
  if (!formEl) return;
  const rows = formEl.querySelector('[data-social-rows]');
  const icons = JSON.parse(formEl.querySelector('[data-social-icons]').textContent);
  const tpl = formEl.querySelector('[data-social-template]');
  const placeholder = (row) => {
    const t = row.querySelector('[data-social-type]').value;
    row.querySelector('[data-social-icon]').innerHTML = icons[t] || '';
    row.querySelector('input').placeholder = t === 'email' ? 'name@example.com' : 'https://…';
  };
  let dirty = false;
  const drag = (row) => wireDrag(row.querySelector('[data-social-drag]'), () => row, () => (dirty = true));
  rows.querySelectorAll('[data-social-row]').forEach((r) => { placeholder(r); drag(r); });
  formEl.addEventListener('change', (e) => { const row = e.target.closest('[data-social-row]'); if (row) placeholder(row); dirty = true; });
  formEl.addEventListener('input', () => (dirty = true));
  formEl.addEventListener('click', (e) => {
    const row = e.target.closest('[data-social-row]');
    if (e.target.closest('[data-social-del]') && row) { row.remove(); dirty = true; }
    if (e.target.closest('[data-social-add]')) {
      rows.append(tpl.content.cloneNode(true));
      const last = rows.lastElementChild;
      placeholder(last);
      drag(last);
      last.querySelector('select').focus();
      dirty = true;
    }
  });
  formEl.addEventListener('submit', () => (dirty = false));
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();

// ── Users (exceptions): picking a patron from the download log fills in the name ──
(() => {
  const id = document.querySelector('[data-ex-id]');
  const name = document.querySelector('[data-ex-name]');
  if (!id || !name) return;
  id.addEventListener('change', () => {
    const opt = [...(id.list?.options || [])].find((o) => o.value === id.value.trim());
    if (opt && !name.value.trim()) name.value = opt.textContent;
  });
})();

// ── Getting started links (Admin → Models / Accessories): add, remove and drag link rows ──
document.querySelectorAll('[data-rows-form]').forEach((formEl) => {
  let dirty = false;
  const wire = (row) => wireDrag(row.querySelector('[data-row-drag]'), () => row, () => (dirty = true));
  formEl.querySelectorAll('[data-row]').forEach(wire);
  formEl.addEventListener('click', (e) => {
    const row = e.target.closest('[data-row]');
    if (e.target.closest('[data-row-del]') && row) { row.remove(); dirty = true; }
    const add = e.target.closest('[data-row-add]');
    if (add) {
      const rows = formEl.querySelector('[data-rows]');
      rows.append(formEl.querySelector('[data-row-template]').content.cloneNode(true));
      wire(rows.lastElementChild);
      rows.lastElementChild.querySelector('input').focus();
      dirty = true;
    }
  });
  formEl.addEventListener('input', () => (dirty = true));
  formEl.addEventListener('submit', () => (dirty = false));
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
});

// ── Import from SmutBase (admin/import.php): find projects (profile address, or pages uploaded / pasted), tick, import one by one ──
(() => {
  const root = document.querySelector('[data-import]');
  if (!root) return;
  const list = document.querySelector('[data-import-list]');
  const rows = list.querySelector('[data-import-rows]');
  const go = list.querySelector('[data-import-go]');
  const count = list.querySelector('[data-import-count]');
  const all = list.querySelector('[data-import-all]');
  const added = root.querySelector('[data-import-added]');
  let pages = [];   // uploaded / pasted pages (project pages are imported from them, not fetched)
  let busy = false;
  const byId = new Map();   // project id → its table row
  const checks = () => [...rows.querySelectorAll('input[type=checkbox]:not(:disabled)')];
  const update = () => {
    const n = checks().filter((c) => c.checked).length;
    go.disabled = busy || !n;
    go.textContent = n ? `Import selected (${n})` : 'Import selected';
  };
  const editLink = (m) => el('a', { href: `/admin/edit.php?slug=${encodeURIComponent(m.slug)}`, target: '_blank' }, m.name);
  const status = (p) => p.imported
    ? p.imported.broken
      ? el('span', {}, el('span', { class: 'badge tone-red', title: 'Made from SmutBase’s bot check instead of the project page. Importing again replaces it.' }, 'Broken draft'), ' ', editLink(p.imported))
      : p.imported.draft
        ? el('span', {}, el('span', { class: 'badge tone-green', title: 'Still a draft: tick it to import it again, which replaces this draft' }, 'Imported · draft'), ' ', editLink(p.imported))
        : el('span', {}, el('span', { class: 'badge tone-green' }, 'Imported'), ' ', editLink(p.imported))
    : p.similar
      ? el('span', {}, el('span', { class: 'badge tone-mustard', title: 'A model with the same name is already in the catalog: probably this one, added by hand' }, 'Same name'), ' ', editLink(p.similar))
      : el('span', { class: 'badge tone-grey' }, 'New');
  const row = (p) => {
    const cb = el('input', { type: 'checkbox', 'aria-label': 'Import ' + p.title });
    cb.checked = p.imported ? !!p.imported.broken : !p.similar;
    cb.disabled = !!p.imported && !p.imported.draft;
    cb.addEventListener('change', update);
    const tr = el('tr', {},
      el('td', { class: 'c-check' }, cb),
      el('td', {}, el('a', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, p.title)),
      el('td', { class: 'muted' }, p.franchise || '—'),
      el('td', {}, p.name, p.major > 1 ? el('span', { class: 'muted' }, ' v' + p.major) : null),
      el('td', { class: 'c-imp' }, status(p)));
    tr._project = p;
    return tr;
  };
  const summary = () => {
    const ps = [...byId.values()].map((tr) => tr._project);
    count.textContent = `${ps.length} projects · ${ps.filter((p) => p.imported).length} already imported`;
  };
  // fresh = a new search (the address); otherwise the projects join the list (pasted pages), keeping what is there
  const show = (projects, fresh) => {
    if (fresh) { byId.clear(); rows.replaceChildren(); }
    for (const p of projects) {
      const tr = byId.get(p.id);
      if (tr) { if (p.file !== undefined) tr._project.file = p.file; continue; }
      byId.set(p.id, rows.appendChild(row(p)));
    }
    const first = list.hidden;
    list.hidden = false;
    summary(); update();
    if (first || fresh) list.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  const find = async (body, btn, fresh) => {
    if (btn) btn.disabled = true;
    try { show((await post('/admin/import.php', { action: 'list', ...body })).projects, fresh); return true; }
    catch (e) { toast(e.message + (e.data?.needPage ? ' Copy the pages from your browser and paste them below instead.' : ''), 'err'); return false; }
    finally { if (btn) btn.disabled = false; }
  };
  root.querySelector('[data-import-find]').addEventListener('submit', (e) => {
    e.preventDefault();
    pages = []; added.textContent = '';
    find({ url: e.target.url.value }, e.target.querySelector('button'), true);
  });
  const addPages = async (texts) => {
    const before = pages.length;
    pages = pages.concat(texts.filter((t) => /<[a-z]/i.test(t)));
    if (pages.length === before) { toast('That isn’t a web page. Copy the whole page (Ctrl+A, then Ctrl+C) and paste it again.', 'err'); return; }
    if (await find({ html: pages })) added.textContent = `${pages.length} page${pages.length === 1 ? '' : 's'} added`;
    else pages = pages.slice(0, before);
  };
  root.querySelector('[data-import-files]').addEventListener('change', async (e) => {
    const texts = await Promise.all([...e.target.files].map((f) => f.text()));
    e.target.value = '';
    if (texts.length) addPages(texts);
  });
  // a pasted page: the copied page itself (HTML on the clipboard) or its source (view-source, as text)
  const pasted = (e) => {
    e.preventDefault();
    const html = e.clipboardData.getData('text/html');
    const text = e.clipboardData.getData('text/plain');
    return /^\s*</.test(text) ? text : (html || text);
  };
  root.querySelector('[data-import-paste]').addEventListener('paste', (e) => { const t = pasted(e); e.target.value = ''; if (t) addPages([t]); });
  all.addEventListener('change', () => { checks().forEach((c) => { if (!c.closest('tr')._project.similar || !all.checked) c.checked = all.checked; }); update(); });
  // the "What to import" ticks are remembered in this browser
  const parts = [...list.querySelectorAll('[data-import-parts] input')];
  try { const off = JSON.parse(localStorage.getItem('m3d_import_skip') || '[]'); parts.forEach((c) => (c.checked = !off.includes(c.value))); } catch { /* storage blocked */ }
  parts.forEach((c) => c.addEventListener('change', () => {
    try { localStorage.setItem('m3d_import_skip', JSON.stringify(parts.filter((x) => !x.checked).map((x) => x.value))); } catch { /* storage blocked */ }
  }));

  let opts = {};
  const importOne = async (tr) => {
    const p = tr._project, cell = tr.querySelector('.c-imp'), cb = tr.querySelector('input');
    cell.replaceChildren(el('span', { class: 'muted' }, 'Importing…'));
    try {
      const r = await post('/admin/import.php', { action: 'import', url: p.url, thumb: p.thumb || '', html: p.html || (p.file !== undefined ? pages[p.file] : ''),
        replace: p.imported?.draft ? p.imported.slug : '', ...opts });
      const bits = [`${r.pictures} picture${r.pictures === 1 ? '' : 's'}`, `${r.versions} version${r.versions === 1 ? '' : 's'}`];
      cell.replaceChildren(el('span', { class: 'badge tone-green' }, r.replaced ? 'Draft replaced' : 'Draft created'), ' ', editLink(r),
        el('span', { class: 'muted small import-info' }, ' · ' + bits.join(' · ') + (r.videos ? ` · ${r.videos} video${r.videos === 1 ? '' : 's'} not imported` : '')),
        ...(r.warnings || []).map((w) => el('div', { class: 'muted small' }, '⚠ ' + w)));
      cb.checked = false; cb.disabled = true; p.imported = { slug: r.slug, name: r.name }; delete p.html;
      return true;
    } catch (e) {
      delete p.html;
      if (!e.data?.needPage) { cell.replaceChildren(el('span', { class: 'badge tone-red' }, 'Not imported'), el('div', { class: 'muted small' }, e.message)); return false; }
      // SmutBase didn't give the server this page: paste it here and the import runs again
      const box = el('textarea', { class: 'input import-paste-row', rows: 2, placeholder: 'Paste this project’s page here', 'aria-label': 'Paste the page of ' + p.title });
      box.addEventListener('paste', async (ev) => {
        const t = pasted(ev);
        if (!t) return;
        p.html = t; box.disabled = true;
        if (!(await importOne(tr)) && !p.imported) { cb.checked = true; }
        summary(); update();
      });
      cell.replaceChildren(el('span', { class: 'badge tone-red' }, 'Page needed'),
        el('div', { class: 'muted small' }, e.message + ' ', el('a', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, 'Open the project'), ', select the whole page (Ctrl+A, Mac ⌘A), copy it and paste it here:'),
        box);
      return false;
    }
  };
  go.addEventListener('click', async () => {
    const todo = checks().filter((c) => c.checked).map((c) => c.closest('tr'));
    if (!todo.length) return;
    busy = true; update();
    opts = { status: list.querySelector('[data-import-status]').value, access: list.querySelector('[data-import-access]').value,
      skip: [...list.querySelectorAll('[data-import-parts] input:not(:checked)')].map((c) => c.value) };
    let ok = 0;
    for (const [i, tr] of todo.entries()) {
      count.textContent = `Importing ${i + 1} of ${todo.length}…`;
      if (await importOne(tr)) ok++;
    }
    busy = false; update();
    const need = todo.filter((tr) => tr.querySelector('.import-paste-row')).length;
    count.textContent = `${ok} of ${todo.length} imported as drafts.` + (need ? ` ${need} need their page pasted (see the list).` : '') + (ok ? ' Open each one to check it and make it public.' : '');
  });
})();

// ── Models / Accessories lists: tick rows, then delete them or set their status / visibility together (admin_batch_bar(), save.php action batch) ──
document.querySelectorAll('[data-batch]').forEach((box) => {
  const kind = box.dataset.batch;
  const table = box.nextElementSibling?.querySelector('table') || document.querySelector('.models-table');
  const bar = box.querySelector('[data-batch-bar]');
  const all = table.querySelector('[data-batch-all]');
  const rows = () => [...table.querySelectorAll('[data-batch-row]')].filter((c) => !c.closest('tr').hidden);   // with a search: only the rows shown
  const picked = () => rows().filter((c) => c.checked);
  const name = (n) => `${n} ${kind === 'models' ? (n === 1 ? 'model' : 'models') : (n === 1 ? 'accessory' : 'accessories')}`;
  const update = () => {
    const n = picked().length;
    bar.hidden = !n;
    box.querySelector('[data-batch-count]').textContent = `${name(n)} selected`;
    all.checked = n > 0 && n === rows().length;
    all.indeterminate = n > 0 && n < rows().length;
    rows().forEach((c) => c.closest('tr').classList.toggle('is-picked', c.checked));
    // the quick picks show their counts, and which one matches the ticked rows
    const drafts = rows().filter((c) => c.closest('tr').dataset.vis === 'draft');
    const same = (list) => list.length > 0 && rows().every((c) => c.checked === list.includes(c));
    box.querySelector('[data-pick-n="all"]').textContent = rows().length;
    box.querySelector('[data-pick-n="draft"]').textContent = drafts.length;
    box.querySelector('[data-pick="draft"]').hidden = !drafts.length;
    box.querySelector('.batch-pick [data-pick="all"]').setAttribute('aria-pressed', same(rows()));
    box.querySelector('.batch-pick [data-pick="draft"]').setAttribute('aria-pressed', same(drafts) && drafts.length < rows().length);
    box.querySelector('.batch-pick [data-pick="none"]').setAttribute('aria-pressed', n === 0);
  };
  table.addEventListener('change', (e) => { if (e.target.matches('[data-batch-row]')) update(); });
  all.addEventListener('change', () => { rows().forEach((c) => (c.checked = all.checked)); update(); });
  box.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
    rows().forEach((c) => (c.checked = b.dataset.pick === 'all' || (b.dataset.pick === 'draft' && c.closest('tr').dataset.vis === 'draft')));
    update();
  }));
  const run = async (op, value, label) => {
    const slugs = picked().map((c) => c.closest('tr').dataset.slug);
    if (!slugs.length) return;
    try {
      const r = await post('/admin/save.php', { kind, action: 'batch', op, value, slugs });
      sessionStorage.setItem('m3d_batch_done', `${label}: ${name(r.changed)}.` + (r.skipped?.length ? ` Skipped (no password set yet): ${r.skipped.join(', ')}.` : ''));
      location.reload();
    } catch (e) { toast(e.message, 'err'); }
  };
  box.querySelectorAll('[data-batch-op]').forEach((sel) => sel.addEventListener('change', () => {
    const v = sel.value;
    if (!v) return;
    const label = (sel.dataset.batchOp === 'status' ? 'Status' : 'Visibility') + ' set to “' + sel.selectedOptions[0].textContent + '”';
    sel.value = '';
    run(sel.dataset.batchOp, v, label);
  }));
  box.querySelector('[data-batch-delete]').addEventListener('click', () => {
    const list = picked().map((c) => c.closest('tr').querySelector('.m-name')?.textContent.trim());
    const more = list.length > 12 ? `\n…and ${list.length - 12} more` : '';
    if (!confirm(`Delete ${name(list.length)}?\n\n${list.slice(0, 12).join('\n')}${more}\n\nA backup of the list is kept (Admin → Backups → change history).`)) return;
    run('delete', '', 'Deleted');
  });
  // Search: every word must appear in the row's name, franchise / type, status, address… (data-search); accents don't matter.
  // Hidden rows are unticked (a batch never touches rows you can't see) and reordering pauses (dragging needs the full list).
  const search = box.querySelector('[data-batch-search]');
  const empty = box.querySelector('[data-batch-empty]');
  const fold = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const filter = () => {
    const q = search.value.trim();
    const words = fold(q).split(/\s+/).filter(Boolean);
    let shown = 0;
    table.querySelectorAll('tbody tr[data-slug]').forEach((tr) => {
      const hay = fold(tr.dataset.search || tr.textContent);
      tr.hidden = !words.every((w) => hay.includes(w));
      if (tr.hidden) { const c = tr.querySelector('[data-batch-row]'); if (c) c.checked = false; } else shown++;
    });
    table.classList.toggle('is-filtered', words.length > 0);
    empty.hidden = shown > 0;
    empty.textContent = `Nothing matches “${q}”.`;
    const u = new URL(location.href);
    if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
    history.replaceState(null, '', u);
    update();
  };
  search.addEventListener('input', filter);
  search.addEventListener('keydown', (e) => { if (e.key === 'Escape' && search.value) { search.value = ''; filter(); } });
  const q0 = new URL(location.href).searchParams.get('q');
  if (q0) { search.value = q0; filter(); }
  try {
    const done = sessionStorage.getItem('m3d_batch_done');
    if (done) { sessionStorage.removeItem('m3d_batch_done'); toast(done); }
  } catch { /* storage blocked */ }
  update();
});

// ── Settings: the ↑ button (bottom right) appears once the page is scrolled down, and goes back to the top ──
document.querySelectorAll('[data-to-top]').forEach((btn) => {
  const show = () => { btn.hidden = window.scrollY < 400; };
  window.addEventListener('scroll', show, { passive: true });
  show();
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    history.replaceState(null, '', location.pathname + location.search);   // drop the #section from the address
  });
});

// ── Long actions (installing an update): one click only, and say it's working ──
document.querySelectorAll('form[data-busy]').forEach((f) => {
  f.addEventListener('submit', (e) => {
    if (f.dataset.sending) { e.preventDefault(); return; }   // a second click doesn't send it again
    f.dataset.sending = '1';
    f.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    const btn = e.submitter || f.querySelector('button');
    if (btn) { btn.dataset.label = btn.textContent; btn.textContent = f.dataset.busy; }
    f.classList.add('is-busy');
  });
});
window.addEventListener('pageshow', () => document.querySelectorAll('form[data-busy]').forEach((f) => { delete f.dataset.sending; f.classList.remove('is-busy'); f.querySelectorAll('button').forEach((b) => { b.disabled = false; if (b.dataset.label) b.textContent = b.dataset.label; }); }));

// ── Filter forms (Downloads log): apply right away when a menu or date changes; search applies on Enter ──
document.querySelectorAll('form[data-autosubmit]').forEach((f) => {
  f.addEventListener('change', (e) => { if (e.target.matches('select, input[type=date]')) f.requestSubmit(); });
  // keep the address short: leave empty filters (and the default order) out of it
  f.addEventListener('submit', () => f.querySelectorAll('input, select').forEach((i) => { if (!i.value || (i.name === 'sort' && i.value === 'new')) i.disabled = true; }));
});
// coming back with the browser's Back button must not leave fields disabled
window.addEventListener('pageshow', () => document.querySelectorAll('form[data-autosubmit] [disabled]').forEach((i) => (i.disabled = false)));

// ── Number fields: two step buttons (▲▼) instead of the browser's own arrows; holding a button keeps counting ──
function numStepper(input) {
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
}
document.querySelectorAll('input[type=number]').forEach(numStepper);
// the editors build their fields later
new MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach((n) => {
  if (n.nodeType !== 1) return;
  if (n.matches('input[type=number]')) numStepper(n);
  else n.querySelectorAll('input[type=number]').forEach(numStepper);
}))).observe(document.body, { childList: true, subtree: true });

// ── Settings → Site identity: the preview follows the fields before saving (name, coloured part, tagline, colour, logo) ──
(() => {
  const form = document.querySelector('[data-identity-form]');
  if (!form) return;
  const prev = form.querySelector('[data-id-preview]');
  const f = (k) => form.querySelector(`[data-id="${k}"]`);
  const ink = (hex) => {
    const lin = (c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    const [r, g, b] = [1, 3, 5].map((i) => lin(parseInt(hex.slice(i, i + 2), 16) / 255));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.3 ? '#1b1607' : '#ffffff';   // same rule as accent_ink() in view.php
  };
  let logoUrl = null;
  const draw = () => {
    const name = f('name').value.trim() || 'Site name', hl = f('highlight').value, at = hl ? name.lastIndexOf(hl) : -1;
    const text = el('span', {}, at < 0 ? name : [name.slice(0, at), el('span', { class: 'brand-3d' }, hl), name.slice(at + hl.length)]);
    const brand = prev.querySelector('.brand');
    let logo = brand.querySelector('.brand-logo');
    if (logoUrl) logo = el('img', { class: 'brand-logo', src: logoUrl, alt: '' });
    else if (f('nologo')?.checked) logo = null;
    brand.replaceChildren(...[logo, text].filter(Boolean));
    prev.querySelector('.id-prev-line').textContent = (f('tagline').value.trim() || 'Tagline') + '.';
    const c = f('color').value;
    prev.style.setProperty('--mustard', c);
    prev.style.setProperty('--mustard-ink', ink(c));
    form.querySelector('[data-id-hex]').textContent = c;
  };
  const original = prev.querySelector('.brand').cloneNode(true);
  form.addEventListener('input', draw);
  form.addEventListener('change', (e) => {
    if (e.target === f('logo')) {
      if (logoUrl) URL.revokeObjectURL(logoUrl);
      const file = f('logo').files[0];
      logoUrl = file ? URL.createObjectURL(file) : null;
      if (f('nologo')) f('nologo').checked = false;
    }
    if (e.target === f('nologo') && !f('nologo').checked && !logoUrl) prev.querySelector('.brand').replaceWith(original.cloneNode(true));
    draw();
  });
  form.querySelector('[data-id-color-reset]')?.addEventListener('click', (e) => { f('color').value = e.target.dataset.idColorReset; draw(); });
})();

// ── Settings → Patreon: "Use this site's address" fills the redirect address ──
document.querySelector('[data-pt-use]')?.addEventListener('click', (e) => {
  const input = document.querySelector('[data-pt-redirect]');
  input.value = e.currentTarget.dataset.ptUse;
  input.focus();
});
