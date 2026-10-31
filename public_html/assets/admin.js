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

// Text compared without accents or case: searches and suggestions (the same as searchFold() in site.js).
const fold = (t) => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const foldWords = (q) => fold(q).split(/\s+/).filter(Boolean);   // the typed words, each to be found
// the first max of a sorted list, shared fairly between its groups (models can't crowd out the accessories), in the list's order
const fairSlice = (list, group, max) => {
  if (list.length <= max) return list;
  const by = new Map();
  for (const x of list) { const g = group(x); if (!by.has(g)) by.set(g, []); by.get(g).push(x); }
  const keep = new Set();
  for (let round = 0; keep.size < max; round++) for (const xs of by.values()) if (keep.size < max && xs[round]) keep.add(xs[round]);
  return list.filter((x) => keep.has(x));
};
const headBottom = () => Math.max(0, document.querySelector('.site-header')?.getBoundingClientRect().bottom || 0);   // the sticky header's lower edge in the window
// a suggestion list (.g-sugg) opens above its field when it doesn't fit under it in the window and there is more room above
const suggFlip = (sugg) => {
  sugg.classList.remove('up');
  if (sugg.hidden) return;
  const r = sugg.parentElement.getBoundingClientRect(), below = innerHeight - r.bottom, above = r.top - headBottom();
  sugg.classList.toggle('up', below < sugg.offsetHeight + 8 && above > below);
};

// A page address from a title: lowercase letters and digits joined by single dashes, at most `max` characters, never ending with a dash.
const slugify = (t, max = 64) => fold(t).replace(/[^a-z0-9]+/g, '-').replace(/^-+/, '').slice(0, max).replace(/-+$/, '');

// Floating messages at the bottom ([data-toasts]): toast() from the scripts, and the ones a page prints after an action
// (admin_flash() in _admin.php). Each has a ✕; ok / info ones fade out by themselves (longer texts stay longer; the pointer
// or the focus on one keeps it), errors stay until closed. The same text again replaces the older one; 4 at most.
// While a window (<dialog> opened with showModal()) is open, everything outside it is under it and can't be clicked,
// so the message goes in a stack of its own inside that window, which goes away with it.
function toast(msg, kind = 'ok') {
  msg = String(msg).replace(/^✓\s*/, '');   // the round icon (CSS) says it
  let box = document.querySelector('[data-toasts]');
  if (!box) return alert(msg);
  let modal = null;
  try { modal = [...document.querySelectorAll('dialog')].filter((d) => d.matches(':modal')).pop() || null; } catch (e) { /* no :modal (old browsers) */ }
  if (modal) box = modal.querySelector(':scope > .toasts') || modal.appendChild(el('div', { class: 'toasts' }));
  for (const old of box.querySelectorAll('.toast')) if (old.querySelector('.toast-msg').textContent === msg) old.remove();
  const t = el('div', { class: 'toast ' + kind, role: kind === 'err' ? 'alert' : 'status' },
    el('span', { class: 'toast-msg' }, msg), el('button', { type: 'button', class: 'toast-x', 'aria-label': 'Close', title: 'Close' }, '✕'));
  box.append(t);
  const all = box.querySelectorAll('.toast');
  for (let i = 0; i < all.length - 4; i++) all[i].remove();
  toastWire(t);
}

function toastWire(t) {
  const close = () => {
    if (t.classList.contains('is-out')) return;
    t.classList.add('is-out');
    setTimeout(() => t.remove(), matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 250);
  };
  t.querySelector('.toast-x').addEventListener('click', close);
  if (t.classList.contains('err')) return;
  const wait = Math.min(10000, 3000 + t.textContent.length * 45);
  let timer = setTimeout(close, wait), held = 0;
  const hold = () => { held++; clearTimeout(timer); };
  const free = () => { if (--held <= 0) { held = 0; timer = setTimeout(close, 2000); } };
  t.addEventListener('pointerenter', hold); t.addEventListener('pointerleave', free);
  t.addEventListener('focusin', hold); t.addEventListener('focusout', free);
}
document.querySelectorAll('[data-toasts] .toast').forEach(toastWire);

// Unsaved changes: isDirty() says whether a part of the page holds some. Closing the tab, reloading or typing an address then asks the
// browser's own question (beforeunload); a click on a link that leaves the page in this tab (View Post, the menu, the bell…) asks first
// with confirm(), because some browsers never show the beforeunload question (Safari on iPhone and iPad). Every editor uses this one;
// a page with several (Docs: the pages and the links box) still asks once.
const leaveChecks = [];
function guardLeave(isDirty) {
  if (!leaveChecks.length) {
    let leaving = false;   // the question was just answered with OK: the browser doesn't ask again on its way out
    const dirty = () => leaveChecks.some((f) => f());
    addEventListener('beforeunload', (e) => { if (!leaving && dirty()) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('click', (e) => {
      const a = e.target.closest?.('a[href]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;   // a new tab or window keeps this page
      if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return;
      const u = new URL(a.href, location.href);
      if (u.protocol !== 'http:' && u.protocol !== 'https:') return;   // mailto: and the like
      if (u.origin === location.origin && u.pathname === location.pathname && u.search === location.search && u.hash) return;   // a place on this page
      if (!dirty()) return;
      if (!confirm('You have unsaved changes. Leave without saving them?')) { e.preventDefault(); return; }
      leaving = true;
      setTimeout(() => { leaving = false; }, 1000);   // the link didn't leave the page after all (a file download): ask again next time
    });
  }
  leaveChecks.push(isDirty);
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
// POSTs a file (+ the folder and the CSRF token) to an upload endpoint with progress; resolves to its JSON answer ({ok, url, …}).
// extra: more fields ({name: string | Blob}), e.g. a video's cover
function sendFile(endpoint, file, model, onProgress, extra = {}, retried = false) {
  const fd = new FormData();
  if (file) fd.append('file', file);
  fd.append('model', model || 'misc');
  for (const [k, v] of Object.entries(extra)) if (v !== null && v !== undefined) fd.append(k, v);
  fd.append('csrf', CSRF);
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();   // not fetch: only XHR tells how much of the file has gone
    x.open('POST', endpoint);
    // at 100% the server (through any proxy) still receives, checks and saves the file: shown as busy, not as a bar stuck at 100%
    const size = file?.size || 0;
    x.upload.onprogress = (e) => { if (e.lengthComputable) onProgress?.(size * e.loaded / e.total, e.loaded >= e.total); };
    x.upload.onload = () => onProgress?.(size, true);
    x.onload = () => {
      let data = null;
      try { data = JSON.parse(x.responseText); } catch { /* not JSON */ }
      if (x.status >= 200 && x.status < 300 && data?.ok) resolve(data);
      // a new login in another tab changed the token: send once more with the new one (like post())
      else if (x.status === 403 && !retried) freshCsrf().then((ok) => (ok ? sendFile(endpoint, file, model, onProgress, extra, true).then(resolve, reject) : reject(new Error(data?.error || 'Upload failed (403)'))));
      else reject(new Error(data?.error || (x.status === 413 ? 'The file is larger than the server or a proxy in front of it accepts (often 100 MB).' : `Upload failed (${x.status})`)));
    };
    x.onerror = () => reject(new Error('Upload failed: the connection was interrupted.'));
    x.send(fd);
  });
}

// Progress bar for uploads (the picture picker, Images → Upload images): run(files, folder) uploads them one after the other
// and resolves to the addresses of the ones that worked (each failure is a toast). The bar counts bytes over all the files;
// files pushed onto the same array while it runs (dropped during an upload) join the queue; the files that failed are in .failed.
// finish(text): the full bar with that text (the picture picker stays open after an upload).
function uploadProgress() {
  const text = el('span', { class: 'up-prog-tx' });
  const fill = el('span', { class: 'up-prog-fill' });
  const node = el('div', { class: 'up-prog', hidden: true, role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-live': 'polite' }, text, el('span', { class: 'up-prog-bar' }, fill));
  const run = async (files, folder) => {
    const urls = [], failed = [];
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
      try { urls.push(await upload(f, folder, show)); } catch (e) { failed.push(f); toast(`${f.name}: ${e.message}`, 'err'); }
      before += f.size;
    }
    node.classList.remove('busy');
    return Object.assign(urls, { failed });
  };
  const finish = (msg) => { fill.style.width = '100%'; node.setAttribute('aria-valuenow', '100'); text.textContent = msg; };
  return { node, run, finish, hide: () => { node.hidden = true; } };
}

// ── Pictures dropped on (or pasted into) a text in the md_html() format: each file is uploaded (upload.php, into `folder`) and
// written in as "![](/media/…)" on a line of its own with a blank line around it, which md_html() needs to show a picture.
// Where: the caret (pasted) or the point under the pointer (dropped; the caret follows the pointer while dragging); at the start of
// a line or on an empty line it goes there, anywhere else at the end of that line. Several files = consecutive lines = a gallery.
// While a file uploads its line reads "![Uploading name…]()"; the address replaces it, a failure takes the line out (and says why).
// folder: a media folder name, or a function returning it (the model editor's folder follows the address); onEdit: called after
// every change it makes (the editors mark themselves unsaved; the textarea also gets an input event).
const DROP_IMG_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];   // what upload.php takes
let dropQueue = Promise.resolve();   // one upload at a time, across every text of the page
// video(file, i): a video dropped at caret index i (texts that take videos: blog posts, docs pages); without it a video is refused with a toast.
function imageDrop(ta, folder = 'misc', onEdit = () => {}, { video = null } = {}) {
  itemMention(ta);   // every text in the md_html() format comes here: "@" lists the models, accessories and docs pages too
  const changed = () => { ta.dispatchEvent(new Event('input', { bubbles: true })); onEdit(); };
  // where a picture goes for a caret at i: [start, end] of the text it replaces (an empty line's spaces) — see above
  const spot = (i) => {
    const v = ta.value;
    const a = v.lastIndexOf('\n', i - 1) + 1;
    let b = v.indexOf('\n', i);
    if (b < 0) b = v.length;
    if (v.slice(a, b).trim() === '') return [a, b];
    return i === a ? [a, a] : [b, b];
  };
  // the caret index under a point: a hidden copy of the textarea with the text up to i and a marker after it; a binary search
  // finds the last i whose marker is before the point (browsers have no reliable way to ask this of a textarea)
  const indexAt = (x, y) => {
    const cs = getComputedStyle(ta);
    const m = el('div', { 'aria-hidden': 'true' });
    for (const p of ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'wordSpacing', 'lineHeight', 'textTransform', 'textIndent', 'tabSize', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'wordBreak']) m.style[p] = cs[p];
    Object.assign(m.style, { position: 'absolute', top: '0', left: '-10000px', visibility: 'hidden', boxSizing: 'content-box', border: '0', whiteSpace: 'pre-wrap', overflowWrap: 'break-word',
      width: Math.max(0, ta.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight)) + 'px' });
    const txt = document.createTextNode('');
    const mark = el('span', {}, '\u200b');
    m.append(txt, mark);
    document.body.append(m);
    const r = ta.getBoundingClientRect();
    const px = x - r.left - ta.clientLeft + ta.scrollLeft, py = y - r.top - ta.clientTop + ta.scrollTop;
    const v = ta.value;
    const before = (i) => { txt.data = v.slice(0, i); const t = mark.offsetTop; return t + mark.offsetHeight <= py || (t <= py && mark.offsetLeft <= px); };
    let lo = 0, hi = v.length;
    while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (before(mid)) lo = mid; else hi = mid - 1; }
    m.remove();
    return lo;
  };
  const insert = (files, i) => {
    const [a, b] = spot(i);
    const v = ta.value;
    const pre = a === 0 || v.slice(0, a).endsWith('\n\n') ? '' : v.slice(0, a).endsWith('\n') ? '\n' : '\n\n';
    const after = v.slice(b);
    const post = after === '' || after.startsWith('\n\n') ? '' : after.startsWith('\n') ? '\n' : '\n\n';
    const marks = [];   // each one unique, so it is found again even when the text changed meanwhile
    for (const f of files) {
      const name = (f.name || 'picture').replace(/[\[\]()\n\r]/g, ' ').trim().slice(0, 80) || 'picture';
      let ph = `![Uploading ${name}…]()`;
      for (let n = 2; v.includes(ph) || marks.includes(ph); n++) ph = `![Uploading ${name} (${n})…]()`;
      marks.push(ph);
    }
    const text = marks.join('\n');
    ta.setRangeText(pre + text + post, a, b, 'end');
    ta.setSelectionRange(a + pre.length + text.length, a + pre.length + text.length);
    changed();
    files.forEach((f, k) => {
      const ph = marks[k];
      const swap = (text) => {   // the placeholder (wherever it is now) becomes text; '' = its line goes
        const at = ta.value.indexOf(ph);
        if (at < 0) return;   // removed meanwhile: the picture stays in the Media library only
        let s = at, e = at + ph.length;
        if (text === '') {   // the line goes with the line breaks around it, leaving the wider of the two gaps (none at the ends)
          const v = ta.value;
          while (s > 0 && v[s - 1] === '\n') s--;
          while (e < v.length && v[e] === '\n') e++;
          text = s === 0 || e === v.length ? '' : '\n'.repeat(Math.max(at - s, e - at - ph.length));
        }
        ta.setRangeText(text, s, e, 'preserve');
        changed();
      };
      dropQueue = dropQueue.then(() => upload(f, typeof folder === 'function' ? folder() : folder)
        .then((url) => swap(`![](${url})`), (err) => { swap(''); toast(`${f.name}: ${err.message}`, 'err'); }))
        .catch(() => {});   // never a rejected queue: the next files still go
    });
  };
  const hasFiles = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
  let raf = 0, lastXY = '';
  ta.addEventListener('dragover', (e) => {
    if (!hasFiles(e) || ta.disabled || ta.readOnly) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    ta.classList.add('img-drop-on');
    const xy = `${Math.round(e.clientX / 4)},${Math.round(e.clientY / 4)}`;   // the caret follows the pointer (not on every pixel)
    if (raf || xy === lastXY) return;
    lastXY = xy;
    const { clientX: x, clientY: y } = e;
    raf = requestAnimationFrame(() => {
      raf = 0;
      const [a] = spot(indexAt(x, y));
      ta.focus({ preventScroll: true });
      ta.setSelectionRange(a, a);
    });
  });
  const off = () => { ta.classList.remove('img-drop-on'); lastXY = ''; };
  ta.addEventListener('dragleave', off);
  ta.addEventListener('drop', (e) => {
    if (!hasFiles(e) || ta.disabled || ta.readOnly) return;
    e.preventDefault();
    off();
    const files = [...e.dataTransfer.files];
    const pics = files.filter((f) => DROP_IMG_TYPES.includes(f.type));
    const vids = files.filter((f) => /^video\//.test(f.type) || /\.(mp4|webm|m4v|mov)$/i.test(f.name));
    if (vids.length && video) video(vids[0], spot(indexAt(e.clientX, e.clientY))[0]);   // one at a time: its window checks it first
    if (pics.length + (video ? vids.length : 0) < files.length) toast(video ? 'Only pictures (JPG, PNG, WebP, GIF) and videos (MP4, WebM) can be added to the text.' : 'Only pictures (JPG, PNG, WebP, GIF) can be added to the text.', 'err');
    else if (vids.length > 1) toast('One video at a time: drop the others after this one.', 'err');
    if (pics.length) insert(pics, indexAt(e.clientX, e.clientY));
  });
  ta.addEventListener('paste', (e) => {
    const cd = e.clipboardData;
    // a copied picture; not when text comes with it (Word, web pages: the text is what's wanted, the picture is a render of it)
    if (!cd || cd.types.includes('text/plain') || ta.disabled || ta.readOnly) return;
    const pics = [...cd.files].filter((f) => DROP_IMG_TYPES.includes(f.type));
    if (!pics.length) return;
    e.preventDefault();
    insert(pics, ta.selectionStart);
  });
  return ta;
}

function autoGrow(ta) {
  const fit = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 2 + 'px'; };
  ta.addEventListener('input', fit);
  requestAnimationFrame(fit);
  return ta;
}

// An editor's Save button ([data-save] in its header) and Ctrl+S / ⌘S: work() runs once at a time ("Saving…"), a failure shows as a
// toast; work() returning true keeps the button greyed (the page is going to another address).
function saveButton(work) {
  const btn = document.querySelector('[data-save]');
  const save = async () => {
    if (btn.disabled) return;   // already saving (Ctrl+S pressed twice)
    btn.disabled = true; btn.textContent = 'Saving…';
    try { if (await work() === true) return; } catch (e) { toast(e.message, 'err'); }
    btn.disabled = false; btn.textContent = 'Save';
  };
  btn.addEventListener('click', save);
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } });
}

// The list under a sign typed in a text, like GitHub: "#" for bug reports (bugMention()), "@" for models, accessories and docs pages
// (itemMention()). As you type, what follows the sign narrows it; ↑ ↓ choose, Enter / Tab / a click insert, Esc closes. o = {sign, re (the
// sign and what follows it just before the caret: group 2), label (the list's name), find(q) → [[title or null, [item…]]…] (may be a
// promise), row(item, q) → the row's content, none(q) (no match), foot() (the hint after "Enter"), insert(item, at, pos) → [before, text, after]}
const mentionMark = (text, q) => {   // the typed word lit in a name (or number)
  const i = q ? fold(text).indexOf(fold(q)) : -1;   // fold() keeps the length of plain text; accented names just go unlit
  return i < 0 || fold(text).length !== text.length ? [text] : [text.slice(0, i), el('span', { class: 'bm-mark' }, text.slice(i, i + q.length)), text.slice(i + q.length)];
};
function mentionList(ta, o) {
  let pop = null, items = [], sel = 0, skip = false, asked = 0;
  const token = () => {   // [index of the sign, what follows it]
    const pos = ta.selectionStart;
    if (pos !== ta.selectionEnd) return null;
    const m = o.re.exec(ta.value.slice(0, pos));
    return m ? [pos - m[2].length - 1, m[2]] : null;
  };
  const place = (at) => {   // under the sign (a hidden copy of the field finds where it is)
    const cs = getComputedStyle(ta);
    const m = el('div', { 'aria-hidden': 'true' });
    for (const k of ['boxSizing', 'width', 'fontFamily', 'fontSize', 'fontWeight', 'letterSpacing', 'lineHeight', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderStyle', 'textIndent', 'wordSpacing', 'tabSize']) m.style[k] = cs[k];
    Object.assign(m.style, { position: 'absolute', top: '0', left: '-9999px', visibility: 'hidden', whiteSpace: 'pre-wrap', overflowWrap: 'break-word' });
    const mk = el('span', {}, o.sign);
    m.append(ta.value.slice(0, at), mk);
    document.body.append(m);
    const r = ta.getBoundingClientRect();
    const x = r.left + mk.offsetLeft - ta.scrollLeft - 12, y = Math.min(r.top + mk.offsetTop + mk.offsetHeight - ta.scrollTop + 8, r.bottom + 6);
    const top = Math.max(r.top, r.top + mk.offsetTop - ta.scrollTop) - 6;   // the typed line's top, for a list above it
    m.remove();
    const vw = document.documentElement.clientWidth;
    pop.style.left = Math.max(12, Math.min(x, vw - pop.offsetWidth - 12)) + scrollX + 'px';
    // no room under the line (end of the page): above it, when there is more room there (under the header); shorter when neither fits
    const below = innerHeight - 8 - y, above = top - headBottom() - 8, up = pop.offsetHeight > below && above > below;
    if (pop.offsetHeight > (up ? above : below)) pop.style.maxHeight = Math.max(120, up ? above : below) + 'px';
    pop.style.top = (up ? top - pop.offsetHeight : y) + scrollY + 'px';
  };
  const close = () => { pop?.remove(); pop = null; items = []; };
  const pick = (it) => {
    const t = token();
    if (!t) return close();
    const [before, ins, after] = o.insert(it, t[0], ta.selectionStart);
    ta.value = before + ins + after;
    ta.setSelectionRange(before.length + ins.length, before.length + ins.length);
    close();
    skip = true;   // what was just inserted would open the list again
    ta.dispatchEvent(new Event('input', { bubbles: true }));   // grows the field, marks the editor unsaved, redraws a preview
  };
  const show = () => {
    if (!pop) return;
    pop.querySelectorAll('.bm-row').forEach((r, i) => { r.classList.toggle('on', i === sel); r.setAttribute('aria-selected', String(i === sel)); if (i === sel) r.scrollIntoView({ block: 'nearest' }); });
  };
  const update = async () => {
    const t = token();
    if (!t) return close();
    const n = ++asked;
    const groups = (await o.find(t[1])).filter((g) => g[1].length);
    if (n !== asked || !token()) return;   // typed on meanwhile
    items = groups.flatMap((g) => g[1]);
    sel = 0;
    const row = (it) => {
      const r = o.row(it, t[1]);
      r.addEventListener('pointerdown', (e) => e.preventDefault());   // keeps the caret in the field
      r.addEventListener('click', () => pick(it));
      r.addEventListener('pointermove', () => { if (sel !== items.indexOf(it)) { sel = items.indexOf(it); show(); } });
      return r;
    };
    pop?.remove();
    pop = el('div', { class: 'bm-pop', role: 'listbox', 'aria-label': o.label },
      items.length ? groups.flatMap(([h, list], i) => [i ? el('div', { class: 'bm-sep' }) : null, h && el('div', { class: 'bm-head' }, h), ...list.map(row)])
        : el('div', { class: 'bm-none' }, o.none(t[1])),
      items.length ? el('div', { class: 'bm-foot' }, el('kbd', {}, '↑'), ' ', el('kbd', {}, '↓'), ' to choose · ', el('kbd', {}, 'Enter'), o.foot()) : null);
    pop.addEventListener('pointerdown', (e) => e.preventDefault());
    document.body.append(pop);
    place(t[0]);
    show();
  };
  ta.addEventListener('input', () => { if (skip) { skip = false; return; } update(); });
  ta.addEventListener('keydown', (e) => {
    if (!pop) return;
    const stop = () => { e.preventDefault(); e.stopImmediatePropagation(); };   // before the list's own Enter (new line) and the editor's keys
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { if (items.length) { sel = (sel + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length; show(); } stop(); }
    else if ((e.key === 'Enter' || e.key === 'Tab') && items.length && !e.shiftKey) { stop(); pick(items[sel]); }
    else if (e.key === 'Escape') { stop(); close(); }
  });
  ta.addEventListener('keyup', (e) => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) { if (token()) update(); else close(); } });
  ta.addEventListener('click', () => { if (pop && !token()) close(); });
  ta.addEventListener('blur', close);
  window.addEventListener('resize', close);
}

// "#" in a changelog line lists the bug reports: the number or words of the title narrow it; it inserts "#6", and right after a "Fix"
// tag at the start ("Fix #") "#6: ", the line's usual form.
// o = {bugs: [{n, t (title), s (status), mine (about this item), a (about name), f (fixed in)}], statuses: {key: [label, colour]}, name: () => item name}
const CHANGE_TAG_RE = /^\s*(New|Feature|Fix|Bug|Enhancement|Improvement|Optimization|Removal|UI|Outfits?|Physics|Note)(\s*\([^)]*\))?(\s*,?\s*#\d+)*\s*,?\s*$/;   // case-sensitive, like change_line()
function bugMention(ta, o) {
  const done = (b) => b.s === 'fixed' || b.s === 'closed';
  const rank = (a, b) => (b.mine - a.mine) || (done(a) - done(b)) || (b.n - a.n);   // this item first, open first, newest first
  mentionList(ta, {
    sign: '#', re: /(^|[\s(,;])#([^\s#,;:()]{0,40})$/, label: 'Bug reports',
    find: (q) => {
      if (q === '') {
        const mine = o.bugs.filter((b) => b.mine && !done(b)).sort(rank).slice(0, 6);
        const rest = o.bugs.filter((b) => !mine.includes(b)).sort(rank).slice(0, 8 - mine.length);
        return mine.length ? [[o.name() + ' · Open', mine], ['Other Reports', rest]] : [[null, rest]];
      }
      const num = /^\d+$/.test(q);
      const words = foldWords(q);
      const hits = o.bugs.filter((b) => num ? String(b.n).startsWith(q) || words.every((w) => fold(b.t).includes(w)) : words.every((w) => fold(b.t).includes(w)));
      return [[null, hits.sort((a, b) => (num ? (b.n === Number(q)) - (a.n === Number(q)) : 0) || rank(a, b)).slice(0, 8)]];
    },
    row: (b, q) => {
      const num = /^\d+$/.test(q);
      const [label, color] = o.statuses[b.s] || o.statuses.new;
      const sub = [b.mine ? '' : b.a, b.f ? 'fixed in ' + b.f : ''].filter(Boolean).join(' · ');
      return el('div', { class: 'bm-row', role: 'option' },
        el('span', { class: 'bm-n' }, '#', mentionMark(String(b.n), num ? q : '')),
        el('span', { class: 'bm-t' }, el('b', {}, mentionMark(b.t, num ? '' : q)), sub && el('small', {}, sub.charAt(0).toUpperCase() + sub.slice(1))),
        el('span', { class: 'bm-st', style: '--c:' + color }, label));
    },
    none: (q) => 'No report matches “' + q + '”.',
    foot: () => [' to insert · ', el('kbd', {}, 'Esc'), ' to close'],
    insert: (b, at, pos) => {
      const before = ta.value.slice(0, at), after = ta.value.slice(pos).replace(/^[^\s#,;:()]*/, '');   // the rest of a word the caret is in goes too
      return [before, '#' + b.n + (CHANGE_TAG_RE.test(before) && !after.trim() ? ': ' : /^[\s,.;:)]/.test(after) ? '' : ' '), after];
    },
  });
}

// Model, accessory and docs links for the texts (md_html() shows a link alone on its line as a card, md_link_cards()): the list comes from
// jump.php?cards=1 ({t, k, s, p, l (the public page), x}), loaded once, on the first "@" or Card button.
let cardList = null;
const loadCards = () => cardList ||= fetch('/admin/jump.php?cards=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  .then((r) => r.json()).then((d) => (d.items || []).filter((i) => i.l)).catch(() => { cardList = null; return []; });
const CARD_LINE_RE = /^\[[^\]\n]+\]\(\/(?:models|accessories|scenes|docs)\/[^)\s]*\)$/;
const cardLink = (i) => '[' + i.t.replace(/[[\]]/g, '') + '](' + i.l + ')';
const cardGroup = (i) => i.k === 'Model' ? 'Models' : i.k === 'Docs' ? 'Docs' : 'Accessories';
// the matches of q (every word in the name, the line under it or the extra words), models first, then accessories, then docs; in each
// group the names that start with q, then those holding every word; max = shared between the groups, so each one keeps its place
const cardFind = (list, q, max = 0) => {
  const words = foldWords(q);
  const hit = (i) => words.every((w) => fold(i.t + ' ' + i.s + ' ' + (i.x || '')).includes(w));
  const named = (i) => (fold(i.t).startsWith(fold(q)) ? 2 : 0) + (words.every((w) => fold(i.t).includes(w)) ? 1 : 0);
  const order = ['Models', 'Accessories', 'Docs'];
  const out = list.filter(hit).sort((a, b) => (order.indexOf(cardGroup(a)) - order.indexOf(cardGroup(b))) || (named(b) - named(a)));
  return max ? fairSlice(out, cardGroup, max) : out;
};
const cardThumb = (i) => i.p ? el('img', { src: i.p, alt: '', loading: 'lazy' })
  : el('span', { class: 'cm-ico', 'aria-hidden': 'true' }, i.k === 'Docs' ? '¶' : (i.t || '?').charAt(0));

// "@" in a text (itemMention(), every text in the md_html() format through imageDrop()): "@tifa" lists the models, accessories and docs pages;
// Enter / a click puts the link in its place: on a line of its own it becomes a card (a blank line before it when the line above is text, a new
// line after it for the next one), inside a sentence a plain link.
function itemMention(ta) {
  if (ta.dataset.mention) return;
  ta.dataset.mention = '1';
  mentionList(ta, {
    sign: '@', re: /(^|[\s(])@([^\s@()[\]]{0,40})$/, label: 'Models, accessories and docs pages',
    find: async (q) => {
      const groups = [];
      for (const it of cardFind(await loadCards(), q, 9)) { const g = cardGroup(it); if (groups.at(-1)?.[0] !== g) groups.push([g, []]); groups.at(-1)[1].push(it); }
      return groups;
    },
    row: (it, q) => el('div', { class: 'bm-row cm-row', role: 'option' }, cardThumb(it),
      el('span', { class: 'bm-t' }, el('b', {}, mentionMark(it.t, q.split(/\s+/)[0])), it.s && el('small', {}, it.s))),
    none: (q) => q ? 'Nothing matches “' + q + '”.' : 'No models, accessories or docs pages yet.',
    foot: () => ' to add · alone on its line it shows as a card',
    insert: (it, at, pos) => {
      const v = ta.value;
      let before = v.slice(0, at), after = v.slice(pos);
      const lineStart = before.lastIndexOf('\n') + 1, lineEnd = after.indexOf('\n');
      const alone = before.slice(lineStart).trim() === '' && (lineEnd < 0 ? after : after.slice(0, lineEnd)).trim() === '';
      let ins = cardLink(it);
      if (alone) {   // a card: a block of its own (cards on the lines right under each other sit side by side), the caret after a blank line
        before = before.slice(0, lineStart);
        after = lineEnd < 0 ? '' : after.slice(lineEnd + 1);
        const above = before.replace(/\n+$/, '').split('\n').pop().trim();
        if (CARD_LINE_RE.test(above)) before = before.replace(/\n+$/, '\n');   // right under the card above: the two sit side by side
        else if (before !== '' && !before.endsWith('\n\n')) before = before.replace(/\n?$/, '\n\n');
        const next = after.replace(/^\n+/, '');
        ins += CARD_LINE_RE.test(next.split('\n')[0].trim()) ? '\n' : '\n\n';
        after = next;
      } else if (!/^[\s,.;:!?)]/.test(after)) ins += ' ';
      return [before, ins, after];
    },
  });
}

// The Card button of the docs and blog toolbars (mdTools()): a window to search and tick models, accessories and docs pages; resolves to the
// ticked ones in the order they were ticked ([] = cancelled).
function cardPicker() {
  return new Promise((resolve) => {
    const picked = [];
    let kind = '';
    const search = el('input', { class: 'input', type: 'search', placeholder: 'Search models, accessories and docs pages…', 'aria-label': 'Search' });
    const chips = el('div', { class: 'cp-chips' });
    const list = el('div', { class: 'cp-list', role: 'listbox', 'aria-multiselectable': 'true' }, el('p', { class: 'muted small' }, 'Loading…'));
    const count = el('span', { class: 'muted small' });
    const go = el('button', { type: 'button', class: 'btn btn-primary btn-sm', disabled: true });
    const dlg = el('dialog', { class: 'g-modal cp-modal', 'aria-label': 'Add Cards' },
      el('div', { class: 'g-modal-head' }, el('h2', {}, 'Add Cards'), el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => done([]) }, '✕')),
      el('div', { class: 'g-modal-body' }, search, chips, list),
      el('div', { class: 'g-modal-foot' }, count, el('div', { class: 'row-btns' }, el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => done([]) }, 'Cancel'), go)));
    const done = (out) => { dlg.close(); dlg.remove(); resolve(out); };
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); done([]); });
    go.addEventListener('click', () => done(picked.slice()));
    const foot = () => {
      const n = picked.length;
      go.disabled = !n;
      go.textContent = n > 1 ? `Add ${n} Cards` : 'Add Card';
      count.textContent = n ? (n > 1 ? `${n} picked, added in the order you picked them` : '1 picked') : 'Tick the ones to add.';
    };
    let all = [];
    const draw = () => {
      const shown = cardFind(all, search.value).filter((i) => !kind || cardGroup(i) === kind);
      list.replaceChildren(...(shown.length ? shown.map((it) => {
        const box = el('input', { type: 'checkbox', checked: picked.includes(it), 'aria-label': it.t });
        const row = el('label', { class: 'cp-row' + (picked.includes(it) ? ' on' : '') }, box, cardThumb(it),
          el('span', { class: 'bm-t' }, el('b', {}, it.t), it.s && el('small', {}, it.s)), el('span', { class: 'cp-kind' }, it.k));
        box.addEventListener('change', () => {
          const i = picked.indexOf(it);
          if (box.checked && i < 0) picked.push(it); else if (!box.checked && i >= 0) picked.splice(i, 1);
          row.classList.toggle('on', box.checked);
          foot();
        });
        return row;
      }) : [el('p', { class: 'muted small' }, 'Nothing matches.')]));
    };
    search.addEventListener('input', draw);
    document.body.append(dlg);
    dlg.showModal();
    search.focus();
    foot();
    loadCards().then((items) => {
      all = items;
      const kinds = ['Models', 'Accessories', 'Docs'].filter((k) => all.some((i) => cardGroup(i) === k));
      if (kinds.length > 1) chips.replaceChildren(...['', ...kinds].map((k) => {
        const b = el('button', { type: 'button', class: 'chip' + (k === kind ? ' is-on' : ''), 'aria-pressed': String(k === kind) }, k || 'All');
        b.addEventListener('click', () => { kind = k; chips.querySelectorAll('.chip').forEach((c) => { const on = c === b; c.classList.toggle('is-on', on); c.setAttribute('aria-pressed', String(on)); }); draw(); });
        return b;
      }));
      draw();
    });
  });
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

