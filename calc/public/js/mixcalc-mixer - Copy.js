// /calc/public/js/mixcalc-mixer.js
// Mixer (beta): sums ingredients into a final mixture, and can solve
// for two additives (water/sugar/ethanol) to hit target ABM/SBM.
//
// Reuses the existing API /calc/api/solve.php.
// Conventions for each row (at 20 °C):
//   Supply either (ABM & SBM) or (ABV & Sugar_WV).
//   Supply either grams or mL (we'll compute the other via density).
//
// Notes:
// - For rows entered with ABV & Sugar_WV, we call mode 'abv_sugarwv' to infer ABM/SBM at 20 °C.
// - For rows with ABM & SBM, we call mode 'abm_sbm' to get Density (for g <-> mL).
// - Bulk sugar uses 0.6 mL per gram (density 1.6667 g/mL) per user spec.
// - Water density ~ 0.9982 g/mL at 20 °C. Ethanol density ~ 0.7893 g/mL at 20 °C.
//   (We could also call the solver for water & ethanol, but fixed refs are fine here).

import { solve } from './mixcalc-core.js';

// ---- Helpers ----
const nz = (x, d=null) => (x === undefined || x === null) ? d : x;
const num = (el) => {
  if (!el) return null;
  const s = String((el.value || '').trim()).replace(',', '.');
  if (s === '') return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
};
const setDisabled = (el, disabled, clear=false) => {
  if (!el) return;
  el.disabled = !!disabled;
  if (clear) el.value = '';
};
const byClass = (row, cls) => row.querySelector('.' + cls);

// Cache resolved composition per <tr> (auto GC when rows disappear)
const rowState = new WeakMap(); // row => { abm, sbm, rho, T }
function getRowState(row){ return rowState.get(row) || null; }
function setRowState(row, st){ rowState.set(row, st); }


// Use mixer UI temperature if present, else 20
function mixTemp() {
  const el = document.getElementById('mix_report_T');
  const v = Number((el?.value || '20').replace(',', '.'));
  if (!Number.isFinite(v)) return 20.0;
  return Math.max(10, Math.min(30, v));
}

// Core call: abv_sugarwv with both temps provided
async function solveFrom_ABV_SWV(ABV, SWV, T) {
  return await solve({
    mode: 'abv_sugarwv',
    ABV, ABV_T: T,
    Sugar_WV: SWV, Sugar_WV_T: T,
    report_T: T
  }, { endpoint: '/calc/api/solve.php' });
}

// Search ABV so that returned ABM matches target (for ABM + SWV)
async function searchABVForTargetABM(targetABM, SWV, T) {
  let lo = 0, hi = 96;      // practical ABV range
  let best = null, bestErr = Infinity;

  for (let i = 0; i < 14; i++) { // bisection-ish
    const mid = (lo + hi) / 2;
    const j = await solveFrom_ABV_SWV(mid, SWV, T);
    if (!j || j.ok !== true || typeof j.abm !== 'number') {
      // nudge bounds to keep searching even on failed mid
      hi = mid; continue;
    }
    const err = j.abm - targetABM;
    if (Math.abs(err) < Math.abs(bestErr)) { best = j; bestErr = err; }
    if (err > 0) hi = mid; else lo = mid;
  }
  return best; // may be null if all failed
}

// Search Sugar_WV so that returned SBM matches target (for ABV + SBM)
async function searchSWVForTargetSBM(ABV, targetSBM, T) {
  let lo = 0, hi = 1200;    // generous g/L range
  let best = null, bestErr = Infinity;

  for (let i = 0; i < 14; i++) {
    const mid = (lo + hi) / 2;
    const j = await solveFrom_ABV_SWV(ABV, mid, T);
    if (!j || j.ok !== true || typeof j.sbm !== 'number') {
      // nudge bounds to keep searching even on failed mid
      hi = mid; continue;
    }
    const err = j.sbm - targetSBM;
    if (Math.abs(err) < Math.abs(bestErr)) { best = j; bestErr = err; }
    if (err > 0) hi = mid; else lo = mid;
  }
  return best;
}

