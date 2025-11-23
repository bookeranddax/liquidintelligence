// /recipes/combobox.js
// Lightweight combobox with type-ahead, solid keyboard control, ARIA, and safe create.
// Key features:
//  • Debounced remote fetch + client-side alphabetical sort
//  • Inline type-ahead (autocompletes and selects tail)
//  • Arrow ↑/↓ to move; Enter picks; Tab commits highlight and lets focus move
//  • No auto-open on prefetch; opens only on focus/typing
//  • Each instance owns its dropdown; no cross-talk or recursion
//  • Optional rich rows (renderRow) and create flow

export function makeCombo(opts) {
  const {
    input,              // <input> (required)
    select,             // <select> (optional, kept in sync if provided)
    fetchList,          // (q) => Promise<{items:[...]}> | Promise<[...]> | [...]
    renderLabel,        // (item) => string  (required)
    renderRow,          // (item) => HTML string (optional; falls back to renderLabel)
    onPick,             // (item|null) => void
    onCreate,           // async (name) => newId (optional)
    getId,              // (item) => any (optional, resolves ID field)
    minChars = 0,
    debounceMs = 150,
    openOnFocus = true,
    prefetch = true,         // prefetch once on init (for “recent” list)
    allowCreate = true,      // allow creation at all
    createOnlyWhenEmpty = true, // only create when list is empty
    clearable = false,       // adds an × button to clear selection
    sorter = null,           // optional client sorter: (a,b) => number
    aria = true,             // add ARIA attributes
    typeahead = true,        // inline type-ahead completion
  } = opts;

  if (!input) throw new Error('makeCombo: input is required');
  if (!renderLabel) throw new Error('makeCombo: renderLabel is required');

  // ---- helpers ----
  const escapeHtml = (s) => String(s)
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;');

  const resolveId = (it) =>
    (getId ? getId(it) :
      (it?.id ?? it?.drink_id ?? it?.ingredient_id ?? it?.value ?? it?.ID ?? null));

  // ---- state ----
  let items = [];
  let highlighted = -1;
  let open = false;
  let lastQuery = '';
  let pendingTimer = 0;
  let ignoreSelectChange = false;      // prevent select-change recursion
  let suppressNextInputFetch = false;  // ignore input fetch right after programmatic set
  let fetching = false;                // show “Searching…” while awaiting fetch

  // ---- dropdown ----
  const dd = document.createElement('div');
  dd.style.position = 'absolute';
  dd.style.zIndex = '9999';
  dd.style.minWidth = '240px';
  dd.style.maxHeight = '240px';
  dd.style.overflow = 'auto';
  dd.style.background = '#fff';
  dd.style.border = '1px solid #ddd';
  dd.style.borderRadius = '6px';
  dd.style.boxShadow = '0 8px 24px rgba(0,0,0,.12)';
  dd.style.display = 'none';
  dd.setAttribute('role', 'listbox');
  document.body.appendChild(dd);

  // ARIA wiring
  let ddId = null;
  if (aria) {
    ddId = 'cbx-' + Math.random().toString(36).slice(2);
    dd.id = ddId;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', ddId);
  }

  function positionDD() {
    const r = input.getBoundingClientRect();
    dd.style.left  = `${window.scrollX + r.left}px`;
    dd.style.top   = `${window.scrollY + r.bottom + 4}px`;
    dd.style.width = `${r.width}px`;
  }

  function closeDD() {
    open = false;
    dd.style.display = 'none';
    highlighted = -1;
    if (aria) input.setAttribute('aria-expanded', 'false');
  }

  function openDD() {
    open = true;
    dd.style.display = 'block';
    positionDD();
    if (aria) input.setAttribute('aria-expanded', 'true');
  }

  // Keep select options in sync (without forcing selection)
function syncSelectOptions() {
  if (!select) return;
  const oldValue = select.value;
  // Include a blank option to avoid auto-selecting the first real option
  const head = `<option value="" hidden></option>`;
  const html = head + items.map(i => {
    const val = resolveId(i);
    return `<option value="${val != null ? String(val) : ''}">${escapeHtml(renderLabel(i))}</option>`;
  }).join('');
  select.innerHTML = html;

  // Keep previous value if still present, otherwise keep blank
  if (oldValue && [...select.options].some(o => o.value === oldValue)) {
    ignoreSelectChange = true;
    select.value = oldValue;
    queueMicrotask(() => { ignoreSelectChange = false; });
  } else {
    ignoreSelectChange = true;
    select.value = '';           // ensure no selection by default
    queueMicrotask(() => { ignoreSelectChange = false; });
  }
}


 function makeInfoRow(text){
   const row = document.createElement('div');
   row.textContent = text;
   row.style.padding = '6px 8px';
   row.style.color = '#64748b';
   row.style.whiteSpace = 'nowrap';
   row.style.overflow = 'hidden';
   row.style.textOverflow = 'ellipsis';
   row.setAttribute('role', 'option');
   row.setAttribute('aria-disabled', 'true');
   return row;
 }


  // Render dropdown rows
  function renderRows() {
    dd.innerHTML = '';
    if (fetching) {
     dd.appendChild(makeInfoRow('Searching…'));
     return;
    }
   if (!items.length && lastQuery.length >= minChars) {
     dd.appendChild(makeInfoRow('No matches'));
     return;
   }
    items.forEach((it, idx) => {
      const row = document.createElement('div');
      row.innerHTML = renderRow ? renderRow(it) : escapeHtml(renderLabel(it));
      row.style.padding = '6px 8px';
      row.style.cursor = 'pointer';
      row.style.whiteSpace = 'nowrap';
      row.style.overflow = 'hidden';
      row.style.textOverflow = 'ellipsis';
      row.setAttribute('role', 'option');
      row.setAttribute('aria-selected', idx === highlighted ? 'true' : 'false');
      row.style.background = (idx === highlighted) ? '#f1f5f9' : '';

       // Use pointerdown; fall back to mousedown if Pointer Events aren’t supported
       const handler = (e) => {
         e.preventDefault();
         pickIndex(idx);
       };
       if (window.PointerEvent) {
         row.addEventListener('pointerdown', handler, { capture: true });
       } else {
         row.addEventListener('mousedown', handler, { capture: true });
       }

      dd.appendChild(row);
    });
  }

  // Main render: do NOT auto-open just because we fetched; only open if
  // already open OR user is focused (typing) now.
  function render({ autopen = false } = {}) {
     syncSelectOptions();
     renderRows();
 
     // Open if we have items OR we are fetching (to show "Searching…")
     // OR we have a query (to show "No matches") — and user wants it open.
     const haveDisplayContent =
       fetching || items.length > 0 || (lastQuery && lastQuery.length >= minChars);
 
     if ((open || autopen) && haveDisplayContent) {
       if (!fetching && items.length > 0 && highlighted < 0) highlighted = 0;
       openDD();
     } else {
       closeDD();
     }
   }

  function repaintHighlight() {
    // repaint highlight quickly without full render
    [...dd.children].forEach((row, i) => {
      row.style.background = (i === highlighted) ? '#f1f5f9' : '';
      row.setAttribute('aria-selected', i === highlighted ? 'true' : 'false');
    });
  }

  function move(delta) {
    if (!items.length) return;
    highlighted = (highlighted + delta + items.length) % items.length;
    repaintHighlight();
  }

  function pickIndex(idx) {
    if (idx < 0 || idx >= items.length) return;
    const it = items[idx];
    const id = resolveId(it);

    // Update input text + place caret at end (and stop next input fetch)
    suppressNextInputFetch = true;
    const label = renderLabel(it);
    input.value = label;
    input.setSelectionRange(label.length, label.length);

    // Sync <select> (guard against recursion)
    if (select) {
      ignoreSelectChange = true;
      select.value = (id != null ? String(id) : '');
      queueMicrotask(() => { ignoreSelectChange = false; });
    }

    closeDD();
    highlighted = -1;

    // Fire callback
    if (onPick) onPick(it);
  }

  // Inline type-ahead: completes and selects the tail
  function tryTypeahead() {
    if (!typeahead) return;
    const q = input.value;
    if (!q) { highlighted = items.length ? 0 : -1; return; }
    const lowerQ = q.toLowerCase();
    const cand = items.find(it => renderLabel(it).toLowerCase().startsWith(lowerQ));
    if (cand) {
      const txt = renderLabel(cand);
      if (txt.toLowerCase() !== lowerQ) {
        suppressNextInputFetch = true;      // avoid fetch loop
        input.value = txt;
        input.setSelectionRange(q.length, txt.length);
      }
      highlighted = items.indexOf(cand);
    } else {
      highlighted = items.length ? 0 : -1;
    }
  }

  // ---- fetching ----
  async function doFetch(q) {
    lastQuery = q;
    if (q.length < minChars) { items = []; fetching = false; render(); return; }
     fetching = true;
     render({ autopen: document.activeElement === input || open });
     try {
       const res = await fetchList(q);
       const list = Array.isArray(res) ? res : (res?.items || []);
       items = list ? [...list] : [];
       // client-side sort for predictability
       if (sorter) items.sort(sorter);
       else items.sort((a,b) => renderLabel(a).localeCompare(renderLabel(b), undefined, {sensitivity:'base'}));
       tryTypeahead();
     } finally {
       fetching = false;
       render({ autopen: document.activeElement === input });
     }
  }

  function scheduleFetch(q) {
    clearTimeout(pendingTimer);
    pendingTimer = setTimeout(() => doFetch(q), debounceMs);
  }

  // ---- events ----
  input.addEventListener('input', () => {
    if (suppressNextInputFetch) { suppressNextInputFetch = false; return; }
    const q = input.value.trim();
   // Open now so user sees immediate feedback, even before results arrive
   if (q.length >= minChars) {
     open = true;
     fetching = true;
     lastQuery = q;
     render({ autopen: true });
   } else {
     fetching = false;
     items = [];
     closeDD();
   }
   scheduleFetch(q);
  });

  input.addEventListener('focus', () => {
    if (!openOnFocus) return;
    if (items.length || (lastQuery && lastQuery.length >= minChars)) {
     open = true;
     render({ autopen: true });
   }
    positionDD();
  });

  window.addEventListener('resize', positionDD);
  window.addEventListener('scroll', positionDD, true);

  input.addEventListener('keydown', async (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault(); if (!open) { open = true; render({ autopen:true }); } else move(+1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); if (open) move(-1);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (open && items.length && highlighted >= 0) { pickIndex(highlighted); return; }
      // Create only if allowed and appropriate
      if (allowCreate && (!createOnlyWhenEmpty || items.length === 0) && onCreate) {
        const name = input.value.trim();
        if (name) {
          try {
            const newId = await onCreate(name);
            const res = await fetchList(name);
            const list = Array.isArray(res) ? res : (res?.items || []);
            items = list ? [...list] : [];
            if (sorter) items.sort(sorter);
            else items.sort((a,b)=>renderLabel(a).localeCompare(renderLabel(b), undefined, {sensitivity:'base'}));
            const foundIdx = items.findIndex(i => String(resolveId(i)) === String(newId));
            if (foundIdx >= 0) pickIndex(foundIdx); else closeDD();
          } catch (err) {
            console.error('create failed', err);
          }
        }
      } else {
        closeDD();
      }
    } else if (e.key === 'Tab') {
      // Commit highlight but DO NOT prevent default (so focus can move)
      if (open && highlighted >= 0) pickIndex(highlighted);
      // allow tab to proceed
    } else if (e.key === 'Escape') {
      closeDD();
    }
  });

  // Select manual change (kept working; no infinite recursion)
  if (select) {
    select.addEventListener('change', () => {
      if (ignoreSelectChange) return;
      const val = select.value;
      const idx = items.findIndex(i => String(resolveId(i)) === String(val));
      if (idx >= 0) {
        pickIndex(idx);
      } else {
        const opt = select.selectedOptions && select.selectedOptions[0];
        if (opt) {
          suppressNextInputFetch = true;
          input.value = opt.textContent || '';
          input.setSelectionRange(input.value.length, input.value.length);
        }
        closeDD();
      }
    });
  }

  // Clicking outside closes
  document.addEventListener('mousedown', (e) => {
    if (e.target === input || dd.contains(e.target)) return;
    closeDD();
  });

  // Optional clear button
  if (clearable) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = '×';
    btn.title = 'Clear';
    btn.style.marginLeft = '6px';
    btn.addEventListener('click', () => {
      suppressNextInputFetch = true;
      input.value = '';
      input.focus();
      if (select) {
        ignoreSelectChange = true;
        select.value = '';
        queueMicrotask(() => { ignoreSelectChange = false; });
      }
      closeDD();
      if (onPick) onPick(null);
    });
    input.insertAdjacentElement('afterend', btn);
  }

  // Initial prefetch (recent list) – does NOT auto-open
  (async function initPrefetch() {
    if (!prefetch) return;
    try {
      const res = await fetchList('');
      const list = Array.isArray(res) ? res : (res?.items || []);
      items = list ? [...list] : [];
      if (sorter) items.sort(sorter);
      else items.sort((a,b)=>renderLabel(a).localeCompare(renderLabel(b), undefined, {sensitivity:'base'}));
      // don't open; just prep the list for when user focuses
      render({ autopen: false });
    } catch { /* ignore */ }
  })();

  return {
    refresh: () => scheduleFetch(lastQuery),
    close: closeDD,
    destroy: () => {
      clearTimeout(pendingTimer);
      dd.remove();
    }
  };
}
