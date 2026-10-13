// Admin panel
let CSRF = document.querySelector('meta[name="csrf"]')?.content || '';   // renewed by post() when the login was renewed in another tab

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

async function post(url, body, again = true) {
  const r = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(body),
  });
  let data = null;
  try { data = await r.json(); } catch { /* not JSON */ }
  // refused (logged out, or logged in again in another tab): with a fresh token the same save goes through, so nothing typed is lost
  if (r.status === 403 && again && await freshCsrf()) return post(url, body, false);
  if (!r.ok || !data?.ok) throw Object.assign(new Error(data?.error || `Server error (${r.status})`), { data });
  return data;
}

/** The login's current CSRF token (after a new login in another tab): true when it changed. */
async function freshCsrf() {
  try {
    const me = await (await fetch('/auth/status.php', { credentials: 'same-origin', cache: 'no-store' })).json();
    if (!me?.admin || !me.csrf || me.csrf === CSRF) return false;
    CSRF = me.csrf;
    document.querySelectorAll('input[name="csrf"]').forEach((i) => (i.value = me.csrf));
    return true;
  } catch { return false; }
}

// onProgress(bytes of the file sent, busy): busy = all sent, the server is making the WebP versions
function upload(file, model, onProgress) {
  return sendFile('/admin/upload.php', file, model, onProgress).then((d) => d.url);
}
// POSTs a file (+ the folder and the CSRF token) to an upload endpoint with progress; resolves to its JSON answer ({ok, url, …})
function sendFile(endpoint, file, model, onProgress, retried = false) {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('model', model || 'misc');
  fd.append('csrf', CSRF);
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();   // not fetch: only XHR tells how much of the file has gone
    x.open('POST', endpoint);
    // at 100% the server (through Cloudflare) still receives, checks and saves the file: shown as busy, not as a bar stuck at 100%
    x.upload.onprogress = (e) => { if (e.lengthComputable) onProgress?.(file.size * e.loaded / e.total, e.loaded >= e.total); };
    x.upload.onload = () => onProgress?.(file.size, true);
    x.onload = () => {
      let data = null;
      try { data = JSON.parse(x.responseText); } catch { /* not JSON */ }
      if (x.status >= 200 && x.status < 300 && data?.ok) resolve(data);
      // a new login in another tab changed the token: send once more with the new one (like post())
      else if (x.status === 403 && !retried) freshCsrf().then((ok) => (ok ? sendFile(endpoint, file, model, onProgress, true).then(resolve, reject) : reject(new Error(data?.error || 'Upload failed (403)'))));
      else reject(new Error(data?.error || (x.status === 413 ? 'The file is larger than the server or Cloudflare accepts (Cloudflare: 100 MB).' : `Upload failed (${x.status})`)));
    };
    x.onerror = () => reject(new Error('Upload failed: the connection was interrupted.'));
    x.send(fd);
  });
}

// Progress bar for uploads (the picture picker, Images → Upload images): run(files, folder) uploads them one after the other
// and resolves to the addresses of the ones that worked (each failure is a toast). The bar counts bytes over all the files;
// files pushed onto the same array while it runs (dropped during an upload) join the queue.
function uploadProgress() {
  const text = el('span', { class: 'up-prog-tx' });
  const fill = el('span', { class: 'up-prog-fill' });
  const node = el('div', { class: 'up-prog', hidden: true, role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-live': 'polite' }, text, el('span', { class: 'up-prog-bar' }, fill));
  const run = async (files, folder) => {
    const urls = [];
    let before = 0;
    node.hidden = false;
    for (let k = 0; k < files.length; k++) {
      const f = files[k];
      const show = (sent, busy) => {
        const total = files.reduce((n, x) => n + x.size, 0) || 1;
        const tag = files.length > 1 ? `${k + 1} of ${files.length}: ` : '';
        const pct = Math.min(100, Math.round((100 * (before + sent)) / total));
        fill.style.width = pct + '%';
        node.setAttribute('aria-valuenow', String(pct));
        node.classList.toggle('busy', busy);
        text.textContent = busy ? `Optimizing ${tag}${f.name}…` : `Uploading ${tag}${f.name} · ${pct}%`;
      };
      show(0, false);
      try { urls.push(await upload(f, folder, show)); } catch (e) { toast(`${f.name}: ${e.message}`, 'err'); }
      before += f.size;
    }
    node.classList.remove('busy');
    return urls;
  };
  return { node, run, hide: () => { node.hidden = true; } };
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

// ── Crop frame: drag it over the picture (move it, resize it from the handles), in a chosen shape. Used by cropImage() and the
// Images page's picture window. onChange({w, h}) gets the frame's size in the picture's own pixels after every change.
const CROP_RATIOS = [['Free', 0], ['9:16', 9 / 16], ['2:3', 2 / 3], ['4:5', 4 / 5], ['1:1', 1], ['16:10', 16 / 10], ['16:9', 16 / 9]];
function cropFrame(img, { aspect = 0, onChange = () => {} } = {}) {
  const box = el('div', { class: 'crop-box' }, ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].map((h) => el('span', { class: 'crop-h crop-' + h, 'data-h': h })));
  // the frame is placed over the picture (crop-area); the stage around it leaves room for the handles on its edges
  const area = el('div', { class: 'crop-area' }, img, box);
  const stage = el('div', { class: 'crop-stage' }, area);
  let ratio = aspect, r = { x: 0, y: 0, w: 0, h: 0 }, W = 0, H = 0;
  const result = () => {   // each side scaled by its own ratio: the shown picture's size is rounded to whole pixels; null while the picture isn't shown yet
    if (!W || !H) return null;
    const sx = img.naturalWidth / W, sy = img.naturalHeight / H;
    return { x: Math.round(r.x * sx), y: Math.round(r.y * sy), w: Math.round(r.w * sx), h: Math.round(r.h * sy) };
  };
  const draw = () => {
    Object.assign(box.style, { left: r.x + 'px', top: r.y + 'px', width: r.w + 'px', height: r.h + 'px' });
    box.classList.toggle('locked', !!ratio);
    if (W) onChange(result());
  };
  const fit = () => {   // the biggest centred frame of the chosen shape
    let w = W, h = H;
    if (ratio) { h = w / ratio; if (h > H) { h = H; w = h * ratio; } }
    r = { x: (W - w) / 2, y: (H - h) / 2, w, h };
    draw();
  };
  const measure = () => { W = img.clientWidth; H = img.clientHeight; };
  const onResize = () => { if (!W || !img.clientWidth) return; const k = img.clientWidth / W; measure(); r = { x: r.x * k, y: r.y * k, w: r.w * k, h: r.h * k }; draw(); };
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
  addEventListener('resize', onResize);
  // (again) once the picture is loaded and shown: a new src (turned picture) or a window that just opened
  const start = () => {
    W = 0;
    const ready = () => { measure(); if (W) fit(); };
    if (img.complete && img.naturalWidth) requestAnimationFrame(ready); else img.addEventListener('load', ready, { once: true });
  };
  start();
  return {
    stage, result, start, fit,
    setRatio: (v) => { ratio = v; fit(); },
    destroy: () => removeEventListener('resize', onResize),
  };
}

// ── Crop & rotate tools: the crop frame over the picture as it will be (turned / mirrored in a canvas; images.php action crop
// does the same on the server) and the side panel's parts: shapes, turn left / right, mirror, the new size. Shared by cropImage()
// and the Images page's picture window. onState(ok): false while a turned picture is being drawn, true once Save can go.
const iwSection = (head, ...kids) => el('section', { class: 'iw-sec' }, el('h3', {}, head), ...kids);
function cropTools(src, { aspect = 0, onState = () => {}, onError = () => {} } = {}) {
  let turns = 0, flip = false, seq = 0, drawing = false, ready = false, preview = '', dead = false;
  const pic = el('img', { alt: '', draggable: 'false' });
  const out = el('div', { class: 'iw-out' }, '…');
  const state = (ok) => { ready = ok; onState(ok); };
  const frame = cropFrame(pic, { aspect, onChange: (r) => { out.textContent = `${r.w} × ${r.h} px`; if (!drawing) state(true); } });
  const ratioBtns = CROP_RATIOS.map(([label, v]) => el('button', { type: 'button', class: 'chip', 'aria-pressed': String(v === aspect), onclick: () => setRatio(v) }, label));
  const setRatio = (v) => { ratioBtns.forEach((b, i) => b.setAttribute('aria-pressed', String(CROP_RATIOS[i][1] === v))); frame.setRatio(v); };
  const turned = () => new Promise((resolve, reject) => {
    if (!turns && !flip) return resolve(src);
    const im = new Image();
    im.onload = () => {
      const odd = turns % 2, cw = odd ? im.naturalHeight : im.naturalWidth, ch = odd ? im.naturalWidth : im.naturalHeight;
      const c = Object.assign(document.createElement('canvas'), { width: cw, height: ch });
      const x = c.getContext('2d');
      if (flip) { x.translate(cw, 0); x.scale(-1, 1); }   // mirrored after the turn, like the server
      x.translate(cw / 2, ch / 2);
      x.rotate(turns * Math.PI / 2);
      x.drawImage(im, -im.naturalWidth / 2, -im.naturalHeight / 2);
      c.toBlob((b) => (b ? resolve(URL.createObjectURL(b)) : reject(new Error('The turned picture could not be drawn.'))));
    };
    im.onerror = () => reject(new Error('This image could not be loaded.'));
    im.src = src;
  });
  // seq: the latest picture asked for (an older one that finishes late is dropped); drawing: Save waits for it
  const redraw = async () => {
    const id = ++seq;
    drawing = true;
    state(false);
    try {
      const u = await turned();
      if (id !== seq || dead) { if (u.startsWith('blob:')) URL.revokeObjectURL(u); return; }
      if (preview) URL.revokeObjectURL(preview);
      preview = u.startsWith('blob:') ? u : '';
      pic.src = u;
      frame.start();   // the frame's first onChange (picture shown and measured) lets Save go again
    } catch (err) { if (id === seq && !dead) onError(err.message); }
    if (id === seq) drawing = false;
  };
  const turn = (d) => { turns = (turns + (flip ? -d : d) + 4) % 4; redraw(); };   // mirrored: a turn to the right is a turn to the left of the picture
  pic.addEventListener('error', () => { if (!preview) onError('This image could not be loaded.'); });
  redraw();
  return {
    stage: frame.stage,
    sections: [
      iwSection('Shape', el('div', { class: 'iw-chips', role: 'group', 'aria-label': 'Shape' }, ratioBtns)),
      iwSection('Rotate', el('div', { class: 'iw-rot' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => turn(-1) }, '↺ Left'),
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => turn(1) }, '↻ Right'),
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { flip = !flip; redraw(); } }, '⇋ Flip'))),
      iwSection('New Picture', out),
    ],
    start: () => frame.start(),   // again once the window is shown (the picture had no size before)
    reset: () => { turns = 0; flip = false; setRatio(aspect); redraw(); },
    // {x, y, w, h} in the turned picture's pixels + rotate / flip for images.php, or null while it isn't ready
    result: () => { const b = !drawing && ready && frame.result(); return b ? { ...b, rotate: turns, flip } : null; },
    destroy: () => { dead = true; seq++; frame.destroy(); if (preview) URL.revokeObjectURL(preview); },
  };
}

// The Details tab of the picture window: what images.php says about a picture (image_info(): size, file, folder, date, what uses it, sizes made)
// + Copy link / Download. Shared by the Images page's window and cropImage().
function pictureFacts(d, url) {
  const copy = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, 'Copy Link');
  copy.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(url); copy.textContent = 'Copied ✓'; setTimeout(() => { copy.textContent = 'Copy Link'; }, 1500); }
    catch { prompt('Copy this address:', url); }
  });
  const acts = el('section', { class: 'iw-sec iw-acts' }, copy, el('a', { class: 'btn btn-ghost btn-sm', href: url, download: d?.name || '' }, 'Download'));
  if (!d) return [iwSection('Picture', el('p', { class: 'iw-none' }, url.startsWith('/media/') ? 'Its details couldn’t be loaded.' : 'This picture is on another website: copy it here (Images page) to crop it.')), acts];
  return [
    iwSection('Picture', el('dl', { class: 'iw-facts' },
      el('dt', {}, 'Size'), el('dd', {}, `${d.w} × ${d.h} px`),
      el('dt', {}, 'File'), el('dd', {}, `${d.size} · ${({ WEBP: 'WebP', JPG: 'JPEG' })[d.type] || d.type}`),
      el('dt', {}, 'Folder'), el('dd', {}, d.folder),
      el('dt', {}, 'Added'), el('dd', {}, d.added))),
    iwSection('Used By', d.uses?.length
      ? el('ul', { class: 'iw-uses' }, d.uses.map(([n, href, what]) => el('li', {}, el('a', { href }, n), what ? el('span', { class: 'what' }, ' · ' + what) : null)))
      : el('p', { class: 'iw-none' }, el('span', { class: 'warn-dot' }, 'Not used'), ' anywhere yet')),
    d.vers?.length > 1 ? iwSection('Sizes Made', el('div', { class: 'iw-vers' }, d.vers.map(([n, u, w]) => el('a', { href: u, target: '_blank', rel: 'noopener', title: 'Open this size' }, el('b', {}, n), ` ${w} px`)))) : null,
    acts,
  ].filter(Boolean);
}
const pictureInfo = {};   // url → promise of its details (one request per picture and page)
const loadPictureInfo = (url) => (pictureInfo[url] ??= url.startsWith('/media/')
  ? fetch('/admin/images.php?info=' + encodeURIComponent(url), { credentials: 'same-origin' }).then((r) => r.json()).then((j) => (j.ok ? j : null)).catch(() => null)
  : Promise.resolve(null));

// ── Picture window outside the Images page (editors' image fields and gallery cards, Admin → Gallery): Details (pictureFacts()) and
// Crop & rotate (cropTools()); tab = the one it opens on. Resolves with {x, y, w, h, rotate, flip} for images.php action crop,
// 'skip' (Keep as is) or null (closed without saving).
function cropImage(src, { aspect = 0, title = 'Crop Image', skip = false, tab = 'crop' } = {}) {
  return new Promise((resolve) => {
    let tools = null, mode = '', info;
    const msg = el('p', { class: 'iw-msg err', role: 'status' });
    const body = el('div', { class: 'iw-body' });
    const foot = el('footer', { class: 'iw-foot' });
    const stage = el('div', { class: 'iw-stage' });
    const view = el('div', { class: 'iw-pan' }, el('div', { class: 'iw-view' }, el('img', { src, alt: '' })));
    const tabBtns = {
      details: el('button', { type: 'button', role: 'tab', onclick: () => show('details') }, 'Details'),
      crop: el('button', { type: 'button', role: 'tab', onclick: () => show('crop') }, 'Crop & Rotate'),
    };
    const close = (v) => { tools?.destroy(); dlg.close(); dlg.remove(); resolve(v); };
    const ghost = (label, onclick) => el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick }, label);
    const canCrop = () => src.startsWith('/media/') && !/\.gif$/i.test(src) && info?.crop !== false;
    const show = (t) => {
      mode = t === 'crop' && canCrop() ? 'crop' : 'details';
      msg.textContent = '';
      for (const [k, b] of Object.entries(tabBtns)) b.setAttribute('aria-selected', String(k === mode));
      tabBtns.crop.hidden = !canCrop();
      tabs.hidden = !canCrop();   // one tab alone isn't shown
      dlg.classList.toggle('is-crop', mode === 'crop');
      tools?.destroy(); tools = null;
      if (mode === 'details') {
        stage.replaceChildren(view);
        body.replaceChildren(...pictureFacts(info, src));
        if (info === undefined) body.replaceChildren(el('p', { class: 'muted small' }, 'Loading…'));
        foot.replaceChildren(...[ghost('Close', () => close(null)), canCrop() && el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => show('crop') }, 'Crop & Rotate…')].filter(Boolean));
        return;
      }
      const saveBtn = el('button', { type: 'button', class: 'btn btn-primary btn-sm', disabled: true, onclick: () => { const b = tools?.result(); if (b) close(b); } }, 'Save');
      tools = cropTools(src, { aspect, onState: (ok) => { saveBtn.disabled = !ok; }, onError: (t) => { msg.textContent = t; } });
      stage.replaceChildren(tools.stage);
      body.replaceChildren(...tools.sections);
      foot.replaceChildren(...[ghost('Reset', () => tools.reset()), skip && ghost('Keep as Is', () => close('skip')), ghost('Cancel', () => (tab === 'crop' ? close(null) : show('details'))), saveBtn].filter(Boolean));
    };
    const tabs = el('div', { class: 'iw-tabs', role: 'tablist' }, tabBtns.details, tabBtns.crop);
    const dlg = el('dialog', { class: 'iw', 'aria-label': title },
      el('header', { class: 'iw-head' }, el('div', { class: 'iw-hl' }, el('span', { class: 'iw-title' }, title)), tabs,
        el('div', { class: 'iw-hr' },
          el('a', { class: 'btn btn-ghost btn-sm iw-full', href: src, target: '_blank', rel: 'noopener', title: 'Open full size in a new tab' }, el('span', { class: 't' }, 'Open Full Size '), '↗'),
          el('button', { type: 'button', class: 'iw-x', 'aria-label': 'Close', onclick: () => close(null) }, '✕'))),
      stage,
      el('aside', { class: 'iw-side' }, el('div', { class: 'iw-scroll' }, body, msg), foot));
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(null); });
    document.body.append(dlg);
    dlg.showModal();
    document.activeElement?.blur?.();
    show(tab);
    loadPictureInfo(src).then((d) => {
      info = d;
      if (!dlg.isConnected) return;
      if (mode === 'details') show('details');   // its details (and whether it can be cropped) arrived
      else if (!canCrop()) show('details');      // an animated GIF: nothing to crop
    });
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
  // move / up listen on the window: moving the row drops the pointer capture, so a release outside the table must still end the drag
  sortable.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('.drag-handle');
    if (!handle || e.button > 0 || drag) return;
    e.preventDefault();
    const row = handle.closest('tr');
    drag = { row, id: e.pointerId };
    row.classList.add('dragging');
    document.body.classList.add('is-dragging');
    addEventListener('pointermove', move, { passive: false });
    addEventListener('pointerup', end);
    addEventListener('pointercancel', end);
  });
  const move = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    e.preventDefault();
    const rows = [...sortable.querySelectorAll('tr[data-slug]')].filter((r) => r !== drag.row);
    const target = rows.find((r) => { const b = r.getBoundingClientRect(); return e.clientY < b.top + b.height / 2; });
    if (target) { if (target.previousElementSibling !== drag.row) sortable.insertBefore(drag.row, target); }
    else if (sortable.lastElementChild !== drag.row) sortable.append(drag.row);
    // scroll the page when dragging near the top/bottom edge
    if (e.clientY < 90) window.scrollBy(0, -12); else if (e.clientY > innerHeight - 50) window.scrollBy(0, 12);
  };
  const end = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    removeEventListener('pointermove', move);
    removeEventListener('pointerup', end);
    removeEventListener('pointercancel', end);
    drag.row.classList.remove('dragging');
    document.body.classList.remove('is-dragging');
    drag = null;
    persist();
  };
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