// Track which group/side was changed last on this row.
const defaultRowState = (T) => ({
  abm: null, sbm: null, abv: null, swv: null, rho: null, T,
  lastCompSource: null,   // 'comp' or 'meas'
  lastQtySource: null     // 'g' or 'ml'
});

function ensureState(row, T) {
  const cur = getRowState(row);
  if (cur && cur.T === T) return cur;
  const ns = cur ? { ...cur, T } : defaultRowState(T);
  setRowState(row, ns);
  return ns;
}

async function updateFromComp(row, ABM, SBM, T, inputs) {
  // One forward call: abm_sbm -> gives Density + the other pair
  const j = await solve(
    { mode: 'abm_sbm', abm: ABM, sbm: SBM, report_T: T },
    { endpoint: '/calc/api/solve.php' }
  );
  if (!j || j.ok !== true) return null;
  const o = j.outputs || {};
  const st = ensureState(row, T);
  st.abm = ABM; st.sbm = SBM;
  st.abv = (typeof o.ABV === 'number') ? o.ABV : null;
  st.swv = (typeof o.Sugar_WV === 'number') ? o.Sugar_WV : null;
  st.rho = (typeof o.Density === 'number') ? o.Density : null;
  st.lastCompSource = 'comp';
  // Fill the other pair in the UI (overwrite — this pair is derived)
  if (inputs.abv) inputs.abv.value = st.abv != null ? st.abv.toFixed(1) : '';
  if (inputs.swv) inputs.swv.value = st.swv != null ? st.swv.toFixed(1) : '';
  return st;
}

async function updateFromMeas(row, ABV, SWV, T, inputs) {
  // One forward call: abv_sugarwv -> gives abm/sbm + rho
  const j = await solve(
    { mode: 'abv_sugarwv', ABV, ABV_T: T, Sugar_WV: SWV, Sugar_WV_T: T, report_T: T },
    { endpoint: '/calc/api/solve.php' }
  );
  if (!j || j.ok !== true) return null;
  const o = j.outputs || {};
  const st = ensureState(row, T);
  st.abv = ABV; st.swv = SWV;
  st.abm = (typeof j.abm === 'number') ? j.abm : null;
  st.sbm = (typeof j.sbm === 'number') ? j.sbm : null;
  st.rho = (typeof o.Density === 'number') ? o.Density : null;
  st.lastCompSource = 'meas';
  // Fill the other pair in the UI (overwrite — this pair is derived)
  if (inputs.abm) inputs.abm.value = st.abm != null ? st.abm.toFixed(1) : '';
  if (inputs.sbm) inputs.sbm.value = st.sbm != null ? st.sbm.toFixed(1) : '';
  return st;
}


async function ensureRowComposition(row, v, T) {
  // v has possible ABM, SBM, ABV, Sugar_WV (numbers or null)
  // Return {abm, sbm, rho} or null on failure. Cache on success.

  const cached = getRowState(row);
  if (cached && cached.T === T && Number.isFinite(cached.rho)) {
    return cached; // already have composition + density at this T
  }

  let j = null;

  if (v.ABM != null && v.SBM != null) {
    // Forward: one call to get density at T
    j = await solve({ mode:'abm_sbm', abm: v.ABM, sbm: v.SBM, report_T: T },
                    { endpoint:'/calc/api/solve.php' });
  } else if (v.ABV != null && v.Sugar_WV != null) {
    // Forward (other pair): one call yields abm/sbm and density
    j = await solve({
      mode:'abv_sugarwv',
      ABV: v.ABV, ABV_T: T,
      Sugar_WV: v.Sugar_WV, Sugar_WV_T: T,
      report_T: T
    }, { endpoint:'/calc/api/solve.php' });
  } else if (v.ABM != null && v.Sugar_WV != null) {
    // Cross-combo: search ABV to match ABM, then we have abm/sbm/rho
    j = await searchABVForTargetABM(v.ABM, v.Sugar_WV, T);
  } else if (v.ABV != null && v.SBM != null) {
    // Cross-combo: search Sugar_WV to match SBM, then we have abm/sbm/rho
    j = await searchSWVForTargetSBM(v.ABV, v.SBM, T);
  } else {
    return null; // not enough info yet
  }

  if (!j || j.ok !== true) return null;

  const abm = (typeof j.abm === 'number') ? j.abm : null;
  const sbm = (typeof j.sbm === 'number') ? j.sbm : null;
  const rho = (j.outputs && typeof j.outputs.Density === 'number') ? j.outputs.Density : null;

  if (abm == null || sbm == null || rho == null) return null;

  const state = { abm, sbm, rho, T };
  setRowState(row, state);
  return state;
}