// "S4…" (only while a Bucket Address is set in Settings → MEGA S4): the bucket's folders and files from admin/s4-files.php,
// one folder at a time, with a filter. One file (a link row's S4… button): a click picks it. multiple (the list's "+ Add from S4…"):
// tick boxes that stay ticked across folders, "Select all" for the files shown, and the files already in the list greyed out (have);
// done gets the chosen files [{key, size}] and, with multiple, who the new links are for
const pickS4 = ({ start = '', multiple = false, have = new Set(), done }) => {
  const sizeTxt = (b) => (b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.ceil(b / 1024)) + ' KB');
  const crumbs = el('div', { class: 's4-crumbs' });
  const filter = el('input', { class: 'input', type: 'search', placeholder: 'Filter this folder', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'Filter' });
  const list = el('div', { class: 's4-list' + (multiple ? ' is-multi' : '') });
  const note = el('p', { class: 'muted small s4-note' });
  const close = () => { dlg.close(); dlg.remove(); };
  const picked = new Map();   // key → file, in the order ticked (the new links come in that order)
  const all = el('input', { type: 'checkbox', 'aria-label': 'Select all files shown' });
  const allRow = el('label', { class: 's4-all', hidden: true }, all, el('span', {}, 'Select All Files Shown'));
  const count = el('span', { class: 's4-count muted small' });
  const clear = el('button', { type: 'button', class: 'linklike s4-clear', onclick: () => { picked.clear(); sync(); } }, 'Clear');
  const access = el('select', { class: 'input', 'aria-label': 'New links are for' }, el('option', { value: 'patrons' }, 'Patrons Only'), el('option', { value: 'public' }, 'Public'));
  const go = el('button', { type: 'button', class: 'btn btn-primary', disabled: true, onclick: () => { close(); done([...picked.values()], access.value); } });
  let rows = [], seq = 0;   // seq: only the folder asked for last is drawn
  const boxes = () => rows.filter((r) => r.querySelector('input:not(:disabled)') && !r.hidden).map((r) => r.querySelector('input'));
  // the tick boxes, "Select all", the count and the button follow `picked`
  const sync = () => {
    if (!multiple) return;
    rows.forEach((r) => { const b = r.querySelector('input'); if (b && !b.disabled) b.checked = picked.has(r.dataset.key); });
    const shown = boxes();
    allRow.hidden = !shown.length;
    all.checked = shown.length > 0 && shown.every((b) => b.checked);
    all.indeterminate = !all.checked && shown.some((b) => b.checked);
    const n = picked.size;
    count.textContent = n ? `${n} ${n === 1 ? 'file' : 'files'} selected` : 'Tick the files to add.';
    clear.hidden = !n;
    go.disabled = !n;
    go.textContent = n ? `Add ${n} ${n === 1 ? 'Link' : 'Links'}` : 'Add Links';
  };
  const show = () => {
    const words = foldWords(filter.value);
    let n = 0;
    rows.forEach((r) => { const ok = words.every((w) => r.dataset.name.includes(w)); r.hidden = !ok; if (ok) n++; });
    note.textContent = rows.length && !n ? 'Nothing matches the filter.' : note.dataset.base || '';
    sync();
  };
  all.addEventListener('change', () => { const on = all.checked; boxes().forEach((b) => { const r = b.closest('.s4-row'); if (on) picked.set(r.dataset.key, r.s4file); else picked.delete(r.dataset.key); }); sync(); });
  const fileRow = (f, prefix) => {
    const name = f.key.slice(prefix.length);
    const facts = [el('span', { class: 'muted small' }, sizeTxt(f.size)), el('span', { class: 'muted small' }, f.date)];
    if (!multiple) return el('button', { type: 'button', class: 's4-row', 'data-name': fold(name), onclick: () => { close(); done([f]); } },
      el('span', { class: 's4-ico', 'aria-hidden': 'true' }, '📄'), el('span', { class: 's4-name' }, name), ...facts);
    const had = have.has(f.key);
    const box = el('input', { type: 'checkbox', disabled: had, checked: had });
    box.addEventListener('change', () => { if (box.checked) picked.set(f.key, f); else picked.delete(f.key); sync(); });
    const r = el('label', { class: 's4-row' + (had ? ' had' : ''), 'data-name': fold(name), 'data-key': f.key, title: had ? 'Already in the download links' : null },
      box, el('span', { class: 's4-name' }, name, had ? el('span', { class: 's4-had' }, 'Already Added') : null), ...facts);
    r.s4file = f;
    return r;
  };
  const open = async (prefix) => {
    const mine = ++seq;
    list.replaceChildren(el('p', { class: 'muted small' }, 'Loading…'));
    rows = [];
    filter.value = '';
    note.dataset.base = '';
    note.textContent = '';
    sync();
    let d;
    try {
      const r = await fetch('/admin/s4-files.php?prefix=' + encodeURIComponent(prefix), { credentials: 'same-origin', cache: 'no-store' });
      d = await r.json();
      if (!d.ok) throw new Error(d.error || 'S4 didn’t answer.');
    } catch (err) { if (mine === seq) list.replaceChildren(el('p', { class: 'res-err small' }, err.message)); return; }
    if (mine !== seq || !dlg.isConnected) return;
    const parts = prefix.split('/').filter(Boolean);
    crumbs.replaceChildren(el('button', { type: 'button', class: 'linklike', onclick: () => open('') }, d.bucket),
      ...parts.flatMap((p, i) => [el('span', { class: 'muted' }, ' / '), el('button', { type: 'button', class: 'linklike', onclick: () => open(parts.slice(0, i + 1).join('/') + '/') }, p)]));
    rows = [
      ...d.folders.map((f) => el('button', { type: 'button', class: 's4-row is-folder', 'data-name': fold(f.slice(prefix.length)), onclick: () => open(f) },
        el('span', { class: 's4-ico', 'aria-hidden': 'true' }, '📁'), el('span', { class: 's4-name' }, f.slice(prefix.length, -1)), el('span'), el('span'))),
      ...d.files.map((f) => fileRow(f, prefix)),
    ];
    list.replaceChildren(...(rows.length ? rows : [el('p', { class: 'muted small' }, 'This folder is empty.')]));
    note.dataset.base = d.more ? 'Only the first files are shown: use a folder or the filter.' : '';
    show();
    filter.focus();
  };
  filter.addEventListener('input', show);
  const title = multiple ? 'Add from S4' : 'Choose from S4';
  const cancel = el('button', { type: 'button', class: 'btn btn-ghost', onclick: close }, 'Cancel');
  const body = el('div', { class: 'lc-dlg-body' },
    el('h2', null, title), crumbs, filter, multiple ? allRow : null, list, note,
    multiple
      ? el('div', { class: 'form-actions s4-foot' }, el('div', { class: 'act-left s4-picked' }, count, clear), el('label', { class: 's4-for' }, el('span', { class: 'muted small' }, 'For'), access), cancel, go)
      : el('div', { class: 'form-actions' }, cancel));
  const dlg = el('dialog', { class: 'lc-dlg s4-dlg', 'aria-label': title }, body);
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg && !picked.size) close(); });   // a click beside it never loses ticked files
  document.body.append(dlg);
  dlg.showModal();
  sync();
  open(start);
};
// the folder of an "s4:" address ('' = the bucket's top), where the S4 window starts
const s4Folder = (v) => { const m = String(v || '').trim().match(/^s4:\/*(.*\/)?[^/]*$/i); return m && m[1] ? m[1] : ''; };
// A full size link field with "S4…" after it (MEGA S4 on with a bucket: on): a file picked = "s4:<file>", announced with an input event.
// PHP pages print the same pair (showcase_full_input(): `.iw-dl-url` with `[data-s4-pick]`, wired below).
const s4Pick = (url) => pickS4({ start: s4Folder(url.value), done: ([f]) => { url.value = 's4:' + f.key; url.dispatchEvent(new Event('input', { bubbles: true })); url.focus(); } });
const s4Field = (url, on) => (on ? el('span', { class: 'iw-dl-url' }, url, el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Choose a file from your S4 bucket', onclick: () => s4Pick(url) }, 'S4…')) : url);
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-s4-pick]');
  const url = b && b.parentElement.querySelector('input');
  if (url) { e.preventDefault(); s4Pick(url); }
});

// The picture window's Full Size Download box (Details): the link behind the viewer's "Download Full Size" of a picture shown in a
// viewer (a Gallery picture, a model's gallery row, an accessory's pictures). state = image_info()'s `full` (full_size_state()).
// save(link) (the Images page): its own Save / Remove buttons, every place showing the picture at once; else onChange(link) on every
// edit (the editor's gallery card, saved with the editor). An empty address = no link.
function fullSizeBox(state, { save = null, onChange = null } = {}) {
  const l = state.link || {};
  const url = el('input', { class: 'input', type: 'url', value: l.url || '', placeholder: state.s4 ? 'https://… or s4:file' : 'https://…', maxlength: 1000 });
  const access = el('select', { class: 'input' }, el('option', { value: 'patrons' }, 'Patrons'), el('option', { value: 'public', selected: l.access === 'public' }, 'Everyone'));
  const size = el('input', { class: 'input', type: 'text', value: l.size || '', maxlength: 40, placeholder: 'e.g. 4K' });
  const value = () => (url.value.trim() ? { url: url.value.trim(), access: access.value, size: size.value.trim() } : null);
  const field = (label, input) => el('label', { class: 'field' }, el('span', { class: 'lbl' }, label), input);
  // no link yet: only the title and "+ Add", the fields open on a click
  const closed = !l.url && !state.mixed;
  const fields = el('div', { class: 'iw-dl-fields', hidden: closed },
    s4Field(url, state.s4),
    el('div', { class: 'grid2' }, field('Access', access), field('Size Label', size)));
  const add = closed ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm iw-dl-add', onclick: () => { fields.hidden = false; add.remove(); box.querySelector('.form-actions')?.removeAttribute('hidden'); url.focus(); } }, '+ Add') : null;
  url.setAttribute('aria-label', 'Full size link');
  const box = el('section', { class: 'iw-sec iw-dl' },
    el('h3', {}, 'Full Size Download', tip('A link to the full resolution file (pictures on the site are at most 2560 px). Visitors get a “Download Full Size” button in the viewer; the address itself never appears in the page, and every download is counted.'), add),
    fields,
    state.mixed ? el('p', { class: 'muted small' }, `The ${state.n} places showing it have different links: saving gives them all this one.`) : null,
    state.dls ? el('p', { class: 'muted small' }, `Downloaded ${state.dls} ${state.dls === 1 ? 'time' : 'times'}.`) : null);
  if (!save) {
    box.addEventListener('input', () => onChange?.(value()));
    box.addEventListener('change', () => onChange?.(value()));
    return box;
  }
  const send = async (link, btn) => {
    btn.disabled = true;
    try { await save(link); } catch { btn.disabled = false; }
  };
  const saveBtn = el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => {
    if (!url.value.trim()) { toast('Paste the link first.', 'err'); url.focus(); return; }
    send(value(), saveBtn);
  } }, 'Save Link');
  const remove = state.link ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => {
    if (confirm(state.n > 1 ? `Remove the full size link from the ${state.n} places showing this picture?` : 'Remove the full size link of this picture?')) send(null, remove);
  } }, 'Remove Link') : null;
  box.append(el('div', { class: 'form-actions', hidden: closed }, remove, saveBtn));
  return box;
}

// The Details tab of the picture window: what images.php says about a picture (image_info(): size, file, folder, date, what uses it, sizes made)
// + Copy link / Download. Shared by the Images page's window and cropImage(). extra = a box after Used By (fullSizeBox()).
function pictureFacts(d, url, extra = null) {
  const copy = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, 'Copy Link');
  copy.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(url); copy.textContent = 'Copied ✓'; setTimeout(() => { copy.textContent = 'Copy Link'; }, 1500); }
    catch { prompt('Copy this address:', url); }
  });
  const acts = el('div', { class: 'iw-acts' }, copy, el('a', { class: 'btn btn-ghost btn-sm', href: url, download: d?.name || '' }, 'Download'));
  if (!d) return [iwSection('Picture', el('p', { class: 'iw-none' }, url.startsWith('/media/') ? 'Its details couldn’t be loaded.' : 'This picture is on another website: copy it here (Media page) to crop it.'), acts), extra].filter(Boolean);
  // the sizes made = one line of widths, each opening that file
  const vers = d.vers?.length > 1 ? d.vers.map(([n, u, w], i) => [i ? ' · ' : '', el('a', { href: u, target: '_blank', rel: 'noopener', title: `${n}: open this size` }, String(w))]).flat().concat(' px') : null;
  return [
    iwSection('Picture', el('dl', { class: 'iw-facts' },
      el('dt', {}, 'Size'), el('dd', {}, `${d.w} × ${d.h} px`),
      el('dt', {}, 'File'), el('dd', {}, `${d.size} · ${({ WEBP: 'WebP', JPG: 'JPEG' })[d.type] || d.type}`),
      vers ? el('dt', {}, 'Sizes') : null, vers ? el('dd', { class: 'iw-vers' }, ...vers) : null,
      el('dt', {}, 'Folder'), el('dd', {}, d.folder),
      el('dt', {}, 'Added'), el('dd', {}, d.added)), acts),
    iwSection('Used By', d.uses?.length
      ? el('ul', { class: 'iw-uses' }, d.uses.map(([n, href, what]) => el('li', {}, el('a', { href }, n), what ? el('span', { class: 'what' }, ' · ' + what) : null)))
      : el('p', { class: 'iw-none' }, el('span', { class: 'warn-dot' }, 'Not used'), ' anywhere yet')),
    extra,
  ].filter(Boolean);
}
const pictureInfo = {};   // url → promise of its details (one request per picture and page)
const loadPictureInfo = (url) => (pictureInfo[url] ??= url.startsWith('/media/')
  ? fetch('/admin/images.php?info=' + encodeURIComponent(url), { credentials: 'same-origin' }).then((r) => r.json()).then((j) => (j.ok ? j : null)).catch(() => null)
  : Promise.resolve(null));

// ── Picture window outside the Images page (editors' image fields and gallery cards, Admin → Gallery): Details (pictureFacts()) and
// Crop & rotate (cropTools()); tab = the one it opens on; full: the Full Size Download box (fullSizeBox()) of the editor's gallery card. Resolves with {x, y, w, h, rotate, flip} for images.php action crop,
// 'skip' (Keep as is) or null (closed without saving).
function cropImage(src, { aspect = 0, title = 'Crop Image', skip = false, tab = 'crop', full = null } = {}) {
  return new Promise((resolve) => {
    let tools = null, mode = '', info;
    const fullEl = full && fullSizeBox(full.state, { onChange: full.onChange });   // full = {state, onChange}: the editor's gallery card (Details)
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
        body.replaceChildren(...pictureFacts(info, src, fullEl));
        if (info === undefined) body.replaceChildren(el('p', { class: 'muted small' }, 'Loading…'));
        foot.replaceChildren(ghost('Close', () => close(null)));   // Crop & Rotate is the tab above (Pi: no second button)
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
    try { await post('/admin/save.php', { kind: sortable.dataset.kind || 'models', action: 'reorder', slugs }); saved = slugs.join(); toast('✓ Order saved.'); }
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

// ── Folder pickers (Media page) ─────────────────────────────────────────────
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
  const SECTIONS = [['all', ''], ['model', 'Models'], ['outfit', 'Accessories'], ['scene', 'Scenes'],
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
    const words = foldWords(search.value);
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
    const last = fpRecent().map((v) => byValue.get(v)).find((i) => i && i.kind === kind && (kind === 'model' || kind === 'outfit' || kind === 'scene'));
    if (last) sel.value = last.value;
  }
  paintBtn();
  sel.fp = { set };
}
document.querySelectorAll('select[data-folder-pick]').forEach((s) => { if (!s.hidden) folderPicker(s); });

// ── Picture files dragged from the computer anywhere on the page (Admin → Gallery, Media): an overlay while over it, dropping
// calls onDrop(pictures). Only files: reordering by ⠿ uses pointer events, not drag and drop. While a window is open (the picker
// has its own drop area) nothing happens, but a file dropped next to it never opens in the tab. where: "added to the gallery". ──
function pageDrop({ hint, where, onDrop }) {
  let inPage = false;   // a picture or link dragged from this page itself (some browsers offer it as a file too)
  document.addEventListener('dragstart', () => { inPage = true; });
  document.addEventListener('dragend', () => { inPage = false; });
  const hasFiles = (e) => !inPage && [...(e.dataTransfer?.types || [])].includes('Files');
  const veil = el('div', { class: 'g-page-drop', hidden: true, 'aria-hidden': 'true' }, el('div', {}, el('b', {}, 'Drop Pictures to Upload'), el('span', {}, 'JPG, PNG, WebP, GIF · ' + hint)));
  document.body.append(veil);
  let veilOff = 0;
  const hideVeil = () => { clearTimeout(veilOff); veil.hidden = true; };
  document.addEventListener('dragover', (e) => {
    if (!hasFiles(e)) return;
    if (document.querySelector('dialog[open]')) {   // the picker's drop area took it already (defaultPrevented); anywhere else: no drop
      if (!e.defaultPrevented) { e.preventDefault(); e.dataTransfer.dropEffect = 'none'; }
      return;
    }
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    veil.hidden = false;
    clearTimeout(veilOff);   // dragover repeats while the pointer is over the page, even standing still: once it stops, the pointer left (or Esc)
    veilOff = setTimeout(hideVeil, 200);
  });
  document.addEventListener('drop', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    hideVeil();
    if (document.querySelector('dialog[open]')) return;
    const all = [...e.dataTransfer.files];
    const pics = all.filter((f) => DROP_IMG_TYPES.includes(f.type));
    const other = all.filter((f) => !pics.includes(f)).map((f) => f.name);
    if (other.length) toast((pics.length ? (other.length > 3 ? `${other.length} files` : other.join(', ')) + ' left out: only' : 'Only') + ` pictures (JPG, PNG, WebP, GIF) can be ${where}.`, 'err');
    if (pics.length) onDrop(pics);
  });
}

// ── Picture picker: one window to pick pictures from the Media library or upload new ones (every image field in the admin) ──
// pickImages({ multiple, folder, title, exclude, excludeLabel, note, files }) → Promise of the chosen addresses ([] = cancelled; .uploaded = new files).
// folder: where uploads go (a model's media folder, docs, faq…), also the library's first filter when it has pictures.
// files: pictures dropped outside the window (Admin → Gallery's page): it opens on Upload New and uploads them, as if dropped there.
// uploadOnly: no library tab, "Done" instead of "Add N Pictures", and it resolves to the uploaded addresses however it is closed (the Media page).
// exclude: addresses shown greyed out with excludeLabel (Admin → Gallery: pictures already in it). Library from admin/library.php.
let pickLib = null;   // the library, loaded once per page (an upload clears it)
function pickImages({ multiple = false, folder = 'misc', title = '', exclude = [], excludeLabel = 'Already Added', note = '', files: dropped = [], uploadOnly = false } = {}) {
  return new Promise((resolve) => {
    const LIB_MAX = 120;   // pictures drawn at once: a search or a folder narrows the rest
    const excluded = new Set(exclude);
    let chosen = [], files = [], queue = [], done = [], pane = 'library', lib = null;   // files: every one chosen; queue: the upload under way; done: addresses uploaded
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
    const cancel = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => close([]) }, 'Cancel');
    const libPane = el('div', { class: 'g-modal-body' },
      el('div', { class: 'g-lib-tools' }, libFolder, search, excluded.size ? el('label', { class: 'toggle' }, onlyNew, el('span', {}, 'Hide ' + excludeLabel)) : null),
      msg, grid, note ? el('p', { class: 'muted small g-lib-note' }, note) : null);
    const upPane = el('div', { class: 'g-modal-body', hidden: true }, el('div', { class: 'g-up' },
      el('label', { class: 'field' }, lbl('Upload Into'), upFolder), drop, prog.node, upMsg));
    const dlg = el('dialog', { class: 'g-modal', 'aria-label': title || 'Pictures' },
      el('div', { class: 'g-modal-head' }, el('h2', {}, title || (multiple ? 'Add Pictures' : 'Choose a Picture')), el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => close([]) }, '✕')),
      uploadOnly ? null : el('nav', { class: 'lib-tabs g-modal-tabs', 'aria-label': 'Where from' }, tabBtn('library', 'From the Media Library'), tabBtn('upload', 'Upload New')),
      libPane, upPane,
      el('div', { class: 'g-modal-foot' }, picked, el('div', { class: 'row-btns' }, cancel, go)));
    const close = (v) => {
      if (busy) return;
      files.forEach((f) => URL.revokeObjectURL(f.preview)); dlg.close(); dlg.remove();
      resolve(uploadOnly ? Object.assign([...done], { uploaded: true }) : v);
    };
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close([]); });
    const drawPicked = () => {
      const n = pane === 'library' ? chosen.length : busy ? files.length : done.length;
      const thumbs = pane === 'library' ? chosen.slice(0, 6).map((u) => el('img', { src: lib?.find((i) => i.url === u)?.small || u, alt: '' })) : files.slice(0, 6).map((f) => el('img', { src: f.preview, alt: '' }));
      picked.replaceChildren(...thumbs, n ? el('b', {}, multiple ? `${n} ${pane === 'upload' && !busy ? 'uploaded' : 'selected'}` : '') : el('span', { class: 'muted small' }, pane === 'library' ? (multiple ? 'Click pictures to pick them.' : 'Click a picture to pick it.') : (multiple ? 'Pictures upload as soon as you choose or drop them.' : 'The picture uploads as soon as you choose or drop it.')));
      go.disabled = !n || busy;
      go.hidden = pane === 'upload' && !busy && !done.length;   // uploads start by themselves
      go.textContent = uploadOnly ? 'Done' : !multiple ? 'Use This Picture' : n ? `Add ${n} ${n === 1 ? 'Picture' : 'Pictures'}` : 'Add Pictures';
      if (busy) go.textContent = 'Uploading…';
      cancel.hidden = uploadOnly && done.length > 0;   // they are in the library already: nothing to cancel
    };
    const drawLib = () => {
      if (!lib) return;
      const words = foldWords(search.value);
      const match = lib.filter((i) => (!libFolder.value || i.folder === libFolder.value || (i.in || []).includes(libFolder.value)) && !(onlyNew.checked && excluded.has(i.url)) && words.every((w) => fold(i.label).includes(w)));
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
      msg.textContent = !match.length ? (lib.length ? 'No pictures match.' : 'The Media library is empty: upload new pictures instead.') : `Showing ${LIB_MAX} of ${match.length}: search or pick a folder to see the others.`;
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
      if (!multiple) { files.forEach((f) => URL.revokeObjectURL(f.preview)); files = []; done = []; imgs.splice(1); }
      if (!busy) queue = [];
      for (const f of imgs) { files.push(Object.assign(f, { preview: URL.createObjectURL(f) })); queue.push(f); }   // during an upload: they join its queue
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
      const urls = await prog.run(queue, where);
      busy = false;
      drop.classList.remove('is-busy');
      upFolder.disabled = false;
      files = files.filter((f) => !urls.failed.includes(f) || (URL.revokeObjectURL(f.preview), false));   // the thumbnails show only what was uploaded
      done.push(...urls);
      if (urls.length) { fpRemember(where); pickLib = null; }
      // the window stays open: it shows what was uploaded, and "Add N Pictures" / "Use This Picture" puts them in
      if (done.length) prog.finish(`✓ ${done.length} ${done.length === 1 ? 'picture' : 'pictures'} uploaded` + (urls.failed.length ? ` · ${urls.failed.length} failed` : ''));
      else prog.hide();
      upMsg.textContent = done.length ? '' : 'Nothing was uploaded. Try again.';
      drawPicked();
      if (done.length && pane === 'upload') go.focus();
    }
    go.addEventListener('click', () => {
      if (pane === 'library') close(chosen);
      else if (done.length && !busy) close(Object.assign([...done], { uploaded: true }));
    });
    document.body.append(dlg);
    dlg.showModal();
    if (dropped.length || uploadOnly) setPane('upload');
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
      if (dropped.length && dlg.isConnected) addFiles(dropped);   // once the Upload Into menu is ready (it is locked while uploading)
    })();
  });
}

// ── Videos in texts (app/video.php): "Add a Video" checks the file here, before it is sent (size, format, whether every browser plays
// it, whether it starts at once), then uploads it with a cover (a frame of the video drawn on a canvas, or a picture of the library) to
// admin/upload-video.php, which checks it again (m3d_video_read(): videoInfo() reads the same headers). ──
const VIDEO_CODECS = {   // the same as VIDEO_CODECS in app/video.php: codec => [name, plays in every current browser]
  avc1: ['H.264', true], avc3: ['H.264', true], hvc1: ['H.265 (HEVC)', false], hev1: ['H.265 (HEVC)', false],
  av01: ['AV1', false], vp09: ['VP9', true], mp4v: ['MPEG-4 Part 2', false], V_VP8: ['VP8', true], V_VP9: ['VP9', true], V_AV1: ['AV1', false],
};
const VIDEO_AUDIO_OK = ['', 'AAC', 'Opus', 'Vorbis', 'MP3'];
const fmtMB = (b) => Math.max(0.1, b / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';   // "24.8 MB", "50 MB" (m3d_mb() without the ".0")
const fmtDur = (s) => { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = String(s % 60).padStart(2, '0'); return h ? `${h}:${String(m).padStart(2, '0')}:${x}` : `${m}:${x}`; };

// {type: 'mp4' | 'webm', w, h, dur, codec, plays, audio, fast}; throws an Error saying what is wrong (like m3d_video_read())
async function videoInfo(file) {
  const buf = async (pos, len) => file.slice(pos, pos + len).arrayBuffer();
  const str = (u8, o, n) => String.fromCharCode(...u8.subarray(o, o + n));
  const head = new Uint8Array(await buf(0, 12));
  if (head.length >= 8 && str(head, 4, 4) === 'ftyp') {
    let pos = 0, moov = null, mdatFirst = false;
    while (pos + 8 <= file.size) {
      const h = new DataView(await buf(pos, 16));
      if (h.byteLength < 8) break;
      let len = h.getUint32(0), hdr = 8;
      const type = str(new Uint8Array(h.buffer), 4, 4);
      if (len === 1) { len = Number(h.getBigUint64(8)); hdr = 16; } else if (len === 0) len = file.size - pos;
      if (len < hdr) throw new Error('The file is damaged.');
      if (type === 'mdat' && !moov) mdatFirst = true;
      if (type === 'moov') {
        if (len > 64 * 1048576) throw new Error('The file is damaged.');
        moov = new Uint8Array(await buf(pos + hdr, len - hdr));
        break;
      }
      pos += len;
    }
    if (!moov) throw new Error('The video’s index (moov) is missing: the file is damaged or still being written.');
    const boxes = (d) => {   // [type, body] of the boxes in d
      const out = [], dv = new DataView(d.buffer, d.byteOffset, d.byteLength);
      for (let p = 0; p + 8 <= d.length;) {
        let l = dv.getUint32(p), hd = 8;
        if (l === 1) { l = Number(dv.getBigUint64(p + 8)); hd = 16; } else if (l === 0) l = d.length - p;
        if (l < hd) break;
        out.push([str(d, p + 4, 4), d.subarray(p + hd, p + l)]);
        p += l;
      }
      return out;
    };
    const find = (d, path) => { for (const [t, b] of boxes(d)) if (t === path[0]) return path.length === 1 ? b : find(b, path.slice(1)); return null; };
    const u32 = (b, o) => new DataView(b.buffer, b.byteOffset + o, 4).getUint32(0);
    const out = { type: 'mp4', w: 0, h: 0, dur: 0, codec: '', plays: false, audio: '', fast: !mdatFirst };
    for (const [t, b] of boxes(moov)) {
      if (t === 'mvhd' && b.length >= 32) {
        const v1 = b[0] === 1;
        const scale = u32(b, v1 ? 20 : 12), dur = v1 ? Number(new DataView(b.buffer, b.byteOffset + 24, 8).getBigUint64(0)) : u32(b, 16);
        if (scale) out.dur = dur / scale;
      }
      if (t !== 'trak') continue;
      const hdlr = find(b, ['mdia', 'hdlr']);
      const kind = hdlr && hdlr.length >= 12 ? str(hdlr, 8, 4) : '';
      const stsd = find(b, ['mdia', 'minf', 'stbl', 'stsd']);
      const fourcc = stsd && stsd.length >= 16 ? str(stsd, 12, 4) : '';
      if (kind === 'vide' && !out.codec) {
        [out.codec, out.plays] = VIDEO_CODECS[fourcc] || [fourcc.trim() || 'unknown', false];
        const tk = find(b, ['tkhd']);
        if (tk && tk.length >= 84) {
          const w = Math.round(u32(tk, tk.length - 8) / 65536), h = Math.round(u32(tk, tk.length - 4) / 65536);
          const turned = u32(tk, tk.length - 44) === 0 && u32(tk, tk.length - 40) !== 0;   // a phone filming upright: a quarter turn
          [out.w, out.h] = turned ? [h, w] : [w, h];
        }
      } else if (kind === 'soun' && !out.audio) {
        out.audio = { mp4a: 'AAC', Opus: 'Opus', 'ac-3': 'AC-3', 'ec-3': 'E-AC-3', '.mp3': 'MP3', alac: 'ALAC' }[fourcc] || fourcc.trim() || 'unknown';
      }
    }
    if (!out.codec) throw new Error('There is no video in this file.');
    return out;
  }
  if (head.length >= 4 && head[0] === 0x1A && head[1] === 0x45 && head[2] === 0xDF && head[3] === 0xA3) {
    const d = new Uint8Array(await buf(0, 4 * 1048576));
    let p = 0, scale = 1000000, durRaw = null, doc = '';
    const out = { type: 'webm', w: 0, h: 0, dur: 0, codec: '', plays: false, audio: '', fast: true };
    const vint = (keep) => {   // an EBML number; null = unknown size
      if (p >= d.length) return 0;
      const b = d[p];
      let len = 1;
      while (len <= 8 && !(b & (0x80 >> (len - 1)))) len++;
      if (len > 8) throw new Error('The file is damaged.');
      let v = keep ? b : b & ((0x80 >> (len - 1)) - 1), all = v === (0x80 >> (len - 1)) - 1;
      for (let i = 1; i < len; i++) { const c = d[p + i] ?? 0; v = v * 256 + c; all = all && c === 0xFF; }
      p += len;
      return !keep && all ? null : v;
    };
    const uint = (a, b) => { let n = 0; for (let i = a; i < b; i++) n = n * 256 + d[i]; return n; };
    const float = (a, b) => (b - a === 4 ? new DataView(d.buffer, a, 4).getFloat32(0) : b - a === 8 ? new DataView(d.buffer, a, 8).getFloat64(0) : 0);
    const walk = (end, track, depth = 0) => {
      if (depth > 6) return;   // these elements nest 4 deep at most: anything deeper is a damaged file
      while (p < end && p < d.length) {
        const id = vint(true), size = vint(false), start = p, stop = size === null ? end : Math.min(end, p + size);
        if ([0x1A45DFA3, 0x18538067, 0x1549A966, 0x1654AE6B, 0xE0].includes(id)) { walk(stop, track, depth + 1); if (p === Infinity) return; }
        else if (id === 0xAE) {
          const t = { type: 0, codec: '', w: 0, h: 0, dw: 0, dh: 0 };
          walk(stop, t, depth + 1);
          if (t.type === 1 && !out.codec) { [out.codec, out.plays] = VIDEO_CODECS[t.codec] || [t.codec || 'unknown', false]; [out.w, out.h] = t.dw && t.dh ? [t.dw, t.dh] : [t.w, t.h]; }
          else if (t.type === 2 && !out.audio) out.audio = { A_OPUS: 'Opus', A_VORBIS: 'Vorbis' }[t.codec] || t.codec || 'unknown';
        }
        else if (id === 0x4282) doc = str(d, start, stop - start);
        else if (id === 0x2AD7B1) scale = uint(start, stop) || 1000000;
        else if (id === 0x4489) durRaw = float(start, stop);
        else if (track && id === 0x83) track.type = uint(start, stop);
        else if (track && id === 0x86) track.codec = str(d, start, stop - start);
        else if (track && id === 0xB0) track.w = uint(start, stop);
        else if (track && id === 0xBA) track.h = uint(start, stop);
        else if (track && id === 0x54B0) track.dw = uint(start, stop);
        else if (track && id === 0x54BA) track.dh = uint(start, stop);
        else if (id === 0x1F43B675) { p = Infinity; return; }   // Cluster: the pictures start, everything needed was before
        if (size === null && id !== 0x18538067) return;
        p = stop;
      }
    };
    walk(d.length, null);
    if (doc !== 'webm') throw new Error('This is a Matroska (.mkv) file, not a WebM video: export as MP4 (H.264) or WebM.');
    if (durRaw !== null) out.dur = (durRaw * scale) / 1e9;
    if (!out.codec) throw new Error('There is no video in this file.');
    return out;
  }
  throw new Error('This is not an MP4 or WebM video.');
}

// The checks shown in "Add a Video": [{k: ok | bad | warn, t: text, h: hint}]; a "bad" one stops the upload
function videoChecks(file, info, max) {
  const out = [];
  const limit = fmtMB(max.bytes);
  if (file.size > max.bytes) out.push({ k: 'bad', t: `${fmtMB(file.size)}: over the ${limit} limit` + (max.why ? ` (${max.why})` : ''), h: 'Export a shorter or lighter copy: 1080p H.264 at about 6 Mbit/s keeps a minute near 45 MB.' });
  else out.push({ k: 'ok', t: `${fmtMB(file.size)}: small enough` });
  const sound = info.audio ? `, ${info.audio} sound` : ', no sound';
  if (info.plays) out.push({ k: 'ok', t: `${info.type === 'mp4' ? 'MP4' : 'WebM'} · ${info.codec} video${sound}: plays in every browser` });
  else out.push({ k: 'warn', t: `${info.codec} video: ` + (/HEVC/.test(info.codec) ? 'doesn’t play in Firefox and in Chrome on many computers' : /AV1/.test(info.codec) ? 'older iPhones and Macs can’t play it' : 'may not play in every browser'), h: 'Export as MP4 with H.264 video (in Blender: Output → FFmpeg Video, Container MPEG-4, Video Codec H.264).' });
  if (!VIDEO_AUDIO_OK.includes(info.audio)) out.push({ k: 'warn', t: `${info.audio} sound: most browsers play the video silent`, h: 'Export the sound as AAC.' });
  if (info.type === 'mp4') out.push(info.fast ? { k: 'ok', t: 'Starts playing at once (no wait for the whole file)' }
    : { k: 'warn', t: 'Its index is at the end of the file: visitors wait longer before it starts', h: 'Export with “Web Optimized” (HandBrake) or “Fast Start” on.' });
  return out;
}

// The cover part of the video windows: a frame of the video (a slider picks the moment), or a picture of the library, or none.
// src = the video's address (an object URL of the chosen file, or /media/… for a saved one). state(): {mode: 'frame' | 'pic' | 'none', pic};
// blob(): the frame as a picture file (WebP, or PNG where the browser can't make WebP). onChange() after every change.
function videoCover(src, { folder = 'blog', current = '', onChange = () => {} } = {}) {
  const vid = el('video', { preload: 'auto', playsinline: true, muted: true });
  vid.muted = true;
  const canvas = el('canvas', { class: 'vd-canvas' });
  const img = el('img', { class: 'vd-canvas', alt: '', hidden: true });
  const none = el('span', { class: 'vd-none', hidden: true }, 'No Cover');
  const thumb = el('div', { class: 'vd-thumb' }, canvas, img, none);
  const range = el('input', { type: 'range', min: '0', max: '1', step: '0.04', value: '0', 'aria-label': 'Frame for the cover', disabled: true });
  const at = el('span', { class: 'muted small vd-at' }, 'Loading frames…');
  const pick = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, 'Choose Picture…');
  const useFrame = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', hidden: true }, 'Use a Frame');
  const noCover = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, 'No Cover');
  let mode = current ? 'pic' : 'frame', pic = current, frames = false, seekTo = null, seeking = false, note = 'Loading frames…';
  const draw = () => {
    canvas.hidden = mode !== 'frame'; img.hidden = mode !== 'pic'; none.hidden = mode !== 'none';
    if (mode === 'pic') img.src = pic;
    range.disabled = !frames;
    range.hidden = !frames && note !== 'Loading frames…';   // can't be shown here: only a picture
    useFrame.hidden = mode === 'frame' || !frames;
    noCover.hidden = mode === 'none';
    at.textContent = mode === 'pic' ? (pic === current ? 'The current cover' : 'A picture') : !frames ? note : mode === 'frame' ? 'Frame at ' + fmtDur(+range.value) : 'The video’s first frame shows';
    onChange();
  };
  const seek = (t) => {   // one seek at a time: the slider moves faster than the video
    if (seeking) { seekTo = t; return; }
    seeking = true;
    vid.currentTime = t;
  };
  vid.addEventListener('seeked', () => {
    const w = vid.videoWidth, h = vid.videoHeight, k = Math.min(1, 1920 / Math.max(w, h));
    if (w && h) {
      canvas.width = Math.round(w * k); canvas.height = Math.round(h * k);
      canvas.getContext('2d').drawImage(vid, 0, 0, canvas.width, canvas.height);
    }
    seeking = false;
    if (seekTo !== null) { const t = seekTo; seekTo = null; seek(t); }
  });
  vid.addEventListener('loadedmetadata', () => {
    frames = true;
    range.max = String(Math.max(0.04, vid.duration || 0));
    range.value = String(Math.min(3, (vid.duration || 0) * 0.1));
    seek(+range.value);
    draw();
  });
  vid.addEventListener('error', () => {   // a codec this browser can't play (H.265 on many computers): only a picture can be the cover
    frames = false;
    if (mode === 'frame') mode = 'none';
    note = 'This browser can’t show the video’s frames: choose a picture for the cover.';
    draw();
  });
  range.addEventListener('input', () => { mode = 'frame'; seek(+range.value); draw(); });
  pick.addEventListener('click', async () => {
    const [u] = await pickImages({ folder, title: 'Cover for the Video' });
    if (u) { mode = 'pic'; pic = u; draw(); }
  });
  useFrame.addEventListener('click', () => { mode = 'frame'; seek(+range.value); draw(); });
  noCover.addEventListener('click', () => { mode = 'none'; draw(); });
  vid.src = src;
  draw();
  return {
    thumb,
    row: el('div', { class: 'vd-cover' }, el('b', {}, 'Cover'), range, at, el('span', { class: 'row-btns' }, useFrame, pick, noCover)),
    state: () => ({ mode: mode === 'frame' && !frames ? 'none' : mode, pic }),
    blob: () => new Promise((res) => canvas.toBlob((b) => res(b), 'image/webp', 0.9)),
    stop: () => { vid.removeAttribute('src'); vid.load(); },
  };
}

// "Add a Video": resolves to the text line ("![caption](/media/blog/name.mp4)") or null. file = one dropped on the text (else chosen here).
// max = {bytes, why} (m3d_video_max(): the 50 MB limit, or the server's when lower).
function videoDialog({ folder = 'blog', file = null, max = { bytes: 50 * 1048576, why: '' } } = {}) {
  return new Promise((resolve) => {
    let busy = false, cover = null, url = '', checks = [];
    const input = el('input', { type: 'file', accept: 'video/mp4,video/webm,.mp4,.webm,.m4v,.mov', hidden: true });
    const drop = el('label', { class: 'g-drop' }, input, el('b', {}, 'Choose a Video'), el('span', { class: 'muted small' }, `or drop it here · MP4 or WebM, up to ${fmtMB(max.bytes)}`));
    const name = el('div', { class: 'vd-name' });
    const sub = el('div', { class: 'muted small' });
    const fill = el('span', { class: 'up-prog-fill' });
    const meterTx = el('span', { class: 'up-prog-tx' });
    const meter = el('div', { class: 'up-prog vd-meter' }, el('span', { class: 'up-prog-bar' }, fill), meterTx);
    const head = el('div', { class: 'vd-file' }, el('div', { class: 'vd-thumb' }), el('div', { class: 'vd-facts' }, name, sub, meter));
    const list = el('ul', { class: 'vd-checks' });
    const caption = el('input', { class: 'input', maxlength: '200', placeholder: 'Shown under the video (optional)' });
    const capField = el('label', { class: 'field' }, lbl('Caption'), caption);
    const coverSlot = el('div');
    const info = el('div', { class: 'vd-info', hidden: true }, head, list, coverSlot, capField);
    const body = el('div', { class: 'g-modal-body vd-body' }, drop, info);
    const again = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', hidden: true, onclick: () => input.click() }, 'Choose Another File…');
    const cancel = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => close(null) }, 'Cancel');
    const go = el('button', { type: 'button', class: 'btn btn-primary btn-sm', disabled: true }, 'Upload and Add');
    const dlg = el('dialog', { class: 'g-modal vd', 'aria-label': 'Add a Video' },
      el('div', { class: 'g-modal-head' }, el('h2', {}, 'Add a Video'), el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => close(null) }, '✕')),
      body, el('div', { class: 'g-modal-foot' }, el('span'), el('div', { class: 'row-btns' }, again, cancel, go)));
    const close = (v) => { if (busy) return; cover?.stop(); if (url) URL.revokeObjectURL(url); dlg.close(); dlg.remove(); resolve(v); };
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(null); });
    let chosen = null;
    const take = async (f) => {
      if (!f || busy) return;
      cover?.stop();
      if (url) URL.revokeObjectURL(url);
      url = ''; cover = null; chosen = f;
      drop.hidden = true; info.hidden = false; again.hidden = false;
      name.textContent = f.name;
      sub.textContent = 'Reading the file…';
      list.replaceChildren(); coverSlot.replaceChildren(); head.firstChild.replaceWith(el('div', { class: 'vd-thumb' }));
      const pct = Math.min(100, (100 * f.size) / max.bytes);
      fill.style.width = pct + '%';
      meter.classList.toggle('is-over', f.size > max.bytes);
      meterTx.replaceChildren(el('span', {}, fmtMB(f.size)), el('span', {}, 'Limit ' + fmtMB(max.bytes)));
      let vi = null;
      try { vi = await videoInfo(f); }
      catch (e) { checks = [{ k: 'bad', t: e.message }]; }
      if (chosen !== f) return;   // another file was chosen meanwhile
      if (vi) {
        checks = videoChecks(f, vi, max);
        sub.textContent = [vi.w && vi.h ? `${vi.w} × ${vi.h}` : '', vi.dur ? fmtDur(vi.dur) : ''].filter(Boolean).join(' · ');
        url = URL.createObjectURL(f);
        cover = videoCover(url, { folder, onChange: () => {} });
        head.firstChild.replaceWith(cover.thumb);
        coverSlot.replaceChildren(cover.row);
      } else sub.textContent = '';
      list.replaceChildren(...checks.flatMap((c) => [el('li', { class: 'vd-' + c.k }, el('b', { 'aria-hidden': 'true' }, { ok: '✓', bad: '✕', warn: '!' }[c.k]), el('span', {}, c.t)), c.h ? el('li', { class: 'vd-hint' }, c.h) : []]));
      go.disabled = !vi || checks.some((c) => c.k === 'bad');
      if (!go.disabled && !caption.value) caption.focus();
    };
    input.addEventListener('change', () => { take(input.files[0]); input.value = ''; });
    for (const t of [drop, body]) {
      t.addEventListener('dragover', (e) => { if ([...(e.dataTransfer?.types || [])].includes('Files')) { e.preventDefault(); drop.classList.add('over'); } });
      t.addEventListener('dragleave', () => drop.classList.remove('over'));
      t.addEventListener('drop', (e) => { if (!e.dataTransfer?.files.length) return; e.preventDefault(); drop.classList.remove('over'); take(e.dataTransfer.files[0]); });
    }
    go.addEventListener('click', async () => {
      if (!chosen || go.disabled) return;
      const st = cover ? cover.state() : { mode: 'none' };
      busy = true; go.disabled = true; again.disabled = true; cancel.disabled = true;
      go.textContent = 'Uploading…';
      meter.classList.remove('is-over');
      try {
        const extra = st.mode === 'frame' ? { cover: await cover.blob() } : {};
        const d = await sendFile('/admin/upload-video.php', chosen, folder, (sent, done) => {
          fill.style.width = Math.round((100 * sent) / chosen.size) + '%';
          meter.classList.toggle('busy', done);
          meterTx.replaceChildren(el('span', {}, done ? 'Checking and saving…' : `Uploading · ${Math.round((100 * sent) / chosen.size)}%`));
        }, extra);
        if (st.mode === 'pic') {
          try { await sendFile('/admin/upload-video.php', null, folder, null, { do: 'cover', video: d.url, from: st.pic }); }
          catch (e) { toast('The video was saved, but its cover couldn’t be: ' + e.message, 'err'); }
        }
        if (d.warn) toast(d.warn, 'err');
        busy = false;
        const cap = caption.value.replace(/[\[\]()\n\r]/g, ' ').replace(/\s+/g, ' ').trim();
        close(`![${cap}](${d.url})`);
      } catch (e) {
        busy = false; go.disabled = false; again.disabled = false; cancel.disabled = false;
        go.textContent = 'Upload and Add';
        meter.classList.remove('busy');
        meterTx.replaceChildren(el('span', {}, fmtMB(chosen.size)), el('span', {}, 'Limit ' + fmtMB(max.bytes)));
        toast(e.message, 'err');
      }
    });
    document.body.append(dlg);
    dlg.showModal();
    if (file) take(file);
  });
}

// "Change Cover…" of a saved video (Media → Videos): a frame, a picture or none, saved at once (do=cover). Resolves true when changed.
function videoCoverDialog(video, current) {
  return new Promise((resolve) => {
    let busy = false;
    const cover = videoCover(video, { current, folder: video.split('/')[2] || 'blog' });
    const save = el('button', { type: 'button', class: 'btn btn-primary btn-sm' }, 'Save Cover');
    const cancel = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => close(false) }, 'Cancel');
    const dlg = el('dialog', { class: 'g-modal vd', 'aria-label': 'Video Cover' },
      el('div', { class: 'g-modal-head' }, el('h2', {}, 'Video Cover'), el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => close(false) }, '✕')),
      el('div', { class: 'g-modal-body vd-body' }, el('div', { class: 'vd-file' }, cover.thumb, el('div', { class: 'vd-facts' }, el('div', { class: 'vd-name' }, video.split('/').pop()))), cover.row),
      el('div', { class: 'g-modal-foot' }, el('span'), el('div', { class: 'row-btns' }, cancel, save)));
    const close = (v) => { if (busy) return; cover.stop(); dlg.close(); dlg.remove(); resolve(v); };
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(false); });
    save.addEventListener('click', async () => {
      const st = cover.state();
      busy = true; save.disabled = cancel.disabled = true; save.textContent = 'Saving…';
      try {
        const extra = { do: 'cover', video };
        if (st.mode === 'frame') extra.cover = await cover.blob();
        else if (st.mode === 'pic') extra.from = st.pic;
        else extra.remove = '1';
        await sendFile('/admin/upload-video.php', null, '', null, extra);
        busy = false;
        close(true);
      } catch (e) { busy = false; save.disabled = cancel.disabled = false; save.textContent = 'Save Cover'; toast(e.message, 'err'); }
    });
    document.body.append(dlg);
    dlg.showModal();
  });
}

// ── Image library: filter, upload, copy address, delete ──────────────────────
const lib = document.querySelector('[data-lib]');
// Upload Images (and picture files dropped anywhere on the page) open the upload window (pickImages(), "Upload Into" inside it) on the
// folder the page shows: the folder filter's, else the one uploaded into last when it is of the tab's kind (model / accessory), else
// the tab's own first choice ([data-upload-folder], hidden); the page reloads with the new pictures once it is closed
const libUpload = document.querySelector('[data-upload-open]');
if (libUpload && !libUpload.closest('[hidden]')) {   // not on the 3D / Videos tabs
  const upSel = document.querySelector('[data-upload-folder]');
  const has = (v) => [...upSel.options].some((o) => o.value === v);
  const kind = (v) => (v.startsWith('outfit-') ? 'outfit' : v.startsWith('scene-') ? 'scene' : [...upSel.querySelectorAll('optgroup[label="Models"] option')].some((o) => o.value === v) ? 'model' : 'other');
  const first = upSel.value;
  const last = fpRecent().find((v) => has(v) && kind(v) === kind(first) && kind(v) !== 'other');
  if (last) upSel.value = last;
  const openUpload = async (files = []) => {
    const f = document.querySelector('[data-lib-folder]')?.value.replace(/:.*/, '');   // a docs page / FAQ question: its folder
    const urls = await pickImages({ multiple: true, uploadOnly: true, title: 'Upload Images', folder: f && has(f) ? f : upSel.value, files });
    if (urls.length) location.reload();
  };
  libUpload.addEventListener('click', () => openUpload());
  pageDrop({ hint: 'they upload into the folder you pick', where: 'uploaded here', onDrop: openUpload });
}
if (lib) {
  const cards = [...lib.querySelectorAll('.lib-card')];
  const search = document.querySelector('[data-lib-search]');
  const folder = document.querySelector('[data-lib-folder]');
  const unused = document.querySelector('[data-lib-unused]');
  const empty = document.querySelector('[data-lib-empty]');
  const pager = document.querySelector('[data-lib-pager]');
  const tabs = [...document.querySelectorAll('[data-lib-tab]')];
  const PER = 60;   // 2 to 6 to a row: full rows on every screen
  let page = Math.max(1, parseInt(new URLSearchParams(location.search).get('page'), 10) || 1);
  let shown = cards;   // every card the search and filters match, on every page (the picture window goes through them)
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
    const words = foldWords(search.value);
    // the search box: file name and where it is used (every word, accents ignored); the menu: any of the card's groups
    const match = cards.filter((c) => words.every((w) => c.foldedName.includes(w)) && (!folder.value || c.dataset.folder.split(' ').includes(folder.value)) && (!unused.checked || c.dataset.used === '0'));
    shown = match;
    const pages = Math.max(1, Math.ceil(match.length / PER));
    page = Math.min(page, pages);
    const on = new Set(match.slice((page - 1) * PER, page * PER));
    for (const c of cards) c.hidden = !on.has(c);
    const n = match.length;
    empty.hidden = n > 0;
    renderPager(pages);
    const u = new URL(location.href);
    if (page > 1) u.searchParams.set('page', page); else u.searchParams.delete('page');
    if (unused.checked) u.searchParams.set('unused', '1'); else u.searchParams.delete('unused');
    history.replaceState(null, '', u);
    // the tabs count what Unused Only shows, and take it along to the other tabs
    for (const a of tabs) {
      const t = new URL(a.href);
      if (unused.checked) t.searchParams.set('unused', '1'); else t.searchParams.delete('unused');
      a.href = t;
      const n = a.querySelector('[data-all]');
      n.textContent = unused.checked ? n.dataset.unused : n.dataset.all;
    }
  }
  [search, folder, unused].forEach((x) => x.addEventListener('input', () => apply()));
  apply(true);   // the first page (or ?page=), and ?unused=1 (kept across the tabs)
  const info = JSON.parse(document.getElementById('lib-info')?.textContent || '{}');
  const delCard = (card) => {   // only unused ones can be deleted: one less in both counts of its tab
    card.remove(); cards.splice(cards.indexOf(card), 1);
    const n = document.querySelector('[data-lib-tab][aria-current] [data-all]');
    if (n) { n.dataset.all = Math.max(0, n.dataset.all - 1); n.dataset.unused = Math.max(0, n.dataset.unused - 1); }
    apply(true);
  };
  lib.addEventListener('click', async (e) => {
    const card = e.target.closest('.lib-card');
    if (!card) return;
    const open = e.target.closest('[data-open]');
    if (open && !(e.ctrlKey || e.metaKey || e.shiftKey || e.button)) { e.preventDefault(); imageWindow(card); }   // ctrl / middle click: a new tab, as before
    if (e.target.closest('[data-copy]')) {
      try { await navigator.clipboard.writeText(card.dataset.url); toast('✓ Address copied.'); }
      catch { prompt('Copy this address:', card.dataset.url); }
    }
    if (e.target.closest('[data-crop]')) imageWindow(card, 'crop');
    if (e.target.closest('[data-v3d]')) preview3d(card.dataset.url, card.querySelector('.lib-name').textContent);   // the 3D Files tab
    if (e.target.closest('[data-video-cover]') && await videoCoverDialog(card.dataset.url, card.dataset.cover || '')) location.reload();   // the Videos tab
    if (e.target.closest('[data-del]')) {
      if (!confirm(`Delete ${card.dataset.url.split('/').pop()}? This can't be undone.`)) return;
      try { await post('/admin/images.php', { action: 'delete', url: card.dataset.url }); delCard(card); toast(card.matches('.lib-3d') ? '✓ File deleted.' : card.matches('.lib-video') ? '✓ Video deleted.' : '✓ Image deleted.'); }
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
      const at = cur.dataset.url;
      const full = d.full ? fullSizeBox(d.full, { save: async (link) => {   // every place showing the picture at once
        try {
          const r = await post('/admin/images.php', { action: 'fullsize', url: at, link: link || { url: '' } });
          d.full = r.full;
          if (cur.dataset.url !== at || mode !== 'details') return;
          details();
          say(link ? '✓ Link saved.' : '✓ Link removed.', 'ok');
        } catch (err) { say(err.message); throw err; }
      } }) : null;
      body.replaceChildren(...pictureFacts(d, cur.dataset.url, full));
      const del = el('button', { type: 'button', class: 'btn btn-ghost btn-sm iw-del', disabled: used() > 0, title: used() ? 'In use: remove it there first' : 'Delete this picture' }, 'Delete');
      del.addEventListener('click', async () => {
        if (!confirm(`Delete ${d.name}? This can't be undone.`)) return;
        try {
          await post('/admin/images.php', { action: 'delete', url: cur.dataset.url });
          const gone = cur, list = shown.includes(cur) ? shown : cards;
          const after = list[list.indexOf(gone) + 1] || list[list.indexOf(gone) - 1];
          delCard(gone);
          if (after && after !== gone) { cur = after; render(); say('✓ Image deleted.', 'ok'); } else { close(); toast('✓ Image deleted.'); }
        } catch (err) { say(err.message); }
      });
      foot.replaceChildren(del);
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
    if (c) imageWindow(c, 'details', '✓ Saved.');
    else toast('✓ Saved in another tab.');   // e.g. an unused copy of a picture from another folder
  }
}

// ── Media → Find Duplicates (admin/duplicates.php): the first visit reads the pictures a share at a time, then the groups:
// chips, "Keep Selected, Remove the Others" (confirm, then the group goes) and "Not Duplicates" ──
const dupScan = document.querySelector('[data-dup-scan]');
if (dupScan) (async () => {
  const tx = dupScan.querySelector('[data-dup-tx]'), fill = dupScan.querySelector('.up-prog-fill');
  let last = -1, still = 0;   // a share that reads nothing new twice in a row (the cache folder isn't writable): stop instead of asking forever
  for (let fails = 0; fails < 3;) {
    try {
      const r = await post('/admin/duplicates.php', { action: 'scan' });
      tx.textContent = `${r.done} of ${r.total}`;
      fill.style.width = Math.round(r.done / Math.max(1, r.total) * 100) + '%';
      if (r.done >= r.total) { location.reload(); return; }
      still = r.done > last ? 0 : still + 1;
      last = r.done;
      if (still >= 2) { toast(`Reading stopped with ${r.total - r.done} ${r.total - r.done === 1 ? 'picture' : 'pictures'} left: the server couldn’t keep what it read. Reload to try again.`, 'err'); return; }
      fails = 0;
    } catch (e) { if (++fails >= 3) toast(e.message, 'err'); }
  }
})();
const dupList = document.querySelector('[data-dups]');
if (dupList) {
  const chips = [...document.querySelectorAll('[data-dup-chip]')];
  const none = document.querySelector('[data-dup-none]');
  const show = (k) => {
    chips.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.dupChip === k)));
    dupList.querySelectorAll('[data-dup]').forEach((g) => { g.hidden = k !== '' && (k === 'gallery' ? g.dataset.dupGallery !== '1' : g.dataset.dup !== k); });
  };
  chips.forEach((c) => c.addEventListener('click', () => show(c.dataset.dupChip)));
  // a group done: it goes, the chips' numbers follow (a chip left empty goes, back to All)
  const gone = (g) => {
    g.remove();
    const left = [...dupList.querySelectorAll('[data-dup]')];
    for (const c of chips) {
      const k = c.dataset.dupChip;
      const n = left.filter((x) => k === '' || (k === 'gallery' ? x.dataset.dupGallery === '1' : x.dataset.dup === k)).length;
      c.querySelector('span').textContent = n;
      if (k !== '' && !n) { if (c.getAttribute('aria-pressed') === 'true') show(''); c.remove(); }
    }
    if (!left.length) { document.querySelector('[data-dup-chips]')?.remove(); none.hidden = false; }
  };
  dupList.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-dup-merge], [data-dup-ok]');
    if (!b) return;
    const g = b.closest('[data-dup]');
    const items = JSON.parse(g.dataset.items), keep = g.querySelector('input[type=radio]:checked')?.value;
    const merge = b.matches('[data-dup-merge]'), others = items.length - 1;
    if (merge && !confirm(g.dataset.dup === 'gallery'
      ? `Remove the other ${others === 1 ? 'copy' : others + ' copies'} from the Gallery? The one kept gets their characters and likes.`
      : `Keep the selected picture and delete the other ${others === 1 ? 'file' : others + ' files'}? Every place using ${others === 1 ? 'it' : 'them'} will show the kept one. This can’t be undone.`)) return;
    g.querySelectorAll('button').forEach((x) => (x.disabled = true));
    try {
      const r = await post('/admin/duplicates.php', { action: merge ? 'merge' : 'ok', kind: g.dataset.dup, items, keep });
      toast(r.msg || '✓ Marked as not duplicates.');
      gone(g);
    } catch (err) { g.querySelectorAll('button').forEach((x) => (x.disabled = false)); toast(err.message, 'err'); }
  });
}