// ── Folder pickers (Images page) ─────────────────────────────────────────────
// The long folder menus ([data-folder-pick]) become a button + panel: a search box, the folders used last (upload only),
// one row per character with its generations as buttons, then accessories and the other folders (Docs / FAQ tabs: one
// row per page or question, under its docs section or FAQ topic). The <select> stays,
// hidden, and keeps the value: the upload and the filter read it as before. Its options carry data-kind / name / gen /
// sub / thumb / icon (folder_option() in images.php).
const FP_RECENT = 'm3d_upload_recent';
const fpRecent = () => { try { const r = JSON.parse(localStorage.getItem(FP_RECENT) || '[]'); return Array.isArray(r) ? r : []; } catch { return []; } };
const fpRemember = (v) => { try { localStorage.setItem(FP_RECENT, JSON.stringify([v, ...fpRecent().filter((x) => x !== v)].slice(0, 3))); } catch { /* storage blocked */ } };
const FP_FOLDER_ICON = '<path d="M3.5 7.5c0-1.1.9-2 2-2h4l2 2h7c1.1 0 2 .9 2 2v8c0 1.1-.9 2-2 2h-13c-1.1 0-2-.9-2-2z"/>';
const FP_GRID_ICON = '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>';
const FP_PAGE_ICON = '<path d="M6.5 3.5h7l4 4v11c0 1.1-.9 2-2 2h-9c-1.1 0-2-.9-2-2v-13c0-1.1.9-2 2-2z"/><path d="M13.5 3.5v4h4M8.5 12.5h7M8.5 16h5"/>';
const FP_QUESTION_ICON = '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.6a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.4"/><path d="M12 16.8v.2"/>';
function folderPicker(sel) {
  const fold = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const upload = sel.dataset.folderPick === 'upload';
  const items = [...sel.options].map((o) => ({
    value: o.value, kind: o.dataset.kind || (o.value === '' ? 'all' : 'other'), name: o.dataset.name || o.textContent,
    gen: o.dataset.gen || '', ver: o.dataset.ver || '', sub: o.dataset.sub || '', title: o.textContent, thumb: o.dataset.thumb || '', icon: o.dataset.icon || '',
  }));
  const byValue = new Map(items.map((i) => [i.value, i]));
  // one row per character: models with the same name and franchise; anything else is a row of its own
  const rows = [];
  const rowOf = new Map();
  for (const i of items) {
    const key = i.kind === 'model' ? 'm|' + i.name + '|' + i.sub : 'v|' + i.value;
    if (!rowOf.has(key)) { const r = { kind: i.kind, name: i.name, sub: i.sub, thumb: '', icon: i.icon, targets: [] }; rowOf.set(key, r); rows.push(r); }
    rowOf.get(key).targets.push(i);
  }
  for (const r of rows) {
    r.targets.sort((a, b) => (parseFloat(a.gen) || 0) - (parseFloat(b.gen) || 0) || (+a.ver || 0) - (+b.ver || 0));
    // two models of one character with the same generation: tell them apart by major version, else by number
    r.targets.forEach((t) => {
      const same = r.targets.filter((x) => x.gen === t.gen);
      t.chip = (t.gen ? 'Gen ' + t.gen : '—') + (same.length > 1 ? ' · ' + (t.ver ? 'v' + t.ver : same.indexOf(t) + 1) : '');
    });
    r.thumb = [...r.targets].reverse().find((t) => t.thumb)?.thumb || '';
    r.text = fold([r.name, r.sub, ...r.targets.map((t) => t.gen ? 'gen ' + t.gen : '')].join(' '));
  }
  // docs pages and FAQ questions: a heading per docs section / FAQ topic (the row then leaves it out)
  const textSecs = [...new Set(rows.filter((r) => r.kind === 'doc' || r.kind === 'faq').map((r) => r.kind + '|' + r.sub))];
  for (const r of rows) if (r.kind === 'doc' || r.kind === 'faq') { r.sec = r.kind + '|' + r.sub; r.subHidden = true; }
  const SECTIONS = [['all', ''], ['model', 'Models'], ['outfit', 'Accessories'],
    ...textSecs.map((k) => [k, k.slice(k.indexOf('|') + 1) || (k.startsWith('faq') ? 'FAQ' : 'Docs')]), ['other', textSecs.length ? 'Not Used' : 'Other']];

  const pic = (r) => {
    const box = el('span', { class: 'fp-pic' });
    if (r.thumb) box.append(el('img', { src: r.thumb, alt: '', loading: 'lazy', decoding: 'async' }));
    else if (r.kind === 'model') box.textContent = r.name.split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
    else box.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + (r.icon || { all: FP_GRID_ICON, doc: FP_PAGE_ICON, faq: FP_QUESTION_ICON }[r.kind] || FP_FOLDER_ICON) + '</svg>';
    return box;
  };
  // a row: the whole row is one button when it has one folder; otherwise each generation is a button (the row picks the newest)
  const rowEl = (r, targets = r.targets) => {
    const text = el('span', { class: 'fp-tx' }, r.name, r.sub && !r.subHidden ? el('small', { text: r.sub }) : null);
    if (targets.length === 1) {
      const t = targets[0];
      return el('button', { type: 'button', class: 'fp-row', 'data-v': t.value, title: t.title }, pic(r), text, t.gen ? el('span', { class: 'fp-gens' }, el('span', { class: 'fp-gen', text: t.chip })) : null);
    }
    return el('div', { class: 'fp-row fp-multi', 'data-newest': targets[targets.length - 1].value }, pic(r), text,
      el('span', { class: 'fp-gens' }, targets.map((t) => el('button', { type: 'button', class: 'fp-gen', 'data-v': t.value, title: t.title + ' · ' + t.value, text: t.chip }))));
  };

  const btn = el('button', { type: 'button', class: 'fp-btn input', 'aria-haspopup': 'true', 'aria-expanded': 'false', 'aria-label': sel.getAttribute('aria-label') || 'Folder' });
  const search = el('input', { class: 'input fp-search', type: 'search', placeholder: upload ? 'Search models, accessories…' : sel.dataset.searchPh || 'Search folders…', 'aria-label': 'Search' });
  const list = el('div', { class: 'fp-list' });
  const foot = el('div', { class: 'fp-foot muted small' });
  const pop = el('div', { class: 'fp-pop', hidden: true }, search, list, foot);
  const wrap = el('div', { class: 'fp' + (upload ? ' fp-right' : '') }, btn, pop);
  sel.hidden = true;
  sel.after(wrap);

  const paintBtn = () => {
    const i = byValue.get(sel.value) || items[0];
    if (!i) return;
    const r = rows.find((x) => x.targets.includes(i));
    btn.replaceChildren(...[pic({ ...r, thumb: i.thumb || r.thumb }), el('span', { class: 'fp-nm', text: i.name }), i.gen ? el('span', { class: 'fp-gen', text: i.chip }) : null, el('span', { class: 'fp-chev', 'aria-hidden': 'true', text: '▾' })].filter(Boolean));
    btn.title = i.title;
  };
  let act = -1;
  const targets = () => [...list.querySelectorAll('[data-v]')];
  const mark = (n) => {
    const ts = targets();
    ts.forEach((t, k) => t.classList.toggle('act', k === n));
    act = n;
    ts[n]?.scrollIntoView({ block: 'nearest' });
  };
  const render = () => {
    const words = fold(search.value).split(/\s+/).filter(Boolean);
    const hit = (r) => words.every((w) => r.text.includes(w));
    list.replaceChildren();
    let n = 0;
    if (upload && !words.length) {
      const recent = fpRecent().map((v) => byValue.get(v)).filter(Boolean);
      if (recent.length) {
        list.append(el('div', { class: 'fp-h', text: 'Last Used' }));
        for (const t of recent) list.append(rowEl({ ...rows.find((x) => x.targets.includes(t)), thumb: t.thumb || rows.find((x) => x.targets.includes(t)).thumb }, [t]));
      }
    }
    for (const [kind, title] of SECTIONS) {
      const rs = rows.filter((r) => (r.sec || r.kind) === kind && hit(r));
      if (!rs.length) continue;
      const count = rs.reduce((s, r) => s + r.targets.length, 0);
      if (title) list.append(el('div', { class: 'fp-h', text: title + ' · ' + count }));
      rs.forEach((r) => list.append(rowEl(r)));
      n += count;
    }
    if (!n) list.append(el('p', { class: 'fp-none muted small', text: 'Nothing matches “' + search.value.trim() + '”.' }));
    list.querySelectorAll('[data-v]').forEach((b) => b.classList.toggle('on', b.dataset.v === sel.value));
    list.querySelectorAll('.fp-multi').forEach((r) => r.classList.toggle('on', !!r.querySelector('.fp-gen.on')));
    foot.textContent = words.length ? (n ? n + ' found · Enter picks the highlighted one' : '') : 'Type to search, ↑ ↓ and Enter to pick';
    mark(words.length && n ? 0 : -1);
  };
  const open = () => {
    search.value = '';
    render();
    pop.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    place();
    list.querySelector('.on')?.scrollIntoView({ block: 'center' });
    search.focus();
  };
  // inside a window (the picture picker) the panel would be cut off by the window's scrolling body: it floats over the page instead,
  // below the button (above it when there is more room there), its list as tall as the space allows
  const inDialog = () => !!wrap.closest('dialog');
  const place = () => {
    if (!inDialog()) return;
    const r = btn.getBoundingClientRect(), vw = document.documentElement.clientWidth, vh = window.innerHeight;
    const w = Math.min(400, vw - 32);
    Object.assign(pop.style, { position: 'fixed', width: w + 'px', right: 'auto', left: Math.max(16, Math.min(upload ? r.right - w : r.left, vw - w - 16)) + 'px' });
    list.style.maxHeight = '';
    const rest = pop.offsetHeight - list.offsetHeight;
    const below = vh - r.bottom - 24, above = r.top - 24;
    const up = below < 300 && above > below;
    Object.assign(pop.style, up ? { top: 'auto', bottom: (vh - r.top + 8) + 'px' } : { top: (r.bottom + 8) + 'px', bottom: 'auto' });
    list.style.maxHeight = Math.max(120, Math.min(430, (up ? above : below) - rest)) + 'px';
  };
  window.addEventListener('scroll', (e) => { if (!pop.hidden && !pop.contains(e.target)) place(); }, true);
  window.addEventListener('resize', () => { if (!pop.hidden) place(); });
  const close = (refocus) => {
    if (pop.hidden) return;
    pop.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    if (refocus) btn.focus();
  };
  const set = (v) => { if (!byValue.has(v)) return; sel.value = v; paintBtn(); };
  const pick = (v) => {
    const changed = v !== sel.value;
    set(v);
    close(true);
    if (changed) { sel.dispatchEvent(new Event('input', { bubbles: true })); sel.dispatchEvent(new Event('change', { bubbles: true })); }
  };
  btn.addEventListener('click', () => (pop.hidden ? open() : close()));
  search.addEventListener('input', render);
  list.addEventListener('click', (e) => {
    const t = e.target.closest('[data-v]') || e.target.closest('[data-newest]');
    if (t) pick(t.dataset.v ?? t.dataset.newest);
  });
  wrap.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
    if (pop.hidden) return;
    const ts = targets();
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (ts.length) mark(e.key === 'ArrowDown' ? (act + 1) % ts.length : (act <= 0 ? ts.length : act) - 1);
      search.focus();
    } else if (e.key === 'Enter' && document.activeElement === search) {
      e.preventDefault();
      if (ts[act]) pick(ts[act].dataset.v);
    }
  });
  document.addEventListener('pointerdown', (e) => { if (!wrap.contains(e.target)) close(); });
  // Safari doesn't focus a clicked button: focus goes to the nearest box around it that takes focus (the picker window, its scrolling body),
  // which must not count as leaving, or the panel closes before the click lands and the click hits the page under it
  wrap.addEventListener('focusout', (e) => { const r = e.relatedTarget; if (r && !wrap.contains(r) && !r.contains(wrap)) close(); });

  // the upload starts on the folder used last, when it is the same kind as the tab's own first choice (model / accessory)
  if (upload) {
    const kind = byValue.get(sel.value)?.kind;
    const last = fpRecent().map((v) => byValue.get(v)).find((i) => i && i.kind === kind && (kind === 'model' || kind === 'outfit'));
    if (last) sel.value = last.value;
  }
  paintBtn();
  sel.fp = { set };
}
document.querySelectorAll('select[data-folder-pick]').forEach((s) => { if (!s.hidden) folderPicker(s); });

// ── Picture picker: one window to pick pictures from the Images library or upload new ones (every image field in the admin) ──
// pickImages({ multiple, folder, title, exclude, excludeLabel, note }) → Promise of the chosen addresses ([] = cancelled; .uploaded = new files).
// folder: where uploads go (a model's media folder, docs, faq…), also the library's first filter when it has pictures.
// exclude: addresses shown greyed out with excludeLabel (Admin → Gallery: pictures already in it). Library from admin/library.php.
let pickLib = null;   // the library, loaded once per page (an upload clears it)
function pickImages({ multiple = false, folder = 'misc', title = '', exclude = [], excludeLabel = 'Already Added', note = '' } = {}) {
  return new Promise((resolve) => {
    const fold = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const LIB_MAX = 120;   // pictures drawn at once: a search or a folder narrows the rest
    const excluded = new Set(exclude);
    let chosen = [], files = [], pane = 'library', lib = null;
    const tabBtn = (k, label) => el('a', { href: '#', 'data-src': k, 'aria-current': k === 'library' ? 'page' : null, onclick: (e) => { e.preventDefault(); setPane(k); } }, label);
    const libFolder = el('select', { class: 'input', 'data-folder-pick': 'filter', 'data-search-ph': 'Search models, accessories…', 'aria-label': 'Folder' }, el('option', { value: '' }, 'All Folders'));
    const search = el('input', { class: 'input', type: 'search', placeholder: 'Search a picture name…', 'aria-label': 'Search the pictures' });
    const onlyNew = el('input', { type: 'checkbox', class: 'switch', checked: true });
    const msg = el('p', { class: 'muted small' }, 'Loading the pictures…');
    const grid = el('div', { class: 'g-lib' });
    const upFolder = el('select', { class: 'input', 'data-folder-pick': 'upload', 'aria-label': 'Upload into' });
    const upFiles = el('input', { type: 'file', accept: 'image/*', multiple: multiple || null, hidden: true });
    const upMsg = el('p', { class: 'muted small' });
    const prog = uploadProgress();
    let busy = false;
    const drop = el('label', { class: 'g-drop' }, upFiles, el('b', {}, multiple ? 'Choose Pictures' : 'Choose a Picture'), el('span', { class: 'muted small' }, 'or drop ' + (multiple ? 'them' : 'it') + ' here · JPG, PNG, WebP, GIF'));
    const picked = el('div', { class: 'g-picked' });
    const go = el('button', { type: 'button', class: 'btn btn-primary btn-sm', disabled: true });
    const libPane = el('div', { class: 'g-modal-body' },
      el('div', { class: 'g-lib-tools' }, libFolder, search, excluded.size ? el('label', { class: 'toggle' }, onlyNew, el('span', {}, 'Hide ' + excludeLabel)) : null),
      msg, grid, note ? el('p', { class: 'muted small g-lib-note' }, note) : null);
    const upPane = el('div', { class: 'g-modal-body', hidden: true }, el('div', { class: 'g-up' },
      el('label', { class: 'field' }, lbl('Upload Into'), upFolder), drop, prog.node, upMsg));
    const dlg = el('dialog', { class: 'g-modal', 'aria-label': title || 'Pictures' },
      el('div', { class: 'g-modal-head' }, el('h2', {}, title || (multiple ? 'Add Pictures' : 'Choose a Picture')), el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => close([]) }, '✕')),
      el('nav', { class: 'lib-tabs g-modal-tabs', 'aria-label': 'Where from' }, tabBtn('library', 'From the Images Library'), tabBtn('upload', 'Upload New')),
      libPane, upPane,
      el('div', { class: 'g-modal-foot' }, picked, el('div', { class: 'row-btns' }, el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => close([]) }, 'Cancel'), go)));
    const close = (v) => { if (busy) return; files.forEach((f) => URL.revokeObjectURL(f.preview)); dlg.close(); dlg.remove(); resolve(v); };
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close([]); });
    const drawPicked = () => {
      const n = pane === 'library' ? chosen.length : files.length;
      const thumbs = pane === 'library' ? chosen.slice(0, 6).map((u) => el('img', { src: lib?.find((i) => i.url === u)?.small || u, alt: '' })) : files.slice(0, 6).map((f) => el('img', { src: f.preview, alt: '' }));
      picked.replaceChildren(...thumbs, n ? el('b', {}, multiple ? `${n} selected` : '') : el('span', { class: 'muted small' }, pane === 'library' ? (multiple ? 'Click pictures to pick them.' : 'Click a picture to pick it.') : (multiple ? 'Pictures upload as soon as you choose or drop them.' : 'The picture uploads as soon as you choose or drop it.')));
      go.disabled = !n || busy;
      go.hidden = pane === 'upload' && !busy;   // uploads start by themselves
      go.textContent = !multiple ? (pane === 'upload' ? 'Upload and Use It' : 'Use This Picture') : n ? `${pane === 'upload' ? 'Upload' : 'Add'} ${n} ${n === 1 ? 'Picture' : 'Pictures'}` : 'Add Pictures';
      if (busy) go.textContent = 'Uploading…';
    };
    const drawLib = () => {
      if (!lib) return;
      const words = fold(search.value).split(/\s+/).filter(Boolean);
      const match = lib.filter((i) => (!libFolder.value || i.folder === libFolder.value) && !(onlyNew.checked && excluded.has(i.url)) && words.every((w) => fold(i.label).includes(w)));
      grid.replaceChildren(...match.slice(0, LIB_MAX).map((i) => {
        const n = chosen.indexOf(i.url);
        const had = excluded.has(i.url);
        return el('button', { type: 'button', class: 'g-lib-pic' + (n >= 0 ? ' on' : '') + (had ? ' had' : ''), disabled: had, title: i.name + (i.w ? ` · ${i.w}×${i.h}` : '') + (had ? ' · ' + excludeLabel.toLowerCase() : ''), onclick: () => {
          if (n >= 0) chosen.splice(n, 1); else if (multiple) chosen.push(i.url); else chosen = [i.url];
          drawLib(); drawPicked();
        }, ondblclick: () => { if (!multiple) close([i.url]); } }, el('img', { src: i.small, alt: '', loading: 'lazy', decoding: 'async' }),
        n >= 0 ? el('span', { class: 'g-lib-n' }, multiple ? n + 1 : '✓') : null, had ? el('span', { class: 'g-lib-had' }, excludeLabel) : null);
      }));
      msg.hidden = match.length > 0 && match.length <= LIB_MAX;
      msg.textContent = !match.length ? (lib.length ? 'No pictures match.' : 'The Images library is empty: upload new pictures instead.') : `Showing ${LIB_MAX} of ${match.length}: search or pick a folder to see the others.`;
    };
    const setPane = (p) => {
      pane = p;
      dlg.querySelectorAll('[data-src]').forEach((a) => { if (a.dataset.src === p) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
      libPane.hidden = p !== 'library';
      upPane.hidden = p !== 'upload';
      drawPicked();
    };
    const addFiles = (list) => {
      const imgs = [...list].filter((f) => /^image\//.test(f.type));
      if (!imgs.length || (busy && !multiple)) return;
      if (!multiple) { files.forEach((f) => URL.revokeObjectURL(f.preview)); files = []; imgs.splice(1); }
      for (const f of imgs) files.push(Object.assign(f, { preview: URL.createObjectURL(f) }));   // during an upload: they join its queue
      upMsg.textContent = '';
      drawPicked();
      if (!busy) startUpload();   // no extra click: choosing or dropping starts the upload
    };
    [search, onlyNew, libFolder].forEach((x) => x.addEventListener('input', drawLib));
    upFiles.addEventListener('change', () => { addFiles(upFiles.files); upFiles.value = ''; });
    drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); addFiles(e.dataTransfer.files); });
    async function startUpload() {
      busy = true;
      drawPicked();
      if (!multiple) drop.classList.add('is-busy');   // several pictures: more can be dropped meanwhile
      upFolder.disabled = true;
      const where = upFolder.value || folder;
      const urls = await prog.run(files, where);
      busy = false;
      drop.classList.remove('is-busy');
      upFolder.disabled = false;
      if (urls.length) { fpRemember(where); pickLib = null; close(Object.assign(urls, { uploaded: true })); return; }
      prog.hide();
      files.forEach((f) => URL.revokeObjectURL(f.preview));
      files = [];
      upMsg.textContent = 'Nothing was uploaded. Try again.';
      drawPicked();
    }
    go.addEventListener('click', () => {
      if (pane === 'library') close(chosen);
    });
    document.body.append(dlg);
    dlg.showModal();
    drawPicked();
    (async () => {
      try {
        pickLib ??= await fetch('/admin/library.php?folder=' + encodeURIComponent(folder), { credentials: 'same-origin' }).then((r) => r.json());
        if (!pickLib.ok) throw new Error(pickLib.error || 'Could not load the pictures.');
        lib = pickLib.items;
        libFolder.insertAdjacentHTML('beforeend', pickLib.folders);
        if (lib.some((i) => i.folder === folder)) libFolder.value = folder;   // start on this model's (or page's) own pictures
        upFolder.innerHTML = pickLib.upload;
        if (![...upFolder.options].some((o) => o.value === folder)) upFolder.append(el('option', { value: folder }, folder));   // a model not saved yet
        upFolder.value = folder;
        folderPicker(libFolder);
        folderPicker(upFolder);
        upFolder.fp.set(folder);   // the picker would start on the folder used last: here it is this item's own
        drawLib();
      } catch (e) { pickLib = null; msg.textContent = e.message; }
    })();
  });
}