// ---- Row binding / logic ----
function bindRow(row) {
  const I = {
    name: byClass(row, 'mix_name'),

    abm:  byClass(row, 'mix_abm'),
    abv:  byClass(row, 'mix_abv'),

    sbm:  byClass(row, 'mix_sbm'),
    swv:  byClass(row, 'mix_swv'),

    g:    byClass(row, 'mix_g'),
    ml:   byClass(row, 'mix_ml'),
  };

  let solving = false;   // prevents overlapping solves
  let filling = false;   // we are auto-filling derived fields now

  // NEW: one shared debounce per row
  let tId = null;



  // NEW: remember last resolved state signature so we can no-op
  //let lastSig = null; think this is extraneous

  // Enforce one-of rule: when typing into X, clear/disable its sibling Think this block is unneeded
//  const lockPairs = [
//    ['abm','abv'],   // alcohol group
//    ['sbm','swv'],   // sugar group
//    ['g','ml'],      // quantity group
 // ];

  const clearRowState = (row) => { rowState.delete(row); };

  function enforceLocks(changedKey) {
    const pairs = [['abm','abv'], ['sbm','swv'], ['g','ml']];
    for (const [a,b] of pairs) {
      if (changedKey !== a && changedKey !== b) continue;
      const A = I[a], B = I[b];
      if (!A || !B) continue;
      const aTxt = A.value.trim();
      const bTxt = B.value.trim();
      if (aTxt && bTxt) {
        // keep the one the user just edited; clear the other (stay enabled)
        if (changedKey === a) { B.value = ''; } else { A.value = ''; }
      }
      // always keep both enabled
      setDisabled(A, false);
      setDisabled(B, false);
    }
  }



async function recomputeIfReady(changedKey) {
  if (solving || filling) return;
  const T = mixTemp();
  const st = ensureState(row, T);

  const v = {
    ABM: num(I.abm),   SBM: num(I.sbm),
    ABV: num(I.abv),   SWV: num(I.swv),
    g:   num(I.g),     ml:  num(I.ml),
  };

  // Track which group got edited last
  if (changedKey === 'abm' || changedKey === 'sbm') st.lastCompSource = 'comp';
  if (changedKey === 'abv' || changedKey === 'swv') st.lastCompSource = 'meas';
  if (changedKey === 'g')  st.lastQtySource = 'g';
  if (changedKey === 'ml') st.lastQtySource = 'ml';

  solving = true;
  try {
    // 1) Authoritative pair resolution (one call)
    if (st.lastCompSource === 'comp') {
      if (v.ABM != null && v.SBM != null) {
        filling = true;
        const ok = await updateFromComp(row, v.ABM, v.SBM, T, { abv:I.abv, swv:I.swv });
        filling = false;
      } else {
        // not enough to resolve yet
        return;
      }
    } else if (st.lastCompSource === 'meas') {
      if (v.ABV != null && v.SWV != null) {
        filling = true;
        const ok = await updateFromMeas(row, v.ABV, v.SWV, T, { abm:I.abm, sbm:I.sbm });
        filling = false;
      } else {
        return;
      }
    } else {
      // No authoritative pair yet — try to infer from existing data once
      if (v.ABM != null && v.SBM != null) {
        filling = true;
        const ok = await updateFromComp(row, v.ABM, v.SBM, T, { abv:I.abv, swv:I.swv });
        filling = false;
      } else if (v.ABV != null && v.SWV != null) {
        filling = true;
        const ok = await updateFromMeas(row, v.ABV, v.SWV, T, { abm:I.abm, sbm:I.sbm });
        filling = false;
      } else {
        return;
      }
    }

    // Refresh local snapshot after update
    const st2 = getRowState(row);
    const rho = st2?.rho;

    // 2) Quantity sync (hold last edited side)
    filling = true;
    if (rho && rho > 0) {
      if (st.lastQtySource === 'g') {
        if (v.g != null && I.ml) I.ml.value = (v.g / rho).toFixed(1);
      } else if (st.lastQtySource === 'ml') {
        if (v.ml != null && I.g) I.g.value = (v.ml * rho).toFixed(1);
      } else {
        // No preference yet — fill the missing one if exactly one exists
        if (v.g != null && v.ml == null && I.ml) I.ml.value = (v.g / rho).toFixed(1);
        if (v.ml != null && v.g  == null && I.g ) I.g.value  = (v.ml * rho).toFixed(1);
      }
    }
    filling = false;

  } finally {
    solving = false;
  }
}





  // Attach input handlers (input + change) for all fields
  Object.entries(I).forEach(([key, el]) => {
    if (!el || key === 'name') return;

    const handler = () => {
      if (filling) return;          // ignore programmatic writes
      enforceLocks(key);            // only enforce for the user-edited key
      clearTimeout(tId);            // <-- use the row-level tId
      tId = setTimeout(() => {      // <-- no inner `let tId` here
        recomputeIfReady(key);
      }, 180);
    };

    el.addEventListener('input', handler);
    el.addEventListener('change', handler);
  });

}