// ── Key fields (key_field() in _admin.php): a saved webhook / secret / key shows locked; Change opens the field, Cancel or Esc locks it
// again with its saved value (a secret's field is empty: the server keeps the saved one). keyField() gives an editor's field the same look. ──
/** A random password (an item's page password, its download links' passwords): three groups of four, lowercase letters and digits that can't be mixed up when read out ("k7mq-x3rt-p9ae"). */
function randomPassword() {
  const abc = 'abcdefghjkmnpqrstuvwxyz23456789';
  const out = [];
  while (out.length < 12) {
    for (const b of crypto.getRandomValues(new Uint8Array(16))) if (b < 248 && out.length < 12) out.push(abc[b % abc.length]);   // 248 = 8 × 31: no letter more likely than another
  }
  return [0, 4, 8].map((i) => out.slice(i, i + 4).join('')).join('-');
}
// A download link's password (the lock in its row, initEditor's links()): the password field (a random one when it has none yet), New Password, Copy;
// Save hands the new value to done(), Remove Password hands ''. Nothing is stored until the editor is saved.
function linkPwWindow(name, value, done) {
  const input = el('input', { class: 'input', type: 'text', value: value || randomPassword(), minlength: 4, maxlength: 64, required: true, autocomplete: 'off', spellcheck: 'false', 'aria-label': 'Password' });
  const copy = el('button', { type: 'button', class: 'btn btn-ghost' }, 'Copy');
  copy.addEventListener('click', () => copyText(input.value.trim(), copy));
  const f = el('form', { class: 'lc-dlg-body pw-dlg' },
    el('h2', {}, 'Download Password'),
    el('p', { class: 'muted pw-dlg-sub' }, name),
    el('div', { class: 'pw-dlg-row' }, input, el('button', { type: 'button', class: 'btn btn-ghost', onclick: () => { input.value = randomPassword(); input.focus(); } }, 'New Password'), copy),
    el('div', { class: 'form-actions' },
      value ? el('button', { type: 'button', class: 'btn btn-ghost act-left', onclick: () => { close(); done(''); } }, 'Remove Password') : null,
      el('button', { type: 'button', class: 'btn btn-ghost', onclick: () => close() }, 'Cancel'),
      el('button', { type: 'submit', class: 'btn btn-primary' }, 'Save')));
  const dlg = el('dialog', { class: 'lc-dlg', 'aria-label': 'Download Password' }, f);
  const close = () => { dlg.close(); dlg.remove(); };
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = input.value.trim();
    if (v.length < 4) return toast('The password needs at least 4 characters.', 'err');
    close();
    if (v !== value) done(v);
  });
  document.body.append(dlg);
  dlg.showModal();
  input.select();
}
const KEY_LOCK_ICON = '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>';   // KEY_LOCK_ICON in _admin.php
function keyFieldSet(sf, open, focus = true) {
  const input = sf.querySelector(':scope > input'), btn = sf.querySelector('[data-sf-btn]'), name = btn.getAttribute('aria-label').replace(/^\S+ /, '');
  sf.classList.toggle('is-locked', !open);
  btn.textContent = open ? 'Cancel' : 'Change';
  btn.setAttribute('aria-label', (open ? 'Cancel ' : 'Change ') + name);
  if (open) { if (focus) input.focus(); return; }
  if (input.value !== input.defaultValue) { input.value = input.defaultValue; input.dispatchEvent(new Event('input', { bubbles: true })); }
  if (focus) btn.focus();
}
// an editor's field (the item password): locked like key_field() prints it once `saved`; returns the function that locks it (again) after a save
function keyField(input, label, saved) {
  let sf = null;
  const lock = () => {
    if (!sf) {
      sf = el('span', { class: 'sf', 'data-sf': '' });
      input.replaceWith(sf);
      const box = el('span', { class: 'sf-lock' }, el('span', { class: 'sf-dots', 'aria-label': 'Hidden', text: '••••••••••' }), el('span', { class: 'sf-ok', text: '✓ Saved' }));
      box.insertAdjacentHTML('afterbegin', `<svg viewBox="0 0 24 24" aria-hidden="true">${KEY_LOCK_ICON}</svg>`);
      sf.append(box, input, el('button', { type: 'button', class: 'btn btn-ghost btn-sm sf-btn', 'data-sf-btn': '', 'aria-label': 'Change ' + label, text: 'Change' }));
    }
    input.value = input.defaultValue = '';
    keyFieldSet(sf, false, false);
  };
  if (saved) lock();
  return lock;
}
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-sf-btn]');
  if (!btn) return;
  e.preventDefault();   // inside a <label>
  const sf = btn.closest('[data-sf]');
  keyFieldSet(sf, sf.classList.contains('is-locked'));
});
document.addEventListener('keydown', (e) => {
  const sf = e.key === 'Escape' && e.target.matches?.('[data-sf] > input') ? e.target.parentElement : null;
  if (!sf || sf.classList.contains('is-locked')) return;
  e.preventDefault();
  keyFieldSet(sf, false);
});

// ── Editor ────────────────────────────────────────────────────────────────────
const form = document.querySelector('[data-editor]');
if (form) {
  const data = JSON.parse(document.getElementById('editor-data').textContent);
  if (data.kind === 'faq') initFaqEditor(data);
  else if (data.kind === 'docs' || data.kind === 'tools') initDocsEditor(data);   // a tool's tutorials use the docs editor (Admin → Tools)
  else if (data.kind === 'blog') initBlogEditor(data);
  else initEditor(data);
}

// ── A long text in the md_html() format (Docs pages, blog posts): Write / Preview tabs, the formatting toolbar, Ctrl+B / I / K ──
// body = its textarea; folder = where the toolbar's Image button uploads; onEdit = called after the toolbar changed the text
// video: {bytes, why} (m3d_video_max()) = the text takes videos (blog posts, docs pages): a Video button, and videos dropped on the text open "Add a Video"
function mdTools(body, { folder = 'misc', imgTitle = 'Pictures', onEdit = () => {}, paywall = false, video = null } = {}) {
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
  const addVideo = async (file, at) => {
    const line = await videoDialog({ folder, file, max: video });
    if (!line) return;
    if (at !== undefined) body.setSelectionRange(at, at);
    block(body, line);
    onEdit();
  };
  imageDrop(body, folder, onEdit, { video: video ? addVideo : null });   // pictures dropped on or pasted into the text
  const link = () => { const url = prompt('Link address (https://… or a site page like /docs/requirements/):'); if (url) wrap(body, '[', `](${url.trim()})`, 'link text'); };
  body.addEventListener('keydown', (e) => {
    if (!(e.ctrlKey || e.metaKey)) return;
    if (e.key === 'b') { e.preventDefault(); wrap(body, '**', '**', 'bold text'); }
    if (e.key === 'i') { e.preventDefault(); wrap(body, '*', '*', 'italic text'); }
    if (e.key === 'k') { e.preventDefault(); link(); }
  });
  const imgBtn = el('button', { type: 'button', class: 'tb', title: 'Pictures from the Media library or uploaded (several = side-by-side gallery); pictures can also be dropped on or pasted into the text', onclick: async () => {
    const urls = await pickImages({ multiple: true, folder, title: imgTitle });
    if (urls.length) { block(body, urls.map((u) => `![](${u})`).join('\n')); onEdit(); }
  } }, 'Image');
  const tb = (label, title, fn) => el('button', { type: 'button', class: 'tb', title, onclick: fn }, label);
  // a carousel: the picture lines selected in the text, else pictures picked in the library (two or more)
  const carouselBtn = tb('Carousel', 'Carousel: one picture at a time with ‹ › and dots (selected picture lines go inside, else pick the pictures)', async () => {
    const sel = body.value.slice(body.selectionStart, body.selectionEnd).trim();
    const pics = sel.split('\n').filter((l) => /^!\[[^\]]*\]\([^)\s]+\)$/.test(l.trim()));
    const lines = pics.length >= 2 ? sel : (await pickImages({ multiple: true, folder, title: imgTitle })).map((u) => `![](${u})`).join('\n');
    if (!lines) return;
    block(body, `::: carousel\n${lines}\n:::`);
    onEdit();
  });
  const cardBtn = tb('Card', 'Cards of models, accessories or docs pages (or type @ in the text)', async () => {
    const its = await cardPicker();
    if (its.length) { block(body, its.map(cardLink).join('\n')); onEdit(); }
  });
  const toolbar = el('div', { class: 'dp-toolbar', role: 'toolbar', 'aria-label': 'Formatting' },
    tb('B', 'Bold (Ctrl+B)', () => wrap(body, '**', '**', 'bold text')),
    tb('I', 'Italic (Ctrl+I)', () => wrap(body, '*', '*', 'italic text')),
    tb('Link', 'Link (Ctrl+K)', link),
    tb('H2', 'Heading', () => lines(body, (l) => '## ' + l.replace(/^#+\s*/, ''))),
    tb('H3', 'Smaller heading', () => lines(body, (l) => '### ' + l.replace(/^#+\s*/, ''))),
    tb('• List', 'Bullet list', () => lines(body, (l) => '- ' + l.replace(/^(-|\d+\.)\s+/, ''))),
    tb('1. Steps', 'Numbered steps', () => { let n = 0; lines(body, (l) => `${++n}. ` + l.replace(/^(-|\d+\.)\s+/, '')); }),
    boxMenu(() => body),
    tb('Code', 'Code block', () => replaceSel(body, (t) => ['\n```\n' + (t || 'code') + '\n```\n'])),
    tb('Table', 'Table', () => block(body, '| Column | Column |\n|---|---|\n| Text | Text |')),
    tb('Expandable', 'Expandable box: folded until clicked (the selected text goes inside)', () => {
      const t = body.value.slice(body.selectionStart, body.selectionEnd).trim();
      block(body, `+++ Title\n${t || 'Text shown when the box is opened'}\n+++`);
      const at = body.value.lastIndexOf('+++ Title\n', body.selectionStart) + 4;
      body.setSelectionRange(at, at + 5);   // the title selected, ready to type
    }),
    tb('Tabs', 'Tabs: two or more ways to do the same steps (the selected text goes in the first tab)', () => {
      const t = body.value.slice(body.selectionStart, body.selectionEnd).trim();
      block(body, `=== Manual\n${t || '1. First step'}\n=== With MustardUI\n1. First step\n===`);
    }),
    imgBtn,
    carouselBtn,
    video ? tb('Video', `A video (MP4 or WebM, up to ${fmtMB(video.bytes)}), checked before it uploads; videos can also be dropped on the text`, () => addVideo(null)) : null,
    cardBtn,
    paywall ? tb('Patreon', 'Patrons only from here: everyone reads the text above this line, patrons the rest', () => {
      if (/^[ ]{0,3}-{3,}[ ]*patreon[ ]*-{3,}[ ]*$/im.test(body.value)) return toast('The post already has a Patreon line: the patrons’ part starts there.', 'err');
      block(body, '--- Patreon ---');
    }) : null);

  const preview = el('div', { class: 'md docs-body dp-preview', hidden: true });
  const tabWrite = el('button', { type: 'button', class: 'dp-tab active' }, 'Write');
  const tabPrev = el('button', { type: 'button', class: 'dp-tab' }, 'Preview');
  const show = async (prev) => {
    tabWrite.classList.toggle('active', !prev); tabPrev.classList.toggle('active', prev);
    body.hidden = prev; toolbar.hidden = prev; preview.hidden = !prev;
    if (prev) {
      preview.textContent = 'Loading…';
      try { preview.innerHTML = (await post('/admin/preview.php', { text: body.value, paywall })).html || '<p class="muted">Nothing written yet.</p>'; } catch (e) { preview.textContent = e.message; }
    }
  };
  tabWrite.addEventListener('click', () => show(false));
  tabPrev.addEventListener('click', () => show(true));
  return { tabs: el('div', { class: 'dp-tabs' }, tabWrite, tabPrev), toolbar, preview };
}

// ── Docs editor: sections and pages on the left, the selected page on the right ──
function initDocsEditor(data) {
  // data.kind 'tools' = a tool's tutorials (Admin → Tools → Tutorials, app/tools.php): groups for sections, tutorials for pages, at data.base
  // (/tools/<slug>/), with a level, Start Here, a cover and a video; saved as save.php kind "tool-tuts"
  const T = data.kind === 'tools';
  const W = T ? { page: 'tutorial', Page: 'Tutorial', sec: 'group', Sec: 'Group', pages: 'tutorials' } : { page: 'page', Page: 'Page', sec: 'section', Sec: 'Section', pages: 'pages' };
  const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');
  const docs = data.docs;
  docs.sections = docs.sections || [];
  let dirty = false;
  let edits = 0;   // counts changes: a save clears "unsaved" only when nothing changed while it ran
  const mark = () => { dirty = true; edits++; };
  let cur = null;   // { s: section index, p: page index }
  const allPages = () => docs.sections.flatMap((x) => x.pages);
  const uniqueSlug = (base, except) => {
    const used = new Set(allPages().filter((p) => p !== except).map((p) => p.slug));
    let s = base || 'page', n = 2;
    while (used.has(s)) s = `${base || 'page'}-${n++}`;
    return s;
  };
  // the docs home (/docs/) is the first page that isn't a draft
  const homePage = () => (T ? null : allPages().find((p) => !p.draft) || allPages()[0]);
  const base = T ? data.base : '/docs/';
  const urlOf = (p) => (homePage() === p ? base : `${base}${p.slug}/`);
  const addr = (p) => (T ? `?id=${encodeURIComponent(data.id)}&tut=${encodeURIComponent(p.id)}` : `?page=${encodeURIComponent(p.slug)}`);
  const viewLink = document.querySelector('[data-view-page]');

  const tree = el('nav', { class: 'dt', 'aria-label': 'Pages' });
  const pane = el('div', { class: 'dp' });
  form.append(tree, pane);

  const select = (s, p) => {
    cur = { s, p };
    renderTree(); renderPane();
    const page = docs.sections[s].pages[p];
    history.replaceState(null, '', addr(page));
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
    if (!confirm(n ? `Delete this ${W.sec} and its ${n} ${n > 1 ? W.pages : W.page}?` : `Delete this empty ${W.sec}?`)) return;
    const page = currentPage();
    docs.sections.splice(s, 1);
    cur = page ? locate(page) : null;
    mark(); renderTree(); renderPane();
  };
  const addSec = () => {
    docs.sections.push({ title: `New ${W.sec}`, text: '', pages: [] });
    mark(); renderTree(); renderPane();   // the open page's Section menu lists the new one
    const inputs = tree.querySelectorAll('.dt-sec-title');
    inputs[inputs.length - 1].select();
  };
  const addPage = (s) => {
    const page = { slug: uniqueSlug(`new-${W.page}`), title: `New ${W.page}`, summary: '', body: '', isNew: true, ...(T ? { id: newId() } : {}) };
    docs.sections[s].pages.push(page);
    mark();
    select(s, docs.sections[s].pages.length - 1);
    pane.querySelector('input').select();
  };

  function renderTree() {
    tree.replaceChildren(
      ...docs.sections.map((sec, si) => {
        const title = el('input', { class: 'input dt-sec-title', type: 'text', value: sec.title, placeholder: `${W.Sec} title`, maxlength: 80, 'aria-label': `${W.Sec} title` });
        const line = T ? el('input', { class: 'input dt-sec-text', type: 'text', value: sec.text || '', placeholder: 'One line under it (optional)', maxlength: 160, 'aria-label': 'Line under the group title' }) : null;
        line?.addEventListener('input', () => { sec.text = line.value; mark(); });
        title.addEventListener('input', () => { sec.title = title.value; mark(); const opt = pane.querySelector(`option[value="${si}"]`); if (opt) opt.textContent = title.value || 'Untitled Section'; });
        const pages = sec.pages.map((p, pi) => el('li', { class: 'dt-page' + (cur && cur.s === si && cur.p === pi ? ' active' : ''), 'data-pi': pi },
          dragHandle((h) => h.closest('.dt-page'), (item) => reorder(sec, 'pages', item.parentNode, '.dt-page', 'pi', item)),
          el('button', { type: 'button', class: 'dt-link', onclick: () => select(si, pi) }, p.title || `Untitled ${W.Page}`, homePage() === p ? el('span', { class: 'dt-home' }, 'Home') : null, p.start ? el('span', { class: 'dt-home' }, '★ Start') : null, p.draft ? el('span', { class: 'dt-draft' }, 'Draft') : null)));
        return el('div', { class: 'dt-sec', 'data-si': si },
          el('div', { class: 'dt-sec-head' }, dragHandle((h) => h.closest('.dt-sec'), (item) => reorder(docs, 'sections', item.parentNode, '.dt-sec', 'si', item)), title,
            iconBtn('✕', `Delete ${W.sec}`, () => delSec(si), 'del')),
          line,
          pages.length ? el('ul', { class: 'dt-pages' }, pages) : el('p', { class: 'muted small dt-empty' }, `No ${W.pages} yet.`),
          el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => addPage(si) }, `+ ${W.Page}`));
      }),
      el('button', { type: 'button', class: 'btn btn-ghost btn-sm add dt-add-sec', onclick: addSec }, `+ ${W.Sec}`));
  }

  function renderPane() {
    const page = currentPage();
    if (!page) {
      pane.replaceChildren(el('p', { class: 'muted dp-empty' }, `Select a ${W.page} on the left, or add one with + ${W.Page}.`));
      viewLink.href = base;
      return;
    }
    viewLink.href = urlOf(page);
    const title = el('input', { class: 'input', type: 'text', value: page.title, maxlength: 120, required: true });
    const slug = el('input', { class: 'input', type: 'text', value: page.slug, maxlength: 64, pattern: '[a-z0-9][a-z0-9\\-]*', spellcheck: 'false' });
    const sum = el('input', { class: 'input', type: 'text', value: page.summary || '', maxlength: 300, placeholder: T ? 'One line under the title, also used by search engines' : 'One line under the title, also used in the docs home and by search engines' });
    const secSel = el('select', { class: 'input' }, docs.sections.map((sec, si) => el('option', { value: si, selected: si === cur.s }, sec.title || `Untitled ${W.Sec}`)));
    let slugTouched = !page.isNew;
    title.addEventListener('input', () => {
      page.title = title.value;
      // only before the page's first save: a saved page keeps its address (isNew goes on save)
      if (!slugTouched && page.isNew) { page.slug = uniqueSlug(slugify(title.value, 60) || 'page', page); slug.value = page.slug; }
      tree.querySelector('.dt-page.active .dt-link').firstChild.textContent = title.value || `Untitled ${W.Page}`;
    });
    slug.addEventListener('input', () => { slugTouched = true; page.slug = slug.value.trim(); });
    slug.addEventListener('change', () => { page.slug = slugify(slug.value, 60) || uniqueSlug('page', page); slug.value = page.slug; viewLink.href = urlOf(page); history.replaceState(null, '', addr(page)); });
    sum.addEventListener('input', () => (page.summary = sum.value));
    const draft = el('input', { type: 'checkbox', checked: !!page.draft });
    draft.addEventListener('change', () => { page.draft = draft.checked; mark(); renderTree(); viewLink.href = urlOf(page); });
    secSel.addEventListener('change', () => {
      const to = +secSel.value;
      docs.sections[cur.s].pages.splice(cur.p, 1);
      docs.sections[to].pages.push(page);
      mark(); select(to, docs.sections[to].pages.length - 1);
    });

    const body = el('textarea', { class: 'input dp-body', spellcheck: 'true', maxlength: 200000, placeholder: `Write the ${W.page} here. See "How to format pages" above.` });
    body.value = page.body || '';
    body.addEventListener('input', () => (page.body = body.value));
    const { tabs, toolbar, preview } = mdTools(body, { folder: T ? data.folder : 'docs', imgTitle: `Pictures for This ${W.Page}`, onEdit: mark, video: data.video });
    const extra = T ? tutFields(page) : null;

    const isHome = homePage() === page;
    pane.replaceChildren(
      el('div', { class: 'dp-fields' },
        el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Title'), title),
        el('label', { class: 'field' }, el('span', { class: 'lbl' }, W.Sec), secSel),
        el('label', { class: 'field' }, lbl('Address', isHome ? 'The first non-draft page: shown at /docs/.' : ''), el('span', { class: 'prefixed' }, el('span', { class: 'muted' }, base), slug, el('span', { class: 'muted' }, '/'))),
        el('label', { class: 'field check dp-draft' }, draft, el('span', { class: 'lbl' }, 'Draft')),
        el('label', { class: 'field dp-wide' }, el('span', { class: 'lbl' }, 'Summary'), sum),
        extra),
      tabs, toolbar, body, preview,
      el('div', { class: 'dp-foot' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm dp-del', onclick: () => {
          if (!confirm(`Delete the ${W.page} "${page.title}"? It is removed when you save.`)) return;
          docs.sections[cur.s].pages.splice(cur.p, 1);
          cur = null; mark(); renderTree(); renderPane();
        } }, `Delete ${W.Page}`)));
  }

  // a tutorial's own fields: level, Start Here (one per tool), cover picture, video (a file of this site or an https link, optional)
  function tutFields(page) {
    const level = el('select', { class: 'input' }, el('option', { value: '' }, 'None'), Object.entries(data.levels).map(([k, v]) => el('option', { value: k, selected: page.level === k }, v)));
    level.addEventListener('change', () => { page.level = level.value; mark(); });
    const start = el('input', { type: 'checkbox', class: 'switch', checked: !!page.start });
    start.addEventListener('change', () => { allPages().forEach((p) => { if (p !== page) delete p.start; }); page.start = start.checked; mark(); renderTree(); });
    const cover = el('input', { class: 'input', type: 'text', value: page.cover || '', maxlength: 1000, placeholder: '/media/… (optional)', spellcheck: 'false' });
    const coverPrev = el('div', { class: 'img-prev' });
    const showCover = () => coverPrev.replaceChildren(cover.value.trim() ? el('img', { src: cover.value.trim(), alt: '' }) : el('span', { class: 'muted small' }, 'No Image'));
    cover.addEventListener('input', () => { page.cover = cover.value.trim(); showCover(); });
    showCover();
    const pickCover = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: async () => {
      const url = (await pickImages({ folder: data.folder, title: 'Cover Picture' }))[0];
      if (url) { cover.value = url; page.cover = url; showCover(); mark(); }
    } }, 'Choose…');
    const clearCover = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { cover.value = ''; page.cover = ''; showCover(); mark(); } }, 'Remove');
    const video = el('input', { class: 'input', type: 'text', value: page.video || '', maxlength: 1000, placeholder: '/media/….mp4 or https://… (optional)', spellcheck: 'false' });
    video.addEventListener('input', () => (page.video = video.value.trim()));
    const upVideo = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: async () => {
      const line = await videoDialog({ folder: data.folder, max: data.video });
      const url = line && (line.match(/\]\(([^)]+)\)/) || [])[1];
      if (url) { video.value = url; page.video = url; mark(); }
    } }, 'Upload…');
    return el('div', { class: 'tut-extra' },
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Level'), level),
      el('label', { class: 'toggle' }, start, el('span', {}, 'Start Here')),
      el('div', { class: 'field image-field' }, el('span', { class: 'lbl' }, 'Cover Picture'), el('div', { class: 'img-row' }, coverPrev, el('div', { class: 'img-ctl' }, cover, el('div', { class: 'row-btns' }, pickCover, clearCover)))),
      el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Video'), el('div', { class: 'tut-video-row' }, video, upVideo)));
  }

  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  guardLeave(() => dirty);

  saveButton(async () => {
    const out = { sections: docs.sections.map((sec) => ({ title: sec.title.trim(), ...(T ? { text: (sec.text || '').trim() } : {}), pages: sec.pages.map((p) => ({ slug: p.slug, title: p.title.trim(), summary: (p.summary || '').trim(), body: p.body || '', draft: !!p.draft,
      ...(T ? { id: p.id, level: p.level || '', start: !!p.start, cover: p.cover || '', video: p.video || '' } : {}) })) })) };
    const at = edits;
    const sent = allPages();   // pages added while it saves keep following their title
    data.rev = (await post('/admin/save.php', T ? { kind: 'tool-tuts', id: data.id, docs: out, rev: data.rev } : { kind: 'docs', docs: out, rev: data.rev })).rev;
    sent.forEach((p) => delete p.isNew);
    if (edits === at) dirty = false;
    toast(T ? '✓ Tutorials saved.' : '✓ Docs saved.');
    const page = currentPage();
    if (page) viewLink.href = urlOf(page);
  });

  // open the page from ?page=…, otherwise the first one
  let start = null;
  docs.sections.forEach((sec, si) => sec.pages.forEach((p, pi) => { if (p.slug === data.page) start = { s: si, p: pi }; }));
  if (!start && allPages().length) start = { s: docs.sections.findIndex((x) => x.pages.length), p: 0 };
  cur = start;
  renderTree(); renderPane();
  if (T && data.addNew) {   // "+ Add Tutorial" on the tool's page
    if (!docs.sections.length) docs.sections.push({ title: 'Tutorials', text: '', pages: [] });
    addPage(docs.sections.length - 1);
  }
}