// ── Image library: filter, upload, copy address, delete ──────────────────────
const lib = document.querySelector('[data-lib]');
const libUpload = document.querySelector('[data-upload-files]');
const libProg = uploadProgress();
if (libUpload) {
  libUpload.addEventListener('change', async () => {
    const folder = document.querySelector('[data-upload-folder]').value;
    const files = [...libUpload.files];
    const label = libUpload.closest('label');
    if (!libProg.node.isConnected) { libProg.node.classList.add('lib-up-prog'); (label.closest('.admin-head') || label).after(libProg.node); }
    label.classList.add('is-busy');
    libUpload.disabled = true;
    label.firstChild.textContent = 'Uploading…';
    const done = (await libProg.run(files, folder)).length;
    libUpload.value = '';
    libUpload.disabled = false;
    label.classList.remove('is-busy');
    if (done) fpRemember(folder);
    if (done) location.reload(); else { libProg.hide(); label.firstChild.textContent = 'Upload Images'; }
  });
}
if (lib) {
  const cards = [...lib.querySelectorAll('.lib-card')];
  const search = document.querySelector('[data-lib-search]');
  const folder = document.querySelector('[data-lib-folder]');
  const unused = document.querySelector('[data-lib-unused]');
  const count = document.querySelector('[data-lib-count]');
  const empty = document.querySelector('[data-lib-empty]');
  const pager = document.querySelector('[data-lib-pager]');
  const PER = 60;   // 2 to 6 to a row: full rows on every screen
  let page = Math.max(1, parseInt(new URLSearchParams(location.search).get('page'), 10) || 1);
  let shown = cards;   // every card the search and filters match, on every page (the picture window goes through them)
  const fold = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  cards.forEach((c) => { c.foldedName = fold(c.dataset.name); });
  // ‹ 1 2 … 9 › under the grid (like the homepage catalog); the page is kept in ?page= so a reload (upload, crop) comes back to it
  const renderPager = (pages) => {
    pager.hidden = pages < 2;
    pager.replaceChildren();
    if (pages < 2) return;
    const btn = (label, n, aria) => {
      const b = Object.assign(document.createElement('button'), { type: 'button', className: 'chip', textContent: label });
      b.setAttribute('aria-label', aria);
      if (n === page && label === String(n)) b.setAttribute('aria-current', 'page');
      if (n < 1 || n > pages) b.disabled = true;
      else b.addEventListener('click', () => { page = n; apply(true); document.querySelector('.lib-filters').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
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
  function apply(keepPage = false) {
    if (keepPage !== true) page = 1;
    const words = fold(search.value).split(/\s+/).filter(Boolean);
    // the search box: file name and where it is used (every word, accents ignored); the menu: any of the card's groups
    const match = cards.filter((c) => words.every((w) => c.foldedName.includes(w)) && (!folder.value || c.dataset.folder.split(' ').includes(folder.value)) && (!unused.checked || c.dataset.used === '0'));
    shown = match;
    const pages = Math.max(1, Math.ceil(match.length / PER));
    page = Math.min(page, pages);
    const on = new Set(match.slice((page - 1) * PER, page * PER));
    for (const c of cards) c.hidden = !on.has(c);
    const n = match.length;
    count.textContent = pages > 1 ? `${(page - 1) * PER + 1}–${Math.min(n, page * PER)} of ${n}` : n === cards.length ? '' : `${n} shown`;
    empty.hidden = n > 0;
    renderPager(pages);
    const u = new URL(location.href);
    if (page > 1) u.searchParams.set('page', page); else u.searchParams.delete('page');
    history.replaceState(null, '', u);
  }
  [search, folder, unused].forEach((x) => x.addEventListener('input', () => apply()));
  // looking at one folder: the upload goes there too
  folder.addEventListener('input', () => { if (folder.value) document.querySelector('[data-upload-folder]')?.fp?.set(folder.value.replace(/:.*/, '')); });   // a docs page / FAQ question: its folder
  apply(true);   // the first page (or ?page=), and ?unused=1
  const info = JSON.parse(document.getElementById('lib-info')?.textContent || '{}');
  const delCard = (card) => { card.remove(); cards.splice(cards.indexOf(card), 1); apply(true); };
  lib.addEventListener('click', async (e) => {
    const card = e.target.closest('.lib-card');
    if (!card) return;
    const open = e.target.closest('[data-open]');
    if (open && !(e.ctrlKey || e.metaKey || e.shiftKey || e.button)) { e.preventDefault(); imageWindow(card); }   // ctrl / middle click: a new tab, as before
    if (e.target.closest('[data-copy]')) {
      try { await navigator.clipboard.writeText(card.dataset.url); toast('Address copied ✓'); }
      catch { prompt('Copy this address:', card.dataset.url); }
    }
    if (e.target.closest('[data-crop]')) imageWindow(card, 'crop');
    if (e.target.closest('[data-v3d]')) preview3d(card.dataset.url, card.querySelector('.lib-name').textContent);   // the 3D Files tab
    if (e.target.closest('[data-del]')) {
      if (!confirm(`Delete ${card.dataset.url.split('/').pop()}? This can't be undone.`)) return;
      try { await post('/admin/images.php', { action: 'delete', url: card.dataset.url }); delCard(card); toast(card.matches('.lib-3d') ? 'File deleted' : 'Image deleted'); }
      catch (err) { toast(err.message, 'err'); }
    }
  });

  // ── The picture window: a picture of the library with its details (Details) and crop / turn / mirror (Crop & rotate).
  // ‹ › and the arrow keys go through the pictures the search shows. Saving a crop makes a new file (images.php action crop)
  // and comes back to the page with the window open on it (?img=).
  function imageWindow(card, tab = 'details', note = '') {
    let cur = card, mode = tab;
    const I = () => info[cur.dataset.url] || {};
    const used = () => I().uses?.length || 0;
    const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
    const title = el('span', { class: 'iw-title' });
    const pos = el('span', { class: 'iw-pos' });
    const full = el('a', { class: 'btn btn-ghost btn-sm iw-full', target: '_blank', rel: 'noopener', title: 'Open full size in a new tab' }, el('span', { class: 't' }, 'Open Full Size '), '↗');
    const close = () => { tools?.destroy(); removeEventListener('keydown', onKey); dlg.close(); dlg.remove(); };
    const prev = el('button', { type: 'button', class: 'iw-nav iw-prev', 'aria-label': 'Previous picture', onclick: () => go(-1) }, '‹');
    const next = el('button', { type: 'button', class: 'iw-nav iw-next', 'aria-label': 'Next picture', onclick: () => go(1) }, '›');
    const pic = el('img', { alt: '', draggable: 'false' });
    const zoomBtns = ['Fit', '100%'].map((l, i) => el('button', { type: 'button', 'aria-pressed': String(!i), onclick: () => zoom(!!i) }, l));
    const zoomBar = el('div', { class: 'iw-zoom', role: 'group', 'aria-label': 'Zoom' }, zoomBtns);
    const view = el('div', { class: 'iw-pan' }, el('div', { class: 'iw-view' }, pic));   // at 100% only this part scrolls
    const stage = el('div', { class: 'iw-stage' });
    const tabBtns = { details: null, crop: null };
    const tabs = el('div', { class: 'iw-tabs', role: 'tablist' },
      tabBtns.details = el('button', { type: 'button', role: 'tab', onclick: () => show('details') }, 'Details'),
      tabBtns.crop = el('button', { type: 'button', role: 'tab', onclick: () => show('crop') }, 'Crop & Rotate'));
    const body = el('div', { class: 'iw-body' });
    const foot = el('footer', { class: 'iw-foot' });
    const msg = el('p', { class: 'iw-msg', role: 'status' });
    const dlg = el('dialog', { class: 'iw', 'aria-label': 'Picture' },
      el('header', { class: 'iw-head' }, el('div', { class: 'iw-hl' }, title, pos), tabs,
        el('div', { class: 'iw-hr' }, full, el('button', { type: 'button', class: 'iw-x', 'aria-label': 'Close', onclick: close }, '✕'))),
      stage,
      el('aside', { class: 'iw-side' }, el('div', { class: 'iw-scroll' }, body, msg), foot));
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    const say = (t, kind = 'err') => { msg.textContent = t; msg.className = 'iw-msg ' + kind; };

    let tools = null, replace = null, saveBtn = null;   // the Crop & rotate tab (cropTools())

    const go = (d) => {
      if (mode !== 'details') return;
      const list = shown.includes(cur) ? shown : cards;
      const i = list.indexOf(cur);
      if (list.length < 2) return;
      cur = list[(i + d + list.length) % list.length];
      render();
    };
    const zoom = (on) => {
      stage.classList.toggle('is-zoom', on);
      zoomBtns.forEach((b, i) => b.setAttribute('aria-pressed', String(!!i === on)));
      if (on) { view.scrollLeft = (view.scrollWidth - view.clientWidth) / 2; view.scrollTop = (view.scrollHeight - view.clientHeight) / 2; }   // the middle first
    };
    view.addEventListener('pointerdown', (e) => {   // at 100%: drag the picture around with the mouse (touch scrolls by itself)
      if (!stage.classList.contains('is-zoom') || e.pointerType !== 'mouse' || e.button) return;
      e.preventDefault();
      const x0 = e.clientX, y0 = e.clientY, l0 = view.scrollLeft, t0 = view.scrollTop;
      const move = (ev) => { view.scrollLeft = l0 - (ev.clientX - x0); view.scrollTop = t0 - (ev.clientY - y0); };
      const up = () => { removeEventListener('pointermove', move); removeEventListener('pointerup', up); };
      addEventListener('pointermove', move);
      addEventListener('pointerup', up);
    });
    const onKey = (e) => {
      if (mode !== 'details' || e.target.closest?.('input, textarea, select')) return;
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
    };
    addEventListener('keydown', onKey);

    const details = () => {
      const d = I();
      body.replaceChildren(...pictureFacts(d, cur.dataset.url));
      const del = el('button', { type: 'button', class: 'btn btn-ghost btn-sm iw-del', disabled: used() > 0, title: used() ? 'In use: remove it there first' : 'Delete this picture' }, 'Delete');
      del.addEventListener('click', async () => {
        if (!confirm(`Delete ${d.name}? This can't be undone.`)) return;
        try {
          await post('/admin/images.php', { action: 'delete', url: cur.dataset.url });
          const gone = cur, list = shown.includes(cur) ? shown : cards;
          const after = list[list.indexOf(gone) + 1] || list[list.indexOf(gone) - 1];
          delCard(gone);
          if (after && after !== gone) { cur = after; render(); say('Picture deleted ✓', 'ok'); } else { close(); toast('Image deleted'); }
        } catch (err) { say(err.message); }
      });
      foot.replaceChildren(...[del, d.crop && el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => show('crop') }, 'Crop & Rotate…')].filter(Boolean));
    };
    const cropTab = () => {
      tools?.destroy();
      saveBtn = el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: save, disabled: true }, 'Save');
      tools = cropTools(cur.dataset.url, { onState: (ok) => { saveBtn.disabled = !ok; }, onError: (t) => say(t) });
      const n = used();
      replace = el('input', { type: 'checkbox', class: 'switch', checked: n > 0 });
      body.replaceChildren(...tools.sections,
        ...(n ? [el('section', { class: 'iw-sec' },
          el('label', { class: 'toggle' }, replace, el('span', {}, `Replace Original in ${n === 1 ? '1 Place' : n + ' Places'}`)))] : []));
      foot.replaceChildren(
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => tools.reset() }, 'Reset'),
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => show('details') }, 'Cancel'),
        saveBtn);
      stage.replaceChildren(tools.stage);
    };
    async function save() {
      const box = tools?.result();
      if (saveBtn.disabled || !box) return;
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; say('', 'ok');
      try {
        const r = await post('/admin/images.php', { action: 'crop', url: cur.dataset.url, replace: !!replace?.checked, ...box });
        const u = new URL(location.href);
        u.searchParams.set('img', r.url);
        location.href = u;   // the page again, with the window open on the new picture
      } catch (err) { say(err.message); saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
    }
    function show(t) {
      mode = t === 'crop' && I().crop ? 'crop' : 'details';
      say('', 'ok');
      tabBtns.details.setAttribute('aria-selected', String(mode === 'details'));
      tabBtns.crop.setAttribute('aria-selected', String(mode === 'crop'));
      tabBtns.crop.hidden = !I().crop;
      tabs.hidden = !I().crop;   // one tab alone isn't shown
      dlg.classList.toggle('is-crop', mode === 'crop');
      tools?.destroy(); tools = null;   // a turned picture still being drawn for the tab we leave is dropped
      if (mode === 'crop') return cropTab();
      zoom(false);
      stage.replaceChildren(prev, view, zoomBar, next);
      details();
    }
    function render() {
      const list = shown.includes(cur) ? shown : cards;
      title.textContent = I().name || cur.dataset.url.split('/').pop();
      pos.textContent = list.length > 1 ? `${list.indexOf(cur) + 1} of ${list.length}` : '';
      prev.hidden = next.hidden = list.length < 2;
      full.href = cur.dataset.url;
      pic.src = cur.dataset.url;
      show(mode);
    }
    document.body.append(dlg);
    dlg.showModal();
    render();
    if (note) say(note, 'ok');
    document.activeElement?.blur?.();
  }
  // after a crop: the window opens on the new picture
  const back = new URLSearchParams(location.search).get('img');
  if (back) {
    const u = new URL(location.href);
    u.searchParams.delete('img');
    history.replaceState(null, '', u);
    const c = cards.find((x) => x.dataset.url === back);
    if (c) imageWindow(c, 'details', 'Saved ✓ This is the new picture.');
    else toast(`Saved ✓ The new picture (${back.split('/').pop()}) is listed under another tab.`);   // e.g. an unused copy of a picture from another folder
  }
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
  let edits = 0;   // counts changes: a save clears "unsaved" only when nothing changed while it ran
  const mark = () => { dirty = true; edits++; };
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
  const reorder = (owner, prop, box, sel, key, item) => {
    const page = currentPage();
    const old = owner[prop];
    const nodes = [...box.querySelectorAll(':scope > ' + sel)];
    owner[prop] = nodes.map((n) => old[+n.dataset[key]]);
    const h = document.activeElement;
    const keys = !!item && h?.classList.contains('drag-handle') && h.closest(sel) === item;   // moved with the arrow keys: its handle keeps the focus for the next press
    cur = page ? locate(page) : null;
    mark(); renderTree(); renderPane();
    if (keys) {
      const at = nodes.indexOf(item);
      const si = owner === docs ? at : docs.sections.indexOf(owner);
      tree.querySelector(owner === docs ? `.dt-sec[data-si="${si}"] .dt-sec-head .drag-handle` : `.dt-sec[data-si="${si}"] .dt-page[data-pi="${at}"] .drag-handle`)?.focus();
    }
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
    mark(); renderTree(); renderPane();   // the open page's Section menu lists the new one
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
        title.addEventListener('input', () => { sec.title = title.value; mark(); const opt = pane.querySelector(`option[value="${si}"]`); if (opt) opt.textContent = title.value || 'Untitled Section'; });
        const pages = sec.pages.map((p, pi) => el('li', { class: 'dt-page' + (cur && cur.s === si && cur.p === pi ? ' active' : ''), 'data-pi': pi },
          dragHandle((h) => h.closest('.dt-page'), (item) => reorder(sec, 'pages', item.parentNode, '.dt-page', 'pi', item)),
          el('button', { type: 'button', class: 'dt-link', onclick: () => select(si, pi) }, p.title || 'Untitled Page', homePage() === p ? el('span', { class: 'dt-home' }, 'Home') : null, p.draft ? el('span', { class: 'dt-draft' }, 'Draft') : null)));
        return el('div', { class: 'dt-sec', 'data-si': si },
          el('div', { class: 'dt-sec-head' }, dragHandle((h) => h.closest('.dt-sec'), (item) => reorder(docs, 'sections', item.parentNode, '.dt-sec', 'si', item)), title,
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
    const slug = el('input', { class: 'input', type: 'text', value: page.slug, maxlength: 64, pattern: '[a-z0-9][a-z0-9\\-]*', spellcheck: 'false' });
    const sum = el('input', { class: 'input', type: 'text', value: page.summary || '', maxlength: 300, placeholder: 'One line under the title, also used in the docs home and by search engines' });
    const secSel = el('select', { class: 'input' }, docs.sections.map((sec, si) => el('option', { value: si, selected: si === cur.s }, sec.title || 'Untitled Section')));
    let slugTouched = !page.isNew;
    title.addEventListener('input', () => {
      page.title = title.value;
      // only before the page's first save: a saved page keeps its address (isNew goes on save)
      if (!slugTouched && page.isNew) { page.slug = uniqueSlug(slugify(title.value) || 'page', page); slug.value = page.slug; }
      tree.querySelector('.dt-page.active .dt-link').firstChild.textContent = title.value || 'Untitled Page';
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

    const body = el('textarea', { class: 'input dp-body', spellcheck: 'true', maxlength: 200000, placeholder: 'Write the page here. See "How to format pages" above.' });
    body.value = page.body || '';
    body.addEventListener('input', () => (page.body = body.value));
    body.addEventListener('keydown', (e) => {
      if (!(e.ctrlKey || e.metaKey)) return;
      if (e.key === 'b') { e.preventDefault(); wrap(body, '**', '**', 'bold text'); }
      if (e.key === 'i') { e.preventDefault(); wrap(body, '*', '*', 'italic text'); }
      if (e.key === 'k') { e.preventDefault(); tools.link(); }
    });

    const imgBtn = el('button', { type: 'button', class: 'tb', title: 'Pictures from the Images library or uploaded (several = side-by-side gallery)', onclick: async () => {
      const urls = await pickImages({ multiple: true, folder: 'docs', title: 'Pictures for This Page' });
      if (urls.length) { block(body, urls.map((u) => `![](${u})`).join('\n')); mark(); }
    } }, 'Image');
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
      tb('Tabs', 'Tabs: two or more ways to do the same steps (the selected text goes in the first tab)', () => {
        const t = body.value.slice(body.selectionStart, body.selectionEnd).trim();
        block(body, `=== Manual\n${t || '1. First step'}\n=== With MustardUI\n1. First step\n===`);
      }),
      imgBtn);

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
        el('label', { class: 'field check dp-draft' }, draft, lbl('Draft', 'Only admins see it.')),
        el('label', { class: 'field dp-wide' }, el('span', { class: 'lbl' }, 'Summary'), sum)),
      el('div', { class: 'dp-tabs' }, tabWrite, tabPrev),
      toolbar, body, preview,
      el('div', { class: 'dp-foot' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm dp-del', onclick: () => {
          if (!confirm(`Delete the page "${page.title}"? It is removed when you save.`)) return;
          docs.sections[cur.s].pages.splice(cur.p, 1);
          cur = null; mark(); renderTree(); renderPane();
        } }, 'Delete Page')));
  }

  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const saveBtn = document.querySelector('[data-save]');
  const save = async () => {
    if (saveBtn.disabled) return;   // already saving (Ctrl+S pressed twice)
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    try {
      const out = { sections: docs.sections.map((sec) => ({ title: sec.title.trim(), pages: sec.pages.map((p) => ({ slug: p.slug, title: p.title.trim(), summary: (p.summary || '').trim(), body: p.body || '', draft: !!p.draft })) })) };
      const at = edits;
      const sent = allPages();   // pages added while it saves keep following their title
      data.rev = (await post('/admin/save.php', { kind: 'docs', docs: out, rev: data.rev })).rev;
      sent.forEach((p) => delete p.isNew);
      if (edits === at) dirty = false;
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
  let edits = 0;   // counts changes: a save clears "unsaved" only when nothing changed while it ran
  const mark = () => { dirty = true; edits++; };
  const growArea = (value, rows, placeholder, maxlength) => { const t = autoGrow(el('textarea', { class: 'input', rows, placeholder, maxlength })); t.value = value || ''; return t; };
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
    const a = growArea(it.a, 4, 'The answer…', 20000);
    const imgBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pictures from the Images library or uploaded (several = side-by-side gallery)', onclick: async () => {
      const urls = await pickImages({ multiple: true, folder: 'faq', title: 'Pictures for This Answer' });
      if (urls.length) insertAt(a, '\n\n' + urls.map((u) => `![](${u})`).join('\n') + '\n\n');
    } }, 'Add Image');
    const title = el('span', { class: 'rep-title' }, it.q || 'New Question');
    q.addEventListener('input', () => (title.textContent = q.value || 'New Question'));
    const head = el('summary', { class: 'rep-head' }, title, el('span', { class: 'row-btns' }));
    const node = card('faq-q', head, el('div', { class: 'rep-body' },
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Question'), q),
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Answer'), a),
      el('div', { class: 'row-btns' }, imgBtn, boxMenu(() => a, 'input input-sm'))), 'Remove this question?');
    node.get = () => ({ q: q.value.trim(), a: a.value.trim() });
    return node;
  };
  const topic = (s = {}, open = false) => {
    const t = el('input', { class: 'input', type: 'text', value: s.title || '', placeholder: 'Topic title, e.g. Cycles', maxlength: 120 });
    const list = el('div', { class: 'repeat' }, (s.items || []).map((it) => question(it)));
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const n = question(); n.open = true; list.append(n); n.querySelector('input').focus(); mark(); } }, '+ Add Question');
    const title = el('span', { class: 'rep-title' }, `${s.title || 'New Topic'} (${(s.items || []).length})`);
    const refresh = () => (title.textContent = `${t.value || 'New Topic'} (${list.children.length})`);
    t.addEventListener('input', refresh);
    list.addEventListener('click', () => setTimeout(refresh));
    add.addEventListener('click', refresh);
    const head = el('summary', { class: 'rep-head' }, title, el('span', { class: 'row-btns' }));
    const node = card('faq-topic', head, el('div', { class: 'rep-body' }, el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Topic'), t), list, add), 'Remove this whole topic and its questions?');
    node.open = open;
    node.get = () => ({ title: t.value.trim(), items: [...list.children].map((c) => c.get()) });
    return node;
  };

  const intro = growArea(data.faq.intro, 2, 'Short text under the page title (optional)', 2000);
  const topics = el('div', { class: 'repeat' }, (data.faq.sections || []).map((s) => topic(s)));
  const addTopic = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const n = topic({}, true); topics.append(n); n.querySelector('input').focus(); mark(); } }, '+ Add Topic');
  form.append(
    el('fieldset', { class: 'sec' }, el('legend', {}, 'Introduction'), intro),
    el('fieldset', { class: 'sec' }, el('legend', {}, 'Topics and Questions'), topics, addTopic));
  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const saveBtn = document.querySelector('[data-save]');
  const save = async () => {
    if (saveBtn.disabled) return;   // already saving (Ctrl+S pressed twice)
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    try {
      const at = edits;
      data.rev = (await post('/admin/save.php', { kind: 'faq', faq: { intro: intro.value.trim(), sections: [...topics.children].map((n) => n.get()) }, rev: data.rev })).rev;
      if (edits === at) dirty = false;
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

// ── 3D preview (the editors' 3D Preview field): the viewer script loads on first use, like on the public pages (site.js) ──
function cubeIcon() {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = '<path d="M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.3L18.7 8 12 11.7 5.3 8 12 4.3ZM5 9.7l6 3.3v6.6l-6-3.3V9.7Zm8 9.9V13l6-3.3v6.6l-6 3.3Z"/>';
  return s;
}
const VIEWER_SRC = document.currentScript?.dataset.viewer || '/assets/vendor/model-viewer.min.js';   // with ?v=: .js is cached for a year
function loadViewer() {
  // decoders the viewer would otherwise fetch from other websites: pointed at this site (files using them are refused at upload)
  self.ModelViewerElement = Object.assign(self.ModelViewerElement || {}, { dracoDecoderLocation: '/assets/vendor/none/', ktx2TranscoderLocation: '/assets/vendor/none/', lottieLoaderLocation: '/assets/vendor/none/' });
  return (loadViewer.p ??= import(VIEWER_SRC));
}
// The viewer's light: percent of this exposure (`model3dLight`, M3D_EXPOSURE / M3D_LIGHT_RANGE in app/model3d.php)
const M3D_EXPOSURE = 1.1;
// a "Brightness" slider (20–200 %) whose onInput gets the percent; light.set(pct) moves it without calling it back
function lightSlider(pct, onInput) {
  const range = el('input', { type: 'range', min: '20', max: '200', step: '5', 'aria-label': 'Brightness' });
  const out = el('span', { class: 'm3d-light-val' });
  const reset = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Back to 100%' }, 'Reset');
  const show = () => { out.textContent = range.value + '%'; reset.style.visibility = +range.value === 100 ? 'hidden' : ''; };   // keeps its room: the bar doesn't move
  const set = (v) => { range.value = String(v); show(); };
  range.addEventListener('input', () => { show(); onInput(+range.value); });
  reset.addEventListener('click', () => { set(100); onInput(100); });
  set(pct);
  return Object.assign(el('label', { class: 'm3d-light' }, el('span', { class: 'lbl' }, 'Brightness'), range, out, reset), { set });
}
// light = {pct, onInput}: the editor's Light setting, a slider in the window too (both stay in step)
async function preview3d(src, name, light = null) {
  if (!src) return;
  const exp = (pct) => String(Math.round(M3D_EXPOSURE * pct * 10) / 1000);
  const mv = el('model-viewer', { src, alt: '3D preview', 'camera-controls': true, 'auto-rotate': true, 'shadow-intensity': '1', exposure: exp(light?.pct ?? 100), 'interaction-prompt': 'none' });
  const msg = el('div', { class: 'm3d-load' }, 'Loading…');
  const close = () => { dlg.close(); dlg.remove(); };
  const slider = light && lightSlider(light.pct, (pct) => { mv.setAttribute('exposure', exp(pct)); light.onInput(pct); });
  const dlg = el('dialog', { class: 'm3d-dlg' + (slider ? ' has-light' : ''), 'aria-label': '3D Preview' },
    el('header', { class: 'iw-head' }, el('div', { class: 'iw-hl' }, el('span', { class: 'iw-title' }, (name || '3D') + ' · 3D Preview')), el('span'),
      el('div', { class: 'iw-hr' }, el('button', { type: 'button', class: 'iw-x', 'aria-label': 'Close', onclick: close }, '✕'))),
    el('div', { class: 'm3d-stage' }, mv, msg), slider && el('div', { class: 'm3d-dlg-foot' }, slider));
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
  mv.addEventListener('load', () => msg.remove());
  mv.addEventListener('error', () => { msg.textContent = 'This file could not be shown.'; });
  document.body.append(dlg);
  dlg.showModal();
  try { await loadViewer(); } catch { msg.textContent = 'The 3D viewer could not be loaded.'; }
}
(() => {
  let pop = null, cur = null, shownAt = 0;
  const hide = () => { if (pop) pop.hidden = true; cur = null; };
  const show = (b) => {
    pop ??= el('div', { class: 'tip-pop', role: 'tooltip' });
    const host = b.closest('dialog[open]') || document.body;   // a window (the picture picker…) covers everything outside it
    if (pop.parentNode !== host) host.append(pop);
    if (cur !== b) shownAt = Date.now();
    cur = b;
    pop.textContent = '';
    if (b.dataset.tipLinks) {   // Links column of the lists: the link names, grouped by who gets them
      const names = JSON.parse(b.dataset.tipLinks);
      for (const [k, head] of [['patrons', 'Patrons'], ['public', 'Public'], ['addons', 'Add-ons']]) {
        if (!names[k]?.length) continue;
        pop.append(el('div', { class: 'tp-head tp-' + k }, head), el('ul', { class: 'tp-links' }, names[k].map((n) => typeof n === 'string' ? el('li', {}, n)
          : el('li', { class: 'tp-dead' }, n.n, el('span', {}, ' · dead link')))));   // Link Check found it dead
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
  document.addEventListener('click', (e) => {
    const b = e.target.closest?.(TIPS);
    if (!b) return hide();
    if (b.href && cur === b && Date.now() - shownAt > 400) return;   // a link (a dead Links text → Link Check): opens once its text was seen (mouse hover, or a first tap)
    e.preventDefault(); show(b);   // preventDefault: inside a <label>, not a click on its field
  });
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
    const ta = autoGrow(el('textarea', { class: 'input', rows: o.rows || 3, placeholder: o.placeholder, maxlength: o.max }));
    ta.value = value ?? '';
    return { node: el('label', { class: 'field ' + (o.cls || '') }, lbl(label, o.hint), ta), get: () => ta.value.trim() };
  };
  const select = (label, value, options, o = {}) => {
    const s = el('select', { class: 'input' }, Object.entries(options).map(([k, v]) => el('option', { value: k, selected: k === value }, v)));
    return { node: el('label', { class: 'field ' + (o.cls || '') }, lbl(label, o.hint), s), get: () => s.value };
  };
  // an on/off setting: a switch, like everywhere in the admin
  const toggle = (label, value, hint) => {
    const c = el('input', { type: 'checkbox', class: 'switch', checked: !!value });
    return { node: el('label', { class: 'toggle' }, c, el('span', {}, label, hint && tip(hint))), get: () => c.checked };
  };

  // Image: preview + address + upload + crop. o.crop: open the cropper right after an upload (shape o.aspect).
  const image = (label, value, hint, o = {}) => {
    const input = el('input', { class: 'input', type: 'text', value: value || '', placeholder: '/media/… or https://…', maxlength: 1000 });
    const prev = el('div', { class: 'img-prev' });
    const show = () => {
      prev.innerHTML = '';
      const v = input.value.trim();
      if (v) prev.append(el('img', { src: v, alt: '' })); else prev.append(el('span', { class: 'muted small' }, 'No Image'));
      prev.classList.toggle('is-open', !!v);
      prev.title = v ? 'Details and crop' : '';
    };
    prev.addEventListener('click', () => { if (input.value.trim()) crop(false, 'details'); });   // the picture: its details (Crop… opens on crop)
    input.addEventListener('input', show);
    show();
    const btn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pick a picture from the Images library or upload a new one', onclick: async () => {
      const picked = await pickImages({ folder: slugNow(), title: label });
      const url = picked[0];
      if (!url) return;
      input.value = url; show(); form.dispatchEvent(new Event('input'));
      if (o.crop && picked.uploaded && !/\.gif$/i.test(url)) crop(true);   // a new upload: offer the best shape at once
    } }, 'Choose…');
    const cropBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => crop(false) }, 'Crop…');
    const crop = async (afterUpload, tab = 'crop') => {
      const src = input.value.trim();
      if (!src) return;
      if (tab === 'crop' && !src.startsWith('/media/')) return toast('Only images on this site can be cropped: upload it here first.', 'err');
      const box = await cropImage(src, { aspect: o.aspect || 0, title: afterUpload ? 'Crop the New ' + label : label, skip: afterUpload, tab });
      if (!box || box === 'skip') return;
      cropBtn.disabled = true; cropBtn.textContent = 'Cropping…';
      try { input.value = (await post('/admin/images.php', { action: 'crop', url: src, ...box })).url; show(); form.dispatchEvent(new Event('input')); toast('Cropped ✓ Save to keep it.'); }
      catch (e) { toast(e.message, 'err'); }
      cropBtn.disabled = false; cropBtn.textContent = 'Crop…';
    };
    const clear = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { input.value = ''; show(); form.dispatchEvent(new Event('input')); } }, 'Remove');
    return { node: el('div', { class: 'field image-field' }, lbl(label, hint), o.note ? el('p', { class: 'img-note' }, o.note) : null, el('div', { class: 'img-row' }, prev, el('div', { class: 'img-ctl' }, input, el('div', { class: 'row-btns' }, btn, cropBtn, clear)))), get: () => input.value.trim() };
  };

  // 3D preview: one .glb file (admin/upload-3d.php checks it and answers its facts, app/model3d.php). The address can't be typed:
  // upload or remove. Preview opens it in the same viewer as the page.
  const model3d = (value, facts, lightPct) => {
    let src = value || '';
    let light = lightPct || 100;   // the viewer's light, % (`model3dLight`)
    const changed = () => form.dispatchEvent(new Event('input'));
    const lightRow = lightSlider(light, (pct) => { light = pct; changed(); });
    const preview = () => src && preview3d(src, fields.name.get(), { pct: light, onInput: (pct) => { light = pct; lightRow.set(pct); changed(); } });
    const input = el('input', { class: 'input', type: 'text', readonly: true, placeholder: 'No 3D file', tabindex: '-1' });
    const factsLine = el('div', { class: 'm3d-facts' });
    const prev = el('button', { type: 'button', class: 'img-prev m3d-prev', title: 'Preview in 3D', onclick: preview }, cubeIcon());
    const file = el('input', { type: 'file', accept: '.glb,model/gltf-binary', hidden: true });
    const prog = el('div', { class: 'up-prog', hidden: true }, el('span', { class: 'up-prog-tx' }), el('span', { class: 'up-prog-bar' }, el('span', { class: 'up-prog-fill' })));
    const upBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => file.click() }, 'Upload .glb…');
    const viewBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: preview }, 'Preview');
    const clear = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { set('', null); form.dispatchEvent(new Event('input')); } }, 'Remove');
    const n = (x) => (x >= 10000 ? Math.round(x / 1000) + 'k' : x.toLocaleString('en'));
    const showFacts = (f) => {
      factsLine.replaceChildren();
      if (!src || !f) return;
      if (f.error) { factsLine.append(el('span', { class: 'm3d-bad' }, f.error)); return; }
      const bits = [[(f.bytes / 1048576).toFixed(1) + ' MB', 'file'], [n(f.tris), 'triangles'],
        [String(f.textures), (f.textures === 1 ? 'texture' : 'textures') + (f.texMax ? `, up to ${f.texMax} px` : '')]];
      if (f.morphs) bits.push([String(f.morphs), f.morphs === 1 ? 'shape key' : 'shape keys']);
      if (f.rig) bits.push(['', 'rig']);
      if (f.anims) bits.push([String(f.anims), f.anims === 1 ? 'animation' : 'animations']);
      for (const [b, t] of bits) factsLine.append(el('span', {}, b && el('b', {}, b), b ? ' ' + t : t));
      factsLine.append(f.light ? el('span', { class: 'm3d-ok' }, '✓ Light enough for phones')
        : el('span', { class: 'm3d-warn' }, 'May be slow on phones: ' + f.heavy.join(', ')));
    };
    const set = (url, f) => {
      src = url;
      input.value = url;
      input.hidden = !url;
      viewBtn.hidden = clear.hidden = lightRow.hidden = !url;
      prev.classList.toggle('is-open', !!url);
      prev.disabled = !url;
      upBtn.textContent = url ? 'Replace…' : 'Upload .glb…';
      showFacts(f);
    };
    file.addEventListener('change', async () => {
      const f = file.files[0];
      file.value = '';
      if (!f) return;
      const tx = prog.querySelector('.up-prog-tx'), fill = prog.querySelector('.up-prog-fill');
      prog.hidden = false; upBtn.disabled = true;
      try {
        const d = await sendFile('/admin/upload-3d.php', f, slugNow(), (sent, busy) => {
          const pct = Math.min(100, Math.round((100 * sent) / (f.size || 1)));
          fill.style.width = pct + '%';
          prog.classList.toggle('busy', busy);
          tx.textContent = busy ? `Saving ${f.name} on the server…` : `Uploading ${f.name} · ${pct}%`;
        });
        set(d.url, d.info);
        form.dispatchEvent(new Event('input'));
        toast('Uploaded ✓ Save to keep it.');
      } catch (e) { toast(`${f.name}: ${e.message}`, 'err'); }
      prog.hidden = true; prog.classList.remove('busy'); upBtn.disabled = false;
    });
    set(src, facts);
    const hint = 'A .glb file exported from Blender (File → Export → glTF 2.0, Format “glTF Binary”). Visitors load it only when they press “View in 3D” on the main picture. '
      + 'Anyone who opens it can save it, so upload a lighter copy (fewer triangles, 2048 px textures), not the full model. Shown only while the pictures are public.';
    return { node: el('div', { class: 'field image-field m3d-field' }, lbl('3D Preview', hint),
      el('div', { class: 'img-row' }, prev, el('div', { class: 'img-ctl' }, input, factsLine, lightRow, prog, el('div', { class: 'row-btns' }, upBtn, viewBtn, clear, file)))), get: () => src, light: () => light };
  };

  // List of lines: Enter = new line, Shift+Enter = line break inside the same item; o.max = the server's limit per line (clean_list())
  const list = (label, items, o = {}) => {
    const wrap = el('div', { class: 'list' });
    const addRow = (value = '', after = null, focus = false) => {
      const ta = autoGrow(el('textarea', { class: 'input', rows: 1, placeholder: o.placeholder || '', maxlength: o.max || 2000 }));
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
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      });
      if (after) after.after(row); else wrap.append(row);
      if (focus) ta.focus();
      return row;
    };
    (items || []).forEach((v) => addRow(v));
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => addRow('', null, true) }, '+ ' + (o.addLabel || 'Add Line'));
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
      const cap = el('input', { class: 'input', type: 'text', value: caption, placeholder: 'Caption / artist (optional)', maxlength: 200 });
      const img = el('img', { src, alt: '', loading: 'lazy' });
      // crop: a new file (the old one stays for whatever else uses it); this card takes the new address
      const open = async (tab) => {   // the picture: its details; Crop: the window on crop
        const url = card.dataset.src;
        if (tab === 'crop' && !url.startsWith('/media/')) return toast('Only images on this site can be cropped: copy it here first (Images page).', 'err');
        if (tab === 'crop' && /\.gif$/i.test(url)) return toast('Animated GIFs can’t be cropped.', 'err');
        const box = await cropImage(url, { title: 'Gallery Picture', tab });
        if (!box) return;
        cropBtn.disabled = true; cropBtn.textContent = '…';
        try { const r = await post('/admin/images.php', { action: 'crop', url, ...box }); card.dataset.src = r.url; img.src = r.url; form.dispatchEvent(new Event('input')); toast('Cropped ✓ Save to keep it.'); }
        catch (e) { toast(e.message, 'err'); }
        cropBtn.disabled = false; cropBtn.textContent = 'Crop';
      };
      const cropBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Crop this picture', onclick: () => open('crop') }, 'Crop');
      img.classList.add('is-open');
      img.title = 'Details and crop';
      img.addEventListener('click', () => open('details'));
      const card = el('div', { class: 'gal-card', 'data-src': src }, img, cap,
        el('div', { class: 'row-btns' }, dragHandle(() => card), cropBtn, iconBtn('✕', 'Remove', () => { card.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      grid.append(card);
    };
    (items || []).forEach((g) => (typeof g === 'string' ? addCard(g) : addCard(g.src, g.caption || '')));
    const up = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pick pictures from the Images library or upload new ones', onclick: async () => {
      const urls = await pickImages({ multiple: true, folder: slugNow(), title: 'Add to the ' + label, exclude: [...grid.children].map((c) => c.dataset.src), excludeLabel: 'Already Here' });
      urls.forEach((u) => addCard(u));
      if (urls.length) form.dispatchEvent(new Event('input'));
    } }, 'Add Pictures…');
    const byUrl = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { const u = prompt('Image address (/media/… or https://…)'); if (u) { addCard(u.trim()); form.dispatchEvent(new Event('input')); } } }, 'Add by Address');
    return {
      node: el('div', { class: 'field' }, lbl(label, hint), grid, el('div', { class: 'row-btns' }, up, byUrl)),
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

  // Link Check found this address dead (its last check): the row's fields turn red and a red broken chain before Remove says
  // "Dead link" on hover and opens Link Check (a new tab, so the editor stays), until the address is changed. The other link rows
  // keep its room (form.has-dead), so they line up
  const markDead = (row, url) => {
    const first = url.value.trim();
    if (!(data.deadLinks || {})[first]) return;
    const ico = el('a', { class: 'tip dead-ico', href: '/admin/links.php', target: '_blank', 'data-tip': 'Dead link', 'aria-label': 'Dead link: open Link Check' });
    ico.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m18.84 12.25 1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="m5.17 11.75-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"/><path d="M8 2v3M2 8h3M16 19v3M19 16h3"/></svg>';   // broken chain (Lucide "unlink", ISC)
    row.classList.add('is-dead', 'has-ico');
    row.querySelector('.row-btns').prepend(ico);
    form.classList.add('has-dead');
    url.addEventListener('input', () => { const same = url.value.trim() === first; row.classList.toggle('is-dead', same); ico.style.visibility = same ? '' : 'hidden'; });
  };

  // Download links: label + address + who can use it
  const links = (items) => {
    const wrap = el('div', { class: 'links' });
    const addRow = (l = {}) => {
      const label = el('input', { class: 'input', type: 'text', value: l.label || '', placeholder: 'Label, e.g. Mega', maxlength: 60 });
      const url = el('input', { class: 'input', type: 'url', value: l.url || '', placeholder: 'https://…', maxlength: 1000 });
      const access = el('select', { class: 'input' }, el('option', { value: 'patrons', selected: (l.access || 'patrons') === 'patrons' }, 'Patrons Only'), el('option', { value: 'public', selected: l.access === 'public' }, 'Public'));
      const row = el('div', { class: 'link-row' }, dragHandle(() => row), label, url, access,
        el('div', { class: 'row-btns' }, iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      markDead(row, url);
      wrap.append(row);
      return row;
    };
    (items || []).forEach(addRow);
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { addRow({ label: wrap.children.length ? '' : 'Mega' }).querySelector('input').focus(); form.dispatchEvent(new Event('input')); } }, '+ Add Download Link');
    return {
      node: el('div', { class: 'field' }, lbl('Download Links'), wrap, add),
      get: () => [...wrap.children].map((r) => { const [lb, u, a] = r.querySelectorAll('input, select'); return { label: lb.value.trim(), url: u.value.trim(), access: a.value }; }).filter((l) => l.url || l.label),
    };
  };

  // Add-ons required: name + download address, shown in the download box
  const addonList = (items) => {
    const wrap = el('div', { class: 'links' });
    const addRow = (a = {}) => {
      const label = el('input', { class: 'input', type: 'text', value: a.label || '', placeholder: 'Add-on name', maxlength: 60 });
      const url = el('input', { class: 'input', type: 'text', value: a.url || '', placeholder: 'https://… or a page of this site', maxlength: 1000 });
      const row = el('div', { class: 'link-row addon-row' }, dragHandle(() => row), label, url,
        el('div', { class: 'row-btns' }, iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      markDead(row, url);
      wrap.append(row);
      return row;
    };
    (items || []).forEach(addRow);
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { addRow().querySelector('input').focus(); form.dispatchEvent(new Event('input')); } }, '+ Add Required Add-On');
    return {
      node: el('div', { class: 'field' }, lbl('Add-Ons Required'), wrap, add),
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

  // Access: who can download the patron links. On top of a paid tier (never instead of it): a minimum tier, months as a patron,
  // total paid; and whether it counts in the unlock limits. Empty or 0 = none.
  const accessSection = () => {
    const cur = data.currency;
    // minimum tier = its price a month; the tiers seen at patron logins (data.tiers) are one click away
    fields.requireTier = text(`Minimum Tier (${cur} a Month)`, m.requireTierCents ? String(m.requireTierCents / 100) : '', { placeholder: '0 = any paid tier', cls: 'tier-amount' });
    // amounts are text fields: a number field drops a typed comma ("7,5" became 75 or nothing); the save checks the value
    fields.requireTier.input.inputMode = 'decimal';
    const tierIn = fields.requireTier.input;
    const tiers = [[0, 'Any Paid Tier'], ...(data.tiers || [])];
    const chips = tiers.map(([c, label]) => el('button', { type: 'button', class: 'chip', 'data-cents': c, onclick: () => {
      tierIn.value = c ? String(c / 100) : ''; tierIn.dispatchEvent(new Event('input', { bubbles: true })); mark();
    } }, label));
    const mark = () => { const v = Math.round(parseFloat(tierIn.value.replace(',', '.')) * 100) || 0; chips.forEach((b) => b.setAttribute('aria-pressed', String(+b.dataset.cents === v))); };
    tierIn.addEventListener('input', mark);
    mark();

    fields.requireMonths = text('Months as a Patron (At Least)', m.requireMonths || '', { type: 'number', placeholder: '0 = no requirement', hint: 'From the start of their current membership.' });
    fields.requireTotal = text(`Total Support (At Least, ${cur})`, m.requireTotalCents ? String(m.requireTotalCents / 100) : '', { placeholder: '0 = no requirement' });
    fields.requireMonths.input.min = 0; fields.requireMonths.input.step = 1;
    // with both set: both needed (and) or either one enough (or)
    fields.requireMode = select('', m.requireMode === 'or' ? 'or' : 'and', { and: 'and', or: 'or' }, { cls: 'req-mode' });
    fields.requireMode.node.querySelector('select').setAttribute('aria-label', 'Both requirements, or either one');
    fields.requireTotal.input.inputMode = 'decimal';
    fields.noLimits = toggle('Exclude from Unlock Limits', m.noLimits, 'Downloads of this item never use an unlock and are never refused by the limits.');

    // release dates (Berlin time): patrons from, everyone from; before the patrons date the page is hidden or "Coming soon"
    const rel = data.release || {};
    fields.releasePatrons = text('Patrons From (Optional)', rel.patrons || '', { type: 'datetime-local' });
    fields.releasePublic = text('Everyone From (Optional)', rel.public || '', { type: 'datetime-local' });
    const relName = 'rel-' + Math.random().toString(36).slice(2, 8);
    const relSeg = el('div', { class: 'vis-seg rel-seg', role: 'radiogroup', 'aria-label': 'Before the patrons date' },
      Object.entries({ hidden: 'Hidden', soon: 'Shown as “Coming Soon”' }).map(([k, label]) => el('label', { class: 'vis-opt' },
        el('input', { type: 'radio', name: relName, value: k, checked: k === (rel.before || 'hidden') }), el('span', {}, label))));
    fields.releaseBefore = { node: el('div', { class: 'field rel-before' }, el('span', { class: 'lbl' }, 'Before the patrons date, the page is'), relSeg), get: () => relSeg.querySelector('input:checked')?.value || 'hidden' };
    const strip = el('div', { class: 'rel-line' });
    const relSync = () => {
      const p = fields.releasePatrons.get(), e = fields.releasePublic.get();
      fields.releaseBefore.node.hidden = !p;
      const day = (v, time = true) => new Date(v).toLocaleString('en-GB', time ? { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short' });
      const step = (cls, title, sub) => el('div', { class: 'rel-step ' + cls }, el('b', {}, title), sub);
      const steps = [];
      if (p) steps.push(step('', fields.releaseBefore.get() === 'soon' ? 'Coming Soon' : 'Hidden', 'until ' + day(p)));
      if (e) {
        const days = p ? Math.round((new Date(e) - new Date(p)) / 864e5) : 0;
        steps.push(step('p', 'Patrons Only', p ? `${day(p, false)} → ${day(e, false)}${days > 0 ? ` (${days} day${days === 1 ? '' : 's'})` : ''}` : 'until ' + day(e)));
        steps.push(step('e', 'Free for Everyone', 'from ' + day(e)));
      } else if (p) steps.push(step('p', 'Patrons', 'from ' + day(p)));
      strip.replaceChildren(...steps);
      strip.hidden = !steps.length;
      const bad = p && e && e <= p;
      fields.releasePublic.input.setCustomValidity(bad ? '“Everyone From” must come after “Patrons From”.' : '');
    };
    [fields.releasePatrons.input, fields.releasePublic.input].forEach((i) => i.addEventListener('input', relSync));
    relSeg.addEventListener('change', relSync);
    relSync();

    const part = (title, hint, ...kids) => el('div', { class: 'access-part' }, el('div', { class: 'access-part-h' }, title, hint && tip(hint)), ...kids);
    // folded to one summary line ("Any paid tier · No release dates") while nothing is set; open when something is
    const num = (f) => parseFloat(f.get().replace(',', '.')) || 0;
    const sumText = () => {
      const p = fields.releasePatrons.get(), e = fields.releasePublic.get();
      const day = (v) => new Date(v).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
      const cents = Math.round(num(fields.requireTier) * 100);
      const named = (data.tiers || []).find(([c]) => c === cents);
      const money = (v) => { try { return new Intl.NumberFormat('en', { style: 'currency', currency: cur, minimumFractionDigits: 0 }).format(v); } catch { return cur + ' ' + v; } };
      const out = [cents > 0 ? `${named ? named[1] : money(cents / 100)} tier or higher` : 'Any paid tier'];
      const loyal = [num(fields.requireMonths) > 0 && `${num(fields.requireMonths)} months as a patron`, num(fields.requireTotal) > 0 && `${money(num(fields.requireTotal))} in total`].filter(Boolean);
      if (loyal.length) out.push(loyal.join(fields.requireMode.get() === 'or' ? ' or ' : ' and '));
      out.push(p || e ? [p && 'Patrons ' + day(p), e && 'Everyone ' + day(e)].filter(Boolean).join(', ') : 'No release dates');
      if (fields.noLimits.get()) out.push('Not counted in unlock limits');
      return out.join(' · ');
    };
    const sum = el('span', { class: 'access-sum' });
    const body = el('div', { class: 'access-body' });
    const fold = el('details', { class: 'access-fold' }, el('summary', {}, sum), body);
    const sec = section('Access', `Who can download the patron links, and from when. Everyone needs a paid Patreon tier; these only add conditions. Empty or 0 = none.`, fold);
    const sync = () => (sum.textContent = sumText());
    sec.addEventListener('input', sync);
    sec.addEventListener('change', sync);
    body.append(part('Release Dates', 'Times are Berlin time. Before “Patrons From” nobody can download it (admins can). From “Everyone From” every patron link works for anyone, with no login. Discord gets a post on each date.',
        el('div', { class: 'grid2' }, fields.releasePatrons.node, fields.releasePublic.node), fields.releaseBefore.node, strip),
      part('Tier', `Patrons below it see “You need the … tier or higher” with an Upgrade link. The tier buttons are the tiers seen when patrons log in.`,
        el('div', { class: 'tier-pick' }, el('div', { class: 'chips' }, chips), fields.requireTier.node)),
      part('Loyalty', '',
        el('div', { class: 'req-grid' }, fields.requireMonths.node, fields.requireMode.node, fields.requireTotal.node)),
      part('Unlock Limits', 'Admin → Access → Unlock Limits. When on, downloading this never uses one of the patron’s unlocks and is never refused by the limits.', fields.noLimits.node));
    sync();
    fold.open = !!(num(fields.requireTier) || num(fields.requireMonths) || num(fields.requireTotal) || fields.releasePatrons.get() || fields.releasePublic.get() || fields.noLimits.get());
    return sec;
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
  const VIS_SHORT = { public: 'Public', blurred: 'Teaser', 'blurred-patrons': 'Clear for Patrons', password: 'Password', draft: 'Draft' };
  const visBar = (value, options) => {
    const name = 'vis-' + Math.random().toString(36).slice(2, 8);
    const bar = el('div', { class: 'vis-seg', role: 'radiogroup', 'aria-label': 'Visibility' });
    for (const [k, label] of Object.entries(options)) {
      const r = el('input', { type: 'radio', name, value: k, checked: k === value });
      const ic = el('span', { class: 'vis-ic' });
      ic.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${VIS_ICONS[k] || ''}</svg>`;
      bar.append(el('label', { class: 'vis-opt', title: label }, r, ic, el('span', {}, VIS_SHORT[k] || label)));
    }
    return { node: el('div', { class: 'field' }, bar), get: () => bar.querySelector('input:checked')?.value || 'public' };
  };
  // models add their status row (status + featured): the section is then "Visibility & Status"
  const visibilitySection = (statusRow) => {
    fields.visibility = visBar(m.visibility || 'public', data.visibility);
    fields.password = text('Password', '', { max: 200, placeholder: m.hasPassword ? 'A password is set: leave empty to keep it' : 'At least 4 characters', hint: 'A new password locks out everyone who used the old one.' });
    fields.password.input.autocomplete = 'off';
    fields.unlisted = toggle('Hide from Catalog', m.unlisted, 'Reachable only by its address.');
    const hints = {
      public: 'Everyone can see it.',
      blurred: 'Every picture is blurred (a teaser). Downloads work as usual.',
      'blurred-patrons': 'Blurred, except for logged-in patrons on a paid tier.',
      password: 'The page and its downloads need the password.',
      draft: 'Only admins can see it.',
    };
    const hint = el('p', { class: 'hint vis-hint' });
    const pwBox = el('div', { class: 'grid2 pw-box' }, fields.password.node, fields.unlisted.node);
    const sync = () => { const v = fields.visibility.get(); hint.textContent = hints[v] || ''; pwBox.hidden = v !== 'password'; };
    fields.visibility.node.addEventListener('change', sync);
    sync();
    form.append(section(statusRow ? 'Visibility & Status' : 'Visibility', null, fields.visibility.node, hint, pwBox, statusRow));
  };

  const changelogFields = (hintWhat) => {
    fields.changelogNote = text('Text Above the Changelog (Optional)', m.changelogNote, { max: 1000, placeholder: 'If not specified near the version, you do not need to redownload textures.' });
    fields.changelog = repeat({
      items: m.changelog || [],
      addLabel: 'Add New Version (Goes on Top)',
      addTop: true,
      openFirst: 0,   // all versions start collapsed; a newly added one opens
      confirmRemove: 'Remove this version from the changelog?',
      blank: () => {   // the next version of the top one (3 → 4, 3.1 → 3.2, same stage), dated today
        const top = (fields.changelog?.get() || [])[0];
        const ver = top ? String(top.version || '').replace(/\d+(?!.*\d)/, (n) => String(Number(n) + 1)) : '';
        return { version: ver !== String(top?.version || '') ? ver : '', stage: top?.stage, date: new Date().toLocaleDateString('en-GB'), changes: [] };
      },
      build: (en) => {
        const v = text('Version', en.version, { max: 60, placeholder: 'e.g. 4 or 3.1' });
        const d = text('Date', en.date, { max: 40, placeholder: 'dd/mm/yyyy' });
        const h = text('Label (Optional)', en.highlight, { max: 120, list: 'highlights', hint: 'Mentions of textures, a first release or the last version for a Blender release become a badge.' });
        const notes = list('Notes (Optional)', en.notes, { max: 5000, addLabel: 'Add Paragraph' });
        const ch = list('Changes', en.changes, { max: 3000, addLabel: 'Add Change', placeholder: 'Fix: …   /   New: …   /   Enhancement: …', hint: 'New:, Fix:, Bug:, Enhancement:, Improvement:, Optimization:, Removal: or UI: at the start get a coloured tag.' });
        const img = image('Image (Optional)', en.image);
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
        // "Beta 2" typed with Beta (or Standard) picked: keep "2" and pick Beta, so the site never says "Beta Beta 2"
        const stageWord = () => {
          const mt = /^\s*(alpha|beta|α|β)[\s.\-:]*(?=\S)/i.exec(v.get());
          if (!mt) return;
          const k = /^(alpha|α)$/i.test(mt[1]) ? 'alpha' : 'beta';
          v.input.value = v.get().slice(mt[0].length);
          stageBtns[Object.keys(STAGE).indexOf(k)].click();
        };
        v.input.addEventListener('change', stageWord);
        return {
          body: el('div', { class: 'rep-body' }, el('div', { class: 'grid-ver' }, v.node, d.node, h.node, stageNode), ch.node, notes.node, img.node),
          get: () => { stageWord(); return { version: v.get(), date: d.get(), highlight: h.get(), stage, notes: notes.get(), changes: ch.get(), image: img.get() }; },   // Ctrl+S while typing "Beta 2": no change event yet
          title: () => [(stage !== 'standard' && !v.get().toLowerCase().startsWith(stage) ? STAGE[stage][0] + ' ' : '') + (v.get() || 'New Version'), d.get()].filter(Boolean).join(' · '),   // "Beta 2 · 16/08/2026"
        };
      },
    });
    form.append(section('Changelog', 'The top entry is the latest version.', fields.changelogNote.node, fields.changelog.node));
  };

  // ── Build the form ──
  form.append(el('datalist', { id: 'franchises' }, data.franchises.map((f) => el('option', { value: f }))));
  if (isOutfit) form.append(el('datalist', { id: 'acc-types' }, (data.types || []).map((t) => el('option', { value: t }))));
  form.append(el('datalist', { id: 'highlights' }, ['New Textures!', 'First release for patrons.', 'Last Blender 4.5 compatible version'].map((f) => el('option', { value: f }))));

  const base = isOutfit ? '/accessories/' : '/models/';
  fields.name = text('Name', m.name, { placeholder: isOutfit ? 'e.g. Summer dress' : 'e.g. Tifa Lockhart', max: 120 });
  if (data.isNew) {
    fields.slug = text('Page Address', '', { max: 64, placeholder: isOutfit ? 'e.g. summer-dress' : 'e.g. lara-croft-v2' });
    let touched = false;
    fields.slug.input.addEventListener('input', () => (touched = true));
    fields.name.input.addEventListener('input', () => {
      if (!touched) fields.slug.input.value = fields.name.get().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+/, '').slice(0, 64).replace(/-+$/, '');   // the address fits its 64 characters: what is shown is what is saved
    });
  }
  const slugNode = fields.slug?.node ?? el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Page Address'), el('code', { class: 'slug' }, `${base}${m.slug}/`));

  if (isOutfit) {
    // type: free text with suggestions (like a model's franchise); empty = Outfit. Catalog filter and the line above the name
    fields.type = text('Type', m.type || 'Outfit', { list: 'acc-types', placeholder: 'e.g. Hair', max: 40, hint: 'Outfit, Hair, Necklace… Pick one in the list or type a new one: the Accessories page gets a filter per type.' });
    form.append(section('Basics', null, el('div', { class: 'grid-acc' }, fields.name.node, fields.type.node, slugNode)));
    visibilitySection();
    // the rest follows the accessory page: pictures, "Optimized for", description, download box, changelog
    fields.images = gallery(m.images, 'The first picture is the preview.', 'Pictures');
    fields.model3d = model3d(m.model3d, data.model3d, m.model3dLight);
    fields.model3dLight = { get: fields.model3d.light };
    form.append(section('Images', null, fields.images.node, fields.model3d.node));
    fields.models = modelPicker(m.models);
    form.append(section('Optimized For', null, fields.models.node));
    fields.description = area('Description (Optional)', m.description, { rows: 2, max: 5000 });
    form.append(section('Description', null, fields.description.node));
    fields.addons = addonList(m.addons);
    form.append(section('Requirements', null, fields.addons.node));
    fields.links = links(m.links);
    form.append(section('Downloads', null, fields.links.node), accessSection());
    changelogFields();
  } else {
    fields.franchise = text('Franchise', m.franchise, { max: 80, list: 'franchises', placeholder: 'e.g. Final Fantasy' });
    fields.game = text('Game (Optional)', m.game, { max: 120, placeholder: 'e.g. Rise of the Tomb Raider' });
    fields.generation = text('Generation', m.generation ?? '', { max: 10, placeholder: 'e.g. 4', cls: 'small-field' });
    fields.majorVersion = text('Major Version', m.majorVersion ?? '', { type: 'number', placeholder: 'e.g. 2', cls: 'small-field', hint: 'Models with the same name and a major version are shown as versions of each other (the older ones say a newer version exists). Empty = not part of a series.' });
    form.append(section('Basics', null,
      grid2(fields.name.node, slugNode),
      el('div', { class: 'grid-gen' }, fields.franchise.node, fields.game.node, fields.generation.node, fields.majorVersion.node)));

    fields.status = select('Status', m.status, data.statuses);
    fields.featured = toggle('Featured', m.featured, 'Shown in the homepage slideshow.');
    visibilitySection(el('div', { class: 'grid-status' }, fields.status.node, fields.featured.node));

    // catalog cards are 4:5 (.cover-card), the model page picture 9:16 (.media .cover-hero): the cropper starts with that shape
    fields.thumb = image('Catalog Image', m.thumb, '', { crop: true, aspect: 4 / 5, note: 'Best: portrait 4:5, at least 800 × 1000 px.' });
    fields.cover = image('Main Image', m.cover, 'Top of the model page and the homepage slideshow. Empty = the catalog image.', { crop: true, aspect: 9 / 16, note: 'Best: portrait 9:16, e.g. 2160 × 3840 px.' });
    fields.gallery = gallery(m.gallery, 'Any shape: the row under the main image shows every picture at the same height, at its own width.');
    fields.model3d = model3d(m.model3d, data.model3d, m.model3dLight);
    fields.model3dLight = { get: fields.model3d.light };
    form.append(section('Images', null, grid2(fields.thumb.node, fields.cover.node), fields.gallery.node, fields.model3d.node));

    // the rest follows the model page from top to bottom: facts and description, download box, features, notes, changelog, sidebar
    fields.description = area('Short Description (Optional)', m.description, { rows: 2, max: 5000 });
    fields.note = text('Rig Note (Optional)', m.note, { max: 120, placeholder: 'e.g. ARP based model' });
    form.append(section('Description', null, fields.description.node, grid2(fields.note.node)));

    // what it needs to work: the Blender version (a fact on the page) and the add-ons (in the download box)
    fields.blender = text('Blender Supported', m.blender, { max: 60, placeholder: 'e.g. 4.5 or above' });
    fields.addons = addonList(m.addons);
    form.append(section('Requirements', null, grid2(fields.blender.node), fields.addons.node));
    fields.links = links(m.links);
    form.append(section('Downloads', null, fields.links.node), accessSection());

    fields.features = list('Features', m.features, { addLabel: 'Add Feature', hint: 'Pasting several lines makes one item per line.' });
    form.append(section('Features', null, fields.features.node));

    fields.notes = repeat({
      items: (m.notes || []).map((n) => (typeof n === 'string' ? { text: n } : n)),
      addLabel: 'Add Note',
      openFirst: 0,   // folded to their first line; a newly added one opens
      build: (n) => {
        const t = area('Text', n.text, { rows: 2, max: 5000 });
        const img = image('Image (Optional)', n.image);
        return { body: el('div', { class: 'rep-body' }, t.node, img.node), get: () => ({ text: t.get(), image: img.get() }), title: () => (t.get().split('\n')[0] || 'New Note').slice(0, 80) };
      },
    });
    form.append(section('Notes', null, fields.notes.node));

    changelogFields();

    fields.futureIdeas = list('Future Ideas', m.futureIdeas, { addLabel: 'Add Idea' });
    fields.knownIssues = list('Known Issues', m.knownIssues, { addLabel: 'Add Issue' });
    fields.credits = list('Credits', m.credits, { addLabel: 'Add Credit' });
    fields.extra = repeat({
      items: m.extra || [],
      label: 'Other Sections',
      addLabel: 'Add Section',
      openFirst: 0,
      build: (sec) => {
        const t = text('Title', sec.title, { max: 120, placeholder: 'e.g. Statistic and Legacy Models' });
        const groups = repeat({
          items: sec.groups || [],
          addLabel: 'Add Paragraph + List',
          build: (g) => {
            const intro = area('Paragraph', g.intro, { rows: 2, max: 2000 });
            const items = list('List', g.items, { addLabel: 'Add Item' });
            return { body: el('div', { class: 'rep-body' }, intro.node, items.node), get: () => ({ intro: intro.get(), items: items.get() }), title: () => (intro.get() || 'Group').slice(0, 60) };
          },
        });
        return { body: el('div', { class: 'rep-body' }, t.node, groups.node), get: () => ({ title: t.get(), groups: groups.get() }), title: () => t.get() || 'New Section' };
      },
    });
    form.append(section('Sidebar', 'Shown next to the changelog on the model page.', fields.futureIdeas.node, fields.knownIssues.node, fields.credits.node, fields.extra.node,
      optionalBlocks([[fields.futureIdeas, 'Future Ideas'], [fields.knownIssues, 'Known Issues'], [fields.credits, 'Credits'], [fields.extra, 'Other Section']])));
  }

  // ── Menu above the form: a button per section; it scrolls there (below the header that stays on top) and the
  // section in view is marked as you scroll ──
  const menu = document.querySelector('[data-ed-menu]');
  if (menu) {
    const head = document.querySelector('.sticky-head');
    const offset = () => (head && getComputedStyle(head).position === 'sticky' ? head.getBoundingClientRect().bottom : 0) + 12;
    const secs = [...form.querySelectorAll(':scope > fieldset.sec')];
    const links = secs.map((sec) => {
      const name = sec.querySelector('legend').firstChild.textContent;
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
  let edits = 0;   // counts changes: a save clears "unsaved" only when nothing changed while it ran
  form.addEventListener('input', () => { dirty = true; edits++; });
  form.addEventListener('change', () => { dirty = true; edits++; });
  form.addEventListener('submit', (e) => e.preventDefault());
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const listUrl = isOutfit ? '/admin/outfits.php' : '/admin/models.php';
  const saveBtn = document.querySelector('[data-save]');
  const save = async () => {
    if (saveBtn.disabled) return;   // already saving (Ctrl+S pressed twice)
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    try {
      const at = edits;
      const r = await post('/admin/save.php', { kind: data.kind, action: 'save', originalSlug: data.isNew ? '' : m.slug, rev: data.isNew ? undefined : data.rev, item: collect() });
      data.rev = r.rev;   // the next save checks against what this one stored
      if (edits === at) dirty = false;
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

// ── Title that stays on top (editors): its line shows only once the page scrolls under it ──
(() => {
  const head = document.querySelector('.sticky-head');
  if (!head) return;
  const check = () => {
    const cs = getComputedStyle(head);
    head.classList.toggle('is-stuck', cs.position === 'sticky' && head.getBoundingClientRect().top <= parseFloat(cs.top) + 1 && window.scrollY > 0);
  };
  addEventListener('scroll', check, { passive: true });
  addEventListener('resize', check);
  check();
})();

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
      (rows.lastElementChild.querySelector('input.input') || rows.lastElementChild.querySelector('input')).focus();
      dirty = true;
    }
  });
  formEl.addEventListener('input', () => (dirty = true));
  formEl.addEventListener('submit', () => (dirty = false));
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
});

// ── Settings → Bug reports: the webhook field is only used while "Notify on Discord" is on (read-only otherwise; its value is kept) ──
document.querySelectorAll('[data-bugs-form]').forEach((form) => {
  const sw = form.elements.bugs_notify;
  const hook = form.elements.bugs_webhook;
  if (!sw || !hook) return;
  const sync = () => { hook.readOnly = !sw.checked; hook.tabIndex = sw.checked ? 0 : -1; };
  sw.addEventListener('change', sync);
  sync();
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
    go.textContent = n ? `Import Selected (${n})` : 'Import Selected';
  };
  const editLink = (m) => el('a', { href: `/admin/edit.php?slug=${encodeURIComponent(m.slug)}`, target: '_blank' }, m.name);
  const status = (p) => p.imported
    ? p.imported.broken
      ? el('span', {}, el('span', { class: 'badge tone-red', title: 'Made from SmutBase’s bot check instead of the project page. Importing again replaces it.' }, 'Broken Draft'), ' ', editLink(p.imported))
      : p.imported.draft
        ? el('span', {}, el('span', { class: 'badge tone-green', title: 'Still a draft: tick it to import it again, which replaces this draft' }, 'Imported · Draft'), ' ', editLink(p.imported))
        : el('span', {}, el('span', { class: 'badge tone-green' }, 'Imported'), ' ', editLink(p.imported))
    : p.similar
      ? el('span', {}, el('span', { class: 'badge tone-mustard', title: 'A model with the same name is already in the catalog: probably this one, added by hand' }, 'Same Name'), ' ', editLink(p.similar))
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
      cell.replaceChildren(el('span', { class: 'badge tone-green' }, r.replaced ? 'Draft Replaced' : 'Draft Created'), ' ', editLink(r),
        el('span', { class: 'muted small import-info' }, ' · ' + bits.join(' · ') + (r.videos ? ` · ${r.videos} video${r.videos === 1 ? '' : 's'} not imported` : '')),
        ...(r.warnings || []).map((w) => el('div', { class: 'muted small' }, '⚠ ' + w)));
      cb.checked = false; cb.disabled = true; p.imported = { slug: r.slug, name: r.name }; delete p.html;
      return true;
    } catch (e) {
      delete p.html;
      if (!e.data?.needPage) { cell.replaceChildren(el('span', { class: 'badge tone-red' }, 'Not Imported'), el('div', { class: 'muted small' }, e.message)); return false; }
      // SmutBase didn't give the server this page: paste it here and the import runs again
      const box = el('textarea', { class: 'input import-paste-row', rows: 2, placeholder: 'Paste this project’s page here', 'aria-label': 'Paste the page of ' + p.title });
      box.addEventListener('paste', async (ev) => {
        const t = pasted(ev);
        if (!t) return;
        p.html = t; box.disabled = true;
        if (!(await importOne(tr)) && !p.imported) { cb.checked = true; }
        summary(); update();
      });
      cell.replaceChildren(el('span', { class: 'badge tone-red' }, 'Page Needed'),
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
      try { sessionStorage.setItem('m3d_batch_done', `${label}: ${name(r.changed)}.` + (r.skipped?.length ? ` Skipped (no password set yet): ${r.skipped.join(', ')}.` : '')); } catch { /* storage blocked: saved all the same */ }
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

// ── Settings tabs: an old address like settings.php#smutbase goes to the tab holding that section; a folded box named in the address opens ──
(() => {
  const nav = document.querySelector('[data-set-tabs]');
  if (!nav) return;
  const go = () => {
    const id = decodeURIComponent(location.hash.slice(1));
    if (!id) return;
    const box = document.getElementById(id);
    if (box) { if (box.matches('details')) { box.open = true; box.scrollIntoView(); } return; }
    const tab = JSON.parse(nav.dataset.setTabs)[id];
    const here = new URLSearchParams(location.search).get('tab') || 'site';
    if (tab && tab !== here && location.pathname.endsWith('/settings.php')) location.replace(`${location.pathname}${tab === 'site' ? '' : '?tab=' + tab}#${id}`);
  };
  go();
  window.addEventListener('hashchange', go);
})();

// ── Settings → Tools / System: the counts (media, sessions, log…) take a while, so they arrive after the page,
//    in two parts side by side (?tools=quick, ?tools=media; not ?stats=, which ad blockers block); a part that fails says why where its numbers go ──
(() => {
  if (!document.querySelector('[data-stat]')) return;
  const parts = { quick: ['sessions', 'log', 'backups', 'disk'], media: ['unused', 'temp', 'media'] };
  Object.entries(parts).forEach(([part, keys]) => {
    const spots = (k) => document.querySelectorAll(`[data-stat="${k}"]`);
    const btns = (k) => document.querySelectorAll(`[data-stat-btn="${k}"]`);
    fetch(`${location.pathname}?tools=${part}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(async (r) => {
        const txt = await r.text();
        let d = null;
        try { d = JSON.parse(txt); } catch (_) { /* not JSON: an error page */ }
        if (!d) throw new Error(`server answered ${r.status}${r.ok ? ' with a page instead of the numbers' : ''}`);
        if (!d.ok) throw new Error(d.error || `server answered ${r.status}`);
        return d.stats;
      })
      .then((stats) => keys.forEach((k) => {
        const v = stats[k];
        spots(k).forEach((el) => { el.textContent = v ? v.text : ''; });
        btns(k).forEach((b) => { b.disabled = !!v && v.n === 0; });
      }))
      .catch((err) => keys.forEach((k) => {
        // the buttons still work, they just can't say how much there is
        const why = err instanceof TypeError ? 'the browser blocked the request: an ad blocker?' : (err.message || 'no answer');
        spots(k).forEach((el) => { el.textContent = `Couldn’t count (${why}).`; });
        btns(k).forEach((b) => { b.disabled = false; });
      }));
  });
})();

// ── Long actions (installing an update): one click only, and say it's working ──
document.querySelectorAll('form[data-busy]').forEach((f) => {
  f.addEventListener('submit', (e) => {
    if (f.dataset.sending) { e.preventDefault(); return; }   // a second click doesn't send it again
    f.dataset.sending = '1';
    f.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    const btn = e.submitter || f.querySelector('button');
    if (btn) { btn.dataset.label = btn.textContent; btn.textContent = f.dataset.busy || 'Working…'; }
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
}
document.querySelectorAll('input[type=number]').forEach(numStepper);
// the editors build their fields later
new MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach((n) => {
  if (n.nodeType !== 1) return;
  if (n.matches('input[type=number]')) numStepper(n);
  else n.querySelectorAll('input[type=number]').forEach(numStepper);
}))).observe(document.body, { childList: true, subtree: true });

// ── Tabs in rendered texts (format help, docs preview): as on the site (site.js), without remembering the choice ──
function mdTabs(g) {
  if (g.classList.contains('is-tabs')) return;
  const tabs = [...g.querySelectorAll(':scope > .md-tab-list > [data-tab]')];   // its own: a box in a tab can hold another group
  const show = (key) => {
    tabs.forEach((b) => b.setAttribute('aria-selected', b.dataset.tab === key ? 'true' : 'false'));
    g.querySelectorAll(':scope > [data-tab-panel]').forEach((p) => (p.hidden = p.dataset.tabPanel !== key));
  };
  tabs.forEach((b) => b.addEventListener('click', () => show(b.dataset.tab)));
  g.querySelector(':scope > .md-tab-list').hidden = false;
  g.classList.add('is-tabs');
  show(tabs[0]?.dataset.tab);
}
document.querySelectorAll('[data-md-tabs]').forEach(mdTabs);
new MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach((n) => {
  if (n.nodeType !== 1) return;
  if (n.matches('[data-md-tabs]')) mdTabs(n);
  else n.querySelectorAll('[data-md-tabs]').forEach(mdTabs);
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

// ── Settings → Discord announcements: the preview's message line follows the field; the webhook address is checked as it is typed ──
(() => {
  const form = document.querySelector('[data-discord-form]');
  if (!form) return;
  const esc = (t) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]);
  const discordMd = (t) => esc(t)   // same rules as discord_md() in _admin.php
    .replace(/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
    .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
    .replace(/(^|[^*\w])\*(?!\s)([^*\n]*[^*\s])\*(?![*\w])/g, '$1<i>$2</i>')   // no lookbehind: Safari before 16.4 can't read it, and the whole file would stop
    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
    .replace(/@(everyone|here)\b/g, '<span class="dc-ment">@$1</span>')
    .replace(/&lt;@&amp;\d+&gt;/g, '<span class="dc-ment">@role</span>');
  const msg = form.querySelector('[data-dc-message]'), out = form.querySelector('[data-dc-content]');
  msg.addEventListener('input', () => {
    out.innerHTML = discordMd(msg.value.trim());
    out.hidden = msg.value.trim() === '';
  });
  const hook = form.querySelector('[data-dc-hook]'), state = form.querySelector('[data-dc-hook-state]');
  const re = /^https:\/\/(?:(?:ptb|canary)\.)?discord(?:app)?\.com\/api\/webhooks\/\d{5,30}\/[A-Za-z0-9_-]{20,200}$/;   // DISCORD_WEBHOOK_RE in discord.php
  const check = () => {
    const v = hook.value.trim();
    state.className = 'dc-hook-state small ' + (v === '' ? '' : re.test(v) ? 'res-ok' : 'res-err');
    state.textContent = v === '' ? '' : re.test(v) ? '✓ A Discord webhook address' : 'This isn’t a Discord webhook address: use “Copy Webhook URL” in the channel’s settings.';
  };
  hook.addEventListener('input', check);
  check();
})();

// ── Downloads → All-time totals: search (accents ignored, every word must match), Models / Accessories, sortable columns.
// The four tiles above add up the rows shown; the choices stay in the address (tq, tkind, tsort) so a reload keeps them.
(() => {
  const table = document.querySelector('[data-tot-table]');
  if (!table) return;
  const tbody = table.tBodies[0];
  const rows = [...tbody.querySelectorAll('tr[data-kind]')];
  const empty = tbody.querySelector('[data-tot-empty]');
  const search = document.querySelector('[data-tot-search]');
  const count = document.querySelector('[data-tot-count]');
  const kinds = [...document.querySelectorAll('[data-tot-kind]')];
  const sums = [...document.querySelectorAll('[data-tot-sums] [data-sum]')];
  const fold = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  rows.forEach((r, i) => { r.hay = fold(r.dataset.search || r.textContent); r.pos = i; });
  const u0 = new URL(location.href).searchParams;
  let kind = u0.get('tkind') || '';
  let [key, dir] = (u0.get('tsort') || 'total-desc').split('-');
  if (!table.querySelector(`[data-tot-sort="${key}"]`)) [key, dir] = ['total', 'desc'];
  if (search && u0.get('tq')) search.value = u0.get('tq');

  const remember = () => {
    const u = new URL(location.href);
    const q = search ? search.value.trim() : '';
    const set = (k, v, def) => (v && v !== def ? u.searchParams.set(k, v) : u.searchParams.delete(k));
    set('tq', q); set('tkind', kind); set('tsort', `${key}-${dir}`, 'total-desc');
    history.replaceState(null, '', u);
  };
  const filter = () => {
    const q = search ? search.value.trim() : '';
    const words = fold(q).split(/\s+/).filter(Boolean);
    const tot = { total: 0, patrons: 0, public: 0, likes: 0 };
    let shown = 0;
    rows.forEach((r) => {
      r.hidden = (kind && r.dataset.kind !== kind) || !words.every((w) => r.hay.includes(w));
      if (r.hidden) return;
      shown++;
      for (const k in tot) tot[k] += +r.dataset[k] || 0;
    });
    sums.forEach((b) => { b.textContent = tot[b.dataset.sum].toLocaleString('en-US'); });
    kinds.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.totKind === kind)));
    if (count) { count.hidden = shown === rows.length; count.textContent = `${shown} of ${rows.length} shown`; }
    empty.hidden = shown > 0;
    empty.firstElementChild.textContent = q ? `Nothing matches “${q}”.` : 'Nothing to show.';
    remember();
  };
  const sort = () => {
    const m = dir === 'asc' ? 1 : -1;
    const byName = (a, b) => a.dataset.name.localeCompare(b.dataset.name);
    rows.sort((a, b) => (key === 'name' ? m * byName(a, b) : m * (a.dataset[key] - b.dataset[key]) || b.dataset.total - a.dataset.total || byName(a, b)));
    rows.forEach((r) => tbody.insertBefore(r, empty));
    table.querySelectorAll('[data-tot-sort]').forEach((b) => b.closest('th').setAttribute('aria-sort', b.dataset.totSort === key ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'));
    remember();
  };
  table.querySelectorAll('[data-tot-sort]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.totSort;
    if (k === key) dir = dir === 'asc' ? 'desc' : 'asc';
    else [key, dir] = [k, k === 'name' ? 'asc' : 'desc'];   // names A→Z first, numbers biggest first
    sort();
  }));
  kinds.forEach((c) => c.addEventListener('click', () => { kind = c.dataset.totKind; filter(); }));
  if (search) {
    search.addEventListener('input', filter);
    search.addEventListener('keydown', (e) => { if (e.key === 'Escape' && search.value) { search.value = ''; filter(); } });
  }
  sort();
  filter();
})();

// ── Assets → Link check (admin/links.php): nothing runs by itself. "Check all links" asks the server about one link at a time
// (a short pause between them, a longer one when MEGA asks to slow down; Stop ends it), "Check again" one row. Each answer
// replaces its row; the tiles, the Problems count, the chips (All · Problems · MEGA · transfer.it · Other) and the search follow.
// The "+ website" buttons of the Other Websites box add that website (its links are then listed and checked) and reload.
(() => {
  document.querySelectorAll('[data-lc-add]').forEach((b) => b.addEventListener('click', async () => {
    b.disabled = true;
    try { await post('/admin/links.php', { add: b.dataset.lcAdd }); location.reload(); } catch (err) { b.disabled = false; toast(err.message, 'err'); }
  }));
  const table = document.querySelector('[data-lc]');
  if (!table) return;
  const tbody = table.tBodies[0];
  const empty = tbody.querySelector('[data-lc-empty]');
  const all = document.querySelector('[data-lc-all]');
  const progress = document.querySelector('[data-lc-progress]');
  const search = document.querySelector('[data-lc-search]');
  const chips = [...document.querySelectorAll('[data-lc-chip]')];
  const tiles = [...document.querySelectorAll('[data-lc-tile]')];
  const fold = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const rows = () => [...tbody.querySelectorAll('tr[data-lc-row]')];
  let show = chips.find((c) => c.getAttribute('aria-pressed') === 'true')?.dataset.lcChip || '';
  let running = false;
  let stop = false;

  const matches = (r) => {
    if (show === 'problems') return r.dataset.st === 'dead' || r.dataset.st === 'unknown';
    if (show.startsWith('st:')) return r.dataset.st === show.slice(3);
    return !show || r.dataset.group === show;
  };
  const apply = () => {
    const words = fold(search ? search.value.trim() : '').split(/\s+/).filter(Boolean);
    let shown = 0;
    const n = { dead: 0, unknown: 0, ok: 0, new: 0 };
    rows().forEach((r) => {
      n[r.dataset.st] = (n[r.dataset.st] || 0) + 1;
      const hay = fold(r.dataset.search || r.textContent);
      r.hidden = !matches(r) || !words.every((w) => hay.includes(w));
      if (!r.hidden) shown++;
    });
    n.problems = n.dead + n.unknown;
    document.querySelectorAll('[data-lc-n]').forEach((b) => { b.textContent = n[b.dataset.lcN] || 0; });
    chips.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.lcChip === show)));
    tiles.forEach((t) => t.classList.toggle('on', show === `st:${t.dataset.lcTile}`));
    empty.hidden = shown > 0;
    const tab = document.querySelector('.lib-tabs a[href="/admin/links.php"]');   // "2 dead" next to the tab's name
    let dead = tab?.querySelector('.lc-tab-dead');
    if (tab && n.dead && !dead) tab.append((dead = el('span', { class: 'lc-tab-dead' })));
    if (dead) n.dead ? (dead.textContent = `${n.dead} dead`) : dead.remove();
  };

  const check = async (row) => {
    row.classList.add('is-checking');
    const btn = row.querySelector('[data-lc-one]');
    if (btn) { btn.disabled = true; btn.textContent = 'Checking…'; }
    try {
      const d = await post('/admin/links.php', { h: row.dataset.lcRow });
      const t = document.createElement('tbody');
      t.innerHTML = d.rows.join('');
      const fresh = [...t.children];
      // the same address on several items: every row of it gets the answer (same order as the page)
      tbody.querySelectorAll(`tr[data-lc-row="${CSS.escape(row.dataset.lcRow)}"]`).forEach((r, i) => (fresh[i] ? r.replaceWith(fresh[i]) : r.remove()));
      apply();
      return d;
    } catch (e) {
      row.classList.remove('is-checking');
      if (btn) { btn.disabled = false; btn.textContent = 'Check Again'; }
      throw e;
    }
  };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));

  // "Replace…" on a dead row: one new address for every item, add-on and Gallery picture using the old one (links.php {replace, to}),
  // then the page reloads and checks the new address
  const replace = (row) => {
    const h = row.dataset.lcRow;
    const same = tbody.querySelectorAll(`tr[data-lc-row="${CSS.escape(h)}"]`);
    const n = same.length;
    const input = el('input', { class: 'input', type: 'url', required: true, placeholder: 'https://mega.nz/…', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'New address' });
    const go = el('button', { type: 'submit', class: 'btn btn-primary' }, `Replace in ${n} ${n === 1 ? 'Place' : 'Places'}`);
    const close = () => { dlg.close(); dlg.remove(); };
    const used = el('ul', { class: 'lc-used' }, [...same].map((r) => {
      const c = r.cells;
      return el('li', null, el('b', null, c[0].querySelector('.m-name')?.textContent || ''), el('span', { class: 'muted' }, ` · ${c[1].firstChild?.textContent || ''} · ${c[1].querySelector('.muted')?.textContent || ''}`));
    }));
    const form = el('form', { method: 'dialog', class: 'lc-dlg-body' },
      el('h2', null, 'Replace Link Everywhere'),
      el('span', { class: 'lbl' }, 'Old Address'), el('code', { class: 'lc-old' }, row.dataset.url || ''),
      el('span', { class: 'lbl' }, 'Used By'), used,
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'New Address'), input),
      el('div', { class: 'form-actions' }, el('button', { type: 'button', class: 'btn btn-ghost', onclick: close }, 'Cancel'), go));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      go.disabled = true;
      try {
        const d = await post('/admin/links.php', { replace: h, to: input.value.trim() });
        const u = new URL(location.href);
        u.searchParams.set('replaced', d.n);
        u.searchParams.set('listed', d.listed ? '1' : '0');
        if (d.listed) u.searchParams.set('check', d.h); else u.searchParams.delete('check');
        location.replace(u);
      } catch (err) { go.disabled = false; toast(err.message, 'err'); }
    });
    const dlg = el('dialog', { class: 'lc-dlg', 'aria-label': 'Replace Link Everywhere' }, form);
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
    document.body.append(dlg);
    dlg.showModal();
    input.focus();
  };

  tbody.addEventListener('click', async (e) => {
    const rb = e.target.closest('[data-lc-replace]');
    if (rb) { replace(rb.closest('tr')); return; }
    const b = e.target.closest('[data-lc-one]');
    if (!b || b.disabled) return;
    try { await check(b.closest('tr')); } catch (err) { toast(err.message, 'err'); }
  });

  all?.addEventListener('click', async () => {
    if (running) { stop = true; all.disabled = true; all.textContent = 'Stopping…'; return; }
    running = true; stop = false;
    all.textContent = 'Stop';
    progress.hidden = false;
    const list = [...new Set(rows().map((r) => r.dataset.lcRow))];
    const got = { dead: 0, unknown: 0, ok: 0 };
    let done = 0;
    for (const h of list) {
      if (stop) break;
      const row = tbody.querySelector(`tr[data-lc-row="${CSS.escape(h)}"]`);
      if (!row) continue;
      progress.textContent = `Checking ${done + 1} of ${list.length}…`;
      try {
        const d = await check(row);
        got[d.st] = (got[d.st] || 0) + 1;
        await wait(d.slow ? 5000 : 400);   // be gentle with MEGA: one link at a time, longer when it asks to slow down
      } catch (err) {
        toast(err.message, 'err');
        if (/Session expired|Not logged in/.test(err.message)) break;
      }
      done++;
    }
    running = false;
    all.disabled = false;
    all.textContent = 'Check All Links';
    progress.textContent = `${stop ? 'Stopped after' : 'Checked'} ${done} of ${list.length}`;
    const bad = [got.dead && `${got.dead} dead`, got.unknown && `${got.unknown} couldn’t be checked`].filter(Boolean);
    toast(bad.length ? `Done: ${bad.join(', ')}.` : 'Done: every link checked works.', got.dead ? 'err' : 'ok');
  });
  window.addEventListener('beforeunload', (e) => { if (running) e.preventDefault(); });
  // back from "Replace…": check the new address once, then take ?check= out of the address (a reload doesn't check again)
  const fresh = new URLSearchParams(location.search).get('check');
  if (new URLSearchParams(location.search).has('replaced')) {
    const u = new URL(location.href);
    ['check', 'replaced', 'listed'].forEach((k) => u.searchParams.delete(k));
    history.replaceState(null, '', u);
  }
  if (fresh) {
    const row = tbody.querySelector(`tr[data-lc-row="${CSS.escape(fresh)}"]`);
    if (row) check(row).then((d) => toast(d.st === 'ok' ? 'The new link works.' : d.st === 'dead' ? 'The new link is dead too.' : 'The new link couldn’t be checked.', d.st === 'ok' ? 'ok' : 'err')).catch((err) => toast(err.message, 'err'));
  }

  chips.forEach((c) => c.addEventListener('click', () => { show = c.dataset.lcChip; apply(); }));
  tiles.forEach((t) => t.addEventListener('click', () => { show = show === `st:${t.dataset.lcTile}` ? '' : `st:${t.dataset.lcTile}`; apply(); }));
  if (search) {
    search.addEventListener('input', apply);
    search.addEventListener('keydown', (e) => { if (e.key === 'Escape' && search.value) { search.value = ''; apply(); } });
  }
  apply();
})();

// ── Backups: the calendar next to "All backups" (‹ › change month, a day shows only its backups, again or "Show all" = everything) ──
document.querySelectorAll('[data-bk-cal]').forEach((cal) => {
  const sec = cal.closest('section');
  const months = [...cal.querySelectorAll('.bk-month')];
  const filter = sec.querySelector('[data-bk-filter]');
  let picked = null;
  const show = (day) => {
    picked = day;
    cal.querySelectorAll('[data-bk-day]').forEach((b) => { b.classList.toggle('is-on', b.dataset.bkDay === day); b.setAttribute('aria-pressed', String(b.dataset.bkDay === day)); });
    sec.querySelectorAll('[data-bk-group]').forEach((g) => { g.hidden = !!day && g.dataset.bkGroup !== day; });
    filter.hidden = !day;
  };
  cal.addEventListener('click', (e) => {
    const nav = e.target.closest('[data-bk-month]');
    if (nav && !nav.disabled) {
      months.forEach((m, i) => { m.hidden = i !== +nav.dataset.bkMonth; });
      const same = months[+nav.dataset.bkMonth].querySelector(`[aria-label="${nav.getAttribute('aria-label')}"]`);
      (same.disabled ? months[+nav.dataset.bkMonth].querySelector('[data-bk-month]:not(:disabled)') : same)?.focus();
      return;
    }
    const d = e.target.closest('[data-bk-day]');
    if (d) show(picked === d.dataset.bkDay ? null : d.dataset.bkDay);
  });
  filter.querySelector('[data-bk-all]').addEventListener('click', () => show(null));
});

// ── Downloads → patron log: the period menu fills From / To ("Custom dates…" shows them instead), and ticked rows can be removed ──
document.querySelectorAll('[data-log-period]').forEach((sel) => {
  const form = sel.form;
  const dates = form.querySelector('[data-log-dates]');
  sel.addEventListener('change', (e) => {
    if (sel.value === 'custom') {   // wait for the dates (each one sends the form when picked)
      e.stopPropagation();
      dates.hidden = false;
      form.elements.from.focus();
      return;
    }
    form.elements.from.value = sel.selectedOptions[0].dataset.from || '';
    form.elements.to.value = '';
  });
});
document.querySelectorAll('[data-log-rows]').forEach((form) => {
  const bar = form.querySelector('[data-log-selbar]');
  const all = form.querySelector('[data-log-selall]');
  const boxes = [...form.querySelectorAll('input[name="rows[]"]')];
  const update = () => {
    const n = boxes.filter((b) => b.checked).length;
    boxes.forEach((b) => b.closest('tr').classList.toggle('is-sel', b.checked));
    bar.hidden = n === 0;
    form.querySelector('[data-log-selcount]').textContent = n;
    all.checked = n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
  };
  boxes.forEach((b) => b.addEventListener('change', update));
  all.addEventListener('change', () => { boxes.forEach((b) => (b.checked = all.checked)); update(); });
  form.querySelector('[data-log-selnone]').addEventListener('click', () => { boxes.forEach((b) => (b.checked = false)); update(); });
  form.addEventListener('submit', (e) => {
    const n = boxes.filter((b) => b.checked).length;
    const msg = `Remove ${n === 1 ? 'this download' : `these ${n} downloads`} from the log?\n\nWhen it was the patron's only download of that model or accessory, it no longer counts as unlocked, so the unlock is given back. The all-time download counts stay.`;
    if (!n || !confirm(msg)) e.preventDefault();
  });
  update();
});

// ── Admin menu: two rows whenever one row doesn't fit (the logo, the site name and the items change the width it needs, so a
// fixed breakpoint isn't enough): body.nav-2row repeats the narrow-screen layout of admin.css. Measured in one row each time. ──
(() => {
  const inner = document.querySelector('.admin .header-inner');
  const nav = inner?.querySelector('.admin-nav');
  if (!nav) return;
  const fits = () => {
    const links = nav.querySelectorAll('a');
    const last = links[links.length - 1];
    const next = nav.nextElementSibling;
    if (!last) return true;
    const right = last.getBoundingClientRect().right;
    const limit = next ? next.getBoundingClientRect().left - 12 : inner.getBoundingClientRect().right;
    return right <= limit && nav.scrollWidth <= nav.clientWidth + 1 && inner.scrollWidth <= inner.clientWidth + 1;
  };
  const check = () => {
    document.body.classList.remove('nav-2row');
    if (getComputedStyle(nav).order === '3') return;   // already two rows: the narrow-screen rules of admin.css
    if (!fits()) document.body.classList.add('nav-2row');
  };
  let t = 0;
  window.addEventListener('resize', () => { cancelAnimationFrame(t); t = requestAnimationFrame(check); });
  document.fonts?.ready.then(check);
  document.querySelectorAll('.brand img').forEach((i) => i.complete || i.addEventListener('load', check, { once: true }));
  check();
})();

// ── Admin → Gallery (admin/gallery.php): the pictures (tick to change several, click to edit one), "+ Add pictures"
// (from the Images library or uploaded). Everything saves through save.php kind "gallery",
// which answers with the whole gallery again (admin_gallery_payload()), so the page simply redraws. ──
(() => {
  const root = document.querySelector('[data-gadmin]');
  if (!root) return;
  let G = JSON.parse(document.getElementById('gallery-data').textContent);
  const fold = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const VIS_ICONS = {
    public: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    blurred: '<path d="m3 3 18 18M10.6 6.1A10 10 0 0 1 12 6c5 0 9 6 9 6a17 17 0 0 1-2.6 3.2M6.6 6.6C4.3 8.1 3 12 3 12s4 6 9 6c1.6 0 3-.4 4.3-1.1"/>',
    'blurred-patrons': '<circle cx="9" cy="8" r="4"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 11l2 2 4-4"/>',
    draft: '<path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/>',
  };
  const VIS_HINT = {
    public: 'Everyone can see it.',
    blurred: 'Blurred for everyone (a teaser): no viewer, no download.',
    'blurred-patrons': 'Blurred, except for logged-in patrons on a paid tier.',
    draft: 'Only admins can see it.',
  };
  const MODEL_VIS = { draft: 'a draft', soon: 'hidden until its release date', password: 'behind a password', blurred: 'blurred', 'blurred-patrons': 'blurred except for patrons' };
  const fmtDate = (d) => { const t = d ? new Date(d + 'T12:00:00') : null; return t && !isNaN(t) ? t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : ''; };
  const initials = (n) => n.split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
  const modelBy = (slug) => G.models.find((m) => m.slug === slug);
  const avatar = (who, cls = 'ga-av') => {
    const m = who.model ? modelBy(who.model) : null;
    return m?.thumb ? el('img', { class: cls, src: m.thumb, alt: '', loading: 'lazy', decoding: 'async' }) : el('span', { class: cls + ' ga-ini', 'aria-hidden': 'true' }, initials(who.name));
  };
  const charName = (m) => m.name + (m.gen ? ' · Gen ' + m.gen : '');
  let sel = G.still || null;   // the picture in the edit panel
  let dirty = false;
  const ticked = new Set();
  const filt = { q: '', who: '', only: '' };
  let linkBox = null;   // the batch "Set full size link…" box while it is open: its fields (kept between redraws)
  addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  const leaveOk = () => !dirty || confirm('You have unsaved changes. Leave them?');

  const setUrl = () => {
    const u = new URL(location.href);
    u.search = '';
    if (sel) u.searchParams.set('still', sel);
    history.replaceState(null, '', u);
  };
  // the picture in the edit panel changed on the server (saved, removed, a batch action on it): its unsaved edits go; anything else keeps them
  const apply = (g) => {
    const before = JSON.stringify(G.stills.find((s) => s.id === sel) || null);
    G = { ...G, ...g };
    if (JSON.stringify(G.stills.find((s) => s.id === sel) || null) !== before) dirty = false;
    render();
  };
  const save = async (body, msg) => {
    const r = await post('/admin/save.php', { kind: 'gallery', ...body });
    apply(r.gallery);
    if (msg) toast(typeof msg === 'function' ? msg(r) : msg);
    return r;
  };

  function render() {
    if (!G.stills.find((s) => s.id === sel)) sel = null;
    // pictures the search hides are unticked, like the Models list: a batch change only ever touches what is shown
    const shown = new Set(shownStills().map((s) => s.id));
    for (const id of [...ticked]) if (!shown.has(id)) ticked.delete(id);
    const body = stillsTab();
    const bar = G.stills.length ? toolsBar() : null;
    // the search bar stays in the page between redraws (typing keeps its focus, the character picker keeps its panel)
    if (bar && root.firstChild === bar) { while (bar.nextSibling) bar.nextSibling.remove(); root.append(body); }
    else root.replaceChildren(...[bar, body].filter(Boolean));
    setUrl();
  }

  // ── Search bar, like the Images page: search box, the character picker (folderPicker: faces, generations, its own search), Drafts / No character ──
  let bar = null, barSearch = null, barWho = null, barChips = null, barAll = null, barKey = '';
  function toolsBar() {
    if (!bar) {
      barSearch = el('input', { class: 'input', type: 'search', placeholder: 'Search a caption or character…', 'aria-label': 'Search the pictures', value: filt.q });
      barSearch.addEventListener('input', () => { filt.q = barSearch.value; render(); });
      barChips = el('div', { class: 'chips' });
      barAll = el('button', { type: 'button', class: 'btn btn-ghost btn-sm g-sel-all', onclick: () => { shownStills().forEach((s) => ticked.add(s.id)); render(); } });
      bar = el('div', { class: 'lib-filters g-adm-tools' }, barSearch, barChips, barAll);
    }
    // the characters change with the pictures: a new picker when they do
    const key = JSON.stringify(G.chars.map((c) => [c.key, c.name, c.gen, c.thumb]));
    if (key !== barKey) {
      barKey = key;
      if (barWho?.nextSibling && barWho.nextSibling !== barChips) barWho.nextSibling.remove();   // the old picker (none with 0–1 characters)
      barWho?.remove();
      if (filt.who && !G.chars.some((c) => c.key === filt.who)) filt.who = '';
      barWho = el('select', { class: 'input', 'aria-label': 'Character', 'data-search-ph': 'Search characters…' },
        el('option', { value: '', 'data-kind': 'all' }, 'All Characters'),
        G.chars.map((c) => el('option', { value: c.key, selected: c.key === filt.who, 'data-kind': c.model ? 'model' : 'other', 'data-name': c.name,
          'data-sub': c.franchise || '', 'data-gen': c.gen || '', 'data-thumb': c.thumb || '' }, c.name + (c.franchise ? ' · ' + c.franchise : '') + ' (' + c.count + (c.count === 1 ? ' picture)' : ' pictures)'))));
      barWho.addEventListener('change', () => { filt.who = barWho.value; render(); });
      barSearch.after(barWho);
      if (G.chars.length > 1) folderPicker(barWho); else barWho.hidden = true;
    }
    const counts = { draft: G.stills.filter((s) => s.visibility === 'draft').length, none: G.stills.filter((s) => !s.who.length).length };
    if (filt.only && !counts[filt.only]) filt.only = '';
    const chip = (k, label, n) => el('button', { type: 'button', class: 'chip', 'aria-pressed': String(filt.only === k), onclick: () => { filt.only = filt.only === k ? '' : k; render(); } }, label, n !== undefined ? el('span', {}, ' ' + n) : null);
    barChips.replaceChildren(...[counts.draft ? chip('draft', 'Drafts', counts.draft) : null, counts.none ? chip('none', 'No Character', counts.none) : null].filter(Boolean));
    barChips.hidden = !barChips.children.length;
    // tick every picture the search / character / chip shows (e.g. one character's pictures, to give them one link)
    const shown = shownStills();
    const filtered = filt.q || filt.who || filt.only;
    barAll.textContent = filtered ? `Select All Shown (${shown.length})` : 'Select All';
    barAll.hidden = !shown.length || shown.every((s) => ticked.has(s.id));
    return bar;
  }

  // ── The pictures ──
  const followNote = (s) => {   // the stricter models it follows
    const strict = s.who.filter((w) => w.model && MODEL_VIS[w.vis]);
    return strict.length ? strict.map((w) => `${w.name}’s model is ${MODEL_VIS[w.vis]}`).join('; ') + ', so this picture follows it.' : '';
  };
  function stillsTab() {
    if (!G.stills.length) {
      return el('div', { class: 'g-empty-adm' }, el('p', { class: 'lead' }, 'No pictures yet.'),
        el('p', { class: 'muted' }, 'Add pictures from the Images library or upload new ones. The gallery appears in the site menu once one picture is not a draft.'),
        el('button', { type: 'button', class: 'btn btn-primary', onclick: openAdd }, '+ Add Pictures'));
    }
    const shown = shownStills();   // the gallery's own order (the page shows them like this): drag the cards to change it
    const canDrag = !filt.q && !filt.who && !filt.only;   // a filtered list has gaps: no reordering then
    const grid = el('div', { class: 'g-adm-grid' + (canDrag ? '' : ' is-filtered') }, shown.map((s) => card(s, canDrag)));
    if (!shown.length) grid.append(el('p', { class: 'muted' }, 'No pictures match.'));
    return el('div', {}, batchBar(), el('div', { class: 'g-adm' }, grid, el('aside', { class: 'g-panel' }, sel ? panelFor(sel) : el('p', { class: 'muted g-panel-empty' }, 'Click a picture to edit its caption, characters, date, visibility and full size link.'))));
  }
  // the edit panel survives redraws (ticking a card, the search, filters) while it has unsaved changes
  let panel = null;
  function panelFor(id) {
    if (!(dirty && panel?.id === id)) panel = { id, node: stillPanel(G.stills.find((s) => s.id === id)) };
    return panel.node;
  }
  function shownStills() {
    const words = fold(filt.q).split(/\s+/).filter(Boolean);
    return G.stills.filter((s) => {
      if (filt.only === 'draft' && s.visibility !== 'draft') return false;
      if (filt.only === 'none' && s.who.length) return false;
      if (filt.who && !s.who.some((w) => w.key === filt.who)) return false;
      const text = fold([s.caption, ...s.who.map((w) => w.name)].join(' '));
      return words.every((w) => text.includes(w));
    });
  }
  // a card dropped somewhere new (or moved with the arrow keys): save the order at once; the edit panel keeps its unsaved changes
  // one save at a time (arrow keys pressed quickly): each reads the order when its turn comes, so an older order never lands last
  let orderQueue = Promise.resolve();
  function saveOrder(grid) {
    orderQueue = orderQueue.then(async () => {
      const ids = [...grid.querySelectorAll('.g-card[data-id]')].map((c) => c.dataset.id);
      if (ids.join() === G.stills.map((s) => s.id).join()) return;
      try {
        const r = await post('/admin/save.php', { kind: 'gallery', action: 'order', ids });
        G = { ...G, stills: r.gallery.stills };
        toast('Order saved ✓');
      } catch (e) { toast(e.message, 'err'); render(); }
    });
  }
  function card(s, canDrag = false) {
    const box = el('input', { type: 'checkbox', 'aria-label': 'Select', checked: ticked.has(s.id) });
    box.addEventListener('click', (e) => e.stopPropagation());
    box.addEventListener('change', () => { if (box.checked) ticked.add(s.id); else ticked.delete(s.id); render(); });
    const tags = [];
    if (s.visibility && s.visibility !== 'public') tags.push(el('span', { class: 'g-pill' }, G.visibility[s.visibility]));
    if (followNote(s)) tags.push(el('span', { class: 'g-pill g-pill-follow', title: followNote(s) }, 'Follows the Model'));
    if (s.link?.url) tags.push(el('span', { class: 'g-pill g-pill-dl', title: 'Full size download for ' + (s.link.access === 'public' ? 'everyone' : 'patrons') }, '↓ Full Size'));
    // a picture that isn't 9:16 leaves bands in the card: a blurred copy of it fills them (same file, nothing more to download)
    const bars = !(s.w && s.h) || Math.abs(s.w / s.h - 9 / 16) > 0.02;
    const c = el('div', { class: 'g-card' + (s.id === sel ? ' on' : '') + (ticked.has(s.id) ? ' ticked' : ''), 'data-id': s.id, tabindex: '0', role: 'button', 'aria-label': 'Edit ' + (s.caption || 'picture') },
      el('div', { class: 'g-card-pic', style: s.w && s.h ? `--r:${s.w / s.h}` : null }, bars ? el('img', { class: 'g-card-bg', src: s.small, alt: '', 'aria-hidden': 'true', loading: 'lazy', decoding: 'async' }) : null, el('img', { src: s.small, alt: '', loading: 'lazy', decoding: 'async' }), el('label', { class: 'g-tick' }, box), tags.length ? el('span', { class: 'g-pills' }, tags) : null),
      el('div', { class: 'g-card-body' },
        el('b', { class: s.caption ? '' : 'muted' }, s.caption || 'No Caption'),
        s.who.length ? el('span', { class: 'g-who' }, s.who.map((w) => el('span', { class: 'g-who-tag' }, avatar(w), w.name))) : el('span', { class: 'muted small' }, 'No Character'),
        el('span', { class: 'g-card-foot' }, el('span', { class: 'muted small' }, fmtDate(s.date)),
          canDrag ? dragHandle((h) => h.closest('.g-card'), (item) => saveOrder(item.parentNode)) : null)));
    const open = () => { if (s.id === sel || !leaveOk()) return; sel = s.id; dirty = false; render(); if (innerWidth < 900) root.querySelector('.g-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    c.addEventListener('click', open);
    c.addEventListener('keydown', (e) => { if (e.target === c && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(); } });
    return c;
  }
  function batchBar() {
    const ids = [...ticked].filter((id) => G.stills.some((s) => s.id === id));
    if (!ids.length) { linkBox = null; return el('div', { hidden: true }); }
    const vis = el('select', { class: 'input', 'aria-label': 'Set visibility' }, el('option', { value: '' }, 'Set Visibility…'), Object.entries(G.visibility).map(([k, v]) => el('option', { value: k }, v)));
    vis.addEventListener('change', async () => {
      if (!vis.value) return;
      try { await save({ action: 'visibility', ids, value: vis.value }, `${ids.length} ${ids.length === 1 ? 'picture' : 'pictures'}: ${G.visibility[vis.value]} ✓`); }
      catch (e) { toast(e.message, 'err'); vis.value = ''; }
    });
    const linkBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-expanded': String(!!linkBox), onclick: () => { linkBox = linkBox ? null : linkFields(ids); render(); } }, 'Set Full Size Link…');
    return el('div', { class: 'batch-bar g-batch' }, el('b', {}, `${ids.length} selected`), vis, linkBtn,
      el('button', { type: 'button', class: 'btn btn-danger btn-sm', onclick: async () => {
        if (!confirm(`Remove ${ids.length === 1 ? 'this picture' : `these ${ids.length} pictures`} from the gallery? The pictures stay in the Images library.`)) return;
        try { await save({ action: 'delete', ids }, 'Removed from the gallery ✓'); ids.forEach((id) => ticked.delete(id)); render(); }
        catch (e) { toast(e.message, 'err'); }
      } }, 'Remove from Gallery'),
      el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { ticked.clear(); linkBox = null; render(); } }, 'Clear'),
      linkBox ? linkPanel(ids) : null);
  }
  // one full size link for every ticked picture (often one file per character); starts with their link when they all share one
  function linkFields(ids) {
    const links = ids.map((id) => G.stills.find((s) => s.id === id)?.link || null);
    const same = links[0]?.url && links.every((l) => l?.url === links[0].url && l.access === links[0].access && (l.size || '') === (links[0].size || '')) ? links[0] : null;
    return {
      url: el('input', { class: 'input', type: 'url', value: same?.url || '', placeholder: 'https://… (Google Drive, Mega, your cloud…)', maxlength: 1000 }),
      access: el('select', { class: 'input', 'aria-label': 'Who can download it' }, el('option', { value: 'patrons' }, 'Patrons'), el('option', { value: 'public', selected: same?.access === 'public' }, 'Everyone')),
      size: el('input', { class: 'input', type: 'text', value: same?.size || '', maxlength: 40, placeholder: 'e.g. 4K · 3840×5760' }),
    };
  }
  function linkPanel(ids) {
    const { url, access, size } = linkBox;
    const n = ids.length, pics = n === 1 ? 'picture' : 'pictures', Pics = n === 1 ? 'Picture' : 'Pictures';
    const had = ids.filter((id) => G.stills.find((s) => s.id === id)?.link?.url);
    const send = async (link, msg, btn) => {
      btn.disabled = true;
      try { await save({ action: 'link', ids, link }, msg); linkBox = null; render(); }
      catch (e) { toast(e.message, 'err'); btn.disabled = false; }
    };
    const field = (label, hint, input) => el('label', { class: 'field' }, lbl(label, hint), input);
    const applyBtn = el('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, `Apply to ${n} ${Pics}`);
    const remove = had.length ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => {
      if (confirm(`Remove the full size link from ${had.length === 1 ? 'this picture' : `these ${had.length} pictures`}?`)) send({ url: '' }, 'Link removed ✓', remove);
    } }, 'Remove the Link') : null;
    const f = el('form', { class: 'g-batch-link' },
      el('b', {}, `Full Size Link for ${n} ${Pics}`),
      field('Link', '', url),
      el('div', { class: 'grid2' }, field('Who Can Download It', '', access), field('Size Label', '', size)),
      had.length && had.length < n ? el('p', { class: 'muted small' }, `${had.length} of them already ${had.length === 1 ? 'has' : 'have'} a link: it will be replaced.`)
        : had.length ? el('p', { class: 'muted small' }, `${n === 1 ? 'It already has' : 'They already have'} a link: it will be replaced.`) : null,
      el('div', { class: 'form-actions' }, remove, applyBtn));
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!url.value.trim()) { toast('Paste the link first.', 'err'); url.focus(); return; }
      send({ url: url.value.trim(), access: access.value, size: size.value }, `Link set on ${n} ${pics} ✓`, applyBtn);
    });
    return f;
  }

  // characters field: chips + a text box suggesting models (and names already used); Enter or a click adds, Backspace removes the last
  function charsField(list, onChange) {
    const chips = el('span', { class: 'g-chips' });
    const input = el('input', { class: 'g-chips-in', type: 'text', placeholder: 'Type a name…', 'aria-label': 'Add a character', autocomplete: 'off', maxlength: 80 });
    const sugg = el('div', { class: 'g-sugg', hidden: true });
    const box = el('div', { class: 'input g-chips-box', onclick: () => input.focus() }, chips, input);
    let act = 0;
    const names = () => [...new Set(G.stills.flatMap((s) => s.who.filter((w) => !w.model).map((w) => w.name)))];
    const label = (c) => modelBy(c) ? charName(modelBy(c)) : c;
    const draw = () => {
      chips.replaceChildren(...list.map((c, i) => el('span', { class: 'g-chip' }, avatar({ model: modelBy(c) ? c : null, name: modelBy(c)?.name || c }), label(c),
        el('button', { type: 'button', 'aria-label': 'Remove ' + label(c), onclick: (e) => { e.stopPropagation(); list.splice(i, 1); draw(); onChange(); } }, '×'))));
    };
    const add = (c) => {
      if (c && list.length >= 20) { toast('20 characters at most.', 'err'); return; }   // like clean_still()
      if (c && !list.some((x) => fold(x) === fold(c))) { list.push(c); onChange(); }
      input.value = ''; draw(); suggest();
    };
    const suggest = () => {
      const q = fold(input.value.trim());
      sugg.replaceChildren();
      if (!q) { sugg.hidden = true; return; }
      const words = q.split(/\s+/);
      const hits = G.models.filter((m) => !list.includes(m.slug) && words.every((w) => fold([m.name, m.franchise, m.gen ? 'gen ' + m.gen : ''].join(' ')).includes(w))).slice(0, 8)
        .map((m) => ({ v: m.slug, node: [avatar({ model: m.slug, name: m.name }), el('span', {}, charName(m)), el('small', { class: 'muted' }, (m.franchise ? m.franchise + ' · ' : '') + 'Model')] }));
      for (const n of names()) if (!list.some((x) => fold(x) === fold(n)) && words.every((w) => fold(n).includes(w))) hits.push({ v: n, node: [avatar({ name: n }), el('span', {}, n), el('small', { class: 'muted' }, 'Name')] });
      const typed = input.value.trim();
      if (!hits.some((h) => fold(h.v) === fold(typed)) && !G.models.some((m) => fold(m.name) === fold(typed))) hits.push({ v: typed, node: [el('span', { class: 'ga-av ga-ini' }, '+'), el('span', {}, `Add “${typed}” as a name`)] });
      hits.forEach((h, i) => sugg.append(el('button', { type: 'button', class: 'g-sugg-row' + (i === 0 ? ' act' : ''), onmousedown: (e) => e.preventDefault(), onclick: () => add(h.v) }, h.node)));
      act = 0;
      sugg.hidden = false;
    };
    input.addEventListener('input', suggest);
    input.addEventListener('keydown', (e) => {
      const rows = [...sugg.children];
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && rows.length) {
        e.preventDefault();
        act = (act + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
        rows.forEach((r, i) => r.classList.toggle('act', i === act));
      } else if (e.key === 'Enter') { e.preventDefault(); rows[act]?.click(); }
      else if (e.key === 'Backspace' && !input.value && list.length) { list.pop(); draw(); onChange(); }
      else if (e.key === 'Escape') { sugg.hidden = true; }
    });
    input.addEventListener('blur', () => setTimeout(() => { sugg.hidden = true; }, 150));
    draw();
    return el('div', { class: 'g-chars-field' }, box, sugg);
  }

  function stillPanel(s) {
    const st = { caption: s.caption || '', chars: [...(s.chars || [])], date: s.date || G.today, visibility: s.visibility || 'public', link: { url: s.link?.url || '', access: s.link?.access || 'patrons', size: s.link?.size || '' } };
    const mark = () => { dirty = true; };
    const f = el('form', { class: 'g-edit', novalidate: true });
    f.addEventListener('input', mark);
    const caption = el('input', { class: 'input', type: 'text', value: st.caption, maxlength: 200, placeholder: 'e.g. Studio portrait' });
    const date = el('input', { class: 'input', type: 'date', value: st.date });
    const vis = el('div', { class: 'vis-seg g-vis', role: 'radiogroup', 'aria-label': 'Visibility' });
    for (const [k, label] of Object.entries(G.visibility)) {
      const ic = el('span', { class: 'vis-ic' });
      ic.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${VIS_ICONS[k] || ''}</svg>`;
      vis.append(el('label', { class: 'vis-opt', title: label }, el('input', { type: 'radio', name: 'g-vis', value: k, checked: k === st.visibility }), ic, el('span', {}, label)));
    }
    const hint = el('p', { class: 'hint vis-hint' });
    const syncHint = () => { const v = vis.querySelector('input:checked').value; hint.textContent = VIS_HINT[v] + (followNote(s) ? ' ' + followNote(s) : ' If a character’s model is hidden, blurred or behind a password, the picture follows the model.'); };
    vis.addEventListener('change', syncHint);
    syncHint();
    const url = el('input', { class: 'input', type: 'url', value: st.link.url, placeholder: 'https://… (Google Drive, Mega, your cloud…)', maxlength: 1000 });
    const access = el('select', { class: 'input', 'aria-label': 'Who can download it' }, el('option', { value: 'patrons', selected: st.link.access !== 'public' }, 'Patrons'), el('option', { value: 'public', selected: st.link.access === 'public' }, 'Everyone'));
    const size = el('input', { class: 'input', type: 'text', value: st.link.size, maxlength: 40, placeholder: 'e.g. 4K · 3840×5760' });
    const field = (label, hint, ...kids) => el('label', { class: 'field' }, lbl(label, hint), ...kids);
    // crop: a new file in the same folder (the old one stays for whatever else uses it); Save puts it in the gallery
    let src = s.src;
    const picImg = el('img', { src: s.small, alt: '' });
    const picLink = el('a', { class: 'g-edit-pic', href: s.src, target: '_blank', title: 'Open the picture' }, picImg);
    const picInfo = el('span', {}, s.src.split('/').pop() + (s.w ? ` · ${s.w}×${s.h}` : ''));
    const open = async (tab) => {   // the picture: its details; Crop…: the window on crop
      if (tab === 'crop' && /\.gif$/i.test(src)) return toast('Animated GIFs can’t be cropped.', 'err');
      const box = await cropImage(src, { title: caption.value || 'Gallery Picture', tab });
      if (!box) return;
      cropBtn.disabled = true; cropBtn.textContent = 'Cropping…';
      try {
        const r = await post('/admin/images.php', { action: 'crop', url: src, ...box });
        src = r.url; picImg.src = r.small || r.url; picLink.href = r.url; picInfo.textContent = r.url.split('/').pop() + ` · ${r.w}×${r.h}`;
        mark(); toast('Cropped ✓ Save to keep it.');
      } catch (e) { toast(e.message, 'err'); }
      cropBtn.disabled = false; cropBtn.textContent = 'Crop…';
    };
    const cropBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => open('crop') }, 'Crop…');
    picLink.title = 'Details and crop';
    picLink.addEventListener('click', (e) => { if (e.ctrlKey || e.metaKey || e.shiftKey || e.button) return; e.preventDefault(); open('details'); });   // ctrl / middle click: the file in a new tab
    f.append(
      picLink,
      el('p', { class: 'muted small g-edit-info' }, picInfo, cropBtn),
      field('Caption', '', caption),
      el('div', { class: 'field' }, lbl('Characters', 'Pick a model, or type any name (characters without a model yet). Visitors find the picture by these names. A model that is a draft, behind a password or blurred makes the picture follow it.'), charsField(st.chars, mark)),
      field('Date', '', date),
      el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Visibility'), vis), hint,
      s.likes ? el('p', { class: 'muted small' }, `♥ Liked by ${s.likes} ${s.likes === 1 ? 'patron' : 'patrons'}.`) : '',   // not null: the DOM's own f.append() would print it
      el('fieldset', { class: 'g-dl' }, el('legend', {}, 'Full Size Download', tip('A link to the full resolution file (pictures on the site are at most 2560 px). Visitors get a “Download Full Size” button in the viewer; the address itself never appears in the page, and every download is counted.')),
        field('Link', '', url), el('div', { class: 'grid2' }, field('Who Can Download It', '', access), field('Size Label', '', size)),
        s.dls ? el('p', { class: 'muted small' }, `Downloaded ${s.dls} ${s.dls === 1 ? 'time' : 'times'}.`) : null),
      el('div', { class: 'form-actions' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm del-btn act-left', onclick: async () => {
          if (!confirm('Remove this picture from the gallery? It stays in the Images library.')) return;
          try { await save({ action: 'delete', ids: [s.id] }, 'Removed from the gallery ✓'); } catch (e) { toast(e.message, 'err'); }
        } }, 'Remove from Gallery'),
        el('button', { class: 'btn btn-primary btn-sm' }, 'Save')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = f.querySelector('.btn-primary');
      btn.disabled = true;
      f.inert = true;   // no typing while it saves: the panel is drawn again from the saved picture
      dirty = false;
      try {
        await save({ action: 'save', id: s.id, still: { src, caption: caption.value, chars: st.chars, date: date.value, visibility: vis.querySelector('input:checked').value, link: { url: url.value.trim(), access: access.value, size: size.value } } }, 'Saved ✓');
      } catch (err) { dirty = true; f.inert = false; toast(err.message, 'err'); btn.disabled = false; }
    });
    return el('div', {}, el('h2', { class: 'g-panel-title' }, 'Edit Picture'), f);
  }

  // ── "+ Add pictures": the picture picker (Images library or upload); pictures already in the gallery are greyed out ──
  async function openAdd() {
    if (!leaveOk()) return;
    const srcs = await pickImages({ multiple: true, folder: 'gallery', title: 'Add Pictures', exclude: G.stills.map((s) => s.src), excludeLabel: 'Already in the Gallery',
      note: 'Pictures from a model’s folder come in tagged with that model; you can change it afterwards.' });
    if (!srcs.length) return;
    dirty = false;   // the panel's edits were given up (leaveOk) or there were none
    try {
      const r = await save({ action: 'add', srcs }, (x) => `Added ${x.added.length} ${x.added.length === 1 ? 'picture' : 'pictures'} ✓` + (x.skipped ? ` (${x.skipped} already in the gallery)` : ''));
      filt.q = ''; filt.who = ''; filt.only = '';
      // the search bar shows the cleared filter too (the picker is rebuilt only when the characters change)
      if (barSearch) barSearch.value = '';
      if (barWho) { barWho.value = ''; barWho.fp?.set(''); }
      if (r.added.length === 1) sel = r.added[0];
      render();
    } catch (e) { toast(e.message, 'err'); }
  }
  document.querySelector('[data-g-add]').addEventListener('click', openAdd);

  render();
})();

// ── "Jump to…" (Ctrl+K / ⌘K anywhere in the admin, or the magnifier in the header): a few letters of a model, accessory, docs page,
// bug report, admin page or Settings box, then Enter (Ctrl+Enter: a new tab). The list comes from jump.php, loaded on first use.
// It looks and opens like the public header's search (site.js [data-hsearch]): the bar grows from the magnifier over the menu
// (✕, Esc or a click outside closes it), the results under it. A text field that uses Ctrl+K itself (the docs editor's
// link button) keeps it ──
(() => {
  const root = document.querySelector('[data-jump]');
  if (!root) return;
  const btn = root.querySelector('[data-jump-open]');
  const panel = root.querySelector('[data-jump-panel]');
  const q = root.querySelector('[data-jump-input]');
  const list = root.querySelector('[data-jump-list]');
  if (/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {   // Macs say ⌘K (Ctrl+K works there too)
    btn.title = btn.title.replace('Ctrl+K', '⌘K');
    btn.setAttribute('aria-label', btn.getAttribute('aria-label').replace('Ctrl+K', '⌘K'));
  }
  const fold = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  let items = null;
  let loading = null;
  let shown = [];
  let cur = 0;
  let timer = 0;
  const line = (text) => { const li = document.createElement('li'); li.className = 'hs-none'; li.textContent = text; return li; };
  const mark = () => {
    list.querySelectorAll('[role=option]').forEach((li, i) => {
      li.setAttribute('aria-selected', String(i === cur));
      li.firstChild.classList.toggle('is-active', i === cur);
    });
    if (shown.length) { q.setAttribute('aria-activedescendant', 'jump-' + cur); list.querySelector('#jump-' + cur)?.scrollIntoView({ block: 'nearest' }); }
    else q.removeAttribute('aria-activedescendant');
  };
  const draw = () => {
    const words = fold(q.value).split(/\s+/).filter(Boolean);
    shown = [];
    // nothing typed: no list (like the public search), the results come with the first letter
    if (!words.length) { list.replaceChildren(); mark(); return; }
    if (!items) { list.replaceChildren(line('Loading…')); mark(); return; }
    // every word must be found (in the name, kind or extra words); ranked: the name starts with what was typed, every word
    // starts a word of the name ("char 1" → Character 1, then Character 10), every word is in the name, the rest
    const all = words.join(' ');
    const rank = (it) => {
      if (it.f.startsWith(all)) return 0;
      const parts = it.f.split(/[^a-z0-9]+/).filter(Boolean);
      if (words.every((w) => parts.some((p) => p.startsWith(w.replace(/^#/, ''))))) return 1;
      return words.every((w) => it.f.includes(w)) ? 2 : 3;
    };
    const res = items.filter((it) => words.every((w) => it.all.includes(w))).map((it, i) => [rank(it), i, it]).sort((a, b) => a[0] - b[0] || a[1] - b[1]).map((x) => x[2]);
    shown = res.slice(0, 12);
    cur = 0;
    // one row = the public search's: picture (else the first letter), title, then the kind (mustard) · the line under it
    list.replaceChildren(...(shown.length ? shown.map((it, i) => {
      const li = document.createElement('li');
      li.id = 'jump-' + i;
      li.setAttribute('role', 'option');
      const a = document.createElement('a');
      a.href = it.u;
      a.tabIndex = -1;
      let pic;
      if (it.p) { pic = document.createElement('img'); pic.src = it.p; pic.alt = ''; pic.loading = 'lazy'; }
      else { pic = document.createElement('span'); pic.setAttribute('aria-hidden', 'true'); pic.textContent = it.t.replace(/^[#+\s\d]+/, '').slice(0, 1).toUpperCase() || '#'; }
      pic.className = 'hs-pic';
      const t = document.createElement('span'); t.className = 'hs-t'; t.textContent = it.t;
      const s = document.createElement('span'); s.className = 'hs-s';
      const k = document.createElement('span'); k.className = 'hs-k'; k.textContent = it.k;
      s.append(k, it.s ? ' · ' + it.s : '');
      a.append(pic, t, s);
      li.append(a);
      li.addEventListener('mousemove', () => { if (cur !== i) { cur = i; mark(); } });
      return li;
    }) : [line('Nothing found.')]));
    mark();
  };
  const load = () => loading ||= fetch('/admin/jump.php', { credentials: 'same-origin' })
    .then((r) => r.json())
    .then((d) => {
      if (!d.ok) throw new Error(d.error || 'The list could not be loaded.');
      items = d.items.map((it) => ({ ...it, f: fold(it.t), all: fold(`${it.t} ${it.k} ${it.s} ${it.x}`) }));
      if (isOpen()) draw();
    })
    .catch((e) => { loading = null; list.replaceChildren(line(e.message || 'The list could not be loaded.')); });
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
    if (isOpen()) { q.focus(); q.select(); return; }
    clearTimeout(timer);
    q.value = '';
    panel.hidden = false;
    place();
    from();
    panel.getBoundingClientRect();   // start the animation from the magnifier
    root.classList.add('is-open');
    panel.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    q.setAttribute('aria-expanded', 'true');
    q.focus({ preventScroll: true });
    load();
    draw();
  };
  const close = (focus = false) => {
    if (!isOpen()) return;
    from();
    root.classList.remove('is-open');
    panel.classList.remove('is-open');
    uncover();
    btn.setAttribute('aria-expanded', 'false');
    q.setAttribute('aria-expanded', 'false');
    timer = setTimeout(() => { if (!isOpen()) panel.hidden = true; }, 340);
    if (focus) btn.focus();
  };
  btn.addEventListener('click', open);
  root.querySelector('[data-jump-close]').addEventListener('click', () => close(true));
  list.addEventListener('click', (e) => { if (e.target.closest('a') && !e.ctrlKey && !e.metaKey) close(); });   // a Settings box on this same page loads nothing: the bar goes
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen()) { e.preventDefault(); close(true); return; }
    if (e.defaultPrevented || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || e.key.toLowerCase() !== 'k') return;
    if (document.querySelector('dialog[open]')) return;   // not under a window (the page behind it can't be used)
    e.preventDefault();
    open();
  });
  q.addEventListener('input', draw);
  q.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (shown.length) { cur = (cur + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length; mark(); }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const it = shown[cur];
      if (!it) return;
      if (e.ctrlKey || e.metaKey) window.open(it.u, '_blank');
      else { close(); location.href = it.u; }
    }
  });
  document.addEventListener('pointerdown', (e) => { if (isOpen() && !root.contains(e.target)) close(); });
  root.addEventListener('focusout', (e) => { if (e.relatedTarget && !root.contains(e.relatedTarget)) close(); });
  window.addEventListener('resize', () => { if (isOpen()) { place(); from(); } });
})();
