// /public/js/mixcalc-core.js
export async function solve(payload, { endpoint = '/calc/api/solve.php', signal } = {}) {
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload),
    signal
  });

  // Parse defensively to avoid "Unexpected end of JSON input"
  const text = await res.text();
  let json = null;
  if (text) {
    try { json = JSON.parse(text); }
    catch (e) {
      throw new Error(`Bad JSON (status ${res.status}): ${text.slice(0,200)}`);
    }
  }

  if (!res.ok) {
    // Surface server error message if present
    const msg = json?.error || `HTTP ${res.status}`;
    throw new Error(msg);
  }

  if (!json || json.ok !== true) {
    throw new Error(json?.error || 'Solver error');
  }
  return json;
}

/** Optional helper: build a payload from a form (names: abv, abv_t, brixatc, brixatc_t, density, density_t, sugar_wv, sugar_wv_t, abm, sbm, report_t, mode) */
export function payloadFromForm(form) {
  const v = Object.fromEntries(new FormData(form).entries());
  const num = k => (k in v && v[k] !== '') ? Number(String(v[k]).replace(',', '.')) : undefined;
  const out = { mode: v.mode || undefined };

  // Include anything numeric; api/solve.php normalizes keys.
  for (const k of Object.keys(v)) {
    if (k === 'mode') continue;
    const n = num(k);
    if (n !== undefined && !Number.isNaN(n)) out[k] = n;
  }
  return out;
}