// ── Blog editor: the posts on the left (newest first), the open post on the right. Each post is saved on its own (save.php
// kind "blog" with the post's revision); rules in app/blog.php, checks in clean_post() (_admin.php): keep the maxlengths in step.
function initBlogEditor(data) {
  const BLOG_VIS = { public: 'Public', patrons: 'Patrons Only', draft: 'Draft' };
  const BLOG_ICONS = {
    public: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    patrons: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    draft: '<path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/>',
  };
  const BLOG_HINT = {
    public: 'Everyone can read it (up to a “--- Patreon ---” line, if the text has one).',
    patrons: 'Everyone sees the title, summary, cover and first paragraph (or the text above a “--- Patreon ---” line); patrons on a paid tier read the rest.',
    draft: 'Only admins can see it.',
  };
  const itemBy = Object.fromEntries(data.items.map((it) => [it.ref, it]));
  const loaded = Date.now();
  // now in site time, as the date field writes it ("2026-10-20T18:05"): the server's clock when the page was made + the time since
  const siteNow = () => new Date(Date.parse(data.now + ':00Z') + Date.now() - loaded).toISOString().slice(0, 16);
  const viewLink = document.querySelector('[data-view-page]');
  const saveBtn = document.querySelector('[data-save]');
  let cur = null;     // the open post: a copy being edited (id '' = not saved yet)
  let dirty = false;
  let edits = 0;      // a save clears "unsaved" only when nothing changed while it ran
  const mark = () => { dirty = true; edits++; };
  let q = '';

  const side = el('nav', { class: 'dt', 'aria-label': 'Posts' });
  const pane = el('div', { class: 'dp' });
  form.append(side, pane);
  const search = el('input', { class: 'input ba-search', type: 'search', placeholder: 'Search posts…', 'aria-label': 'Search posts' });
  search.addEventListener('input', (e) => { e.stopPropagation(); q = search.value; renderList(); });   // not a change to the post
  const list = el('ul', { class: 'ba-list' });
  side.append(el('div', { class: 'dt-sec' },
    el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => open(null) }, '+ New Post'), search, list));

  const fresh = () => ({ id: '', slug: '', title: '', local: '', summary: '', cover: '', body: '', topics: [], items: [], visibility: 'draft', pinned: false, discord: !!data.discord, state: 'draft', isNew: true });
  const copy = (p) => ({ ...fresh(), visibility: 'public', discord: false, ...structuredClone(p), isNew: false });   // what a saved post leaves out = its default
  const pills = (p) => [
    p.state === 'draft' ? el('i', { class: 'ba-pill' }, 'Draft') : null,
    p.state === 'scheduled' ? el('i', { class: 'ba-pill sched' }, 'Scheduled') : null,
    p.visibility === 'patrons' ? el('i', { class: 'ba-pill lock' }, 'Patrons') : null,
    p.pinned ? el('i', { class: 'ba-pill pin' }, 'Pinned') : null].filter(Boolean);
  const fmt = (local) => { const d = local ? new Date(local + ':00Z') : null; return d && !isNaN(d) ? d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }) : ''; };
  const thumb = (p) => (p.thumb ? el('img', { src: p.thumb, alt: '', loading: 'lazy' }) : el('span', { class: 'ba-noimg', 'aria-hidden': 'true' }));

  function renderList() {
    const words = foldWords(q);
    const rows = data.posts.filter((p) => words.every((w) => fold([p.title, p.slug, ...(p.topics || [])].join(' ')).includes(w)))
      .map((p) => el('li', {}, el('button', { type: 'button', class: 'ba-item' + (cur && cur.id === p.id ? ' active' : ''), onclick: () => open(p.id) },
        thumb(p), el('span', {}, el('b', {}, p.title || 'Untitled Post'), el('span', {}, ...pills(p), fmt(p.local))))));
    if (cur && !cur.id) rows.unshift(el('li', {}, el('button', { type: 'button', class: 'ba-item active' }, el('span', { class: 'ba-noimg' }),
      el('span', {}, el('b', {}, cur.title || 'New Post'), el('span', {}, el('i', { class: 'ba-pill' }, 'Not Saved'))))));
    list.replaceChildren(...rows);
    if (!rows.length) list.append(el('li', { class: 'muted small dt-empty' }, data.posts.length ? 'No posts found.' : 'No posts yet.'));
  }

  function open(id) {
    if (dirty && !confirm('Leave this post? Your changes to it are not saved.')) return;
    const p = id ? data.posts.find((x) => x.id === id) : null;
    cur = p ? copy(p) : fresh();
    dirty = false;
    history.replaceState(null, '', p ? `?post=${encodeURIComponent(p.id)}` : location.pathname);
    renderList(); renderPane();
    if (innerWidth < 1000 && id !== undefined) pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (!p) pane.querySelector('input')?.focus();
  }
  function close() {   // no post open: the pane only says how to write one
    cur = null; dirty = false;
    history.replaceState(null, '', location.pathname);
    renderList(); renderPane();
  }

  // chips with suggestions (Topics, Models and Accessories): list = the values, options() = [{v, label, sub, pic}]
  function chipField({ list, max, options, label, allowNew = false, placeholder, maxlength = 40 }) {
    const chips = el('span', { class: 'g-chips' });
    const input = el('input', { class: 'g-chips-in', type: 'text', placeholder, 'aria-label': placeholder, autocomplete: 'off', maxlength });
    const sugg = el('div', { class: 'g-sugg', hidden: true });
    const box = el('div', { class: 'input g-chips-box', onclick: () => input.focus() }, chips, input);
    let act = 0;
    const draw = () => chips.replaceChildren(...list.map((v, i) => {
      const o = options().find((x) => x.v === v);
      return el('span', { class: 'g-chip' + (o?.pic ? '' : ' no-pic') }, o?.pic ? el('img', { class: 'ga-av', src: o.pic, alt: '' }) : null, label(v),
        el('button', { type: 'button', 'aria-label': 'Remove ' + label(v), onclick: (e) => { e.stopPropagation(); list.splice(i, 1); draw(); mark(); } }, '×'));
    }));
    const add = (v) => {
      if (v && list.length >= max) { toast(`${max} at most.`, 'err'); return; }
      if (v && !list.some((x) => fold(x) === fold(v))) { list.push(v); mark(); }
      input.value = ''; draw(); suggest(); input.focus();
    };
    const suggest = () => {
      const typed = input.value.trim().replace(/\s+/g, ' ');
      const words = foldWords(typed);
      sugg.replaceChildren();
      const named = (o) => (fold(o.label).startsWith(fold(typed)) ? 2 : 0) + (words.every((w) => fold(o.label).includes(w)) ? 1 : 0);
      const hits = fairSlice(options().filter((o) => !list.some((x) => fold(x) === fold(o.v)) && words.every((w) => fold(o.label + ' ' + (o.sub || '') + ' ' + (o.x || '')).includes(w)))
        .sort((a, b) => named(b) - named(a)), (o) => o.group || '', 10);   // the name before the line under it; models and accessories share the rows
      if (allowNew && typed && !options().some((o) => fold(o.v) === fold(typed)) && !list.some((x) => fold(x) === fold(typed))) hits.push({ v: typed, label: `Add “${typed}”`, isNew: true });
      if (!hits.length || (!typed && !document.activeElement?.isSameNode(input))) { sugg.hidden = true; return; }
      hits.forEach((h, i) => sugg.append(el('button', { type: 'button', class: 'g-sugg-row' + (i === 0 ? ' act' : ''), onmousedown: (e) => e.preventDefault(), onclick: () => add(h.v) },
        h.pic ? el('img', { class: 'ga-av', src: h.pic, alt: '' }) : h.isNew ? el('span', { class: 'ga-av ga-ini' }, '+') : null, el('span', {}, h.label), h.sub ? el('small', { class: 'muted' }, h.sub) : null)));
      act = 0;
      sugg.hidden = false;
      suggFlip(sugg);
    };
    input.addEventListener('input', (e) => { e.stopPropagation(); suggest(); });   // typing isn't a change until a chip is added
    input.addEventListener('focus', suggest);
    input.addEventListener('keydown', (e) => {
      const rows = [...sugg.children];
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && rows.length && !sugg.hidden) {
        e.preventDefault();
        act = (act + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
        rows.forEach((r, i) => r.classList.toggle('act', i === act));
      } else if (e.key === 'Enter' || (e.key === ',' && allowNew)) { e.preventDefault(); if (!sugg.hidden) rows[act]?.click(); }
      else if (e.key === 'Backspace' && !input.value && list.length) { list.pop(); draw(); mark(); }
      else if (e.key === 'Escape') { sugg.hidden = true; }
    });
    input.addEventListener('blur', () => setTimeout(() => { sugg.hidden = true; }, 150));
    draw();
    const field = el('div', { class: 'g-chars-field' }, box, sugg);
    field.addValue = (v) => {   // from outside (a card in the text): false when it is there already or the list is full
      if (list.length >= max || list.some((x) => fold(x) === fold(v))) return false;
      list.push(v); draw(); mark();
      return true;
    };
    return field;
  }

  // the post's poll (app/blog.php → "Polls"): question, 2–8 choices (text, a picture or a model / accessory's card picture), picks, closing date,
  // when results show; p.pollEdit = what the editor holds ({on: false} = no poll; votes stay stored and come back with it)
  const POLL_MAX = 8;
  const POLL_RESULTS = { after: 'After Voting', always: 'Always', closed: 'When Closed' };
  function pollField(p) {
    p.pollEdit ??= p.poll ? { on: true, ...structuredClone(p.poll), closes: p.closesLocal || '' }
      : { on: false, q: '', opts: [{ id: '', text: '' }, { id: '', text: '' }], max: 1, closes: '', results: 'after', change: false };
    const pl = p.pollEdit;
    const counts = p.votes ? p.votes[1] || {} : {};
    const voters = p.votes ? p.votes[0] : 0;
    const on = el('input', { type: 'checkbox', class: 'switch', checked: pl.on });
    const body = el('div', { class: 'ba-poll-body', hidden: !pl.on });
    on.addEventListener('change', () => { pl.on = on.checked; body.hidden = !on.checked; if (on.checked) body.querySelector('input')?.focus(); });

    const q = el('input', { class: 'input', type: 'text', value: pl.q || '', maxlength: 200, placeholder: 'e.g. Who Should Be the Next Model?' });
    q.addEventListener('input', () => (pl.q = q.value));
    const rows = el('div', { class: 'ba-poll-opts' });
    const addBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { pl.opts.push({ id: '', text: '' }); draw(); mark(); rows.lastChild?.querySelector('input')?.focus(); } }, '+ Add Choice');
    const maxSel = el('select', { class: 'input' });
    const fillMax = () => {
      const n = Math.max(2, pl.opts.length);
      pl.max = Math.min(Math.max(1, +pl.max || 1), n);
      maxSel.replaceChildren(...Array.from({ length: n }, (_, i) => el('option', { value: i + 1, selected: i + 1 === pl.max }, i ? `Up to ${i + 1}` : 'One')));
    };
    maxSel.addEventListener('change', () => (pl.max = +maxSel.value));
    const itemOpts = () => [el('option', { value: '' }, 'No Link'),
      ...[['models', 'Models'], ['outfits', 'Accessories'], ['scenes', 'Scenes']].map(([k, t]) => el('optgroup', { label: t }, ...data.items.filter((it) => it.ref.startsWith(k + ':')).map((it) => el('option', { value: it.ref }, it.name))))];
    function draw() {
      rows.replaceChildren(...pl.opts.map((o) => {
        const pic = el('button', { type: 'button', class: 'ba-poll-pic', title: 'Choose a picture', 'aria-label': 'Choose a picture' });
        const showPic = () => {
          const src = o.pic || itemBy[o.item]?.pic || '';
          pic.replaceChildren(src ? el('img', { src, alt: '' }) : el('span', { 'aria-hidden': 'true' }, '+'));
          picDel.hidden = !o.pic;
        };
        pic.addEventListener('click', async () => {
          const picked = await pickImages({ folder: 'blog', title: 'Picture for This Choice' });
          if (!picked[0]) return;
          o.pic = picked[0]; showPic(); mark();
        });
        const picDel = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Remove the picture', onclick: () => { delete o.pic; showPic(); mark(); } }, 'No Picture');
        const text = el('input', { class: 'input', type: 'text', value: o.text || '', maxlength: 80, placeholder: 'Choice', 'aria-label': 'Choice' });
        text.addEventListener('input', () => (o.text = text.value));
        const item = el('select', { class: 'input ba-poll-item', 'aria-label': 'Link to a model or accessory' }, ...itemOpts());
        item.value = o.item && itemBy[o.item] ? o.item : '';
        item.addEventListener('change', () => {
          o.item = item.value;
          if (o.item && !text.value.trim()) text.value = o.text = itemBy[o.item].name;
          showPic();
        });
        const n = o.id && counts[o.id] ? counts[o.id] : 0;
        const del = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-label': 'Remove this choice', onclick: () => {
          if (pl.opts.length <= 2) return toast('A poll needs two choices at least.', 'err');
          if (n && !confirm(`Remove “${o.text}”? Its ${n === 1 ? 'vote goes' : n + ' votes go'} with it.`)) return;
          pl.opts.splice(pl.opts.indexOf(o), 1); draw(); mark();
        } }, '✕');
        const row = el('div', { class: 'ba-poll-row' }, dragHandle((h) => h.closest('.ba-poll-row'), () => { pl.opts = [...rows.children].map((r) => r._o); mark(); }),
          pic, el('div', { class: 'ba-poll-mid' }, text, el('div', { class: 'ba-poll-sub' }, item, picDel, o.id ? el('span', { class: 'muted small' }, n === 1 ? '1 vote' : `${n} votes`) : null)), del);
        row._o = o;
        showPic();
        return row;
      }));
      addBtn.hidden = pl.opts.length >= POLL_MAX;
      fillMax();
    }
    draw();
    const closes = el('input', { class: 'input', type: 'datetime-local', value: pl.closes || '', step: 60 });
    closes.addEventListener('input', () => (pl.closes = closes.value));
    const res = el('select', { class: 'input' }, ...Object.entries(POLL_RESULTS).map(([k, t]) => el('option', { value: k, selected: k === (pl.results || 'after') }, t)));
    res.addEventListener('change', () => (pl.results = res.value));
    const change = el('input', { type: 'checkbox', class: 'switch', checked: !!pl.change });
    change.addEventListener('change', () => (pl.change = change.checked));
    body.append(
      el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Question'), q),
      el('div', { class: 'field' }, lbl('Choices', 'Two to eight. A choice linked to a model or accessory shows its catalog picture (unless you pick one) and links to its page in the results.'), rows, el('div', { class: 'row-btns' }, addBtn)),
      el('div', { class: 'ba-poll-set' },
        el('label', { class: 'field' }, el('span', { class: 'lbl' }, 'Each Patron Picks'), maxSel),
        el('label', { class: 'field' }, lbl('Closes', `Site time (${data.tz}). Empty = open until you remove the poll.`), closes),
        el('label', { class: 'field' }, lbl('Show Results', 'After Voting: to patrons who voted. Always: to everyone who can read the post. When Closed: to nobody until it closes. You always see them.'), res)),
      el('label', { class: 'toggle' }, change, el('span', {}, 'Patrons Can Change Their Vote')),
      p.votes ? el('p', { class: 'muted small' }, voters === 1 ? '1 patron voted so far.' : `${voters} patrons voted so far.`) : null);
    return el('div', { class: 'field ba-poll' },
      el('label', { class: 'toggle' }, on, el('span', {}, 'Add a Poll to This Post'), tip('Paying patrons vote under the post, once each.')), body);
  }

  const F = {};   // the open post's fields that a save writes back (address and date made by the server, the delete button)
  function renderPane() {
    const p = cur;
    saveBtn.disabled = !p;
    viewLink.textContent = p ? 'View Post' : 'View Blog';
    if (!p) { viewLink.href = '/blog/'; pane.replaceChildren(el('p', { class: 'muted dp-empty' }, data.posts.length ? 'Pick a post on the left, or write one with + New Post.' : 'No posts yet. Write the first one with + New Post.')); return; }
    viewLink.href = p.id ? `/blog/${p.slug}/` : '/blog/';
    const field = (label, hint, ...kids) => el('label', { class: 'field' }, lbl(label, hint), ...kids);

    const title = el('input', { class: 'input', type: 'text', value: p.title, maxlength: 160, required: true, placeholder: 'e.g. Lena Version 4 Is Out' });
    F.slug = el('input', { class: 'input', type: 'text', value: p.slug, maxlength: 80, pattern: '[a-z0-9][a-z0-9\\-]*', spellcheck: 'false', placeholder: 'made from the title' });
    let slugTouched = !p.isNew;
    title.addEventListener('input', () => {
      p.title = title.value;
      if (!slugTouched) { p.slug = slugify(title.value, 70); F.slug.value = p.slug; }   // only until the first save: then the address stays
      const b = list.querySelector('.ba-item.active b'); if (b) b.textContent = title.value || (p.id ? 'Untitled Post' : 'New Post');
    });
    F.slug.addEventListener('input', () => { slugTouched = true; p.slug = F.slug.value.trim(); });
    F.slug.addEventListener('change', () => { p.slug = slugify(F.slug.value, 70); F.slug.value = p.slug; });

    F.date = el('input', { class: 'input', type: 'datetime-local', value: p.local || '', step: 60 });
    F.date.addEventListener('input', () => (p.local = F.date.value));

    const vis = el('div', { class: 'vis-seg ba-vis', role: 'radiogroup', 'aria-label': 'Visibility' });
    for (const [k, text] of Object.entries(BLOG_VIS)) {
      const ic = el('span', { class: 'vis-ic' });
      ic.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${BLOG_ICONS[k]}</svg>`;
      vis.append(el('label', { class: 'vis-opt', title: text }, el('input', { type: 'radio', name: 'ba-vis', value: k, checked: k === p.visibility }), ic, el('span', {}, text)));
    }
    const hint = el('p', { class: 'hint vis-hint' }, BLOG_HINT[p.visibility]);
    vis.addEventListener('change', () => {
      const was = p.visibility;
      p.visibility = vis.querySelector('input:checked').value;
      hint.textContent = BLOG_HINT[p.visibility];
      // a draft made public: its date becomes now (unless it is set later), so it comes first and Discord announces it
      if (was === 'draft' && p.visibility !== 'draft' && p.state === 'draft' && (!F.date.value || F.date.value < siteNow())) {
        F.date.value = p.local = siteNow();
        toast('The date is now: the post is published when you save.', 'info');
      }
    });
    const pinned = el('input', { type: 'checkbox', class: 'switch', checked: !!p.pinned });
    pinned.addEventListener('change', () => (p.pinned = pinned.checked));
    const cmt = el('input', { type: 'checkbox', class: 'switch', checked: !p.noComments });
    cmt.addEventListener('change', () => (p.noComments = !cmt.checked));

    const topicsInUse = () => {
      const n = {};
      for (const x of data.posts) for (const t of x.topics || []) { const k = fold(t); n[k] ??= { v: t, label: t, c: 0 }; n[k].c++; }
      return Object.values(n).sort((a, b) => b.c - a.c).map((o) => ({ ...o, sub: o.c === 1 ? '1 post' : `${o.c} posts` }));
    };
    const topics = chipField({ list: p.topics, max: 6, options: topicsInUse, label: (v) => v, allowNew: true, placeholder: 'Add a topic…' });
    const items = chipField({ list: p.items, max: 12, maxlength: 80, placeholder: 'Add a model or accessory…',
      options: () => data.items.map((it) => ({ v: it.ref, label: it.name, sub: it.sub, x: it.x, pic: it.pic, group: it.ref.split(':')[0] })), label: (v) => itemBy[v]?.name || v });

    // cover: a picture of the Media library or a new upload (folder "blog"); Crop… makes a new file (16:9 is best)
    const cover = el('input', { class: 'input', type: 'text', value: p.cover || '', placeholder: '/media/…', maxlength: 1000 });
    const prev = el('div', { class: 'img-prev' });
    const show = () => {
      const v = cover.value.trim();
      prev.replaceChildren(v ? el('img', { src: v, alt: '' }) : el('span', { class: 'muted small' }, 'No Image'));
      prev.classList.toggle('is-open', !!v);
      prev.title = v ? 'Details and crop' : '';
    };
    const setCover = (v) => { cover.value = p.cover = v; show(); mark(); };
    cover.addEventListener('input', () => { p.cover = cover.value.trim(); show(); });
    const cropBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => crop(false) }, 'Crop…');
    const crop = async (afterUpload, tab = 'crop') => {
      const src = cover.value.trim();
      if (!src) return;
      if (tab === 'crop' && !src.startsWith('/media/')) return toast('Only images on this site can be cropped: upload it here first.', 'err');
      const box = await cropImage(src, { aspect: 16 / 9, title: afterUpload ? 'Crop the New Cover' : 'Cover Picture', skip: afterUpload, tab });
      if (!box || box === 'skip') return;
      cropBtn.disabled = true; cropBtn.textContent = 'Cropping…';
      try { setCover((await post('/admin/images.php', { action: 'crop', url: src, ...box })).url); toast('✓ Cropped.'); }
      catch (e) { toast(e.message, 'err'); }
      cropBtn.disabled = false; cropBtn.textContent = 'Crop…';
    };
    prev.addEventListener('click', () => { if (cover.value.trim()) crop(false, 'details'); });
    show();
    const coverField = el('div', { class: 'field image-field ba-cover' }, lbl('Cover Picture', 'On the cards, at the top of the post and in link previews (Public posts only).'), el('p', { class: 'img-note' }, 'Best at 16:9.'),
      el('div', { class: 'img-row' }, prev, el('div', { class: 'img-ctl' }, cover, el('div', { class: 'row-btns' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: async () => {
          const picked = await pickImages({ folder: 'blog', title: 'Cover Picture' });
          if (!picked[0]) return;
          setCover(picked[0]);
          if (picked.uploaded && !/\.gif$/i.test(picked[0])) crop(true);
        } }, 'Choose…'),
        cropBtn,
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => setCover('') }, 'Remove')))));

    const sum = el('input', { class: 'input', type: 'text', value: p.summary || '', maxlength: 300, placeholder: 'One or two lines: on the cards, under the title and in link previews' });
    sum.addEventListener('input', () => (p.summary = sum.value));
    const body = el('textarea', { class: 'input dp-body', spellcheck: 'true', maxlength: 200000, placeholder: 'Write the post here. See "How to format posts" above.' });
    body.value = p.body || '';
    body.addEventListener('input', () => (p.body = body.value));
    // a model / accessory / scene card put in the text (a line holding only its link: "@", the Card button, pasted) joins "Models and Accessories
    // in This Post"; only cards new since the last look, so a chip removed by hand stays removed while its card stays in the text
    const cardRefs = () => new Set([...body.value.matchAll(/^[ \t]*\[[^\]\n]+\]\(\/(models|accessories|scenes)\/([a-z0-9][a-z0-9_-]*)\/?(?:#[\w-]*)?\)[ \t]*$/gim)]
      .map((m) => ({ models: 'models:', accessories: 'outfits:', scenes: 'scenes:' })[m[1].toLowerCase()] + m[2].toLowerCase()).filter((r) => itemBy[r]));
    let inText = cardRefs();
    body.addEventListener('input', () => {
      const now = cardRefs();
      const added = [...now].filter((r) => !inText.has(r) && items.addValue(r));
      inText = now;
      if (added.length) toast(`✓ ${added.map((r) => itemBy[r].name).join(', ')} added.`);
    });
    const { tabs, toolbar, preview } = mdTools(body, { folder: 'blog', imgTitle: 'Pictures for This Post', onEdit: mark, paywall: true, video: data.video });

    const dsc = el('input', { type: 'checkbox', class: 'switch', checked: !!p.discord });
    dsc.addEventListener('change', () => (p.discord = dsc.checked));
    const discord = p.announced ? el('p', { class: 'muted small' }, '✓ Announced on Discord.')
      : data.discord ? el('label', { class: 'toggle' }, dsc, el('span', {}, 'Announce on Discord When Published'), tip('Posted by the Discord webhook once the post is Public or Patrons Only and its date has come (title, summary, link; the cover only for Public posts).'))
        : el('p', { class: 'muted small' }, 'Discord announcements are off. ', el('a', { href: data.discordUrl }, 'Turn them on'), ' to post new blog posts there too.');

    const pollBox = pollField(p);

    pane.replaceChildren(
      el('div', { class: 'dp-fields ba-fields' },
        el('label', { class: 'field dp-wide' }, el('span', { class: 'lbl' }, 'Title'), title),
        el('label', { class: 'field' }, lbl('Address', 'Keep it once the post is out: links to the old address stop working.'), el('span', { class: 'prefixed' }, el('span', { class: 'muted' }, '/blog/'), F.slug, el('span', { class: 'muted' }, '/'))),
        field('Date', `Site time (${data.tz}). A later date publishes the post then; empty = when you save.`, F.date),
        el('div', { class: 'field dp-wide' }, el('span', { class: 'lbl' }, 'Visibility'), el('div', { class: 'ba-vis-row' }, vis,
          el('label', { class: 'toggle' }, pinned, el('span', {}, 'Pinned')),
          data.comments ? el('label', { class: 'toggle' }, cmt, el('span', {}, 'Allow Comments')) : null)), el('div', { class: 'dp-wide' }, hint),
        el('div', { class: 'field dp-wide' }, lbl('Topics'), topics),
        el('div', { class: 'dp-wide' }, coverField),
        el('label', { class: 'field dp-wide' }, el('span', { class: 'lbl' }, 'Summary'), sum)),
      tabs, toolbar, body, preview,
      el('div', { class: 'field ba-items' }, lbl('Models and Accessories in This Post'), items),
      pollBox,
      el('div', { class: 'ba-discord' }, discord),
      el('div', { class: 'dp-foot' }, F.del = el('button', { type: 'button', class: 'btn btn-ghost btn-sm dp-del', onclick: del }, p.id ? 'Delete Post' : 'Discard')));
  }

  async function del() {
    if (!cur.id) { dirty = false; const first = data.posts[0]; first ? open(first.id) : close(); return; }
    if (!confirm(`Delete the post "${cur.title}"? This can’t be undone (its likes and poll votes go too).`)) return;
    try {
      data.posts = (await post('/admin/save.php', { kind: 'blog', action: 'delete', id: cur.id, rev: cur.rev })).posts;
      dirty = false;
      toast('✓ Post deleted.');
      data.posts[0] ? open(data.posts[0].id) : close();
    } catch (e) { toast(e.message, 'err'); }
  }

  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  guardLeave(() => dirty);
  const save = async () => {
    if (saveBtn.disabled || !cur) return;
    if (!cur.title.trim()) { toast('The post needs a title.', 'err'); pane.querySelector('input').focus(); return; }
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
    const p = cur;
    try {
      const at = edits;
      const out = { title: p.title.trim(), slug: p.slug, date: p.local || '', summary: (p.summary || '').trim(), cover: (p.cover || '').trim(), body: p.body || '',
        topics: p.topics, items: p.items, visibility: p.visibility, pinned: !!p.pinned, discord: !!p.discord, comments: !p.noComments,
        poll: p.pollEdit ? { on: p.pollEdit.on, q: (p.pollEdit.q || '').trim(), opts: p.pollEdit.opts.map((o) => ({ id: o.id || '', text: (o.text || '').trim(), pic: o.pic || '', item: o.item || '' })),
          max: p.pollEdit.max, closes: p.pollEdit.closes || '', results: p.pollEdit.results || 'after', change: !!p.pollEdit.change } : (p.poll ? { on: true, ...p.poll, closes: p.closesLocal || '' } : null) };
      const r = await post('/admin/save.php', { kind: 'blog', id: p.id, rev: p.rev, post: out });
      data.posts = r.posts;
      const saved = data.posts.find((x) => x.id === r.id);
      if (cur === p && saved) {
        // the server may have made the address or the date: write them back, keep what was typed meanwhile
        Object.assign(p, { id: saved.id, rev: saved.rev, state: saved.state, slug: saved.slug, local: saved.local, announced: saved.announced, thumb: saved.thumb, isNew: false });
        // the poll as saved (new choices got their ids): drawn again only when nothing was typed meanwhile
        if (edits === at) { p.poll = saved.poll; p.votes = saved.votes; p.closesLocal = saved.closesLocal; p.pollEdit = null; const old = pane.querySelector('.ba-poll'); old?.replaceWith(pollField(p)); }
        F.slug.value = saved.slug; F.date.value = saved.local; F.del.textContent = 'Delete Post';
        viewLink.href = `/blog/${saved.slug}/`;
        history.replaceState(null, '', `?post=${encodeURIComponent(saved.id)}`);
        if (edits === at) dirty = false;
      }
      renderList();
      toast(saved?.state === 'draft' ? '✓ Draft saved.' : saved?.state === 'scheduled' ? '✓ Post scheduled.' : '✓ Post saved.');
    } catch (e) { toast(e.message, 'err'); }
    saveBtn.disabled = false; saveBtn.textContent = 'Save';
  };
  saveBtn.addEventListener('click', save);
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } });

  // ?post=<id> opens that post, otherwise the newest; no posts = nothing open until + New Post; ?new=1 (Quick Add, the header's "+") = a new post
  const start = data.posts.find((p) => p.id === data.post) || data.posts[0];
  cur = start ? copy(start) : null;
  renderList(); renderPane();
  if (new URLSearchParams(location.search).get('new') === '1') open(null);   // open() also takes ?new out of the address
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
  const card = (cls, head, body, confirmText) => {
    const item = el('details', { class: 'rep-item ' + cls }, head, body);
    head.prepend(dragHandle(() => item));
    head.querySelector('.row-btns').append(   // reorder by dragging ⠿ (or focus it and use the arrow keys)
      iconBtn('✕', 'Remove', (e) => { e.preventDefault(); if (confirm(confirmText)) { item.remove(); mark(); } }, 'del'));
    return item;
  };
  const question = (it = {}) => {
    const q = el('input', { class: 'input', type: 'text', value: it.q || '', placeholder: 'The question', maxlength: 300 });
    const a = imageDrop(growArea(it.a, 4, 'The answer…', 20000), 'faq');
    const imgBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pictures from the Media library or uploaded (several = side-by-side gallery); pictures can also be dropped on or pasted into the answer', onclick: async () => {
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

  const intro = imageDrop(growArea(data.faq.intro, 2, 'Short text under the page title (optional)', 2000), 'faq');
  const topics = el('div', { class: 'repeat' }, (data.faq.sections || []).map((s) => topic(s)));
  const addTopic = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const n = topic({}, true); topics.append(n); n.querySelector('input').focus(); mark(); } }, '+ Add Topic');
  form.append(
    el('fieldset', { class: 'sec' }, el('legend', {}, 'Introduction'), intro),
    el('fieldset', { class: 'sec' }, el('legend', {}, 'Topics and Questions'), topics, addTopic));
  form.addEventListener('input', mark);
  form.addEventListener('submit', (e) => e.preventDefault());
  guardLeave(() => dirty);

  saveButton(async () => {
    const at = edits;
    data.rev = (await post('/admin/save.php', { kind: 'faq', faq: { intro: intro.value.trim(), sections: [...topics.children].map((n) => n.get()) }, rev: data.rev })).rev;
    if (edits === at) dirty = false;
    toast('✓ FAQ saved.');
  });
}

// Help texts behind a "?": tip(text) is the button, lbl(label, hint) a field label with one. One floating box shows the text
// on hover or focus (a tap on phones), kept inside the window
function tip(text) {
  return el('button', { type: 'button', class: 'tip', 'data-tip': text, 'aria-label': 'Help: ' + text }, '?');
}
function lbl(label, hint) {
  return el('span', { class: 'lbl' }, label, hint && tip(hint));
}

// A text field that offers the values already in use (the editors' Franchise, Game and accessory Type): typing lists the matches
// (case, accents and small typos don't matter), Enter or a click takes one, so a variant never becomes a new value by accident.
// A new value is a choice of its own: the last row "+ New …", or leaving the field; it then shows "New" (and "Did you mean…?" when
// close to one in use). A value that differs from one in use only by case / accents / spaces takes that one's spelling on leaving.
// values = [{v, n (items using it), f? (group, e.g. a game's franchise)}]; o = {noun: 'franchise', unit: ['model', 'models'], group: () => text}
function suggestField(input, values, o) {
  const key = (t) => fold(t).replace(/\s+/g, ' ').trim();   // values compared without accents, case or extra spaces
  const dist = (a, b) => {   // edit distance (Levenshtein), for typos
    if (Math.abs(a.length - b.length) > 3) return 9;
    let prev = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
      const cur = [i];
      for (let j = 1; j <= b.length; j++) cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = cur;
    }
    return prev[b.length];
  };
  const close = (q, v) => q.length >= 4 && (dist(q, v) <= Math.max(1, Math.floor(v.length / 6)) || dist(q, v.slice(0, q.length)) <= 1);
  const same = (t) => values.find((x) => key(x.v) === key(t));
  const noun = o.noun, Noun = noun[0].toUpperCase() + noun.slice(1);
  const sugg = el('div', { class: 'g-sugg sg-list', hidden: true, role: 'listbox' });
  const note = el('div', { class: 'sg-note', hidden: true });
  let act = 0;
  input.setAttribute('autocomplete', 'off');
  const set = (v) => { input.value = v; input.dispatchEvent(new Event('change', { bubbles: true })); state(); };
  const hide = () => { sugg.hidden = true; input.setAttribute('aria-expanded', 'false'); };
  const show = () => {
    const typed = input.value.trim(), q = key(typed), g = key(o.group?.() || '');
    const score = (x) => {   // lower = better; null = not a match
      const v = key(x.v), grp = g && key(x.f) === g ? 0 : 1;
      if (!q) return grp * 10;
      if (v === q) return -1;
      if (v.startsWith(q)) return 1 + grp * 10;
      if (q.split(' ').every((w) => v.includes(w))) return 2 + grp * 10;
      return close(q, v) ? 3 + grp * 10 : null;
    };
    let rows = values.map((x) => [x, score(x)]).filter(([, sc]) => sc !== null).sort((a, b) => a[1] - b[1] || b[0].n - a[0].n).map(([x]) => x);
    if (!q && g && rows.some((x) => key(x.f) === g)) rows = rows.filter((x) => key(x.f) === g);   // empty Game: this franchise's games
    rows = rows.slice(0, 8);
    sugg.replaceChildren(...rows.map((x) => el('button', { type: 'button', class: 'g-sugg-row', role: 'option', onmousedown: (e) => e.preventDefault(), onclick: () => { set(x.v); hide(); } },
      el('span', {}, x.v), el('small', { class: 'muted' }, [x.f && x.f !== o.group?.() ? x.f : '', x.n ? x.n + ' ' + o.unit[x.n === 1 ? 0 : 1] : ''].filter(Boolean).join(' · ')))));
    if (typed && !same(typed)) sugg.append(el('button', { type: 'button', class: 'g-sugg-row sg-add', role: 'option', onmousedown: (e) => e.preventDefault(), onclick: () => { set(typed); hide(); } },
      el('span', { class: 'sg-plus' }, '+'), el('span', {}, `New ${Noun} “${typed}”`)));
    act = 0;
    [...sugg.children].forEach((r, i) => r.classList.toggle('act', i === act));
    sugg.hidden = !sugg.children.length;
    suggFlip(sugg);
    input.setAttribute('aria-expanded', String(!sugg.hidden));
  };
  // under the field: "New" for a value no other item uses, with the closest one in use when it looks like a typo
  const state = () => {
    const typed = input.value.trim();
    const isNew = typed !== '' && !same(typed);
    note.hidden = !isNew;
    input.classList.toggle('is-new', isNew);
    if (!isNew) return;
    const q = key(typed);
    const near = values.filter((x) => close(q, key(x.v)) || (q.length >= 3 && (key(x.v).startsWith(q) || q.startsWith(key(x.v))))).sort((a, b) => dist(q, key(a.v)) - dist(q, key(b.v)))[0];
    note.replaceChildren(el('span', { class: 'sg-new' }, 'New ' + Noun),
      near ? el('span', {}, ' Did you mean ', el('button', { type: 'button', class: 'sg-mean', onclick: () => set(near.v) }, near.v), '?') : ` No other ${o.unit[0]} uses it yet.`);
  };
  input.addEventListener('input', () => { show(); note.hidden = true; input.classList.remove('is-new'); });
  input.addEventListener('focus', show);
  input.addEventListener('keydown', (e) => {
    const rows = [...sugg.children];
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (sugg.hidden) { show(); return; }
      if (!rows.length) return;
      act = (act + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
      rows.forEach((r, i) => r.classList.toggle('act', i === act));
      rows[act].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && !sugg.hidden && rows[act]) { e.preventDefault(); rows[act].click(); }
    else if (e.key === 'Escape' && !sugg.hidden) { e.preventDefault(); hide(); }
  });
  input.addEventListener('blur', () => setTimeout(() => {
    if (document.activeElement === input) return;   // back in the field already
    hide();
    const typed = input.value.trim(), hit = same(typed);
    if (hit && hit.v !== input.value) set(hit.v); else state();   // "final  fantasy" → "Final Fantasy"
  }, 150));
  state();
  return el('div', { class: 'sg' }, input, sugg, note);
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
const M3D_EXPOSURE = 0.9;
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
      for (const [k, head] of [['patrons', 'Patrons'], ['public', 'Public'], ['off', 'Hidden'], ['addons', 'Add-ons']]) {
        if (!names[k]?.length) continue;
        pop.append(el('div', { class: 'tp-head tp-' + k }, head), el('ul', { class: 'tp-links' }, names[k].map((n) => typeof n === 'string' ? el('li', {}, n)
          : (() => {
            const li = el('li', { class: n.dead ? 'tp-dead' : '' }, n.n, n.dead ? el('span', {}, ' · dead link') : null);
            if (n.pw) li.insertAdjacentHTML('afterbegin', `<svg class="tp-lock" viewBox="0 0 24 24" aria-hidden="true">${KEY_LOCK_ICON}</svg>`);
            return li;
          })())));   // a lock: its own password; dead: Link Check found it dead
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
  const isScene = data.kind === 'scenes';
  const isOutfit = data.kind === 'outfits' || isScene;   // a scene has an accessory's fields, without a type or "All Models"
  const what = isScene ? 'Scene' : isOutfit ? 'Accessory' : 'Model';
  const fields = {};
  let dirty = false;
  // uploads go to media/<model>/, media/outfit-<outfit>/ or media/scene-<scene>/
  const slugNow = () => { const sl = (data.isNew ? fields.slug.get() : m.slug) || ''; return sl ? (isScene ? 'scene-' + sl : isOutfit ? 'outfit-' + sl : sl) : 'misc'; };

  // Field builders. Each returns { node, get }. Help texts (o.hint) sit behind a "?" next to the label (tip(), shown on hover / tap)
  const text = (label, value, o = {}) => {
    const input = el('input', { class: 'input', type: o.type || 'text', value: value ?? '', placeholder: o.placeholder, list: o.list, maxlength: o.max });
    return { node: el('label', { class: 'field ' + (o.cls || '') }, lbl(label, o.hint), o.suggest ? suggestField(input, o.suggest.values || [], o.suggest) : input), get: () => input.value.trim(), input };
  };
  const area = (label, value, o = {}) => {
    const ta = autoGrow(el('textarea', { class: 'input', rows: o.rows || 3, placeholder: o.placeholder, maxlength: o.max }));
    ta.value = value ?? '';
    if (o.img) imageDrop(ta, slugNow);   // a text in the md_html() format: pictures dropped or pasted go in as ![](…)
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
    const btn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pick a picture from the Media library or upload a new one', onclick: async () => {
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
      try { input.value = (await post('/admin/images.php', { action: 'crop', url: src, ...box })).url; show(); form.dispatchEvent(new Event('input')); toast('✓ Cropped.'); }
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
        toast('✓ Uploaded.');
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
      if (o.img) imageDrop(ta, slugNow);
      if (o.mention) o.mention(ta);   // before the keys below: its list takes ↑ ↓ Enter Tab Esc while open
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
    const addCard = (src, caption = '', link = null) => {
      const cap = el('input', { class: 'input', type: 'text', value: caption, placeholder: 'Caption / artist (optional)', maxlength: 200 });
      const img = el('img', { src, alt: '', loading: 'lazy' });
      const pill = el('span', { class: 'g-pill g-pill-dl gal-dl', title: 'Full size download: click the picture to change it' }, '↓ Full Size');
      const setLink = (l) => { card.fullLink = l; pill.hidden = !l; };
      // crop: a new file (the old one stays for whatever else uses it); this card takes the new address
      const open = async (tab) => {   // the picture: its details; Crop: the window on crop
        const url = card.dataset.src;
        if (tab === 'crop' && !url.startsWith('/media/')) return toast('Only images on this site can be cropped: copy it here first (Media page).', 'err');
        if (tab === 'crop' && /\.gif$/i.test(url)) return toast('Animated GIFs can’t be cropped.', 'err');
        const box = await cropImage(url, { title: label === 'Pictures' ? 'Picture' : 'Gallery Picture', tab,
          full: { state: { link: card.fullLink, s4: !!data.s4 }, onChange: (l) => { setLink(l); form.dispatchEvent(new Event('input')); } } });
        if (!box) return;
        cropBtn.disabled = true; cropBtn.textContent = '…';
        try { const r = await post('/admin/images.php', { action: 'crop', url, ...box }); card.dataset.src = r.url; img.src = r.url; form.dispatchEvent(new Event('input')); toast('✓ Cropped.'); }
        catch (e) { toast(e.message, 'err'); }
        cropBtn.disabled = false; cropBtn.textContent = 'Crop';
      };
      const cropBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Crop this picture', onclick: () => open('crop') }, 'Crop');
      img.classList.add('is-open');
      img.title = 'Details and crop';
      img.addEventListener('click', () => open('details'));
      const card = el('div', { class: 'gal-card', 'data-src': src }, img, pill, cap,
        el('div', { class: 'row-btns' }, dragHandle(() => card), cropBtn, iconBtn('✕', 'Remove', () => { card.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      setLink(link?.url ? link : null);
      grid.append(card);
    };
    (items || []).forEach((g) => (typeof g === 'string' ? addCard(g) : addCard(g.src, g.caption || '', g.link)));
    const up = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pick pictures from the Media library or upload new ones', onclick: async () => {
      const urls = await pickImages({ multiple: true, folder: slugNow(), title: 'Add to the ' + label, exclude: [...grid.children].map((c) => c.dataset.src), excludeLabel: 'Already Here' });
      urls.forEach((u) => addCard(u));
      if (urls.length) form.dispatchEvent(new Event('input'));
    } }, 'Add Pictures…');
    const byUrl = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { const u = prompt('Image address (/media/… or https://…)'); if (u) { addCard(u.trim()); form.dispatchEvent(new Event('input')); } } }, 'Add by Address');
    return {
      node: el('div', { class: 'field' }, lbl(label, hint), grid, el('div', { class: 'row-btns' }, up, byUrl)),
      get: () => [...grid.children].map((c) => {
        const cap = c.querySelector('input').value.trim();
        return cap || c.fullLink ? { src: c.dataset.src, ...(cap ? { caption: cap } : {}), ...(c.fullLink ? { link: c.fullLink } : {}) } : c.dataset.src;
      }),
    };
  };

  // Repeating blocks (notes, changelog versions, extra sections)
  const repeat = (o) => {
    const wrap = el('div', { class: 'repeat' });
    const getters = new Map();
    // o.build(value) → { body, get, title, meta?, focus? }: meta() = what a closed block shows after its title, focus() = the field a new block starts in
    const addItem = (value, top = false, open = true) => {
      const { body, get, title, meta, focus } = o.build(value || {});
      const metaEl = meta ? el('span', { class: 'rep-meta' }) : null;
      const head = el('summary', { class: 'rep-head' }, dragHandle(() => item), el('span', { class: 'rep-title' }, title()), metaEl,
        el('span', { class: 'row-btns' }, iconBtn('✕', 'Remove', (e) => { e.preventDefault(); if (confirm(o.confirmRemove || 'Remove this block?')) { item.remove(); form.dispatchEvent(new Event('input')); } }, 'del')));
      const item = el('details', { class: 'rep-item' + (o.cls ? ' ' + o.cls : ''), open }, head, body);
      const sync = () => { head.querySelector('.rep-title').textContent = title(); metaEl?.replaceChildren(...meta().filter(Boolean)); };
      body.addEventListener('input', sync);
      if (metaEl) { item.addEventListener('toggle', sync); sync(); }
      item.focusField = focus;
      getters.set(item, get);
      if (top) wrap.prepend(item); else wrap.append(item);
      return item;
    };
    (o.items || []).forEach((v, i) => addItem(v, false, o.openFirst === undefined || i < o.openFirst));
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { const it = addItem(o.blank ? o.blank() : {}, o.addTop); (it.focusField?.() || it.querySelector('input,textarea'))?.focus(); form.dispatchEvent(new Event('input')); } }, '+ ' + o.addLabel);
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

  // a new link's label from its file: the name without folder and extension, "_" as spaces ("Alice_Textures_4K.zip" → "Alice Textures 4K")
  const s4Label = (key) => { const f = key.split('/').pop(); return (f.replace(/\.[a-z0-9]{1,5}$/i, '').replace(/_+/g, ' ').trim() || f).slice(0, 60); };

  // Download links: label + address + who can use it
  const links = (items) => {
    const wrap = el('div', { class: 'links' });
    const addRow = (l = {}) => {
      const label = el('input', { class: 'input', type: 'text', value: l.label || '', placeholder: 'Label, e.g. Mega', maxlength: 60 });
      const url = el('input', { class: 'input', type: 'url', value: l.url || '', placeholder: data.s4 ? 'https://… or s4:file name' : 'https://…', maxlength: 1000 });
      const access = el('select', { class: 'input' }, el('option', { value: 'patrons', selected: (l.access || 'patrons') === 'patrons' }, 'Patrons Only'), el('option', { value: 'public', selected: l.access === 'public' }, 'Public'));
      // on / off: an off link stays here but isn't on the site (and its /get/ address refuses), so it can come back later
      const on = el('input', { type: 'checkbox', class: 'switch link-on', checked: !l.off });
      const sync = () => { const t = on.checked ? 'Shown on the site' : 'Hidden from the site'; on.title = t; on.setAttribute('aria-label', t); row.classList.toggle('is-off', !on.checked); };
      on.addEventListener('change', () => { sync(); form.dispatchEvent(new Event('input')); });
      // its own password (the lock): only people who have it download through it (auth/get.php asks); set in a window, kept in a hidden field
      const pw = el('input', { type: 'hidden', class: 'link-pw-in', value: l.pw || '' });
      const lock = el('button', { type: 'button', class: 'icon-btn link-lock' });
      lock.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${KEY_LOCK_ICON}</svg>`;
      const syncPw = () => {
        const t = pw.value ? 'Password set: only people who have it can download' : 'Add a password';
        lock.title = t; lock.setAttribute('aria-label', t);
        lock.classList.toggle('is-on', !!pw.value);
      };
      lock.addEventListener('click', () => linkPwWindow(label.value.trim() || 'Download', pw.value, (v) => {
        pw.value = v; syncPw(); form.dispatchEvent(new Event('input')); lock.focus();
      }));
      syncPw();
      const row = el('div', { class: 'link-row' }, dragHandle(() => row), label, url, access,
        el('div', { class: 'row-btns' }, lock, on, data.s4 ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm s4-btn', title: 'Choose a file from your S4 bucket', onclick: () => pickS4({ start: s4Folder(url.value), done: ([f]) => {
          url.value = 's4:' + f.key;
          url.dispatchEvent(new Event('input', { bubbles: true }));
        } }) }, 'S4…') : null,
          iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')), pw);
      sync();
      markDead(row, url);
      wrap.append(row);
      return row;
    };
    (items || []).forEach(addRow);
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { addRow({ label: wrap.children.length ? '' : 'Mega' }).querySelector('input').focus(); form.dispatchEvent(new Event('input')); } }, '+ Add Download Link');
    // several S4 files at once: one new link each, labelled after its file; rows left completely empty make way for them
    const urlOf = (r) => r.querySelectorAll('input.input')[1];
    const addS4 = data.s4 ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => {
      const rows = [...wrap.children];
      const have = new Set(rows.map((r) => urlOf(r).value.trim()).filter((v) => /^s4:/i.test(v)).map((v) => v.replace(/^s4:\/*/i, '')));
      const last = rows.map((r) => urlOf(r).value).reverse().find((v) => /^s4:/i.test(v.trim()));
      pickS4({ multiple: true, have, start: s4Folder(last), done: (files, who) => {
        rows.forEach((r) => { if (![...r.querySelectorAll('input.input')].some((i) => i.value.trim())) r.remove(); });
        files.forEach((f) => addRow({ label: s4Label(f.key), url: 's4:' + f.key, access: who }));
        form.dispatchEvent(new Event('input'));
      } });
    } }, '+ Add from S4…') : null;
    // a temporary link to give someone (Access → Temporary Links): every file of this item, as saved; only once the item is saved
    const share = data.isNew ? null : el('button', { type: 'button', class: 'btn btn-ghost btn-sm sh-btn', title: 'A link for someone to download these files for a while, without Patreon', onclick: () => {
      const pic = isOutfit ? [fields.images.get()[0]].flat().map((g) => (typeof g === 'string' ? g : g?.src || ''))[0] || '' : fields.thumb.get() || fields.cover.get();
      const it = { k: data.kind, s: m.slug, t: fields.name.get() || m.name, u: isScene ? 'Scene' : isOutfit ? fields.type.get() || 'Outfit' : fields.franchise.get(), p: pic,
        f: [...wrap.children].map((r) => { const [lb, u] = r.querySelectorAll('input.input'); return r.querySelector('.link-on').checked && u.value.trim() ? lb.value.trim() || 'Download' : ''; }).filter(Boolean) };
      shareWindow({ item: it, unsaved: dirty });
    } }, 'Temporary Link…');
    return {
      node: el('div', { class: 'field' }, lbl('Download Links'), wrap, addS4 || share ? el('div', { class: 'row-btns links-add' }, add, addS4, share) : add),
      get: () => [...wrap.children].map((r) => {
        const [lb, u, a] = r.querySelectorAll('input.input, select'), pw = r.querySelector('.link-pw-in').value;
        return { label: lb.value.trim(), url: u.value.trim(), access: a.value, ...(r.querySelector('.link-on').checked ? {} : { off: true }), ...(pw ? { pw } : {}) };
      }).filter((l) => l.url || l.label),
    };
  };

  // Add-ons required: name + download address + the oldest version that works, shown in the download box. The ones other items
  // already use (data.addons, admin_addon_choices()) come back without looking up their address: typing in a name lists them
  // (with their address and minimum version), and the most used ones not on this item yet are one click away under the list
  const addonList = (items) => {
    const wrap = el('div', { class: 'links' });
    const known = data.addons || [];
    const urlsIn = (except) => new Set([...wrap.children].filter((r) => r !== except).map((r) => r.querySelectorAll('input.input')[1].value.trim()).filter(Boolean));
    const host = (u) => (/^\//.test(u) ? u : u.replace(/^https?:\/\/(www\.)?/i, '').split(/[/?#]/)[0]);
    const addRow = (a = {}) => {
      const label = el('input', { class: 'input', type: 'text', value: a.label || '', placeholder: 'Add-on name', maxlength: 60, autocomplete: 'off' });
      const url = el('input', { class: 'input', type: 'text', value: a.url || '', placeholder: 'https://… or a page of this site', maxlength: 1000 });
      const min = el('input', { class: 'input addon-min', type: 'text', value: a.min || '', placeholder: 'Min. version', maxlength: 20, 'aria-label': 'Minimum version' });
      // the name field lists the add-ons in use (not those already in another row): a click / Enter fills the three fields
      const sugg = el('div', { class: 'g-sugg', hidden: true, role: 'listbox' });
      let act = 0;
      const hide = () => { sugg.hidden = true; };
      const pick = (k) => {
        label.value = k.label; url.value = k.url; min.value = k.min || '';
        hide();
        url.dispatchEvent(new Event('input', { bubbles: true }));
        min.focus();
      };
      const show = () => {
        const words = foldWords(label.value), have = urlsIn(row);
        const rows = known.filter((k) => !have.has(k.url) && k.url !== url.value.trim() && words.every((w) => fold(k.label + ' ' + k.url).includes(w))).slice(0, 8);
        sugg.replaceChildren(...rows.map((k) => el('button', { type: 'button', class: 'g-sugg-row ao-row', role: 'option', onmousedown: (e) => e.preventDefault(), onclick: () => pick(k) },
          el('span', { class: 'ao-main' }, el('b', {}, k.label), k.min ? el('span', { class: 'ao-min' }, k.min + '+') : null),
          el('small', { class: 'muted' }, host(k.url) + ' · ' + k.n + ' ' + (k.n === 1 ? 'item' : 'items')))));
        act = 0;
        [...sugg.children].forEach((r, i) => r.classList.toggle('act', i === act));
        sugg.hidden = !sugg.children.length;
        suggFlip(sugg);
      };
      if (known.length) {
        label.addEventListener('focus', show);
        label.addEventListener('input', show);
        label.addEventListener('blur', () => setTimeout(hide, 150));
        label.addEventListener('keydown', (e) => {
          const rows = [...sugg.children];
          if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && rows.length && !sugg.hidden) {
            e.preventDefault();
            act = (act + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
            rows.forEach((r, i) => r.classList.toggle('act', i === act));
            rows[act].scrollIntoView({ block: 'nearest' });
          } else if (e.key === 'Enter' && !sugg.hidden && rows[act]) { e.preventDefault(); rows[act].click(); }
          else if (e.key === 'Escape' && !sugg.hidden) { e.preventDefault(); hide(); }
        });
      }
      const row = el('div', { class: 'link-row addon-row' }, dragHandle(() => row), el('div', { class: 'sg' }, label, sugg), url, min,
        el('div', { class: 'row-btns' }, iconBtn('✕', 'Remove', () => { row.remove(); form.dispatchEvent(new Event('input')); }, 'del')));
      markDead(row, url);
      wrap.append(row);
      return row;
    };
    (items || []).forEach(addRow);
    const add = el('button', { type: 'button', class: 'btn btn-ghost btn-sm add', onclick: () => { addRow().querySelector('input').focus(); form.dispatchEvent(new Event('input')); } }, '+ Add Required Add-On');
    // the most used add-ons this item doesn't have yet, as chips: a click adds it with its address and minimum version
    const used = el('div', { class: 'ao-used' });
    const syncUsed = () => {
      const have = urlsIn(null);
      const left = known.filter((k) => !have.has(k.url)).slice(0, 8);
      used.replaceChildren(...(left.length ? [el('span', { class: 'ao-used-t' }, 'Used Before'), ...left.map((k) => el('button', { type: 'button', class: 'chip ao-chip', title: k.url, onclick: () => {
        [...wrap.children].forEach((r) => { if (![...r.querySelectorAll('input.input')].some((i) => i.value.trim())) r.remove(); });   // an empty row makes way for it
        addRow(k);
        form.dispatchEvent(new Event('input'));
      } }, '+ ' + k.label, k.min ? el('span', { class: 'ao-min' }, k.min + '+') : null))] : []));
      used.hidden = !left.length;
    };
    form.addEventListener('input', syncUsed);
    syncUsed();
    return {
      node: el('div', { class: 'field' }, lbl('Add-Ons Required'), wrap, el('div', { class: 'row-btns links-add' }, add), used),
      get: () => [...wrap.children].map((r) => { const [lb, u, mn] = r.querySelectorAll('input'); return { label: lb.value.trim(), url: u.value.trim(), ...(mn.value.trim() ? { min: mn.value.trim() } : {}) }; }).filter((a) => a.url || a.label),
    };
  };

  // Compatible models: tick the models an accessory is optimized for, or switch on All Models (every model, future ones too:
  // the ticks are hidden but kept, so turning it off brings them back)
  // an accessory's Optimized For: Selected Models (the ones ticked), All Models (every model, also the ones added later: `allModels`)
  // or Blender (a generic asset, no model: `fits` = 'blender')
  const FIT_ICONS = {
    models: '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>',
    all: '<circle cx="9" cy="8" r="3.4"/><path d="M2.8 19.5c.6-3.4 3.1-5.4 6.2-5.4s5.6 2 6.2 5.4"/><path d="M15.2 4.9a3.4 3.4 0 0 1 0 6.3"/><path d="M17.6 14.4c2 .7 3.3 2.5 3.6 5.1"/>',
    blender: '<circle cx="14.5" cy="14" r="6"/><circle cx="14.5" cy="14" r="2"/><path d="M10.2 9.8 3.5 10M12.4 8.4 7 3.8"/>',
  };
  // the model cards to tick (Optimized For, a scene's models) and the box that filters them; every card one height: tick, picture (or the
  // name's first letter), the name on one line (cut with …, whole on hover) and Gen · franchise under it
  const modelCards = (selected) => {
    const sel = new Set(selected || []);
    const q = el('input', { class: 'input', type: 'search', placeholder: 'Filter models…' });
    const grid = el('div', { class: 'mpick' }, data.models.map((mm) => el('label', { class: 'mpick-item', 'data-name': fold(mm.name + ' ' + (mm.sub || '')), title: [mm.name, mm.sub].filter(Boolean).join(' · ') },
      el('input', { type: 'checkbox', value: mm.slug, checked: sel.has(mm.slug) }),
      mm.thumb ? el('img', { src: mm.thumb, alt: '', loading: 'lazy' }) : el('span', { class: 'mpick-ph', 'aria-hidden': 'true' }, mm.name.slice(0, 1)),
      el('span', { class: 'mpick-txt' }, el('span', { class: 'mpick-name' }, mm.name), mm.sub ? el('span', { class: 'mpick-sub' }, mm.sub) : null))));
    q.addEventListener('input', () => { const w = foldWords(q.value); grid.querySelectorAll('.mpick-item').forEach((it) => (it.hidden = w.length > 0 && !w.every((x) => it.dataset.name.includes(x)))); });
    return { q, grid };
  };
  const modelPicker = (selected, all, fit) => {
    const seg = segBar(fit === 'blender' ? 'blender' : all ? 'all' : 'models', { models: 'Selected Models', all: 'All Models', blender: 'Blender' }, { icons: FIT_ICONS, label: 'Optimized for', cls: 'fit-seg' });
    const { q, grid } = modelCards(selected);
    const show = () => { q.hidden = grid.hidden = seg.get() !== 'models'; };   // the ticks are kept for when Selected Models is picked again
    seg.bar.addEventListener('change', show);
    show();
    return { node: el('div', { class: 'field' }, seg.bar, q, grid), get: () => [...grid.querySelectorAll('input:checked')].map((c) => c.value), all: () => seg.get() === 'all',
      fits: () => (seg.get() === 'blender' ? 'blender' : '') };
  };

  // a scene's models: the same cards to tick, and above them one row per ticked model with the version the scene was made with
  // (its changelog's versions, newest first; a newly ticked model starts on its newest one; "Version Not Said" = none stored)
  const scenePicker = (selected, vers) => {
    const ver = { ...(vers || {}) };
    const bySlug = new Map(data.models.map((mm) => [mm.slug, mm]));
    const { q, grid } = modelCards(selected);
    const picked = () => [...grid.querySelectorAll('input:checked')].map((c) => c.value);
    const words = (v) => (/^\d/.test(v) ? 'Version ' + v : v);
    const rows = el('div', { class: 'scp-rows' });
    const draw = () => {
      rows.replaceChildren(...picked().map((s) => {
        const mm = bySlug.get(s);
        const opts = [...(mm.vers || [])];
        if (!(s in ver)) ver[s] = opts[0] || '';   // just ticked: its newest version
        if (ver[s] && !opts.includes(ver[s])) opts.unshift(ver[s]);   // stored, no longer in its changelog: kept
        const box = el('select', { class: 'input', 'aria-label': 'Version of ' + mm.name },
          ['', ...opts].map((v) => el('option', { value: v, selected: v === ver[s] }, v === '' ? 'Version Not Said' : words(v))));
        box.addEventListener('change', () => (ver[s] = box.value));
        return el('div', { class: 'scp-row' },
          mm.thumb ? el('img', { src: mm.thumb, alt: '', loading: 'lazy' }) : el('span', { class: 'mpick-ph', 'aria-hidden': 'true' }, mm.name.slice(0, 1)),
          el('span', { class: 'mpick-txt' }, el('span', { class: 'mpick-name' }, mm.name), mm.sub ? el('span', { class: 'mpick-sub' }, mm.sub) : null), box);
      }));
      rows.hidden = !rows.children.length;
    };
    grid.addEventListener('change', draw);
    draw();
    return { node: el('div', { class: 'field' }, rows, q, grid), get: picked,
      vers: () => Object.fromEntries(picked().filter((s) => ver[s]).map((s) => [s, ver[s]])) };
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
    // its label goes above the field and the tier buttons together, so both sit on one line
    const tierLbl = fields.requireTier.node.querySelector('.lbl');
    tierLbl.remove();
    tierIn.setAttribute('aria-label', `Minimum tier (${cur} a month)`);
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
    fields.noLimits = toggle('Exclude from Unlock Limits', m.noLimits);

    // release dates (the site's time zone, Settings → Site → Time Zone): patrons from, everyone from; before the patrons date the page is hidden or "Coming soon"
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
      const loyal = [num(fields.requireMonths) > 0 && `${num(fields.requireMonths)} month${num(fields.requireMonths) === 1 ? '' : 's'} as a patron`, num(fields.requireTotal) > 0 && `${money(num(fields.requireTotal))} in total`].filter(Boolean);
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
    body.append(part('Release Dates', `Times are in the site’s time zone (${(rel.tz || 'Europe/Berlin').replace(/_/g, ' ')}); visitors see them in their own. Before “Patrons From” nobody can download it (admins can). From “Everyone From” every patron link works for anyone, with no login. Discord gets a post on each date.`,
        el('div', { class: 'grid2' }, fields.releasePatrons.node, fields.releasePublic.node), fields.releaseBefore.node, strip),
      part('Tier', `Patrons below it see “You need the … tier or higher” with an Upgrade link. The tier buttons are the campaign’s tiers (Settings → Patreon → Campaign).`,
        el('div', { class: 'field tier-pick' }, tierLbl, el('div', { class: 'tier-row' }, fields.requireTier.node, el('div', { class: 'chips' }, chips)))),
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
    'blurred-eligible': '<circle cx="12" cy="9" r="6"/><path d="m9 14.2-2 6.8 5-3 5 3-2-6.8"/>',
    password: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
    draft: '<path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/>',
  };
  const VIS_SHORT = { public: 'Public', blurred: 'Teaser', 'blurred-patrons': 'Clear for Patrons', 'blurred-eligible': 'Clear When Eligible', password: 'Password', draft: 'Draft' };
  // a model's status: same bar, its own icons (Public = open lock, Preview = eye, Patreon Only = closed lock, In Development = wrench)
  const STATUS_ICONS = {
    released: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.7-1.5"/>',
    preview: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'patreon-only': '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'in-development': '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3.5 17.3a1.8 1.8 0 0 0 2.5 2.5l5.8-5.8a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
  };
  // a row of icon buttons (radio inputs, so arrow keys work) instead of a menu; a value not in the list picks the first option, like a menu would
  const segBar = (value, options, { icons, short = {}, label, cls = '' }) => {
    const name = 'seg-' + Math.random().toString(36).slice(2, 8);
    const bar = el('div', { class: 'vis-seg ' + cls, role: 'radiogroup', 'aria-label': label });
    const keys = Object.keys(options);
    const pick = keys.includes(value) ? value : keys[0];
    for (const [k, text] of Object.entries(options)) {
      const r = el('input', { type: 'radio', name, value: k, checked: k === pick });
      const ic = el('span', { class: 'vis-ic' });
      ic.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[k] || ''}</svg>`;
      bar.append(el('label', { class: 'vis-opt', title: text }, r, ic, el('span', {}, short[k] || text)));
    }
    return { bar, get: () => bar.querySelector('input:checked')?.value || keys[0] };
  };
  const visBar = (value, options) => {
    const s = segBar(value || 'public', options, { icons: VIS_ICONS, short: VIS_SHORT, label: 'Visibility' });
    return { node: el('div', { class: 'field' }, s.bar), get: s.get };
  };
  // models add their status row (status + featured): the section is then "Visibility & Status"
  const visibilitySection = (statusRow) => {
    fields.visibility = visBar(m.visibility || 'public', data.visibility);
    fields.password = text('Password', '', { max: 200, placeholder: 'At least 4 characters', hint: 'A new password locks out everyone who used the old one.' });
    fields.password.input.autocomplete = 'off';
    const pwIn = fields.password.input, pwWrap = el('span', { class: 'pw-gen' });
    pwIn.replaceWith(pwWrap);
    pwWrap.append(pwIn, el('button', { type: 'button', class: 'btn btn-ghost', onclick: (e) => {
      e.preventDefault();   // inside a <label>
      const sf = pwWrap.querySelector('[data-sf]');
      if (sf?.classList.contains('is-locked')) keyFieldSet(sf, true, false);
      pwIn.value = randomPassword();
      pwIn.dispatchEvent(new Event('input', { bubbles: true }));
      pwIn.focus();
    } }, 'New Password'));
    fields.password.lock = keyField(pwIn, 'Password', !!m.hasPassword);   // never sent to the page: locked while one is set
    fields.unlisted = toggle('Hide from Catalog', m.unlisted);
    const hints = {
      public: 'Everyone can see it.',
      blurred: 'Every picture is blurred (a teaser). Downloads work as usual.',
      'blurred-patrons': 'Blurred, except for logged-in patrons on a paid tier.',
      'blurred-eligible': 'Blurred, except for patrons who meet its requirements (Access: tier, months, total support). Without any, like Clear for Patrons.',
      password: 'The page and its downloads need the password.',
      draft: 'Only admins can see it.',
    };
    const hint = el('p', { class: 'hint vis-hint' });
    const pwBox = el('div', { class: 'grid2 pw-box' }, fields.password.node, fields.unlisted.node);
    const sync = () => { const v = fields.visibility.get(); hint.textContent = hints[v] || ''; pwBox.hidden = v !== 'password'; };
    fields.visibility.node.addEventListener('change', sync);
    sync();
    // comments under the page (app/comments.php): the switch only while they are on for this kind; otherwise the stored choice stays
    fields.comments = data.comments ? toggle('Allow Comments', !m.noComments) : { get: () => !m.noComments };
    form.append(section(statusRow ? 'Visibility & Status' : 'Visibility', null, fields.visibility.node, hint, pwBox, statusRow, data.comments ? el('div', { class: 'ed-comments' }, fields.comments.node) : null));
  };

  const changelogFields = (hintWhat) => {
    const bugRefs = data.bugs?.length ? { bugs: data.bugs, statuses: data.bugStatuses, name: () => fields.name?.get() || 'This ' + what } : null;   // "#" in the changes lists them
    fields.changelogNote = text('Text Above the Changelog (Optional)', m.changelogNote, { max: 1000, placeholder: 'If not specified near the version, you do not need to redownload textures.' });
    fields.changelog = repeat({
      items: m.changelog || [],
      addLabel: 'Add New Version (Goes on Top)',
      addTop: true,
      cls: 'ver-item',
      openFirst: 0,   // all versions start collapsed; a newly added one opens
      confirmRemove: 'Remove this version from the changelog?',
      blank: () => {   // the next version of the top one (3 → 4, 3.1 → 3.2, same stage), dated today
        const top = (fields.changelog?.get() || [])[0];
        const ver = top ? String(top.version || '').replace(/\d+(?!.*\d)/, (n) => String(Number(n) + 1)) : '';
        return { version: ver !== String(top?.version || '') ? ver : '', stage: top?.stage, date: data.today || new Date().toLocaleDateString('en-GB'), changes: [] };   // today in site time
      },
      build: (en) => {
        const v = text('Version', en.version, { max: 60, placeholder: 'e.g. 4 or 3.1' });
        const d = text('Date', en.date, { max: 40, placeholder: 'dd/mm/yyyy' });
        d.input.dataset.date = '';   // the calendar button (datePicker())
        const h = text('Label (Optional)', en.highlight, { max: 120, list: 'highlights', hint: 'Mentions of textures, a first release or the last version for a Blender release become a badge.' });
        const notes = list('Notes', en.notes, { max: 5000, addLabel: 'Add Paragraph', img: true });
        // a version without changes yet (a new one) starts with an empty line, ready to type
        const ch = list('Changes', en.changes?.length ? en.changes : [''], { max: 3000, addLabel: 'Add Change', placeholder: 'Fix: …   /   New: …   /   Enhancement: …',
          hint: 'New:, Fix:, Bug:, Enhancement:, Improvement:, Optimization:, Removal: or UI: at the start get a coloured tag.' + (bugRefs ? ' Type # to pick a bug report ("Fix #6: …"): on the site it links to the report, and saving marks the report fixed in this version.' : ''),
          mention: bugRefs ? (ta) => bugMention(ta, bugRefs) : null });
        const img = image('Image', en.image);
        // Notes and Image show only once used: until then "+ Add Notes" / "+ Add Image" next to "+ Add Change". Removing the last
        // paragraph or the picture hides them again (both send an input event on the form)
        const addNotes = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { notes.node.hidden = false; addNotes.hidden = true; notes.add(); } }, '+ Add Notes');
        const addImg = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Pick a picture from the Media library or upload a new one',
          onclick: () => img.node.querySelector('.img-ctl .row-btns > button').click() }, '+ Add Image');   // the field's Choose…: it shows once a picture is picked
        const optSync = () => {
          const hasNotes = notes.count() > 0, hasImg = !!img.get();
          notes.node.hidden = !hasNotes; addNotes.hidden = hasNotes;
          img.node.hidden = !hasImg; addImg.hidden = hasImg;
        };
        form.addEventListener('input', optSync);
        optSync();
        const adds = el('div', { class: 'row-btns ver-adds' }, ch.node.querySelector(':scope > .add'), addNotes, addImg);
        // release stage: Standard / α Alpha / β Beta (one at a time), a coloured badge on the site for alpha and beta
        let stage = ['alpha', 'beta'].includes(en.stage) ? en.stage : 'standard';
        const STAGE = { standard: ['Standard', '<path d="m5 12.5 4.5 4.5L19 7.5"/>'], alpha: ['Alpha', '<path d="M18.5 6.5c-1.6 5-2.7 8.4-1.4 10.8.5.9 1.4.9 2.4.2"/><path d="M16.6 12.2C15.4 8.4 13.4 6.5 11 6.5c-3 0-5 2.6-5 5.8s1.9 5.7 4.8 5.7c2.6 0 4.6-2.2 5.8-5.8z"/>'], beta: ['Beta', '<path d="M7.5 21.5V8c0-2.7 1.9-4.5 4.5-4.5 2.3 0 4 1.5 4 3.6 0 2-1.5 3.4-3.6 3.6 2.8.2 4.8 1.9 4.8 4.4 0 2.6-2.1 4.4-4.8 4.4-2 0-3.6-.9-4.9-2.3"/>'] };
        const stageBtns = Object.entries(STAGE).map(([k, [label, icon]]) => {
          const b = el('button', { type: 'button', class: 'stage-btn stage-' + k, 'aria-pressed': String(k === stage) }, label);
          b.insertAdjacentHTML('afterbegin', `<svg viewBox="0 0 24 24" aria-hidden="true">${icon}</svg>`);
          b.addEventListener('click', () => { stage = k; stageBtns.forEach((x) => x.setAttribute('aria-pressed', String(x === b))); b.dispatchEvent(new Event('input', { bubbles: true })); });   // bubbles: the title and the unsaved-changes flag update
          return b;
        });
        const stageNode = el('div', { class: 'field ver-stage' }, el('span', { class: 'lbl' }, 'Release'), el('div', { class: 'stage-pick', role: 'group', 'aria-label': 'Release' }, stageBtns));
        // "Beta 2" typed with Beta (or Standard) picked: keep "2" and pick Beta, so the site never says "Beta Beta 2"
        const stageWord = () => {
          const mt = /^\s*(alpha|beta|α|β)[\s.\-:]*(?=\S)/i.exec(v.get());
          if (!mt) return;
          const k = /^(alpha|α)$/i.test(mt[1]) ? 'alpha' : 'beta';
          v.input.value = v.get().slice(mt[0].length);
          stageBtns[Object.keys(STAGE).indexOf(k)].click();
        };
        v.input.addEventListener('change', stageWord);
        v.node.classList.add('ver-v'); d.node.classList.add('ver-d'); h.node.classList.add('ver-h');
        return {
          // one row: Version · Release · Date · Label (narrower boxes wrap it, admin.css), then the changes and the buttons under them
          body: el('div', { class: 'rep-body ver-body' }, el('div', { class: 'grid-ver' }, v.node, stageNode, d.node, h.node), ch.node, adds, notes.node, img.node),
          get: () => { stageWord(); return { version: v.get(), date: d.get(), highlight: h.get(), stage, notes: notes.get(), changes: ch.get(), image: img.get() }; },   // Ctrl+S while typing "Beta 2": no change event yet
          title: () => [(stage !== 'standard' && !v.get().toLowerCase().startsWith(stage) ? STAGE[stage][0] + ' ' : '') + (v.get() || 'New Version'), d.get()].filter(Boolean).join(' · '),   // "Beta 2 · 16/08/2026"
          // a closed version: "6 changes · notes · image" and its label, so it can be found without opening it
          meta: () => {
            const n = ch.get().length;
            const bits = [n ? `${n} ${n === 1 ? 'change' : 'changes'}` : 'No changes', notes.get().length ? 'notes' : '', img.get() ? 'image' : ''].filter(Boolean);
            return [el('span', { class: 'rep-count' }, bits.join(' · ')), h.get() ? el('span', { class: 'rep-tag', title: h.get() }, h.get()) : null];
          },
          focus: () => (v.get() ? ch.node.querySelector('textarea') : v.input),   // the next number is already there: straight to the first change
        };
      },
    });
    form.append(section('Changelog', null, fields.changelogNote.node, fields.changelog.node));
    discordPill();
  };

  // Discord announcements on: a pill in the header of the version it is about (the version out, or the top one dated later) says
  // what saving does with it (the same rules as m3d_discord_after_save() and the release dates), the whole sentence on hover / tap;
  // hidden when there is nothing to say. data.discord = admin_discord_state()
  const discordPill = () => {
    if (!data.discord) return;
    const pill = el('button', { type: 'button', class: 'tip dc-pill', hidden: true });   // .tip: the sentence in the floating .tip-pop, and a tap doesn't open / close the version
    pill.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.3 5.3A17 17 0 0 0 15 4l-.5 1.1a16 16 0 0 0-5 0L9 4a17 17 0 0 0-4.3 1.3C2 9.4 1.3 13.4 1.6 17.3A17 17 0 0 0 6.9 20l1.1-1.8a11 11 0 0 1-1.7-.8l.4-.3a12 12 0 0 0 10.6 0l.4.3-1.7.8 1.1 1.8a17 17 0 0 0 5.3-2.7c.4-4.5-.7-8.5-3.1-12zM8.7 14.9c-1 0-1.9-.9-1.9-2.1s.8-2.1 1.9-2.1 1.9 1 1.9 2.1-.8 2.1-1.9 2.1zm6.6 0c-1 0-1.9-.9-1.9-2.1s.8-2.1 1.9-2.1 1.9 1 1.9 2.1-.8 2.1-1.9 2.1z"/></svg><span></span>';
    const ymd = (d) => {   // the changelog's day formats (m3d_changelog_ts()): 13/10/2026, 13.10.26, 2026-10-13
      let mt = /^(\d{1,2})[/.](\d{1,2})[/.](\d{2}|\d{4})$/.exec(String(d || '').trim());
      if (mt) return `${mt[3].length === 2 ? '20' + mt[3] : mt[3]}-${mt[2].padStart(2, '0')}-${mt[1].padStart(2, '0')}`;
      mt = /^\d{4}-\d{2}-\d{2}$/.exec(String(d || '').trim());
      return mt ? mt[0] : '';
    };
    const label = (en) => {   // m3d_version_label(): "Beta 2", never "Beta Beta 2"
      const v = String(en.version || '').trim(), st = { alpha: 'Alpha', beta: 'Beta' }[en.stage];
      return !v || !st || v.toLowerCase().startsWith(st.toLowerCase()) ? v : `${st} ${v}`;
    };
    const ver = (l) => (/^\d/.test(l) ? 'Version ' + l : l);   // "Version 5", "Beta 2", "V5"
    let at = null;   // the version the pill talks about
    const say = (short, long = '', tone = 'off') => {
      const head = short && at ? fields.changelog.node.querySelectorAll(':scope .repeat > .rep-item')[at.i]?.querySelector(':scope > .rep-head') : null;
      if (!head) { pill.hidden = true; return; }
      if (pill.parentNode !== head) head.querySelector(':scope > .row-btns').before(pill);
      pill.hidden = false;
      pill.className = 'tip dc-pill is-' + tone;
      pill.lastChild.textContent = short;
      pill.dataset.tip = long;
      pill.setAttribute('aria-label', 'Discord: ' + long);
    };
    const sync = () => {
      const dc = data.discord;
      if (!dc) return say('');
      const today = dc.now.slice(0, 10);
      const log = fields.changelog.get().map((en, i) => ({ ...en, i })).filter((en) => String(en.version || '').trim());
      const out = log.filter((en) => { const y = ymd(en.date); return !y || y <= today; });   // undated versions are out
      const soon = log[0] && !out.includes(log[0]) ? log[0] : null;   // the top version, dated later: posted on its day
      at = soon || out[0] || null;
      if (!at) return say('');
      const vis = fields.visibility.get();
      if (vis === 'draft') return say('Not Posted · Draft', 'Not posted on Discord while it’s a draft: making it public posts its version.');
      if (vis === 'password') return say('Not Posted · Password', 'Not posted on Discord: password-protected items never are.');
      const dev = fields.status?.get() === 'in-development';   // posted with a note that it might not download yet (m3d_discord_embed())
      const pf = fields.releasePatrons?.get() || '';
      if (pf && pf > dc.now) return say('Posts on Patrons Date', 'Posted on Discord on its Patrons From date.', 'ok');
      if (soon) return say('Posts on ' + soon.date, `${ver(label(soon))} is posted on Discord on ${soon.date}.`, 'ok');
      const cur = label(out[0]), key = cur + (dev ? '~dev' : '');   // m3d_discord_code()
      if (dc.retry.includes(key)) return say('Post Failed · Retries on Save', `The Discord post of ${ver(cur)} failed: saving tries again.`, 'warn');
      if (dc.sent.includes(key)) return say('');
      // a release: the item wasn't visible, had no version, left development, or the version that was out is now below this one
      const news = !dc.open || !dc.stored || (dc.dev && !dev) || out.slice(1).map(label).includes(dc.stored);
      say(news ? 'Posts on Save' : '', `${ver(cur)} is posted on Discord when you save` + (dev ? ', with a note that it’s still in development and might not be downloadable yet.' : '.'), 'ok');
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('dc-sync', sync);
    queueMicrotask(sync);   // once every field exists
  };

  // ── Build the form ──
  form.append(el('datalist', { id: 'highlights' }, ['New Textures!', 'First release for patrons.', 'Last Blender 4.5 compatible version'].map((f) => el('option', { value: f }))));

  const base = isScene ? '/scenes/' : isOutfit ? '/accessories/' : '/models/';
  fields.name = text('Name', m.name, { placeholder: isScene ? 'e.g. Beach day' : isOutfit ? 'e.g. Summer dress' : 'e.g. Tifa Lockhart', max: 120 });
  if (data.isNew) {
    fields.slug = text('Page Address', '', { max: 64, placeholder: isScene ? 'e.g. beach-day' : isOutfit ? 'e.g. summer-dress' : 'e.g. lara-croft-v2' });
    let touched = false;
    fields.slug.input.addEventListener('input', () => (touched = true));
    fields.name.input.addEventListener('input', () => {
      if (!touched) fields.slug.input.value = slugify(fields.name.get());   // the address fits its 64 characters: what is shown is what is saved
    });
  }
  const slugNode = fields.slug?.node ?? el('div', { class: 'field' }, el('span', { class: 'lbl' }, 'Page Address'), el('code', { class: 'slug' }, `${base}${m.slug}/`));

  // scenes and accessories end alike, as their pages do: description, download box, changelog
  const sceneOrAccessoryEnd = () => {
    fields.description = area('Description (Optional)', m.description, { rows: 2, max: 5000, img: true });
    form.append(section('Description', null, fields.description.node));
    fields.blender = text('Blender Supported', m.blender, { max: 60, placeholder: 'e.g. 4.5 or above' });
    fields.addons = addonList(m.addons);
    form.append(section('Requirements', null, grid2(fields.blender.node), fields.addons.node));
    fields.links = links(m.links);
    form.append(section('Downloads', null, fields.links.node), accessSection());
    changelogFields();
  };
  if (isScene) {
    // the scene page from top to bottom: the wide picture, the models in it, description, download box, changelog
    form.append(section('Basics', null, grid2(fields.name.node, slugNode)));
    visibilitySection();
    fields.images = gallery(m.images, 'The first picture is the preview and goes across the top of the page: wide pictures (16:9) work best.', 'Pictures');
    fields.model3d = model3d(m.model3d, data.model3d, m.model3dLight);
    fields.model3dLight = { get: fields.model3d.light };
    form.append(section('Images', null, fields.images.node, fields.model3d.node));
    fields.models = scenePicker(m.models, m.modelVers);
    fields.modelVers = { get: fields.models.vers };
    form.append(section('Models in This Scene', null, fields.models.node));
    sceneOrAccessoryEnd();
  } else if (isOutfit) {
    // type: free text with suggestions (like a model's franchise); empty = Outfit. Catalog filter and the line above the name
    fields.type = text('Type', m.type || 'Outfit', { placeholder: 'e.g. Hair', max: 40,
      suggest: { values: data.types, noun: 'type', unit: ['accessory', 'accessories'] } });
    form.append(section('Basics', null, el('div', { class: 'grid-acc' }, fields.name.node, fields.type.node, slugNode)));
    visibilitySection();
    // the rest follows the accessory page: pictures, "Optimized for", description, download box, changelog
    // like a model's: catalog cards are square (.ocard-img), the page picture 9:16 (or wide: across the top); both optional, the first picture stands in
    fields.thumb = image('Catalog Image', m.thumb, '', { crop: true, aspect: 1, note: 'Best: square 1:1, at least 800 × 800 px.' });
    fields.cover = image('Main Image', m.cover, 'Top of the accessory page. Empty = the catalog image, else the first picture.', { crop: true, aspect: 9 / 16, note: 'Best: portrait 9:16, e.g. 2160 × 3840 px. A wide picture goes across the top of the page.' });
    fields.images = gallery(m.images, 'The row under the main image. Without a catalog or main image, the first picture is used for them (upright: on the left of the page, wide: across the top).', 'Pictures');
    fields.model3d = model3d(m.model3d, data.model3d, m.model3dLight);
    fields.model3dLight = { get: fields.model3d.light };
    form.append(section('Images', null, grid2(fields.thumb.node, fields.cover.node), fields.images.node, fields.model3d.node));
    fields.models = modelPicker(m.models, m.allModels, m.fits);
    fields.allModels = { get: fields.models.all };
    fields.fits = { get: fields.models.fits };
    form.append(section('Optimized For', null, fields.models.node));
    sceneOrAccessoryEnd();
  } else {
    fields.franchise = text('Franchise', m.franchise, { max: 80, placeholder: 'e.g. Final Fantasy', suggest: { values: data.franchises, noun: 'franchise', unit: ['model', 'models'] } });
    fields.game = text('Game (Optional)', m.game, { max: 120, placeholder: 'e.g. Rise of the Tomb Raider', suggest: { values: data.games, noun: 'game', unit: ['model', 'models'], group: () => fields.franchise.get() } });
    fields.generation = text('Generation', m.generation ?? '', { max: 10, placeholder: 'e.g. 4', cls: 'small-field' });
    fields.majorVersion = text('Major Version', m.majorVersion ?? '', { type: 'number', placeholder: 'e.g. 2', cls: 'small-field', hint: 'Models with the same name and a major version are shown as versions of each other (the older ones say a newer version exists). Empty = not part of a series.' });
    form.append(section('Basics', null,
      grid2(fields.name.node, slugNode),
      el('div', { class: 'grid-gen' }, fields.franchise.node, fields.game.node, fields.generation.node, fields.majorVersion.node)));

    const status = segBar(m.status, data.statuses, { icons: STATUS_ICONS, label: 'Status', cls: 'st-seg' });
    fields.status = { get: status.get };
    fields.featured = toggle('Featured', m.featured, 'Shown in the homepage slideshow.');
    // the model page's "Expected Release" fact, only while In Development
    // and how In Development models still give their files to a few people: only the download links with a password (downloads_box())
    fields.expected = text('Expected Release', m.expected, { max: 40, placeholder: 'To Be Announced', cls: 'exp-field' });
    const devBox = el('div', { class: 'dev-box' }, fields.expected.node,
      el('p', { class: 'hint' }, 'Downloads stay hidden while in development, except the download links with a password (the lock in their row): those show, for the people you give the password to.'));
    visibilitySection(el('div', {}, el('div', { class: 'grid-status' }, el('span', { class: 'lbl' }, 'Status'), status.bar, fields.featured.node), devBox));

    // catalog cards are 4:5 (.cover-card), the model page picture 9:16 (.media .cover-hero): the cropper starts with that shape
    fields.thumb = image('Catalog Image', m.thumb, '', { crop: true, aspect: 4 / 5, note: 'Best: portrait 4:5, at least 800 × 1000 px.' });
    fields.cover = image('Main Image', m.cover, 'Top of the model page and the homepage slideshow. Empty = the catalog image.', { crop: true, aspect: 9 / 16, note: 'Best: portrait 9:16, e.g. 2160 × 3840 px. A wide picture goes across the top of the page.' });
    fields.gallery = gallery(m.gallery, 'Any shape: the row under the main image shows every picture at the same height, at its own width.');
    fields.model3d = model3d(m.model3d, data.model3d, m.model3dLight);
    fields.model3dLight = { get: fields.model3d.light };
    form.append(section('Images', null, grid2(fields.thumb.node, fields.cover.node), fields.gallery.node, fields.model3d.node));

    // the rest follows the model page from top to bottom: facts and description, download box, features, notes, changelog, sidebar
    fields.description = area('Short Description (Optional)', m.description, { rows: 2, max: 5000, img: true });
    fields.note = text('Rig Note (Optional)', m.note, { max: 120, placeholder: 'e.g. ARP based model' });
    form.append(section('Description', null, fields.description.node, grid2(fields.note.node)));

    // what it needs to work: the Blender version (a fact on the page) and the add-ons (in the download box)
    fields.blender = text('Blender Supported', m.blender, { max: 60, placeholder: 'e.g. 4.5 or above' });
    fields.addons = addonList(m.addons);
    form.append(section('Requirements', null, grid2(fields.blender.node), fields.addons.node));
    fields.links = links(m.links);
    form.append(section('Downloads', null, fields.links.node), accessSection());
    const expSync = () => { devBox.hidden = fields.status.get() !== 'in-development'; };
    form.addEventListener('change', expSync);
    expSync();

    fields.features = list('Features', m.features, { addLabel: 'Add Feature', hint: 'Pasting several lines makes one item per line.' });
    form.append(section('Features', null, fields.features.node));

    fields.notes = repeat({
      items: (m.notes || []).map((n) => (typeof n === 'string' ? { text: n } : n)),
      addLabel: 'Add Note',
      openFirst: 0,   // folded to their first line; a newly added one opens
      build: (n) => {
        const t = area('Text', n.text, { rows: 2, max: 5000, img: true });
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
    form.append(section('Sidebar', null, fields.futureIdeas.node, fields.knownIssues.node, fields.credits.node, fields.extra.node,
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
  guardLeave(() => dirty);

  const listUrl = '/admin/' + data.kind + '.php';
  saveButton(async () => {
    const at = edits;
    const r = await post('/admin/save.php', { kind: data.kind, action: 'save', originalSlug: data.isNew ? '' : m.slug, rev: data.isNew ? undefined : data.rev, item: collect() });
    data.rev = r.rev;   // the next save checks against what this one stored
    if (edits === at) dirty = false;
    if (fields.password.get()) fields.password.lock();   // saved: locked like the stored one
    if (r.created) { location.href = `/admin/edit.php?kind=${data.kind}&slug=${encodeURIComponent(r.slug)}&saved=1${r.discord ? '&dc=' + (r.discord.ok ? 1 : 0) : ''}`; return true; }
    if (r.discordState !== undefined) { data.discord = r.discordState; form.dispatchEvent(new Event('dc-sync')); }
    if (r.discord?.ok) toast('✓ Saved and posted on Discord.');
    else if (r.discord) toast(`Saved, but the Discord post failed: ${(r.discord.error || 'no answer').replace(/\.+$/, '')}. Saving again tries again.`, 'err');
    else toast('✓ Saved.');
  });
  if (new URLSearchParams(location.search).has('saved')) {
    const dc = new URLSearchParams(location.search).get('dc');   // the first save posted on Discord (1) or tried and failed (0)
    toast(`✓ ${what} created${dc === '1' ? ' and posted on Discord' : ''}.`);
    if (dc === '0') toast('The Discord post failed: saving again tries again. Settings → Discord shows why.', 'err');
  }
  // Quick Add → Add Changelog Version (the header's "+", bell.js): ?add=version adds the next version on top, opens it and scrolls
  // to it (below the header that stays on top). The address loses ?add, so a reload doesn't add another
  const here = new URL(location.href);
  if (here.searchParams.get('add') === 'version') {
    here.searchParams.delete('add');
    history.replaceState(null, '', here);
    if (!data.isNew) {
      fields.changelog.add();
      const it = fields.changelog.node.querySelector('.rep-item');
      // the lowest edge of what stays on top once scrolled: the site header, and the editor's header where it is sticky
      const cover = Math.max(0, ...[...document.querySelectorAll('.site-header, .sticky-head')].map((h) => {
        const cs = getComputedStyle(h);
        return cs.position === 'sticky' ? (parseFloat(cs.top) || 0) + h.offsetHeight : 0;
      }));
      if (it) window.scrollTo({ top: it.getBoundingClientRect().top + window.scrollY - cover - 16 });
    }
  }

  // Duplicate (edit.php?copy=…): a new item filled from another one, as a draft; its address follows the name like any new item's
  if (data.copyOf) {
    fields.slug.input.value = data.copySlug || slugify(fields.name.get());   // one not taken yet (a second copy: -copy-2)
    dirty = true;
    toast(`Copy of ${data.copyOf}: not saved yet.`, 'info');
  }
  // History → Load This Version (edit.php?from=…): the form holds that version; the address goes back to the item's own, so a reload
  // shows it as it is now
  if (data.loaded) {
    dirty = true;
    here.searchParams.delete('from');
    history.replaceState(null, '', here);
    toast(`Version of ${data.loaded} loaded: not saved yet.`, 'info');
  }
  document.querySelector('[data-history]')?.addEventListener('click', () => historyWindow(data.kind, m.slug, m.name, () => {
    if (dirty && !confirm('You have unsaved changes. Replace them with this version?')) return false;
    dirty = false;   // asked once: the page's own "leave?" question doesn't come again
    return true;
  }));

  document.querySelector('[data-delete]')?.addEventListener('click', async () => {
    const answer = prompt(`To delete "${m.name}", type its name:`);
    if (answer === null) return;
    if (answer.trim() !== m.name) return toast('The name didn’t match, nothing was deleted.', 'err');
    try { await post('/admin/save.php', { kind: data.kind, action: 'delete', slug: m.slug }); dirty = false; location.href = listUrl; }
    catch (e) { toast(e.message, 'err'); }
  });
}

// ── The editor's History window: the saves that changed this item (admin/history.php, _history.php), each with what differs from the
// item now; "Load This Version" opens the editor on it (edit.php?from=…), saved only when the admin presses Save ──
async function historyWindow(kind, slug, name, leaveOk) {
  const list = el('div', { class: 'h-list' }, el('p', { class: 'muted' }, 'Loading…'));
  const close = () => { dlg.close(); dlg.remove(); };
  const dlg = el('dialog', { class: 'lc-dlg hist-dlg', 'aria-label': 'History' }, el('div', { class: 'lc-dlg-body' },
    el('h2', null, `Earlier Versions of ${name}`),
    el('p', { class: 'muted h-lead' }, 'From the last 50 saves of the ' + ({ outfits: 'accessories', scenes: 'scenes' }[kind] || 'models') + ': only the ones that changed this one, each compared with it as it is now. Loading one puts it in the editor; the site doesn’t change until you press Save.'),
    list,
    el('div', { class: 'form-actions' }, el('button', { type: 'button', class: 'btn btn-ghost', onclick: close }, 'Close'))));
  dlg.addEventListener('close', () => dlg.remove());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
  document.body.append(dlg);
  dlg.showModal();
  let rows;
  try {
    const r = await fetch(`/admin/history.php?kind=${kind}&slug=${encodeURIComponent(slug)}`, { credentials: 'same-origin', cache: 'no-store' });
    rows = (await r.json()).rows;
    if (!Array.isArray(rows)) throw new Error();
  } catch { list.replaceChildren(el('p', { class: 'muted' }, 'The history couldn’t be read. Try again in a moment.')); return; }
  if (!rows.length) { list.replaceChildren(el('p', { class: 'muted' }, 'No earlier versions: it hasn’t changed since the oldest save kept (the last 50 of the ' + ({ outfits: 'accessories', scenes: 'scenes' }[kind] || 'models') + ').')); return; }
  const lines = (cls, arr) => arr.map((t) => el('span', { class: cls }, t));
  list.replaceChildren(...rows.map((r) => {
    const diff = el('div', { class: 'h-diff', hidden: true }, r.diff.length ? r.diff.map((d) => el('div', null, el('b', null, d.label),
      d.note ? el('span', { class: 'h-note' }, d.note) : null, ...lines('h-old', d.then), ...lines('h-new', d.now))) : el('span', { class: 'muted' }, 'The same as now.'));
    const load = el('a', { class: 'btn btn-ghost btn-sm', href: `/admin/edit.php?kind=${kind}&slug=${encodeURIComponent(slug)}&from=${encodeURIComponent(r.file)}`,
      onclick: (e) => { if (!leaveOk()) e.preventDefault(); } }, 'Load This Version');
    const show = el('button', { type: 'button', class: 'btn btn-ghost btn-sm h-show', 'aria-expanded': 'false', onclick: () => {
      diff.hidden = !diff.hidden;
      show.textContent = diff.hidden ? 'Show Changes' : 'Hide Changes';
      show.setAttribute('aria-expanded', String(!diff.hidden));
      load.classList.toggle('btn-primary', !diff.hidden);
      load.classList.toggle('btn-ghost', diff.hidden);
    } }, 'Show Changes');
    return el('div', { class: 'h-row' }, el('div', { class: 'h-top' }, el('span', { class: 'h-when' }, r.when),
      el('span', { class: 'h-parts' }, r.parts.length ? r.parts.map((p) => el('span', { class: 'h-part' }, p)) : el('span', { class: 'muted small' }, 'Same as now')), show, load), diff);
  }));
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
  guardLeave(() => dirty);
})();

// ── Users (exceptions): picking a patron from the list ("Anna · 12345678") fills in the name ──
(() => {
  const id = document.querySelector('[data-ex-id]');
  const name = document.querySelector('[data-ex-name]');
  if (!id || !name) return;
  id.addEventListener('change', () => {
    const m = id.value.trim().match(/^(.+?) · [\w-]+$/);
    if (m && !name.value.trim()) name.value = m[1];
  });
})();

// ── Access → Access Exceptions: "+ Add User" opens the Add User box in place (the link opens it with a reload without JS); clearing the search shows everyone ──
(() => {
  const box = document.querySelector('[data-ex-add]');
  const open = document.querySelector('[data-ex-add-open]');
  if (!box || !open) return;
  open.addEventListener('click', (e) => {
    e.preventDefault();
    box.hidden = !box.hidden;
    if (!box.hidden) box.querySelector('[data-ex-id]').focus();
  });
  box.querySelector('[data-ex-add-close]')?.addEventListener('click', () => (box.hidden = true));
  const q = document.querySelector('.exl-bar input[type=search]');
  q?.addEventListener('search', () => { if (q.value === '' && q.defaultValue !== '') q.form.submit(); });
})();

// ── Access: what an unlock rule counts (dl_what_field()): one toggle icon per kind, any mix, at least one; the hidden field holds them joined by "+" ──
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-rule-what] .rule-kind');
  if (!b) return;
  const box = b.closest('[data-rule-what]');
  const on = b.getAttribute('aria-pressed') === 'true';
  if (on && box.querySelectorAll('.rule-kind[aria-pressed="true"]').length === 1) return;   // the last one stays on
  b.setAttribute('aria-pressed', on ? 'false' : 'true');
  const field = box.querySelector('input[type=hidden]');
  field.value = [...box.querySelectorAll('.rule-kind[aria-pressed="true"]')].map((x) => x.dataset.k).join('+');
  field.dispatchEvent(new Event('change', { bubbles: true }));
});

// ── Users (exceptions): a menu set to Custom shows its line in the Custom box under it; Add Rule / delete for their own unlock rules ──
document.querySelectorAll('[data-ex]').forEach((formEl) => {
  const panel = formEl.querySelector('[data-ex-panel]');
  const tpl = document.querySelector('[data-ex-rule-tpl]');
  const sync = () => {
    let any = false;
    formEl.querySelectorAll('[data-ex-mode]').forEach((sel) => {
      const on = sel.value === 'custom';
      sel.classList.toggle('is-custom', on);
      const line = formEl.querySelector(`[data-ex-custom="${sel.dataset.exMode}"]`);
      if (line) {
        line.hidden = !on;
        // hidden fields aren't sent or checked by the browser (a half-typed value in a hidden line would block the Save without a word)
        line.querySelectorAll('input, select, button').forEach((el) => (el.disabled = !on));
      }
      any ||= on;
    });
    if (panel) panel.hidden = !any;
  };
  sync();
  formEl.addEventListener('change', (e) => { if (e.target.matches('[data-ex-mode]')) sync(); });
  formEl.addEventListener('click', (e) => {
    const del = e.target.closest('[data-ex-rule-del]');
    const rules = formEl.querySelector('[data-ex-rules]');
    if (del) {
      del.closest('[data-ex-rule]').remove();
      if (!rules.querySelector('[data-ex-rule]')) formEl.querySelector('[data-ex-rule-add]').click();   // always one row to fill in
    }
    const add = e.target.closest('[data-ex-rule-add]');
    if (add && tpl) {
      add.before(tpl.content.cloneNode(true));
      add.previousElementSibling.querySelector('input').focus();
    }
  });
});

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
      const box = add.closest('[data-rows-box]') || formEl;   // a form with several lists (Admin → Tools): each in its own [data-rows-box]
      const rows = box.querySelector('[data-rows]');
      rows.append(box.querySelector('[data-row-template]').content.cloneNode(true));
      // rows whose switch posts the row's code (Header Menu): a new code for each added row
      const code = 'n' + Date.now().toString(36) + rows.children.length;
      rows.lastElementChild.querySelectorAll('[data-row-token], [data-row-token-on]').forEach((i) => (i.value = code));
      wire(rows.lastElementChild);
      (rows.lastElementChild.querySelector('input.input') || rows.lastElementChild.querySelector('input')).focus();
      dirty = true;
    }
  });
  formEl.addEventListener('input', () => (dirty = true));
  formEl.addEventListener('submit', () => (dirty = false));
  guardLeave(() => dirty);
});

// ── Picture fields of PHP forms (Admin → Tools): [data-img-pick="folder"] holds the input, a preview, Choose… (the picture picker) and Remove ──
document.querySelectorAll('[data-img-pick]').forEach((box) => {
  const input = box.querySelector('input');
  const prev = box.querySelector('.img-prev');
  const show = () => { const v = input.value.trim(); prev.replaceChildren(v ? el('img', { src: v, alt: '' }) : el('span', { class: 'muted small' }, 'No Image')); };
  const changed = () => { show(); input.dispatchEvent(new Event('input', { bubbles: true })); };
  input.addEventListener('change', show);
  box.querySelector('[data-img-choose]').addEventListener('click', async () => {
    const url = (await pickImages({ folder: box.dataset.imgPick, title: box.querySelector('.lbl').textContent }))[0];
    if (url) { input.value = url; changed(); }
  });
  box.querySelector('[data-img-clear]').addEventListener('click', () => { input.value = ''; changed(); });
});

// ── Admin → Pages → a page: the Simple Page | Tool switch at the top shows that type's parts ([data-for]: the other type's are hidden and disabled,
// so they aren't sent) and carries the text across (a tool's intro = a simple page's text). Switching asks first when something would go. ──
document.querySelectorAll('form[data-page-type]').forEach((form) => {
  const parts = (t) => [...form.querySelectorAll(`[data-for="${t}"]`)];
  const texts = [...form.querySelectorAll('textarea[name="intro"]')];
  const slug = form.querySelector('input[name="slug"]');
  const toolData = () => [...form.querySelectorAll('fieldset[data-for="tool"] input:not([type=checkbox]), fieldset[data-for="tool"] textarea')]
    .some((i) => i.value.trim() !== '');
  form.addEventListener('change', (ev) => {
    const r = ev.target;
    if (r.name !== 'type') return;
    const was = form.dataset.pageType;
    const to = r.value;
    const addr = `/${to === 'page' ? 'pages' : 'tools'}/${slug.value.trim() || '…'}/`;
    const saved = form.dataset.saved === '1';
    const text = (texts.find((t) => !t.closest('[disabled]')) || texts[0]).value;
    const cut = to === 'tool' && text.length > 3000;
    let warn = '';
    if (to === 'page' && toolData()) warn = 'A simple page has no logo, screenshot, buttons, features, help links or options: they are deleted when you save.';
    else if (cut) warn = 'A tool’s intro keeps only the first 3,000 characters of the text.';
    if (saved) warn += ` Its address becomes ${addr}`;
    if ((to === 'page' && toolData()) || cut || saved) {
      if (!confirm(`Switch to ${to === 'page' ? 'a simple page' : 'a tool'}? ${warn}`)) { form.querySelector(`input[name="type"][value="${was}"]`).checked = true; return; }
    }
    for (const [t, on] of [[to, true], [was, false]]) for (const p of parts(t)) { p.hidden = !on; if (p.tagName === 'FIELDSET') p.disabled = !on; }
    texts.forEach((t) => { t.value = text; });
    form.dataset.pageType = to;
    form.querySelector('input[name="name"]').placeholder = to === 'page' ? 'e.g. Commissions' : 'e.g. MustardUI';
  });
});

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
      try { sessionStorage.setItem('m3d_batch_done', `✓ ${label}: ${name(r.changed)}.` + (r.skipped?.length ? ` Skipped (no password): ${r.skipped.join(', ')}.` : '')); } catch { /* storage blocked: saved all the same */ }
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
  const filter = () => {
    const q = search.value.trim();
    const words = foldWords(q);
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

// ── Settings and Access tabs: an old address like settings.php#mustardui goes to the tab holding that section; a folded box named in the address opens ──
(() => {
  const nav = document.querySelector('[data-set-tabs]');
  if (!nav) return;
  // phones: the tab row scrolls sideways, so bring the current tab (Backups, Update, Privacy…) into view
  const cur = nav.querySelector('[aria-current="page"]');
  if (cur && nav.scrollWidth > nav.clientWidth) nav.scrollLeft += cur.getBoundingClientRect().left - nav.getBoundingClientRect().left - (nav.clientWidth - cur.offsetWidth) / 2;
  const go = () => {
    const id = decodeURIComponent(location.hash.slice(1));
    if (!id) return;
    const box = document.getElementById(id);
    if (box) { if (box.matches('details')) { box.open = true; box.scrollIntoView(); } return; }
    const tab = JSON.parse(nav.dataset.setTabs)[id];
    const first = nav.dataset.setDefault || 'site';
    const here = new URLSearchParams(location.search).get('tab') || first;
    if (tab && tab !== here && location.pathname.endsWith(nav.dataset.setPage || '/settings.php')) location.replace(`${location.pathname}${tab === first ? '' : '?tab=' + tab}#${id}`);
  };
  go();
  window.addEventListener('hashchange', go);
})();

// ── Settings → Tools / System: the counts (media, sessions, log…) take a while, so they arrive after the page,
//    in two parts side by side (?tools=quick, ?tools=media; not ?stats=, which ad blockers block); a part that fails says why where its numbers go ──
(() => {
  if (!document.querySelector('[data-stat]')) return;
  const parts = { quick: ['sessions', 'log', 'backups', 'avatars', 'disk'], media: ['unused', 'temp', 'media'] };
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
// The editors build their fields later: watchAdded(selector, fn) also runs fn on every match added to the page afterwards (one watcher for all)
const watched = [];
const watchAdded = (sel, fn) => { document.querySelectorAll(sel).forEach(fn); watched.push([sel, fn]); };
new MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach((n) => {
  if (n.nodeType !== 1) return;
  for (const [sel, fn] of watched) { if (n.matches(sel)) fn(n); else n.querySelectorAll(sel).forEach(fn); }
}))).observe(document.body, { childList: true, subtree: true });
watchAdded('input[type=number]', numStepper);

// ── Date fields: a small calendar in the site's colours instead of the browser's own. A date / datetime-local input is hidden and
// keeps its value (Y-m-d, Y-m-dTH:i: forms and the editors read it as before); a text field shows it as 31/10/2026 (14:30) next to a
// calendar button. A text field with data-date (a changelog version's Date) gets the button itself and is written as dd/mm/yyyy ──
const DP_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const dpPad = (n) => String(n).padStart(2, '0');
const dpIso = (d) => d.y + '-' + dpPad(d.m) + '-' + dpPad(d.d);
const dpShow = (d) => dpPad(d.d) + '/' + dpPad(d.m) + '/' + d.y;
// "31/10/2026", "31.10.26", "2026-10-31", each with an optional "14:30" → {y, m, d, t} (t = '' without a time); null when it isn't a real day
function dpParse(v) {
  v = String(v || '').trim();
  let mt = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2})[:.](\d{2}))?$/.exec(v), y, m, d, h, mi;
  if (mt) [, y, m, d, h, mi] = mt;
  else if ((mt = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4}|\d{2})(?:[ ,T]+(\d{1,2})[:.](\d{2}))?$/.exec(v))) [, d, m, y, h, mi] = mt;
  else return null;
  y = +y < 100 ? 2000 + +y : +y; m = +m; d = +d;
  const x = new Date(Date.UTC(y, m - 1, d));
  if (x.getUTCMonth() !== m - 1 || x.getUTCDate() !== d) return null;
  if (h !== undefined && (+h > 23 || +mi > 59)) return null;
  return { y, m, d, t: h !== undefined ? dpPad(h) + ':' + mi : '' };
}
// today in the site's time zone (<html data-tz>), as the server counts it
function dpToday() {
  try {
    const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: document.documentElement.dataset.tz || undefined, year: 'numeric', month: 'numeric', day: 'numeric' }).formatToParts(new Date()).map((x) => [x.type, +x.value]));
    return { y: p.year, m: p.month, d: p.day };
  } catch { const n = new Date(); return { y: n.getFullYear(), m: n.getMonth() + 1, d: n.getDate() }; }
}
const DP_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>';