function bindAllRows() {
  const rows = document.querySelectorAll('#mix_rows tr');
  rows.forEach(bindRow);
}

async function calculateMixture() {
  const T = mixTemp();
  let totalG = 0;
  let totalMl = 0;
  let alcG = 0; // grams of ethanol
  let sugG = 0; // grams of sugar

  const rows = document.querySelectorAll('#mix_rows tr');
  for (const tr of rows) {
    const abmEl = tr.querySelector('.mix_abm');
    const sbmEl = tr.querySelector('.mix_sbm');
    const abvEl = tr.querySelector('.mix_abv');
    const swvEl = tr.querySelector('.mix_swv');
    const gEl   = tr.querySelector('.mix_g');
    const mlEl  = tr.querySelector('.mix_ml');

    const v = {
      ABM: num(abmEl), SBM: num(sbmEl),
      ABV: num(abvEl), SWV: num(swvEl),
      g: num(gEl), ml: num(mlEl),
    };

    // Ensure row state (one forward call if needed)
    let st = getRowState(tr);
    if (!st || st.T !== T || st.rho == null) {
      // Prefer whichever pair is present
      if (v.ABM != null && v.SBM != null) {
        st = await updateFromComp(tr, v.ABM, v.SBM, T, { abv: abvEl, swv: swvEl });
      } else if (v.ABV != null && v.SWV != null) {
        st = await updateFromMeas(tr, v.ABV, v.SWV, T, { abm: abmEl, sbm: sbmEl });
      } else {
        continue; // not enough info on this row
      }
    }

    if (!st || st.rho == null || st.abm == null || st.sbm == null) continue;

    // Convert quantity to grams and mL
    let g = v.g, ml = v.ml;
    if (g == null && ml != null) g = ml * st.rho;
    if (ml == null && g != null) ml = g / st.rho;

    if (!(g > 0)) continue;

    totalG += g;
    totalMl += (ml || 0);
    alcG += (st.abm / 100) * g;
    sugG += (st.sbm / 100) * g;
  }

  if (!(totalG > 0)) return;

  const mixABM = 100 * (alcG / totalG);
  const mixSBM = 100 * (sugG / totalG);

  // One forward solve to get density, ABV, etc.
  const j = await solve(
    { mode: 'abm_sbm', abm: mixABM, sbm: mixSBM, report_T: T },
    { endpoint: '/calc/api/solve.php' }
  );
  if (!j || j.ok !== true) return;

  const o = j.outputs || {};
  // Fill outputs
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
  set('mix_out_weight', totalG.toFixed(1));
  set('mix_out_volume', totalMl.toFixed(1));
  set('mix_out_abm',    mixABM.toFixed(1));
  set('mix_out_sbm',    mixSBM.toFixed(1));
  set('mix_out_wbm',    (100 - mixABM - mixSBM).toFixed(1));
  set('mix_out_abv',    (o.ABV != null ? o.ABV.toFixed(1) : ''));
  set('mix_out_swv',    (o.Sugar_WV != null ? o.Sugar_WV.toFixed(1) : ''));
  set('mix_out_rho',    (o.Density != null ? o.Density.toFixed(5) : ''));
  set('mix_out_brix',   (o.BrixATC != null ? o.BrixATC.toFixed(2) : ''));
  set('mix_out_nd',     (o.nD != null ? o.nD.toFixed(5) : ''));

  // Update the “Reported at T” legend
  const note = document.getElementById('mix_temp_note');
  if (note) note.textContent = T.toFixed(1);
}