// the one calendar popup, moved to the field that opens it
const datePop = (() => {
  let pop, cur = null, view = { y: 0, m: 1 }, focusDay = 1;
  const build = () => {
    pop = el('div', { class: 'dpk', role: 'dialog', 'aria-label': 'Choose a Date', hidden: true });
    if ('popover' in pop) pop.popover = 'manual';   // the top layer: above the editors' windows (<dialog>) too
    pop.addEventListener('pointerdown', (e) => { if (!e.target.closest('input')) e.preventDefault(); });   // the field keeps the focus
    pop.addEventListener('keydown', key);
    // focus leaving for elsewhere closes it (checked a moment later: a new month replaces the buttons and focuses the new ones)
    pop.addEventListener('focusout', () => setTimeout(() => { const a = document.activeElement; if (cur && !pop.contains(a) && !cur.wrap.contains(a)) close(); }));
    document.addEventListener('pointerdown', (e) => { if (cur && !pop.contains(e.target) && !cur.wrap.contains(e.target)) close(); }, true);
    window.addEventListener('resize', () => cur && place());
    window.addEventListener('scroll', () => cur && place(), true);
  };
  const place = () => {
    const r = cur.wrap.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight, vw = document.documentElement.clientWidth;
    const below = r.bottom + 6 + h <= innerHeight || r.top - 6 - h < 0;
    pop.style.left = Math.max(8, Math.min(r.left, vw - w - 8)) + 'px';
    pop.style.top = (below ? r.bottom + 6 : r.top - 6 - h) + 'px';
  };
  const render = () => {
    const sel = cur.get(), today = dpToday(), had = pop.contains(document.activeElement), navWas = document.activeElement?.closest?.('.dpk-nav')?.getAttribute('aria-label');
    const first = new Date(Date.UTC(view.y, view.m - 1, 1)), lead = (first.getUTCDay() + 6) % 7;   // Monday first
    const len = new Date(Date.UTC(view.y, view.m, 0)).getUTCDate();
    focusDay = Math.min(Math.max(1, focusDay), len);
    const nav = (step, label, d) => { const b = el('button', { type: 'button', class: 'dpk-nav', 'aria-label': label, onclick: () => move(step) }); b.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg>`; return b; };
    const days = [];
    for (let i = 0; i < 42; i++) {
      const x = new Date(Date.UTC(view.y, view.m - 1, 1 - lead + i)), d = { y: x.getUTCFullYear(), m: x.getUTCMonth() + 1, d: x.getUTCDate() };
      const out = d.m !== view.m, on = sel && dpIso(sel) === dpIso(d);
      if (i >= 35 && out && i % 7 === 0) break;   // no empty sixth week
      days.push(el('button', { type: 'button', class: 'dpk-day' + (out ? ' is-out' : '') + (on ? ' is-on' : '') + (dpIso(d) === dpIso(today) ? ' is-today' : ''),
        tabindex: !out && d.d === focusDay ? 0 : -1, 'aria-pressed': String(!!on), 'aria-label': d.d + ' ' + DP_MONTHS[d.m - 1] + ' ' + d.y, 'data-d': out ? '' : d.d,
        onclick: () => pick(d) }, String(d.d)));
    }
    const kids = [
      el('div', { class: 'dpk-head' }, nav(-1, 'Previous Month', 'm14.5 6-6 6 6 6'), el('b', { class: 'dpk-title', 'aria-live': 'polite' }, DP_MONTHS[view.m - 1] + ' ' + view.y), nav(1, 'Next Month', 'm9.5 6 6 6-6 6')),
      el('div', { class: 'dpk-grid', role: 'group' }, ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].map((w) => el('span', { class: 'dpk-wd', 'aria-hidden': 'true' }, w)), days),
    ];
    const foot = el('div', { class: 'dpk-foot' });
    if (cur.time) {
      const f = cur;   // its change may come after the popup closed
      const t = el('input', { class: 'input dpk-time', type: 'text', inputmode: 'numeric', maxlength: 5, placeholder: '00:00', value: sel?.t || f.lastTime, 'aria-label': 'Time' });
      t.addEventListener('change', () => {
        const mt = /^(\d{1,2})(?:[:.]?(\d{2}))?$/.exec(t.value.trim());
        if (!mt || +mt[1] > 23 || +(mt[2] || 0) > 59) { t.value = f.get()?.t || f.lastTime; return; }
        f.lastTime = t.value = dpPad(mt[1]) + ':' + (mt[2] || '00');
        const s = f.get();
        if (s) f.set({ ...s, t: f.lastTime });
      });
      t.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); t.dispatchEvent(new Event('change')); if (cur === f) close(true); } });
      foot.append(el('label', { class: 'dpk-tl' }, 'Time', t));
    }
    foot.append(el('span', { class: 'dpk-acts' },
      el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => pick(today) }, 'Today'),
      cur.get() ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { cur.set(null); close(true); } }, 'Clear') : null));
    kids.push(foot);
    pop.replaceChildren(...kids);
    if (had) (navWas ? pop.querySelector(`.dpk-nav[aria-label="${navWas}"]`) : pop.querySelector('.dpk-day[tabindex="0"]'))?.focus();
  };
  const move = (step) => { const x = new Date(Date.UTC(view.y, view.m - 1 + step, 1)); view = { y: x.getUTCFullYear(), m: x.getUTCMonth() + 1 }; render(); };
  const pick = (d) => {
    const s = cur.get();
    cur.set({ y: d.y, m: d.m, d: d.d, t: cur.time ? (pop.querySelector('.dpk-time')?.value || s?.t || cur.lastTime) : '' });
    close(true);
  };
  const key = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); return; }   // not the editor window around it
    const b = e.target.closest('.dpk-day');
    if (!b) return;
    const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    let d = +b.dataset.d || focusDay;
    if (e.key in steps) d += steps[e.key];
    else if (e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); focusDay = d; move(e.key === 'PageUp' ? -1 : 1); pop.querySelector('.dpk-day[tabindex="0"]')?.focus(); return; }
    else return;
    e.preventDefault();
    const x = new Date(Date.UTC(view.y, view.m - 1, d));
    focusDay = x.getUTCDate();
    if (x.getUTCMonth() + 1 !== view.m || x.getUTCFullYear() !== view.y) { view = { y: x.getUTCFullYear(), m: x.getUTCMonth() + 1 }; render(); }
    else pop.querySelectorAll('.dpk-day').forEach((n) => (n.tabIndex = +n.dataset.d === focusDay ? 0 : -1));
    pop.querySelector('.dpk-day[tabindex="0"]')?.focus();
  };
  const open = (f, focusGrid) => {
    if (!pop) build();
    if (cur && cur !== f) close();
    cur = f;
    // inside an open editor window (a modal <dialog>) the popup must be in it, or the window makes it unclickable
    const home = f.wrap.closest('dialog[open]') || document.body;
    if (pop.parentNode !== home) home.append(pop);
    const s = f.get() || dpToday();
    view = { y: s.y, m: s.m }; focusDay = s.d;
    render();
    pop.hidden = false;
    if (pop.showPopover && !pop.matches(':popover-open')) pop.showPopover();
    place();
    f.btn.setAttribute('aria-expanded', 'true');
    if (focusGrid) pop.querySelector('.dpk-day[tabindex="0"]')?.focus();
  };
  const close = (refocus) => {
    if (!cur) return;
    const f = cur;
    cur = null;
    if (pop.hidePopover && pop.matches(':popover-open')) pop.hidePopover();
    pop.hidden = true;
    f.btn.setAttribute('aria-expanded', 'false');
    if (refocus) f.vis.focus();
  };
  return { open, close, isOpen: (f) => cur === f, refresh: (f) => { if (cur === f) { const s = f.get(); if (s) { view = { y: s.y, m: s.m }; focusDay = s.d; } render(); } }, owns: (n) => !!pop && pop.contains(n) };
})();

function datePicker(input) {
  if (input.dataset.dp || input.closest('.date-field')) return;
  input.dataset.dp = '1';
  const native = input.type === 'date' || input.type === 'datetime-local';
  const time = input.type === 'datetime-local';
  const proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  let vis = input;
  const wrap = el('span', { class: 'date-field' });
  if (native) {
    vis = el('input', { class: input.className, type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: time ? 'dd/mm/yyyy hh:mm' : 'dd/mm/yyyy',
      'aria-label': input.getAttribute('aria-label') || null, title: input.title || null });
    vis.disabled = input.disabled;
    input.replaceWith(wrap);
    input.hidden = true;
    wrap.append(input, vis);
  } else {
    if (!input.placeholder) input.placeholder = 'dd/mm/yyyy';
    input.replaceWith(wrap);
    wrap.append(input);
  }
  const btn = el('button', { type: 'button', class: 'date-btn', 'aria-label': 'Choose a Date', 'aria-haspopup': 'dialog', 'aria-expanded': 'false' });
  btn.innerHTML = DP_ICON;
  wrap.append(btn);
  const show = (s) => (s ? dpShow(s) + (time && s.t ? ' ' + s.t : '') : '');
  const f = { wrap, vis, btn, time, lastTime: '00:00',
    get: () => dpParse(native ? proto.get.call(input) : vis.value),
    set: (s) => {   // a pick from the calendar: the field changes as if typed
      if (native) {
        proto.set.call(input, s ? dpIso(s) + (time ? 'T' + (s.t || '00:00') : '') : '');
        vis.value = show(s && time ? { ...s, t: s.t || '00:00' } : s);
      } else vis.value = s ? dpShow(s) : '';
      vis.classList.remove('is-bad');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } };
  if (native) {
    vis.value = show(f.get());
    // the editors and pages set the value from code (a saved post, a period menu): the text follows
    Object.defineProperty(input, 'value', { configurable: true, get: () => proto.get.call(input), set: (v) => { proto.set.call(input, v); vis.value = show(f.get()); vis.classList.remove('is-bad'); } });
    input.focus = () => vis.focus();
    input.setCustomValidity = (m) => { HTMLInputElement.prototype.setCustomValidity.call(input, m); vis.setCustomValidity(m); };
    // typing: the hidden field follows each whole date (so Enter sends it); leaving the field tidies the text, or puts the last good one back
    vis.addEventListener('input', (e) => {
      e.stopPropagation();   // the input's own event below is the one the page listens to
      const t = vis.value.trim(), s = dpParse(t);
      if (t === '' || (s && (!time || s.t))) { proto.set.call(input, s ? dpIso(s) + (time ? 'T' + s.t : '') : ''); input.dispatchEvent(new Event('input', { bubbles: true })); }
      datePop.refresh(f);
    });
    vis.addEventListener('change', (e) => {
      e.stopPropagation();
      const t = vis.value.trim();
      let s = dpParse(t);
      if (s && time && !s.t) s = { ...s, t: dpParse(proto.get.call(input))?.t || '00:00' };
      if (t !== '' && !s) { vis.classList.add('is-bad'); return; }
      vis.classList.remove('is-bad');
      const v = s ? dpIso(s) + (time ? 'T' + s.t : '') : '';
      vis.value = show(s);
      const was = proto.get.call(input);
      proto.set.call(input, v);
      if (v !== was) input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  } else vis.addEventListener('input', () => datePop.refresh(f));
  btn.addEventListener('click', () => (datePop.isOpen(f) ? datePop.close(true) : datePop.open(f, true)));
  vis.addEventListener('click', () => { if (!datePop.isOpen(f) && !vis.disabled && !vis.readOnly) datePop.open(f, false); });
  vis.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' && (e.altKey || datePop.isOpen(f))) { e.preventDefault(); datePop.open(f, true); }
    else if (e.key === 'Escape' && datePop.isOpen(f)) { e.preventDefault(); e.stopPropagation(); datePop.close(); }
  });
  wrap.addEventListener('focusout', (e) => { if (datePop.isOpen(f) && !wrap.contains(e.relatedTarget) && !datePop.owns(e.relatedTarget)) datePop.close(); });
}
watchAdded('input[type=date], input[type=datetime-local], input[data-date]', datePicker);

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
watchAdded('[data-md-tabs]', mdTabs);
watchAdded('textarea[data-img-drop]', (ta) => imageDrop(ta, ta.dataset.imgDrop || 'misc'));   // texts of the PHP pages (Accessories page text, welcome text)

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
  // an SVG logo: the server (GD) can't read SVG, so the browser draws it as a 512 px PNG before saving. A chosen SVG file is sent as that PNG;
  // a saved SVG logo (data: address) keeps itself and sends the PNG as `id_icon_png`, from which the server makes the tab, phone and
  // Discord icons (img_site_icon()), which can't be SVG
  const svgPng = async (svg) => {
    const doc = new DOMParser().parseFromString(svg, 'image/svg+xml').documentElement;
    if (doc.nodeName !== 'svg') throw new Error('not an SVG');
    doc.setAttribute('width', '512'); doc.setAttribute('height', '512');   // Firefox draws an SVG without a size as nothing
    const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(doc)], { type: 'image/svg+xml' }));
    try {
      const img = new Image();
      await new Promise((ok, no) => { img.onload = ok; img.onerror = no; img.src = url; });
      const c = document.createElement('canvas');
      c.width = c.height = 512;
      c.getContext('2d').drawImage(img, 0, 0, 512, 512);
      return await new Promise((ok) => c.toBlob(ok, 'image/png'));
    } finally { URL.revokeObjectURL(url); }
  };
  const put = (input, blob, name) => { const dt = new DataTransfer(); dt.items.add(new File([blob], name, { type: 'image/png' })); input.files = dt.files; };
  let converted = false;
  form.addEventListener('submit', async (e) => {
    if (converted) return;
    const file = f('logo').files[0];
    const saved = original.querySelector('img.brand-logo')?.getAttribute('src') || '';
    let target = null;
    if (file && (file.type === 'image/svg+xml' || /\.svg$/i.test(file.name))) target = f('logo');
    else if (!file && !f('nologo')?.checked && saved.startsWith('data:image/svg+xml')) target = form.querySelector('[data-id-icon-png]');
    if (!target) return;
    e.preventDefault();   // before any await: the form must not go yet
    try {
      const svg = target === f('logo') ? await file.text()
        : saved.includes(';base64,') ? atob(saved.split(',')[1]) : decodeURIComponent(saved.slice(saved.indexOf(',') + 1));
      put(target, await svgPng(svg), target === f('logo') ? 'logo.png' : 'icon.png');
    } catch (err) { if (target === f('logo')) { toast('That SVG could not be read: try a PNG.', 'err'); return; } }   // the saved logo's icons: saved without them
    converted = true;
    form.requestSubmit(e.submitter || undefined);
  });
})();

// ── Settings → Patreon: "Use this site's address" fills the redirect address ──
document.querySelector('[data-pt-use]')?.addEventListener('click', (e) => {
  const input = document.querySelector('[data-pt-redirect]'), sf = input.closest('[data-sf]');
  if (sf) keyFieldSet(sf, true, false);   // a saved address is locked: open it
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
  const warn = form.querySelector('[data-dc-ment-warn]');
  // a role written as plain text ("@Notifications") pings no one: same rule as discord_plain_mention_warning() in _admin.php
  const plainMention = (t) => [...t.matchAll(/(?:^|[\s(])@([\p{L}\p{N}_.-]*[\p{L}\p{N}_])/gu)].map((m) => m[1]).find((n) => n !== 'everyone' && n !== 'here') || '';
  msg.addEventListener('input', () => {
    out.innerHTML = discordMd(msg.value.trim());
    out.hidden = msg.value.trim() === '';
    const n = plainMention(msg.value);
    warn.textContent = n ? `“@${n}” won’t ping anyone: Discord needs the role’s code. Send \\@${n} in any Discord channel and paste here what Discord shows (<@&…>).` : '';
  });
  const hook = form.querySelector('[data-dc-hook]'), state = form.querySelector('[data-dc-hook-state]');
  const re = /^https:\/\/(?:(?:ptb|canary)\.)?discord(?:app)?\.com\/api\/webhooks\/\d{5,30}\/[A-Za-z0-9_-]{20,200}$/;   // DISCORD_WEBHOOK_RE in discord.php
  const check = () => {
    const v = hook.value.trim();   // the saved address is never in the page: an empty field keeps it (locked, key_field())
    state.className = 'dc-hook-state small ' + (re.test(v) ? 'res-ok' : 'res-err');
    state.textContent = v === '' ? '' : re.test(v) ? '✓ A Discord webhook address' : 'This isn’t a Discord webhook address: use “Copy Webhook URL” in the channel’s settings.';
  };
  hook.addEventListener('input', check);
  check();
})();

// ── Stats → the list of models and accessories: search (accents ignored, every word must match), Models / Accessories, sortable
// columns (the first sort is the table's data-tot-default, else "total"); the choices stay in the address (tq, tkind, tsort) so a reload
// keeps them.
(() => {
  const table = document.querySelector('[data-tot-table]');
  if (!table) return;
  const def = table.dataset.totDefault || 'total';
  const tbody = table.tBodies[0];
  const rows = [...tbody.querySelectorAll('tr[data-kind]')];
  const empty = tbody.querySelector('[data-tot-empty]');
  const search = document.querySelector('[data-tot-search]');
  const count = document.querySelector('[data-tot-count]');
  const kinds = [...document.querySelectorAll('[data-tot-kind]')];
  rows.forEach((r) => { r.hay = fold(r.dataset.search || r.textContent); });
  const u0 = new URL(location.href).searchParams;
  let kind = u0.get('tkind') || '';
  let [key, dir] = (u0.get('tsort') || `${def}-desc`).split('-');
  if (!table.querySelector(`[data-tot-sort="${key}"]`)) [key, dir] = [def, 'desc'];
  if (dir !== 'asc') dir = 'desc';
  if (search && u0.get('tq')) search.value = u0.get('tq');

  const remember = () => {
    const u = new URL(location.href);
    const q = search ? search.value.trim() : '';
    const set = (k, v, def) => (v && v !== def ? u.searchParams.set(k, v) : u.searchParams.delete(k));
    set('tq', q); set('tkind', kind); set('tsort', `${key}-${dir}`, `${def}-desc`);
    history.replaceState(null, '', u);
  };
  const filter = () => {
    const q = search ? search.value.trim() : '';
    const words = foldWords(q);
    let shown = 0;
    rows.forEach((r) => {
      r.hidden = (kind && r.dataset.kind !== kind) || !words.every((w) => r.hay.includes(w));
      if (!r.hidden) shown++;
    });
    kinds.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.totKind === kind)));
    if (count) { count.hidden = shown === rows.length; count.textContent = `${shown} of ${rows.length} shown`; }
    empty.hidden = shown > 0;
    empty.firstElementChild.textContent = q ? `Nothing matches “${q}”.` : 'Nothing to show.';
    remember();
  };
  const sort = () => {
    const m = dir === 'asc' ? 1 : -1;
    const byName = (a, b) => a.dataset.name.localeCompare(b.dataset.name);
    rows.sort((a, b) => (key === 'name' ? m * byName(a, b) : m * (a.dataset[key] - b.dataset[key]) || b.dataset[def] - a.dataset[def] || b.dataset.total - a.dataset.total || byName(a, b)));
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

// ── Stats → the picker (a <details>: it works without this): typing filters the list (accents ignored, every word must match),
// ↓ / Enter go to the first match, Esc or a click outside closes it.
(() => {
  const pick = document.querySelector('[data-sp-pick]');
  if (!pick) return;
  const input = pick.querySelector('[data-sp-search]');
  const opts = [...pick.querySelectorAll('[data-sp-opts] a')];
  const groups = [...pick.querySelectorAll('.sp-grp')];
  const none = pick.querySelector('[data-sp-none]');
  opts.forEach((a) => { a.hay = fold(a.dataset.search || a.textContent); });
  const filter = () => {
    const words = foldWords(input.value);
    let shown = 0;
    opts.forEach((a) => { a.hidden = !words.every((w) => a.hay.includes(w)); if (!a.hidden) shown++; });
    groups.forEach((g) => { g.hidden = !!words.length; });   // the group titles only on the whole list
    none.hidden = shown > 0;
  };
  input.addEventListener('input', filter);
  input.addEventListener('keydown', (e) => {
    const first = opts.find((a) => !a.hidden);
    if (e.key === 'Escape') { e.preventDefault(); if (input.value) { input.value = ''; filter(); } else { pick.open = false; pick.querySelector('summary').focus(); } }
    else if ((e.key === 'Enter' || e.key === 'ArrowDown') && first) { e.preventDefault(); e.key === 'Enter' ? first.click() : first.focus(); }
  });
  pick.querySelector('[data-sp-opts]').addEventListener('keydown', (e) => {
    const shown = opts.filter((a) => !a.hidden);
    const i = shown.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' && i > -1) { e.preventDefault(); shown[Math.min(i + 1, shown.length - 1)].focus(); }
    else if (e.key === 'ArrowUp' && i > -1) { e.preventDefault(); (i ? shown[i - 1] : input).focus(); }
    else if (e.key === 'Escape') { pick.open = false; pick.querySelector('summary').focus(); }
  });
  pick.addEventListener('toggle', () => {
    if (!pick.open) return;
    input.value = ''; filter();
    if (matchMedia('(hover: hover)').matches) input.focus();   // phones: no keyboard popping up over the list
    pick.querySelector('[data-sp-opts] a[aria-current]')?.scrollIntoView({ block: 'nearest' });
  });
  document.addEventListener('click', (e) => { if (pick.open && !pick.contains(e.target)) pick.open = false; });
})();

// ── Temporary links (app/shares.php): a link for someone to download one model's or accessory's files for a while, without
// Patreon. One window for the editor's Downloads box ("Temporary Link…": item = that item) and Access → Temporary Links
// ("+ New Temporary Link": items = the ones with a download link on, picked first). Expiry and downloads allowed (each file)
// are chips, the note is only for the creator; Create Link posts to admin/shares.php and the window then shows the address
// with Copy. onDone: after a link was made and the window closed (the list reloads to show it).
const SHARE_TIMES = [['1h', '1 Hour'], ['6h', '6 Hours'], ['1d', '1 Day'], ['3d', '3 Days'], ['7d', '7 Days'], ['30d', '30 Days']];
const SHARE_MAXES = [[1, '1'], [3, '3'], [5, '5'], [10, '10'], [0, 'No Limit']];
async function copyText(text, btn) {
  try { await navigator.clipboard.writeText(text); } catch { prompt('Copy this address:', text); return; }
  if (!btn) return toast('✓ Copied.');
  // the button keeps its width (table rows and the window don't move): "Copied ✓" when it fits, else "✓"
  clearTimeout(btn.copyTimer);
  btn.copyWas ??= btn.textContent;
  const w = btn.getBoundingClientRect().width;
  btn.textContent = 'Copied ✓';
  if (btn.getBoundingClientRect().width > w) btn.textContent = '✓';
  btn.style.width = `${w}px`;
  btn.copyTimer = setTimeout(() => { btn.textContent = btn.copyWas; btn.copyWas = null; btn.style.width = ''; }, 1500);
}
function shareWindow({ item = null, items = null, unsaved = false, onDone = null }) {
  const body = el('div', { class: 'lc-dlg-body' });
  const dlg = el('dialog', { class: 'lc-dlg sh-dlg', 'aria-label': 'Temporary Link' }, body);
  let made = false;
  const close = () => { dlg.close(); dlg.remove(); if (made && onDone) onDone(); };
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg) close(); });
  const cancel = () => el('button', { type: 'button', class: 'btn btn-ghost', onclick: close }, 'Cancel');
  const itemBox = (it) => el('div', { class: 'sh-item' },
    it.p ? el('img', { src: it.p, alt: '' }) : el('span', { class: 'no-img' }),
    el('div', {}, el('b', {}, it.t), el('div', { class: 'muted small' }, [it.u, it.f.length ? it.f.join(' · ') : 'No download links on'].filter(Boolean).join(' · '))));
  const chips = (list, value, label) => {
    let cur = value;
    const row = el('div', { class: 'sh-chips', role: 'group', 'aria-label': label }, list.map(([v, t]) => {
      const b = el('button', { type: 'button', class: 'chip', 'aria-pressed': String(v === cur) }, t);
      b.addEventListener('click', () => { cur = v; row.querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', String(c === b))); });
      return b;
    }));
    return { row, get: () => cur };
  };

  // 1. which model or accessory (the list page only)
  const pick = () => {
    const q = el('input', { class: 'input', type: 'search', placeholder: 'Search models and accessories…', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'Search' });
    const list = el('div', { class: 'sh-pick' });
    const fill = () => {
      const words = foldWords(q.value);
      const hits = items.filter((it) => words.every((w) => fold(`${it.t} ${it.u} ${{ outfits: 'accessory', scenes: 'scene' }[it.k] || 'model'}`).includes(w)));
      list.replaceChildren(...hits.map((it) => el('button', { type: 'button', class: 'sh-pick-row', onclick: () => form(it) },
        it.p ? el('img', { src: it.p, alt: '', loading: 'lazy' }) : el('span', { class: 'no-img' }),
        el('span', {}, el('b', {}, it.t), el('span', { class: 'muted small' }, [{ outfits: 'Accessory', scenes: 'Scene' }[it.k] || 'Model', it.u, `${it.f.length} ${it.f.length === 1 ? 'file' : 'files'}`].filter(Boolean).join(' · '))))));
      if (!hits.length) list.append(el('p', { class: 'muted' }, 'Nothing found.'));
    };
    q.addEventListener('input', fill);
    fill();
    body.replaceChildren(el('h2', {}, 'New Temporary Link'), q, list, el('div', { class: 'form-actions' }, cancel()));
    if (matchMedia('(hover: hover)').matches) q.focus();
  };

  // 2. how long, how many downloads, a note
  const form = (it) => {
    const time = chips(SHARE_TIMES, '1d', 'Expires After');
    const max = chips(SHARE_MAXES, 3, 'Downloads Allowed');
    const note = el('input', { class: 'input', type: 'text', maxlength: 120, placeholder: 'Who it’s for (only you see it)', autocomplete: 'off' });
    const go = el('button', { type: 'submit', class: 'btn btn-primary' }, 'Create Link');
    const f = el('form', { class: 'sh-form' },
      el('h2', {}, 'Temporary Link'), itemBox(it),
      unsaved ? el('p', { class: 'sh-warn' }, 'This editor has unsaved changes: the link offers the download links as they were last saved.') : null,
      el('span', { class: 'lbl' }, 'Expires After'), time.row,
      el('span', { class: 'lbl' }, 'Downloads Allowed', tip('How many times each file can be downloaded with this link.')), max.row,
      el('span', { class: 'lbl' }, 'Note'), note,
      el('div', { class: 'form-actions' }, items ? el('button', { type: 'button', class: 'btn btn-ghost act-left', onclick: pick }, '← Back') : null, cancel(), go));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      go.disabled = true;
      try {
        const d = await post('/admin/shares.php', { add: { kind: it.k, slug: it.s, time: time.get(), max: max.get(), note: note.value.trim() } });
        made = true;
        done(d, max.get());
      } catch (err) { go.disabled = false; toast(err.message, 'err'); }
    });
    body.replaceChildren(f);
  };

  // 3. the address to send
  const done = (d, max) => {
    const url = el('input', { class: 'input', readonly: true, value: d.url, 'aria-label': 'Temporary link' });
    url.addEventListener('focus', () => url.select());
    const copy = el('button', { type: 'button', class: 'btn btn-primary' }, 'Copy');
    copy.addEventListener('click', () => copyText(d.url, copy));
    body.replaceChildren(
      el('h2', {}, el('span', { class: 'sh-ok' }, '✓'), ' Link Ready'),
      el('div', { class: 'sh-copy' }, url, copy),
      el('p', { class: 'sh-meta' }, 'Until ', el('b', {}, d.until), ` · ${max ? `${max} ${max === 1 ? 'download' : 'downloads'} per file` : 'No download limit'}`),
      el('div', { class: 'form-actions' }, items ? null : el('a', { class: 'btn btn-ghost', href: '/admin/users.php?tab=links' }, 'See All Temporary Links'), el('button', { type: 'button', class: 'btn btn-primary', onclick: close }, 'Done')));
    copy.focus();
  };

  document.body.append(dlg);
  items ? pick() : form(item);
  dlg.showModal();
}

// Access → Temporary Links (admin/_shares.php): "+ New Temporary Link", Copy on each row, the chips Active / All
(() => {
  const nw = document.querySelector('[data-share-new]');
  nw?.addEventListener('click', () => shareWindow({ items: JSON.parse(nw.dataset.shareNew || '[]'), onDone: () => location.reload() }));
  document.querySelectorAll('[data-share-copy]').forEach((b) => b.addEventListener('click', () => copyText(b.dataset.shareCopy, b)));
  const table = document.querySelector('[data-share-table]');
  if (!table) return;
  const chips = document.querySelectorAll('[data-share-chip]');
  chips.forEach((c) => c.addEventListener('click', () => {
    chips.forEach((x) => x.setAttribute('aria-pressed', String(x === c)));
    const only = c.dataset.shareChip;
    let n = 0;
    table.querySelectorAll('tr[data-st]').forEach((r) => { r.hidden = !!only && r.dataset.st !== only; if (!r.hidden) n++; });
    table.querySelector('[data-share-empty]').hidden = n > 0;
  }));
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
  const rows = () => [...tbody.querySelectorAll('tr[data-lc-row]')];
  let show = chips.find((c) => c.getAttribute('aria-pressed') === 'true')?.dataset.lcChip || '';
  let running = false;
  let stop = false;

  const matches = (r) => {
    if (show === 'problems') return r.dataset.st === 'dead' || r.dataset.st === 'unknown';
    if (show.startsWith('st:')) return r.dataset.st === show.slice(3);
    if (show === 'renders') return r.dataset.lcRender !== undefined;
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

  // "Remove Link" on a patron render's dead row: the render (and its Gallery copy) lose the link, its rows leave the table
  const unfull = async (b) => {
    if (!confirm('Remove this dead link from the patron’s render?\n\nThe render stays; the viewer’s Download Full Size goes, on its Gallery copy too.')) return;
    b.disabled = true;
    try {
      const d = await post('/admin/links.php', { unfull: b.dataset.lcUnfull });
      const h = b.closest('tr').dataset.lcRow;
      tbody.querySelectorAll(`tr[data-lc-row="${CSS.escape(h)}"][data-lc-render="${CSS.escape(b.dataset.lcUnfull)}"]`).forEach((r) => r.remove());
      apply();
      toast('✓ Link removed.', 'ok');
    } catch (err) { b.disabled = false; toast(err.message, 'err'); }
  };

  tbody.addEventListener('click', async (e) => {
    const rb = e.target.closest('[data-lc-replace]');
    if (rb) { replace(rb.closest('tr')); return; }
    const ub = e.target.closest('[data-lc-unfull]');
    if (ub) { if (!ub.disabled) unfull(ub); return; }
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
    toast(bad.length ? `Done: ${bad.join(', ')}.` : '✓ All links work.', got.dead ? 'err' : 'ok');
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
    if (row) check(row).then((d) => toast(d.st === 'ok' ? '✓ New link works.' : d.st === 'dead' ? 'New link is dead too.' : 'New link couldn’t be checked.', d.st === 'ok' ? 'ok' : 'err')).catch((err) => toast(err.message, 'err'));
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
// (from the Media library or uploaded). Everything saves through save.php kind "gallery",
// which answers with the whole gallery again (admin_gallery_payload()), so the page simply redraws. ──
(() => {
  const root = document.querySelector('[data-gadmin]');
  if (!root) return;
  let G = JSON.parse(document.getElementById('gallery-data').textContent);
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
  const MODEL_VIS = { draft: 'a draft', soon: 'hidden until its release date', password: 'behind a password', blurred: 'blurred', 'blurred-patrons': 'blurred except for patrons', 'blurred-eligible': 'blurred except for eligible patrons' };
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
  let charsBox = null;   // the batch "Characters…" box while it is open: {add: names / model slugs to add, drop: Set of characters to remove}
  guardLeave(() => dirty);
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
      barSearch = el('input', { class: 'input', type: 'search', placeholder: 'Search a caption, character or scene…', 'aria-label': 'Search the pictures', value: filt.q });
      barSearch.addEventListener('input', () => { filt.q = barSearch.value; render(); });
      barChips = el('div', { class: 'chips' });
      barAll = el('button', { type: 'button', class: 'btn btn-ghost btn-sm g-sel-all', onclick: () => { shownStills().forEach((s) => ticked.add(s.id)); render(); } });
      bar = el('div', { class: 'lib-filters g-adm-tools' }, barSearch, barChips, barAll);
    }
    // the characters change with the pictures: a new picker when they do
    // scenes with pictures linked to them ("Download Scene") are in the same picker, value "scene:<slug>"
    const scenes = (G.scenes || []).filter((x) => x.count);
    const key = JSON.stringify([G.chars.map((c) => [c.key, c.name, c.gen, c.thumb]), scenes.map((x) => [x.slug, x.name, x.count, x.thumb])]);
    if (key !== barKey) {
      barKey = key;
      if (barWho?.nextSibling && barWho.nextSibling !== barChips) barWho.nextSibling.remove();   // the old picker (none with 0–1 characters)
      barWho?.remove();
      if (filt.who && !G.chars.some((c) => c.key === filt.who) && !scenes.some((x) => 'scene:' + x.slug === filt.who)) filt.who = '';
      const pics = (n) => ' (' + n + (n === 1 ? ' picture)' : ' pictures)');
      barWho = el('select', { class: 'input', 'aria-label': scenes.length ? 'Character or scene' : 'Character', 'data-search-ph': scenes.length ? 'Search characters and scenes…' : 'Search characters…' },
        el('option', { value: '', 'data-kind': 'all' }, scenes.length ? 'All Characters & Scenes' : 'All Characters'),
        G.chars.map((c) => el('option', { value: c.key, selected: c.key === filt.who, 'data-kind': c.model ? 'model' : 'other', 'data-name': c.name,
          'data-sub': c.franchise || '', 'data-gen': c.gen || '', 'data-thumb': c.thumb || '' }, c.name + (c.franchise ? ' · ' + c.franchise : '') + pics(c.count))),
        scenes.map((x) => el('option', { value: 'scene:' + x.slug, selected: 'scene:' + x.slug === filt.who, 'data-kind': 'scene', 'data-name': x.name, 'data-sub': (x.models || []).map((m) => modelBy(m)?.name).filter(Boolean).join(', ') || 'Scene',
          'data-thumb': x.thumb || '' }, x.name + ' · Scene' + pics(x.count))));
      barWho.addEventListener('change', () => { filt.who = barWho.value; render(); });
      barSearch.after(barWho);
      if (G.chars.length + scenes.length > 1) folderPicker(barWho); else barWho.hidden = true;
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
        el('p', { class: 'muted' }, 'Add pictures from the Media library or upload new ones. The gallery appears in the site menu once one picture is not a draft.'),
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
    const words = foldWords(filt.q);
    return G.stills.filter((s) => {
      if (filt.only === 'draft' && s.visibility !== 'draft') return false;
      if (filt.only === 'none' && s.who.length) return false;
      if (filt.who && (filt.who.startsWith('scene:') ? 'scene:' + (s.scene || '') !== filt.who : !s.who.some((w) => w.key === filt.who))) return false;
      const text = fold([s.caption, ...s.who.map((w) => w.name), (G.scenes || []).find((x) => x.slug === s.scene)?.name || ''].join(' '));
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
        toast('✓ Order saved.');
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
    if (s.rhidden) tags.push(el('span', { class: 'g-pill', title: 'A patron render you hid in Media → From Patrons: not shown in the Gallery until you show it there again' }, 'Hidden'));
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
    if (!ids.length) { linkBox = null; charsBox = null; return el('div', { hidden: true }); }
    const vis = el('select', { class: 'input', 'aria-label': 'Set visibility' }, el('option', { value: '' }, 'Set Visibility…'), Object.entries(G.visibility).map(([k, v]) => el('option', { value: k }, v)));
    vis.addEventListener('change', async () => {
      if (!vis.value) return;
      try { await save({ action: 'visibility', ids, value: vis.value }, `✓ ${ids.length} ${ids.length === 1 ? 'picture' : 'pictures'}: ${G.visibility[vis.value]}.`); }
      catch (e) { toast(e.message, 'err'); vis.value = ''; }
    });
    const linkBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-expanded': String(!!linkBox), onclick: () => { linkBox = linkBox ? null : linkFields(ids); charsBox = null; render(); } }, 'Set Full Size Link…');
    const charsBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-expanded': String(!!charsBox), onclick: () => { charsBox = charsBox ? null : { add: [], drop: new Set() }; linkBox = null; render(); } }, 'Characters…');
    return el('div', { class: 'batch-bar g-batch' }, el('b', {}, `${ids.length} selected`), vis, charsBtn, linkBtn,
      el('button', { type: 'button', class: 'btn btn-danger btn-sm', onclick: async () => {
        if (!confirm(`Remove ${ids.length === 1 ? 'this picture' : `these ${ids.length} pictures`} from the gallery? The pictures stay in the Media library.`)) return;
        try { await save({ action: 'delete', ids }, '✓ Removed from the gallery.'); ids.forEach((id) => ticked.delete(id)); render(); }
        catch (e) { toast(e.message, 'err'); }
      } }, 'Remove from Gallery'),
      el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { ticked.clear(); linkBox = null; render(); } }, 'Clear'),
      linkBox ? linkPanel(ids) : null, charsBox ? charsPanel(ids) : null);
  }
  // the characters of the ticked pictures: who is on them (✕ takes one off all of them, ↺ keeps it after all) and who to add to all
  function charsPanel(ids) {
    const n = ids.length, Pics = n === 1 ? 'Picture' : 'Pictures';
    const on = new Map();   // the characters on the ticked pictures: folded name → {c: as stored, n: on how many}
    for (const id of ids) for (const c of G.stills.find((x) => x.id === id)?.chars || []) {
      const k = fold(c);
      on.set(k, { c: on.get(k)?.c ?? c, n: (on.get(k)?.n || 0) + 1 });
    }
    const label = (c) => (modelBy(c) ? charName(modelBy(c)) : c);
    const apply = el('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, `Apply to ${n} ${Pics}`);
    const sync = () => { apply.disabled = !charsBox.add.length && !charsBox.drop.size; };
    const chips = el('div', { class: 'gbc-chips' });
    const drawOn = () => chips.replaceChildren(...[...on.values()].sort((a, b) => b.n - a.n || label(a.c).localeCompare(label(b.c))).map(({ c, n: k }) => {
      const gone = charsBox.drop.has(c);
      return el('span', { class: 'g-chip' + (gone ? ' is-drop' : '') }, avatar({ model: modelBy(c) ? c : null, name: modelBy(c)?.name || c }), el('span', { class: 'gbc-name' }, label(c)),
        el('small', {}, gone ? 'removed' : `on ${k} of ${n}`),
        el('button', { type: 'button', title: gone ? 'Keep' : 'Remove from these pictures', 'aria-label': (gone ? 'Keep ' : 'Remove ') + label(c),
          onclick: () => { if (gone) charsBox.drop.delete(c); else charsBox.drop.add(c); drawOn(); sync(); } }, gone ? '↺' : '×'));
    }));
    drawOn();
    sync();
    const f = el('form', { class: 'g-batch-link g-batch-chars' },
      el('b', {}, `Characters for ${n} ${Pics}`),
      on.size ? el('div', { class: 'gbc-row' }, el('span', { class: 'lbl' }, 'On These Pictures'), chips) : el('p', { class: 'muted small' }, n === 1 ? 'No character on this picture yet.' : 'No character on these pictures yet.'),
      el('div', { class: 'gbc-row' }, el('span', { class: 'lbl' }, n === 1 ? 'Add' : `Add to All ${n}`), charsField(charsBox.add, sync)),
      el('div', { class: 'form-actions' }, apply));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (apply.disabled) return;
      apply.disabled = true;
      try {
        await save({ action: 'chars', ids, add: charsBox.add, remove: [...charsBox.drop] }, '✓ Characters saved.');
        charsBox = null; render();
      } catch (err) { toast(err.message, 'err'); apply.disabled = false; }
    });
    return f;
  }
  // one full size link for every ticked picture (often one file per character); starts with their link when they all share one
  function linkFields(ids) {
    const links = ids.map((id) => G.stills.find((s) => s.id === id)?.link || null);
    const same = links[0]?.url && links.every((l) => l?.url === links[0].url && l.access === links[0].access && (l.size || '') === (links[0].size || '')) ? links[0] : null;
    return {
      url: el('input', { class: 'input', type: 'url', value: same?.url || '', placeholder: G.s4 ? 'https://… or s4:file' : 'https://… (Google Drive, Mega, your cloud…)', maxlength: 1000 }),
      access: el('select', { class: 'input', 'aria-label': 'Who can download it' }, el('option', { value: 'patrons' }, 'Patrons'), el('option', { value: 'public', selected: same?.access === 'public' }, 'Everyone')),
      size: el('input', { class: 'input', type: 'text', value: same?.size || '', maxlength: 40, placeholder: 'e.g. 4K · 3840×5760' }),
    };
  }
  function linkPanel(ids) {
    const { url, access, size } = linkBox;
    const n = ids.length, Pics = n === 1 ? 'Picture' : 'Pictures';
    const had = ids.filter((id) => G.stills.find((s) => s.id === id)?.link?.url);
    const send = async (link, msg, btn) => {
      btn.disabled = true;
      try { await save({ action: 'link', ids, link }, msg); linkBox = null; render(); }
      catch (e) { toast(e.message, 'err'); btn.disabled = false; }
    };
    const field = (label, hint, input) => el('label', { class: 'field' }, lbl(label, hint), input);
    const applyBtn = el('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, `Apply to ${n} ${Pics}`);
    const remove = had.length ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => {
      if (confirm(`Remove the full size link from ${had.length === 1 ? 'this picture' : `these ${had.length} pictures`}?`)) send({ url: '' }, '✓ Link removed.', remove);
    } }, 'Remove the Link') : null;
    const f = el('form', { class: 'g-batch-link' },
      el('b', {}, `Full Size Link for ${n} ${Pics}`),
      field('Link', '', s4Field(url, G.s4)),
      el('div', { class: 'grid2' }, field('Who Can Download It', '', access), field('Size Label', '', size)),
      had.length && had.length < n ? el('p', { class: 'muted small' }, `${had.length} of them already ${had.length === 1 ? 'has' : 'have'} a link: it will be replaced.`)
        : had.length ? el('p', { class: 'muted small' }, `${n === 1 ? 'It already has' : 'They already have'} a link: it will be replaced.`) : null,
      el('div', { class: 'form-actions' }, remove, applyBtn));
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!url.value.trim()) { toast('Paste the link first.', 'err'); url.focus(); return; }
      send({ url: url.value.trim(), access: access.value, size: size.value }, '✓ Link saved.', applyBtn);
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
      suggFlip(sugg);
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

  // the picture's scene ("Download Scene"): one chip like the characters field; typing (or focusing the empty field) lists the scenes, Backspace / × empties it
  function sceneField(value, onChange) {
    const scenes = G.scenes || [];
    let cur = value || '';
    const chips = el('span', { class: 'g-chips' });
    const input = el('input', { class: 'g-chips-in', type: 'text', 'aria-label': 'Scene', autocomplete: 'off', maxlength: 80 });
    const sugg = el('div', { class: 'g-sugg', hidden: true });
    const box = el('div', { class: 'input g-chips-box', onclick: () => input.focus() }, chips, input);
    let act = 0;
    const pic = (x) => (x?.thumb ? el('img', { class: 'ga-av', src: x.thumb, alt: '', loading: 'lazy', decoding: 'async' }) : el('span', { class: 'ga-av ga-ini', 'aria-hidden': 'true' }, initials(x?.name || cur)));
    const mods = (x) => (x?.models || []).map(modelBy).filter(Boolean);
    const faces = (x) => (mods(x).length ? el('span', { class: 'g-sugg-faces', title: mods(x).map(charName).join(', ') }, ...mods(x).slice(0, 6).map((m) => avatar({ model: m.slug, name: m.name }, 'ga-av ga-sm'))) : '');
    const draw = () => {
      const x = scenes.find((y) => y.slug === cur);
      chips.replaceChildren(...(cur ? [el('span', { class: 'g-chip' }, pic(x), x ? x.name : cur + ' (removed)', faces(x),
        el('button', { type: 'button', 'aria-label': 'Remove the scene', onclick: (e) => { e.stopPropagation(); set(''); } }, '×'))] : []));
      input.placeholder = cur ? '' : 'Type a scene…';
      box.classList.toggle('is-one', !!cur);   // a scene picked: the empty input stays on the chip's line
    };
    const set = (v) => { if (v !== cur) { cur = v; onChange(); } input.value = ''; draw(); sugg.hidden = true; };
    const suggest = () => {
      const words = fold(input.value.trim()).split(/\s+/).filter(Boolean);
      // a scene's models also match ("tifa" finds the bars she is in), and show as small faces: two scenes may share a name
      const hits = scenes.filter((x) => x.slug !== cur && words.every((w) => fold([x.name, x.slug, ...mods(x).map((m) => m.name)].join(' ')).includes(w))).slice(0, 8);
      sugg.replaceChildren(...(hits.length ? hits.map((x, i) => el('button', { type: 'button', class: 'g-sugg-row' + (i === 0 ? ' act' : ''), onmousedown: (e) => e.preventDefault(), onclick: () => set(x.slug) },
        pic(x), el('span', {}, x.name),
        faces(x),
        el('small', { class: 'muted' }, x.count ? `${x.count} ${x.count === 1 ? 'picture' : 'pictures'}` : '')))
        : [el('p', { class: 'muted small g-sugg-none' }, 'No scene found.')]));
      act = 0;
      sugg.hidden = false;
      suggFlip(sugg);
    };
    input.addEventListener('input', suggest);
    input.addEventListener('focus', suggest);
    input.addEventListener('keydown', (e) => {
      const rows = [...sugg.querySelectorAll('.g-sugg-row')];
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && rows.length) {
        e.preventDefault();
        act = (act + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length;
        rows.forEach((r, i) => r.classList.toggle('act', i === act));
      } else if (e.key === 'Enter') { e.preventDefault(); rows[act]?.click(); }
      else if (e.key === 'Backspace' && !input.value && cur) { set(''); suggest(); }
      else if (e.key === 'Escape') { sugg.hidden = true; }
    });
    input.addEventListener('blur', () => setTimeout(() => { sugg.hidden = true; }, 150));
    draw();
    return { node: el('div', { class: 'g-chars-field' }, box, sugg), get: () => cur };
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
    const url = el('input', { class: 'input', type: 'url', value: st.link.url, placeholder: G.s4 ? 'https://… or s4:file' : 'https://… (Google Drive, Mega, your cloud…)', maxlength: 1000 });
    const access = el('select', { class: 'input', 'aria-label': 'Who can download it' }, el('option', { value: 'patrons', selected: st.link.access !== 'public' }, 'Patrons'), el('option', { value: 'public', selected: st.link.access === 'public' }, 'Everyone'));
    const size = el('input', { class: 'input', type: 'text', value: st.link.size, maxlength: 40, placeholder: 'e.g. 4K · 3840×5760' });
    // the scene it was made in (Scenes): the viewer gets a "Download Scene" button to that scene's page; a scene gone since stays picked until changed
    const scenes = G.scenes || [];
    const scene = sceneField(s.scene || '', mark);
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
        mark(); toast('✓ Cropped.');
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
        field('Link', '', s4Field(url, G.s4)), el('div', { class: 'grid2' }, field('Who Can Download It', '', access), field('Size Label', '', size)),
        s.dls ? el('p', { class: 'muted small' }, `Downloaded ${s.dls} ${s.dls === 1 ? 'time' : 'times'}.`) : null),
      scenes.length || s.scene ? el('fieldset', { class: 'g-dl' }, el('legend', {}, 'Download Scene'), el('div', { class: 'field' }, lbl('Scene'), scene.node)) : '',
      el('div', { class: 'form-actions' },
        el('button', { type: 'button', class: 'btn btn-ghost btn-sm del-btn act-left', onclick: async () => {
          if (!confirm('Remove this picture from the gallery? It stays in the Media library.')) return;
          try { await save({ action: 'delete', ids: [s.id] }, '✓ Removed from the gallery.'); } catch (e) { toast(e.message, 'err'); }
        } }, 'Remove from Gallery'),
        el('button', { class: 'btn btn-primary btn-sm' }, 'Save')));
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = f.querySelector('.btn-primary');
      btn.disabled = true;
      f.inert = true;   // no typing while it saves: the panel is drawn again from the saved picture
      dirty = false;
      try {
        await save({ action: 'save', id: s.id, still: { src: src !== s.src ? src : '', caption: caption.value, chars: st.chars, date: date.value, visibility: vis.querySelector('input:checked').value, link: { url: url.value.trim(), access: access.value, size: size.value }, scene: scene.get() } }, '✓ Saved.');   // src only after a crop here: an address the Images page replaced meanwhile stays
      } catch (err) { dirty = true; f.inert = false; toast(err.message, 'err'); btn.disabled = false; }
    });
    return el('div', {}, el('h2', { class: 'g-panel-title' }, 'Edit Picture'), f);
  }

  // ── "+ Add pictures": the picture picker (Images library or upload); pictures already in the gallery are greyed out.
  // files: pictures dropped on the page (below): the picker opens on Upload New with them ──
  async function openAdd(files = []) {
    if (!leaveOk()) return;
    const srcs = await pickImages({ multiple: true, folder: 'gallery', title: 'Add Pictures', exclude: G.stills.map((s) => s.src), excludeLabel: 'Already in the Gallery',
      note: 'Pictures from a model’s folder come in tagged with that model, and from a scene’s folder linked to that scene; you can change it afterwards.', files });
    if (!srcs.length) return;
    dirty = false;   // the panel's edits were given up (leaveOk) or there were none
    try {
      const r = await save({ action: 'add', srcs }, (x) => `✓ ${x.added.length} ${x.added.length === 1 ? 'picture' : 'pictures'} added.`);
      filt.q = ''; filt.who = ''; filt.only = '';
      // the search bar shows the cleared filter too (the picker is rebuilt only when the characters change)
      if (barSearch) barSearch.value = '';
      if (barWho) { barWho.value = ''; barWho.fp?.set(''); }
      if (r.added.length === 1) sel = r.added[0];
      render();
    } catch (e) { toast(e.message, 'err'); }
  }
  document.querySelector('[data-g-add]').addEventListener('click', () => openAdd());

  // picture files dragged from the computer anywhere on the page open "+ Add pictures" on Upload New with them
  pageDrop({ hint: 'they open in Add Pictures', where: 'added to the gallery', onDrop: openAdd });

  // Quick Add → New Gallery Picture (the header's "+", bell.js): ?add=1 opens "+ Add Pictures" at once; render() rewrites the
  // address without it (setUrl), so a reload doesn't open it again
  const quickAdd = new URLSearchParams(location.search).get('add') === '1';
  render();
  if (quickAdd) openAdd();
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
  let items = null;
  let loading = null;
  let shown = [];
  let cur = 0;
  let timer = 0;
  const line = (text) => el('li', { class: 'hs-none' }, text);
  const mark = () => {
    list.querySelectorAll('[role=option]').forEach((li, i) => {
      li.setAttribute('aria-selected', String(i === cur));
      li.firstChild.classList.toggle('is-active', i === cur);
    });
    if (shown.length) { q.setAttribute('aria-activedescendant', 'jump-' + cur); list.querySelector('#jump-' + cur)?.scrollIntoView({ block: 'nearest' }); }
    else q.removeAttribute('aria-activedescendant');
  };
  const draw = () => {
    const words = foldWords(q.value);
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
      const pic = it.p ? el('img', { class: 'hs-pic', loading: 'lazy', alt: '', src: it.p })
        : el('span', { class: 'hs-pic', 'aria-hidden': 'true' }, it.t.replace(/^[#+\s\d]+/, '').slice(0, 1).toUpperCase() || '#');
      const li = el('li', { id: 'jump-' + i, role: 'option' }, el('a', { href: it.u, tabindex: '-1' }, pic,
        el('span', { class: 'hs-t' }, it.t), el('span', { class: 'hs-s' }, el('span', { class: 'hs-k' }, it.k), it.s ? ' · ' + it.s : '')));
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

// ── Admin → Home: Edit Layout (drag the widgets by ⠿ anywhere, their width by the lower right corner, remove them, add hidden ones; Save Layout posts the list) ──
(() => {
  const board = document.querySelector('[data-home-board]');
  const edit = document.querySelector('[data-home-edit]');
  if (!board || !edit) return;
  // placement: the board has 12 columns and rows of 2 px; each widget, in order, goes in its own first column (data-col; 0 = the columns where it sits
  // highest) and as high as those columns are free, spanning as many rows as its height (+ the gap under it): so each keeps its own height, stays
  // where it was put, and nothing above it moves. Phones and narrow windows: tiles two by two, boxes full width, in the same order.
  board.classList.add('is-masonry');
  const SPANS = { tile: [3, 4, 6], box: [4, 6, 8, 12] };
  const NAME = { 3: '¼', 4: '⅓', 6: '½', 8: '⅔', 12: 'Full' };
  const widgets = () => [...board.querySelectorAll(':scope > .hm-w')];
  const shown = () => widgets().filter((w) => !w.hidden);
  const narrow = () => matchMedia('(max-width: 900px)').matches;
  const gapPx = () => parseFloat(getComputedStyle(board).columnGap) || 0;
  const colPx = () => (board.clientWidth - 11 * gapPx()) / 12 + gapPx();   // one column with its gap
  const spanOf = (w) => (narrow() ? (w.dataset.kind === 'tile' ? 6 : 12) : +w.dataset.span);
  const clampCol = (c, s) => Math.min(Math.max(c, 1), 13 - s);
  // where each one goes: items [{s, col, h (rows)}] → [{c, top (row)}]
  const place = (items) => {
    const hs = new Array(12).fill(0);
    const wide = !narrow();
    if (!wide) {
      // phones: one after the other, tiles two by two, like the page without the script (admin.css)
      let row = 0, h = 0, open = false;   // the row being filled, its height, whether its right half is still free
      return items.map((it) => {
        if (open && it.s === 6) { open = false; h = Math.max(h, it.h); return { c: 7, top: row }; }
        row += h; h = it.h; open = it.s === 6;
        return { c: 1, top: row };
      });
    }
    return items.map((it) => {
      const topAt = (c) => Math.max(...hs.slice(c - 1, c - 1 + it.s));
      let c;
      if (wide && it.col) c = clampCol(it.col, it.s);
      else {
        const starts = wide ? Array.from({ length: 13 - it.s }, (_, i) => i + 1) : it.s === 6 ? [1, 7] : [1];
        c = starts.reduce((a, b) => (topAt(b) < topAt(a) ? b : a));
      }
      const top = topAt(c);
      for (let i = c - 1; i < c - 1 + it.s; i++) hs[i] = top + it.h;
      return { c, top };
    });
  };
  // a box given a height shows the list rows that fit whole: the ones cut off are hidden
  const clip = (w) => {
    const rows = w.querySelectorAll('.hm-top > li, .hm-recent tr');
    rows.forEach((r) => r.classList.remove('cut'));
    const box = w.querySelector(':scope > .sec');
    if (!w.dataset.h || !box || !rows.length) return;
    const limit = box.getBoundingClientRect().bottom - (parseFloat(getComputedStyle(box).paddingBottom) || 0) + 0.5;
    let cut = false;
    rows.forEach((r) => { if (cut || r.getBoundingClientRect().bottom > limit) { cut = true; r.classList.add('cut'); } });
  };
  const measure = () => {
    const vis = shown(), gap = gapPx();
    // the width first: the height follows it (the rows stay as they are: freeing them would shrink the board for a moment and scroll the page up)
    vis.forEach((w) => { w.style.gridColumn = 'span ' + spanOf(w); });
    vis.forEach(clip);
    return vis.map((w) => ({ w, s: spanOf(w), col: +w.dataset.col || 0, h: Math.ceil((w.offsetHeight + gap) / 2) }));
  };
  // phones outside Edit Layout: the page's own order (admin.css), nothing to place
  const apply = (items, pos) => items.forEach((it, i) => {
    const flow = narrow() && !board.classList.contains('is-editing');
    it.w.style.gridColumn = flow ? '' : pos[i].c + ' / span ' + it.s;
    it.w.style.gridRow = flow ? '' : pos[i].top + 1 + ' / span ' + it.h;
    it.w.at = pos[i];
  });
  // a box's height counts in tiles (1.5–10 in half steps, HOME_LOW / HOME_TALL in index.php): n tiles and the gaps between them, so it ends level with tiles
  // stacked beside it. While editing the box stays exactly as it will be shown (the same rows), inside its bar and padding (the chrome); nothing else is
  // added (Pi: an empty space under it that grew with its height was confusing), so there it lines up with the tiles only once saved
  const TALL = 10, LOW = 1.5;
  const unit = () => parseFloat(getComputedStyle(board).getPropertyValue('--hm-unit')) || 120;
  const extra = (w) => { const b = w.querySelector(':scope > .sec, :scope > .stat'); return b ? w.offsetHeight - b.offsetHeight : 0; };
  const sizeAll = () => {
    const u = unit(), g = gapPx();
    shown().forEach((w) => { const n = +w.dataset.h; if (n) w.style.setProperty('--h', n * u + (n - 1) * g + 'px'); });
  };
  let dragging = null;
  // while editing the board never gets shorter: a widget made shorter or moved up near the bottom of the page would scroll the page under the pointer
  const hold = () => { if (board.classList.contains('is-editing')) board.style.minHeight = Math.max(parseFloat(board.style.minHeight) || 0, board.offsetHeight) + 'px'; };
  const layout = () => { if (!dragging) { hold(); sizeAll(); const items = measure(); apply(items, place(items)); } };
  let queued = false;
  const later = () => { if (!queued) { queued = true; requestAnimationFrame(() => { queued = false; layout(); }); } };
  if ('ResizeObserver' in window) {
    const ro = new ResizeObserver(later);
    widgets().forEach((w) => ro.observe(w));
  }
  addEventListener('resize', later);
  addEventListener('load', later);   // pictures and fonts
  layout();
  const view = document.querySelector('[data-home-view]'), tools = document.querySelector('[data-home-tools]');
  const form = document.getElementById('home-layout');

  const badge = (w) => {
    const b = w.querySelector('.hm-w-size'), span = +w.dataset.span;
    if (b) b.textContent = (NAME[span] === 'Full' ? 'Full Width' : NAME[span] + ' Width') + (+w.dataset.h ? ' · ' + w.dataset.h + ' Tiles Tall' : '');
  };
  const setSpan = (w, span) => {
    w.dataset.span = span;
    w.style.setProperty('--span', span);
    if (+w.dataset.col) w.dataset.col = clampCol(+w.dataset.col, span);
    badge(w);
    layout();
  };
  // boxes: a height in tiles, half steps
  const setHeight = (w, n) => {
    w.dataset.h = Math.max(LOW, Math.min(TALL, Math.round(n * 2) / 2));
    w.style.setProperty('--tall', w.dataset.h);
    badge(w);
    layout();
  };
  // the corner: the width follows the pointer (from where the drag started), snapped to the allowed widths; for boxes the height too, in half tiles;
  // a double click gives a box back its default height
  const grip = (w) => {
    const isBox = w.dataset.kind === 'box';
    const g = el('button', { type: 'button', class: 'hm-grip', 'aria-label': (isBox ? 'Size of ' : 'Width of ') + w.dataset.title,
      title: isBox ? 'Drag to change the width and height (or focus and use the arrow keys); double-click for its usual height' : 'Drag to change the width (or focus and use ← →)' });
    const box = () => w.querySelector(':scope > .sec');
    // the nearest half tile for a widget this tall (while editing: its box, n tiles and the gaps between, and its chrome)
    const tallOf = (px) => { const g = gapPx(); return Math.round(2 * (px - extra(w) + g) / (unit() + g)) / 2; };
    g.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M14 6 6 14M14 11l-3 3"/></svg>';
    // only the widths that fit between its first column and the right edge: the corner follows the pointer and the widget never jumps left
    const fits = () => SPANS[w.dataset.kind].filter((s) => narrow() || s <= 13 - (+w.dataset.col || w.at?.c || 1) || s <= +w.dataset.span);
    const step = (dir) => {
      const spans = fits();
      const i = spans.indexOf(+w.dataset.span);
      setSpan(w, spans[Math.max(0, Math.min(spans.length - 1, (i < 0 ? 0 : i) + dir))]);
    };
    g.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); step(e.key === 'ArrowRight' ? 1 : -1); }
      else if (isBox && box() && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        setHeight(w, (+w.dataset.h || LOW) + (e.key === 'ArrowDown' ? 0.5 : -0.5));
      }
    });
    if (isBox) g.addEventListener('dblclick', () => setHeight(w, +w.dataset.def || LOW));
    g.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      e.preventDefault();
      const gap = gapPx(), col = colPx(), spans = fits();
      const x0 = e.clientX, w0 = w.getBoundingClientRect().width, y0 = e.clientY, h0 = isBox && box() ? w.offsetHeight : 0;
      let tall = false;   // the height changes only once the pointer has moved up or down a little
      w.classList.add('is-resizing');
      document.body.classList.add('is-resizing');
      if (isBox) document.body.classList.add('is-resizing-xy');
      const move = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        const want = (w0 + ev.clientX - x0 + gap) / col;
        const best = spans.reduce((a, b) => (Math.abs(b - want) < Math.abs(a - want) ? b : a));
        if (best !== +w.dataset.span) setSpan(w, best);
        if (!h0 || (!tall && Math.abs(ev.clientY - y0) < 6)) return;
        tall = true;
        const n = Math.max(LOW, Math.min(TALL, tallOf(h0 + ev.clientY - y0)));
        if (n !== +w.dataset.h) setHeight(w, n);
      };
      const end = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        removeEventListener('pointermove', move);
        removeEventListener('pointerup', end);
        removeEventListener('pointercancel', end);
        w.classList.remove('is-resizing');
        document.body.classList.remove('is-resizing', 'is-resizing-xy');
      };
      addEventListener('pointermove', move);
      addEventListener('pointerup', end);
      addEventListener('pointercancel', end);
    });
    return g;
  };
  // ⠿: the widget follows the pointer; where it would land is shown dashed (the column under its left edge, and the place in the order that puts
  // its top nearest to the pointer's); keyboard: ← → one column, ↑ ↓ above / below the widget next to it in its columns
  const reorder = (list) => board.prepend(...list);
  const mover = (w) => {
    const h = el('button', { type: 'button', class: 'drag-handle drag-sm', title: 'Drag to move (or focus and use the arrow keys)', 'aria-label': 'Move ' + w.dataset.title }, '⠿');
    h.addEventListener('keydown', (e) => {
      const k = e.key;
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(k)) return;
      e.preventDefault();
      if (k === 'ArrowLeft' || k === 'ArrowRight') {
        if (narrow()) return;
        w.dataset.col = clampCol((+w.dataset.col || w.at?.c || 1) + (k === 'ArrowRight' ? 1 : -1), +w.dataset.span);
      } else {
        const items = measure(), i = items.findIndex((it) => it.w === w), top0 = place(items)[i].top, up = k === 'ArrowUp';
        for (let j = up ? i - 1 : i + 1; j >= 0 && j < items.length; j += up ? -1 : 1) {
          const list = items.filter((it) => it.w !== w);
          list.splice(j, 0, items[i]);
          const t = place(list)[j].top;
          if (up ? t < top0 : t > top0) { reorder(list.map((it) => it.w)); break; }
        }
      }
      layout();
      h.focus();
      w.scrollIntoView({ block: 'nearest' });
    });
    h.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      e.preventDefault();
      const r = w.getBoundingClientRect();
      const dx = e.clientX - r.left, dy = e.clientY - r.top;
      const items = measure(), me = items.find((it) => it.w === w), others = items.filter((it) => it !== me);
      let at = items.indexOf(me), col = me.col || w.at?.c || 1, last = e;
      const ph = el('div', { class: 'hm-ph', 'aria-hidden': 'true' });
      ph.style.height = w.offsetHeight + 'px';
      board.append(ph);
      dragging = w;
      w.classList.add('dragging');
      document.body.classList.add('is-dragging');
      const show = (ev) => {
        const b = board.getBoundingClientRect();
        const x = ev.clientX - dx - b.left, y = ev.clientY - dy - b.top;
        if (!narrow()) col = clampCol(Math.round(x / colPx()) + 1, me.s);
        const trial = (i) => { const list = others.slice(); list.splice(i, 0, { ...me, col }); return [list, place(list)]; };
        let best = null;
        for (let i = 0; i <= others.length; i++) {
          const [list, pos] = trial(i), d = Math.abs(pos[i].top * 2 - y);
          if (!best || d < best.d || (d === best.d && Math.abs(i - at) < Math.abs(best.i - at))) best = { d, i, list, pos };
        }
        at = best.i;
        hold();
        apply(best.list, best.pos);
        ph.style.gridColumn = w.style.gridColumn;
        ph.style.gridRow = w.style.gridRow;
        w.style.transform = 'translate(' + (x - w.offsetLeft) + 'px, ' + (y - w.offsetTop) + 'px)';
      };
      // near the top or bottom of the window the page scrolls by itself
      let raf = 0;
      const edgeScroll = () => {
        raf = 0;
        const v = last.clientY < 60 ? -14 : last.clientY > innerHeight - 60 ? 14 : 0;
        if (!v || !dragging) return;
        scrollBy(0, v);
        show(last);
        raf = requestAnimationFrame(edgeScroll);
      };
      const move = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        last = ev;
        show(ev);
        if (!raf) raf = requestAnimationFrame(edgeScroll);
      };
      const end = (ev) => {
        if (ev.pointerId !== e.pointerId) return;
        removeEventListener('pointermove', move);
        removeEventListener('pointerup', end);
        removeEventListener('pointercancel', end);
        cancelAnimationFrame(raf);
        dragging = null;
        ph.remove();
        w.style.transform = '';
        w.classList.remove('dragging');
        document.body.classList.remove('is-dragging');
        const list = others.slice();
        list.splice(at, 0, me);
        reorder(list.map((it) => it.w));
        if (!narrow()) w.dataset.col = col;
        layout();
      };
      addEventListener('pointermove', move);
      addEventListener('pointerup', end);
      addEventListener('pointercancel', end);
    });
    return h;
  };
  const chrome = (w) => {
    w.prepend(el('div', { class: 'hm-w-bar' },
      mover(w),
      el('span', { class: 'nm' }, w.dataset.title),
      el('button', { type: 'button', class: 'hm-w-x', title: 'Remove', 'aria-label': 'Remove ' + w.dataset.title, onclick: () => { w.hidden = true; board.append(w); layout(); } }, '✕')));
    for (const c of w.children) if (!c.classList.contains('hm-w-bar')) c.inert = true;   // its links can't be clicked or tabbed to meanwhile
    w.append(grip(w), el('span', { class: 'hm-w-size', 'aria-hidden': 'true' }));
  };
  // leaving the page with changes not saved asks first (Save Layout and Cancel don't)
  const now = () => JSON.stringify(shown().map((w) => [w.dataset.w, +w.dataset.span, +w.dataset.col || 0, +w.dataset.h || 0]));
  let start = null;
  guardLeave(() => start !== null && now() !== start);
  // while editing every widget keeps the column it is shown in (on wide screens), so moving one never moves the others sideways
  const pin = () => { if (!narrow()) shown().forEach((w) => { if (w.at) w.dataset.col = w.at.c; }); };
  edit.addEventListener('click', () => {
    board.classList.add('is-editing');
    view.hidden = true; tools.hidden = false;
    widgets().forEach(chrome);
    widgets().forEach((w) => setSpan(w, +w.dataset.span));
    layout();
    pin();
    start = now();
  });
  document.querySelector('[data-home-cancel]').addEventListener('click', () => { start = null; location.reload(); });
  form.addEventListener('submit', () => { form.elements.layout.value = now(); start = null; });
  document.getElementById('home-reset')?.addEventListener('submit', (e) => { if (!e.defaultPrevented) start = null; });
  document.querySelector('[data-home-add]').addEventListener('click', () => {
    const hidden = widgets().filter((w) => w.hidden);
    const dlg = el('dialog', { class: 'lc-dlg', 'aria-label': 'Add Widget' });
    const close = () => { dlg.close(); dlg.remove(); };
    dlg.append(el('div', { class: 'lc-dlg-body' },
      el('h2', {}, 'Add Widget'),
      hidden.length ? el('ul', { class: 'hm-add-list' }, hidden.map((w) => el('li', {},
        el('span', { class: 'txt' }, el('b', {}, w.dataset.title), el('span', {}, w.dataset.desc)),
        el('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => { reorder([...shown(), w]); w.hidden = false; w.dataset.col = 0; layout(); pin(); close(); w.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } }, 'Add'))))
        : el('p', { class: 'muted' }, 'Every widget is already on the page.'),
      el('div', { class: 'form-actions' }, el('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: close }, 'Close'))));
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    document.body.append(dlg);
    dlg.showModal();
  });
})();

// A tab row wider than the screen (phones): the current tab scrolled into view, so a tab at the end (From Patrons, 3D Files) isn't hidden
document.querySelectorAll('nav.lib-tabs').forEach((n) => {
  const cur = n.querySelector('[aria-current="page"]');
  if (cur && n.scrollWidth > n.clientWidth) n.scrollLeft += cur.getBoundingClientRect().left - n.getBoundingClientRect().left - (n.clientWidth - cur.offsetWidth) / 2;
});

// ── Media → From Patrons (showcase_tab_html() in admin/_showcase.php): the filters of the other Media tabs (the search, every word, accents
// ignored; the model / accessory menu; Waiting Only), and with one item picked (nothing else filtering) the ⠿ on its shown cards: their order
// on its page, saved at once; Reset Order shows while that item has an order of its own. The ⋯ menus: one open at a time ──
(() => {
  const grid = document.querySelector('[data-sc-grid]');
  if (!grid) return;
  const search = document.querySelector('[data-sc-search]');
  const none = document.querySelector('[data-sc-none]');
  const folder = document.querySelector('[data-sc-folder]');
  const wait = document.querySelector('[data-sc-wait]');
  const reset = document.querySelector('[data-sc-reset]');
  const key = (f) => (f.startsWith('outfit-') ? 'outfits:' + f.slice(7) : f.startsWith('scene-') ? 'scenes:' + f.slice(6) : 'models:' + f);   // the folder → "kind:slug" (data-folder of the cards)
  const shownOf = (f) => [...grid.querySelectorAll(':scope > .sc-sh')].filter((c) => c.dataset.folder === f);
  const apply = () => {
    const words = foldWords(search.value);
    const on = words.length || folder.value || wait.checked;
    let any = false;
    grid.querySelectorAll(':scope > .sc-lib').forEach((c) => {
      const t = fold(c.dataset.search);
      const show = words.every((w) => t.includes(w)) && (!folder.value || c.dataset.folder === folder.value) && (!wait.checked || c.classList.contains('is-wait'));
      c.hidden = !show;
      any ||= show;
    });
    const one = folder.value && !words.length && !wait.checked ? shownOf(folder.value) : [];
    grid.classList.toggle('can-drag', one.length > 1);
    reset.hidden = !one.some((c) => c.hasAttribute('data-pos'));
    reset.querySelector('[name=item]').value = folder.value ? key(folder.value) : '';   // not elements.item: that is the collection's item()
    none.hidden = any || !on;
  };
  let saved = '';
  const save = async (card) => {
    const order = shownOf(card.dataset.folder).map((c) => c.id.slice(2));
    if (order.join() === saved) return;
    try {
      await post('/admin/showcase.php', { item: key(card.dataset.folder), order });
      saved = order.join();
      shownOf(card.dataset.folder).forEach((c) => c.setAttribute('data-pos', ''));
      reset.hidden = false;
      toast('✓ Order saved.');
    } catch (err) { toast(err.message, 'err'); }
  };
  grid.querySelectorAll('.sc-drag').forEach((h) => {
    h.addEventListener('pointerdown', () => { saved = shownOf(h.closest('.sc-lib').dataset.folder).map((c) => c.id.slice(2)).join(); }, true);
    h.addEventListener('keydown', () => { saved = shownOf(h.closest('.sc-lib').dataset.folder).map((c) => c.id.slice(2)).join(); }, true);
    wireDrag(h, (b) => b.closest('.sc-lib'), save);
  });
  [search, folder, wait].forEach((x) => x.addEventListener('input', apply));
  search.addEventListener('keydown', (e) => { if (e.key === 'Escape' && search.value) { search.value = ''; search.dispatchEvent(new Event('input')); } });
  apply();
  const menus = [...grid.querySelectorAll('details.pmenu')];
  const close = (keep) => menus.forEach((m) => { if (m !== keep) m.open = false; });
  menus.forEach((m) => m.addEventListener('toggle', () => { if (m.open) close(m); }));
  document.addEventListener('click', (e) => { if (!e.target.closest('details.pmenu')) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();

// ── Media → From Patrons → + Add Render (showcase_add_dialog() in admin/_showcase.php): the chosen picture shows before it is sent, a file over the
// limit is refused here as on the server (the patrons' Share window does the same in site.js); the link on the name only once a name is typed ──
(() => {
  const dlg = document.querySelector('dialog[data-sc-add]');
  const open = document.querySelector('[data-sc-add-open]');
  if (!dlg || !open || !dlg.showModal) return;
  const form = dlg.querySelector('form');
  const file = dlg.querySelector('[data-sc-file]');
  const drop = dlg.querySelector('[data-sc-drop]');
  const img = dlg.querySelector('[data-sc-img]');
  const name = dlg.querySelector('[data-sc-name]');
  const meta = dlg.querySelector('[data-sc-meta]');
  const hint = meta.textContent;
  const MAX = Number(dlg.dataset.max) || 25 * 1024 * 1024;   // m3d_showcase_max()
  const top = (Math.floor(MAX / 104857.6) / 10) + ' MB';
  let url = '';
  const pick = () => {
    const f = file.files && file.files[0];
    if (url) { URL.revokeObjectURL(url); url = ''; }
    file.setCustomValidity('');
    if (!f) { img.hidden = true; name.textContent = 'Choose a Picture…'; meta.textContent = hint; return; }
    name.textContent = f.name;
    const mb = f.size < 1048576 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB';
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { file.setCustomValidity('Pictures must be JPG, PNG or WebP.'); meta.textContent = 'Pictures must be JPG, PNG or WebP.'; img.hidden = true; return; }
    if (f.size > MAX) { file.setCustomValidity('At most ' + top + '.'); meta.textContent = mb + ': too big, at most ' + top + '.'; img.hidden = true; return; }
    url = URL.createObjectURL(f);
    img.onload = () => { meta.textContent = img.naturalWidth + ' × ' + img.naturalHeight + ' · ' + mb; };
    img.src = url;
    img.hidden = false;
    meta.textContent = mb;
  };
  file.addEventListener('change', pick);
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, () => drop.classList.add('is-over')));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, () => drop.classList.remove('is-over')));
  const made = dlg.querySelector('[data-sc-made]');
  const linkT = dlg.querySelector('[data-sc-link-t]');
  const linkH = dlg.querySelector('[data-sc-link-h]');
  const linkSync = () => {
    const named = made.value.trim() !== '';
    linkT.disabled = !named;
    linkH.disabled = !named || !linkT.value;
    const v = linkH.value.trim().replace(/^@/, '');
    const bad = !linkH.disabled && v !== '' && !/[./]/.test(v) && !(linkT.value === 'x' ? /^[A-Za-z0-9_]{1,15}$/ : /^[A-Za-z0-9_-]{1,64}$/).test(v);
    linkH.setCustomValidity(bad ? (linkT.value === 'x' ? 'X usernames have up to 15 letters, numbers or _.' : 'Use the name in their Patreon address, or paste the address.') : '');
  };
  [made, linkH].forEach((x) => x.addEventListener('input', linkSync));
  linkT.addEventListener('change', () => { linkSync(); if (linkT.value) linkH.focus(); });
  linkSync();
  const item = dlg.querySelector('[data-sc-item]');   // a folderPicker() like every select[data-folder-pick]
  open.addEventListener('click', () => dlg.showModal());
  dlg.querySelector('[data-sc-close]').addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });   // a click on the dark backdrop
  form.addEventListener('submit', (e) => {
    if (!item.value) {   // the menu is a picker button (the select is hidden): the browser can't point at it
      e.preventDefault();
      toast('Pick the model or accessory it was made with.', 'err');
      item.nextElementSibling?.querySelector('.fp-btn')?.focus();
      return;
    }
    form.querySelector('button[type=submit]').textContent = 'Adding…';
  });
  window.addEventListener('pageshow', () => { form.querySelector('button[type=submit]').textContent = 'Add Render'; pick(); linkSync(); });
  if (dlg.hasAttribute('data-open')) dlg.showModal();   // a refused add: again, with what was typed
})();

// ── Media → From Patrons → a render's window (showcase_edit_dialog() in admin/_showcase.php): a click on a card's picture opens it, filled from the
// card's data-sc (ctrl / middle click still opens the file); ‹ › and ← → go through the cards the filters show; leaving with changes asks first.
// Save posts do=edit; a waiting render gets Approve (saves and approves) and Reject; Remove / Reject / Ban post their own `do` ──
(() => {
  const dlg = document.querySelector('dialog[data-sc-edit]');
  const grid = document.querySelector('[data-sc-grid]');
  if (!dlg || !grid || !dlg.showModal) return;
  const $ = (s) => dlg.querySelector('[data-e-' + s + ']');
  const form = $('form'), item = $('form').querySelector('[data-sc-item]');
  const name = $('name'), named = $('named'), linkT = $('link-t'), linkH = $('link-h'), full = $('full-in'), caption = $('caption'), who = $('who');
  const shown = $('shown'), gallery = $('gallery');
  let cur = null, start = '';
  const D = () => JSON.parse(cur.dataset.sc);
  const snap = () => new URLSearchParams(new FormData(form)).toString();
  const dirty = () => cur && snap() !== start;
  const cards = () => [...grid.querySelectorAll(':scope > .sc-lib:not([hidden])')];
  const linkSync = () => {
    linkT.disabled = !named.checked;
    linkH.disabled = !named.checked || !linkT.value;
    const v = linkH.value.trim().replace(/^@/, '');
    const bad = !linkH.disabled && v !== '' && !/[./]/.test(v) && !(linkT.value === 'x' ? /^[A-Za-z0-9_]{1,15}$/ : /^[A-Za-z0-9_-]{1,64}$/).test(v);
    linkH.setCustomValidity(bad ? (linkT.value === 'x' ? 'X usernames have up to 15 letters, numbers or _.' : 'Use the name in their Patreon address, or paste the address.') : '');
    name.required = named.checked;
  };
  const fill = () => {
    const d = D();
    $('title').textContent = (d.name || 'A patron') + ' · ' + d.iname;
    const list = cards(), i = list.indexOf(cur);
    $('pos').textContent = list.length > 1 && i >= 0 ? (i + 1) + ' of ' + list.length : '';
    dlg.querySelectorAll('[data-e-go]').forEach((b) => { b.hidden = list.length < 2 || i < 0; });
    $('img').src = d.src;
    $('full').href = d.src;
    $('id').value = d.id;
    item.fp ? item.fp.set(d.item) : (item.value = d.item);
    name.value = d.name;
    named.checked = d.named;
    linkT.value = d.link_t;
    linkH.value = d.link_h;
    full.value = d.full;
    $('dead').hidden = !d.dead;
    full.classList.toggle('is-dead', d.dead);
    caption.value = d.caption;
    who.value = d.by;
    who.dispatchEvent(new Event('person-sync'));   // shown as the person's card (assets/people.js)
    shown.checked = !d.hidden;
    gallery.checked = d.gallery;
    $('oks').hidden = !d.ok;   // a waiting one: Approve shows it (the switch stays on, so it is sent)
    const sent = (d.added ? 'Added ' : 'Sent ') + d.sent, when = d.ok && !d.added ? 'Approved ' + d.okAt : sent;
    $('state').replaceChildren(el('span', { class: 'sc-e-pill' + (d.ok ? (d.hidden ? ' is-hid' : ' is-ok') : ' is-wait'), text: d.ok ? (d.hidden ? 'Hidden' : 'Shown') : 'Waiting' }),
      el('span', { class: 'sc-e-when', text: when, title: when === sent ? sent : sent + ' · ' + when.toLowerCase() }),
      el('a', { href: d.url + (d.ok && !d.hidden ? '#r-' + d.id : '#patron-renders'), target: '_blank', title: 'View on the ' + d.what + '’s page', text: 'View ↗' }));
    const ban = $('ban');   // a Renders ban of Access; none for a render that is nobody's, nor for an admin's
    ban.hidden = !d.canBan;
    ban.value = d.banned ? 'unban' : 'ban';
    ban.textContent = d.banned ? 'Unban' : 'Ban';
    ban.title = d.banned ? 'Banned from sharing renders: let them share again' : 'Ban them from sharing renders';
    ban.classList.toggle('is-on', !!d.banned);
    const del = $('del');
    del.value = d.ok ? 'remove' : 'reject';
    del.textContent = d.ok ? 'Remove' : 'Reject';
    $('approve').hidden = d.ok;
    linkSync();
    eyeSync();
    start = snap();
  };
  const ask = () => !dirty() || confirm('Leave without saving your changes?');
  const open = (card) => { cur = card; fill(); if (!dlg.open) dlg.showModal(); };
  const close = () => { if (!ask()) return; dlg.close(); cur = null; };
  const go = (n) => {
    const list = cards(), i = list.indexOf(cur);
    if (list.length < 2 || i < 0 || !ask()) return;
    open(list[(i + n + list.length) % list.length]);
  };
  grid.addEventListener('click', (e) => {
    const a = e.target.closest('[data-sc-open]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
    e.preventDefault();
    open(a.closest('.sc-lib'));
  });
  $('close').addEventListener('click', close);
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); if (!e.target.closest?.('.fp')) close(); });
  dlg.querySelectorAll('[data-e-go]').forEach((b) => b.addEventListener('click', () => go(Number(b.dataset.eGo))));
  dlg.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select, .fp')) return;
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); go(e.key === 'ArrowLeft' ? -1 : 1); }
  });
  const eyeSync = () => { $('eye').title = named.checked ? 'Name shown on the render: click to hide it' : 'Name hidden (“A patron”): click to show it'; };
  named.addEventListener('change', eyeSync);
  [named, linkH].forEach((x) => x.addEventListener('input', linkSync));
  linkT.addEventListener('change', () => { linkSync(); if (linkT.value) linkH.focus(); });
  form.addEventListener('submit', (e) => {
    const b = e.submitter, d = D();
    const say = {
      reject: 'Reject this render? The uploaded picture and the request are deleted for good. This can’t be undone.',
      remove: 'Remove this render?\n\nIt leaves the ' + d.what + '’s page and the Gallery, and the picture is deleted for good. This can’t be undone.',
      ban: 'Ban ' + (d.name || 'them') + ' from sharing renders?\n\nThey won’t be able to share new renders until you unban them (here or in Access). The renders they already shared stay until you remove them.',
    }[b?.value];
    if (say && !confirm(say)) { e.preventDefault(); return; }
    if (b?.name !== 'do' && !item.value) { e.preventDefault(); toast('Pick the model or accessory it was made with.', 'err'); return; }
    start = snap();   // leaving the page now is the save itself
  });
  const again = dlg.dataset.again && JSON.parse(dlg.dataset.again);   // a refused save: the window again, with what was typed
  const card = again && document.getElementById('r-' + again.id);
  if (card) {
    open(card);
    item.fp ? item.fp.set(again.item) : (item.value = again.item);
    [[name, 'name'], [linkT, 'link_t'], [linkH, 'link_h'], [full, 'full'], [caption, 'caption'], [who, 'who']].forEach(([f, k]) => { f.value = again[k]; });
    [[named, 'named'], [shown, 'shown'], [gallery, 'gallery']].forEach(([f, k]) => { f.checked = !!again[k]; });
    linkSync();
    eyeSync();
    start = '';   // still unsaved
  }
})();