// ---- Init ----
document.addEventListener('DOMContentLoaded', () => {
  bindAllRows();
  bindBulk();
  
  // Wire the button
  document.getElementById('mix_calc_btn')?.addEventListener('click', calculateMixture);

  // Recompute every row when the Mixer "Report at T" changes
  const mixT = document.getElementById('mix_report_T');
  mixT?.addEventListener('change', () => {
    document.querySelectorAll('#mix_rows tr').forEach(tr => {
      // Invalidate cached density so the next solve refreshes it
      const st = getRowState(tr);
      if (st) setRowState(tr, { ...st, T: NaN }); // force re-fetch at new T

      // Trigger exactly one recompute per row
      const anyEnabled = tr.querySelector('input:not([disabled])');
      if (anyEnabled) anyEnabled.dispatchEvent(new Event('change'));
    });
  });

});



// Bulk helpers (dry sugar / water / ethanol)
function bindBulk() {
  const sugarG  = document.getElementById('bulk_sugar_g');
  const sugarMl = document.getElementById('bulk_sugar_ml');
  const waterG  = document.getElementById('bulk_water_g');
  const waterMl = document.getElementById('bulk_water_ml');
  const ethG    = document.getElementById('bulk_eth_g');
  const ethMl   = document.getElementById('bulk_eth_ml');

  // Dry sugar: 0.6 mL per g
  const sugarFromG  = () => { const g = num(sugarG);  if (g != null) { sugarMl.value = (g * 0.6).toFixed(1); } };
  const sugarFromMl = () => { const ml = num(sugarMl); if (ml != null) { sugarG.value = (ml / 0.6).toFixed(1); } };

  sugarG?.addEventListener('input',  sugarFromG);
  sugarG?.addEventListener('change', sugarFromG);
  sugarMl?.addEventListener('input',  sugarFromMl);
  sugarMl?.addEventListener('change', sugarFromMl);

  // Water at 20 °C: ρ ≈ 0.9982 g/mL ~ 1.0 for our purposes
  const RHO_WATER = 0.9982;
  const waterFromG  = () => { const g = num(waterG);  if (g != null) { waterMl.value = (g / RHO_WATER).toFixed(1); } };
  const waterFromMl = () => { const ml = num(waterMl); if (ml != null) { waterG.value = (ml * RHO_WATER).toFixed(1); } };

  waterG?.addEventListener('input',  waterFromG);
  waterG?.addEventListener('change', waterFromG);
  waterMl?.addEventListener('input',  waterFromMl);
  waterMl?.addEventListener('change', waterFromMl);

  // Pure ethanol at 20 °C: ρ ≈ 0.7893 g/mL
  const RHO_ETH = 0.7893;
  const ethFromG  = () => { const g = num(ethG);  if (g != null) { ethMl.value = (g / RHO_ETH).toFixed(1); } };
  const ethFromMl = () => { const ml = num(ethMl); if (ml != null) { ethG.value = (ml * RHO_ETH).toFixed(1); } };

  ethG?.addEventListener('input',  ethFromG);
  ethG?.addEventListener('change', ethFromG);
  ethMl?.addEventListener('input',  ethFromMl);
  ethMl?.addEventListener('change', ethFromMl);
}


