// /calc/public/js/mixcalc-mixer.js
// Forward-only mixing with per-row validation, cached ρ for g↔mL,
// live totals, compact meta row (WBM, ρ, Brix, nD), target solver.
//
// Requires only a single global Report@T (#mix_report_T).
//
// HTML expectations (present in your current file):
// - tbody#mix_rows with rows containing inputs .mix_abm .mix_sbm .mix_abv .mix_swv .mix_g .mix_ml
// - tfoot Totals row with cells:
//     mix_total_abm_cell, mix_total_sbm_cell, mix_total_abv_cell, mix_total_swv_cell,
//     mix_total_weight_cell, mix_total_volume_cell
// - Add one extra footer row (see HTML snippet below) with:
//     mix_meta_wbm_cell, mix_meta_rho_cell, mix_meta_brix_cell, mix_meta_nd_cell
// - Optional Target panel is already present in your HTML.

import { solve } from './mixcalc-core.js';

// ---------- constants ----------
const RHO_SUGAR_BULK = 1 / 0.62;  // ≈ 1.612903 g/mL
const TOAST_MS = 2600;

// ---------- small utils ----------
const $  = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

const num = (el) => {
  if (!el) return null;
  const s = String((el.value || '').trim()).replace(',', '.');
  if (s === '') return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
};

function showToast(msg) {
  let t = document.getElementById('mix_toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'mix_toast';
    t.style.position = 'fixed';
    t.style.right = '12px';
    t.style.bottom = '12px';
    t.style.maxWidth = '60ch';
    t.style.padding = '10px 12px';
    t.style.borderRadius = '8px';
    t.style.font = '14px/1.3 system-ui, sans-serif';
    t.style.background = 'rgba(220, 38, 38, 0.95)'; // red-ish
    t.style.color = '#fff';
    t.style.zIndex = '99999';
    t.style.boxShadow = '0 6px 20px rgba(0,0,0,.25)';
    t.style.opacity = '0';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  clearTimeout(t._tid);
  t._tid = setTimeout(() => { t.style.opacity = '0'; }, 2400);
}


function mixTemp() {
  const el = $('#mix_report_T');
  const v = Number((el?.value || '20').replace(',', '.'));
  const clamped = Number.isFinite(v) ? Math.max(10, Math.min(30, v)) : 20;
  if (el && clamped !== v) el.value = clamped.toFixed(1);
  return clamped;
}

// ---------- API wrappers ----------
async function solveFrom_ABM_SBM(ABM, SBM, T) {
  return await solve({ mode:'abm_sbm', abm:ABM, sbm:SBM, report_T:T }, { endpoint:'/calc/api/solve.php' });
}
async function solveFrom_ABV_SWV(ABV, SWV, T) {
  return await solve({ mode:'abv_sugarwv', ABV, ABV_T:T, Sugar_WV:SWV, Sugar_WV_T:T, report_T:T }, { endpoint:'/calc/api/solve.php' });
}

// ---------- per-row state ----------
const rowState = new WeakMap(); // row → {abm,sbm,abv,swv,rho,isDry,lastComp,lastQty,_sig}
const getSt = (row) => rowState.get(row) || null;
const setSt = (row, st) => rowState.set(row, st);
const clrSt = (row) => rowState.delete(row);

const defState = () => ({ abm:null, sbm:null, abv:null, swv:null, rho:null, isDry:false, lastComp:null, lastQty:null, _sig:null });
const markInvalid = (row, bad) => row.classList.toggle('mix_invalid', !!bad);



function makeBlankRow(){
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="mix_name"  placeholder="e.g. Ingredient"></td>
    <td><input class="mix_abm"   inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"></td>
    <td><input class="mix_sbm"   inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"></td>
    <td><input class="mix_abv"   inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"></td>
    <td><input class="mix_swv"   inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"></td>
    <td><input class="mix_g"     inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"></td>
    <td><input class="mix_ml"    inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"></td>
    <td class="hold" style="text-align:center;">
      <input type="checkbox" class="mix_hold">
    </td>
    <td class="del"  style="text-align:center;">
      <button type="button" class="mix_del" title="Delete row" aria-label="Delete row">×</button>
    </td>
  `.trim();
  return tr;
}

// Treat a row as “empty” only if all **text** inputs are blank (ignore checkboxes/buttons)
function rowIsEmpty(row){
  return !Array.from(row.querySelectorAll('input'))
    .some(el => (el.type !== 'checkbox' && el.type !== 'button') &&
                (String(el.value||'').trim() !== ''));
}

// Keep exactly one trailing blank row
function ensureTrailingBlankRow() {
  const tbody = document.getElementById('mix_rows');
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll(':scope > tr'));
  const hasEmpty = rows.some(rowIsEmpty);
  if (!hasEmpty) {
    const tr = makeBlankRow();
    tbody.appendChild(tr);
    bindRow(tr);
  }
}

// --- Domain guards (mirror of calculator) ---
function inDomain_ABM_SBM(A, S){
  if (A == null || S == null) return false;
  if (A > 0 && S > 0) return (A <= 60 && S <= 60);
  if (A === 0) return (S <= 83);
  if (S === 0) return (A <= 100);
  return false;
}

function outputsUsable(j){
  const o = j && j.outputs;
  return !!(o && typeof o.Density === 'number' && o.Density > 0);
}


// ---------- forward-solving helpers ----------
async function onComp(row, ABM, SBM, T, out) {
  if (ABM === 0 && SBM === 100) {
    const st = defState();
    st.abm=0; st.sbm=100; st.rho=RHO_SUGAR_BULK; st.isDry=true; st.lastComp='comp';
    setSt(row, st);
    if (out?.abv) out.abv.value = '';
    if (out?.swv) out.swv.value = '';
    markInvalid(row, false);
    return st;
  }
  const j = await solveFrom_ABM_SBM(ABM, SBM, T);
  if (!j || j.ok !== true || !j.outputs || !(j.outputs.Density > 0)) {
    setSt(row, defState());
    markInvalid(row, true);
    showToast('Invalid composition: check ABM/SBM.');
    zeroQuantities(row, out, 'comp');
    return null;
  }
  const o = j.outputs;
  const st = defState();
  st.abm=ABM; st.sbm=SBM; st.abv=o.ABV ?? null; st.swv=o.Sugar_WV ?? null; st.rho=o.Density ?? null; st.lastComp='comp';
  setSt(row, st);
  if (out?.abv) out.abv.value = st.abv!=null ? st.abv.toFixed(1) : '';
  if (out?.swv) out.swv.value = st.swv!=null ? st.swv.toFixed(1) : '';
  markInvalid(row, false);
  return st;
}

async function onMeas(row, ABV, SWV, T, out) {
  const j = await solveFrom_ABV_SWV(ABV, SWV, T);
  if (!j || j.ok !== true || !j.outputs || !(j.outputs.Density > 0)) {
    setSt(row, defState());
    markInvalid(row, true);
    showToast('Invalid measurement: check ABV/Sugar_WV.');
    zeroQuantities(row, out, 'meas');
    return null;
  }
  const st = defState();
  st.abv=ABV; st.swv=SWV;
  st.abm = (typeof j.abm === 'number') ? j.abm : null;
  st.sbm = (typeof j.sbm === 'number') ? j.sbm : null;
  st.rho = (typeof j.outputs.Density === 'number') ? j.outputs.Density : null;
  st.lastComp='meas';
  setSt(row, st);
  if (out?.abm) out.abm.value = st.abm!=null ? st.abm.toFixed(1) : '';
  if (out?.sbm) out.sbm.value = st.sbm!=null ? st.sbm.toFixed(1) : '';
  markInvalid(row, false);
  return st;
}

function zeroQuantities(row, out, kind){
  const g = row.querySelector('.mix_g');
  const ml = row.querySelector('.mix_ml');
  if (g) g.value = '';
  if (ml) ml.value = '';
  if (kind==='comp'){ out?.abv && (out.abv.value=''); out?.swv && (out.swv.value=''); }
  if (kind==='meas'){ out?.abm && (out.abm.value=''); out?.sbm && (out.sbm.value=''); }
}

function mirrorQty(st, I){
  if (!st || !(st.rho>0)) return;
  const g  = num(I.g);
  const ml = num(I.ml);
  if (st.lastQty==='g')   { if (g!=null && I.ml) I.ml.value = (g / st.rho).toFixed(1); }
  else if (st.lastQty==='ml'){ if (ml!=null && I.g) I.g.value = (ml * st.rho).toFixed(1); }
  else {
    if (g!=null && ml==null && I.ml) I.ml.value = (g / st.rho).toFixed(1);
    if (ml!=null && g==null  && I.g) I.g.value  = (ml * st.rho).toFixed(1);
  }
}

// ---------- bind a row ----------
function bindRow(row){

  // delete button (in its own last column)
  const delBtn = row.querySelector('.mix_del');
  if (delBtn && !delBtn._bound) {
    delBtn._bound = true;
    delBtn.addEventListener('click', () => {
      const name = row.querySelector('.mix_name')?.value?.trim();
      const ok = confirm(name ? `Delete row "${name}"?` : 'Delete this row?');
      if (!ok) return;
      try { rowState?.delete?.(row); } catch (_) {}
      row.remove();
      ensureTrailingBlankRow();
      calcMixDebounced();
    });
  }


  const I = {
    abm: row.querySelector('.mix_abm'),
    sbm: row.querySelector('.mix_sbm'),
    abv: row.querySelector('.mix_abv'),
    swv: row.querySelector('.mix_swv'),
    g:   row.querySelector('.mix_g'),
    ml:  row.querySelector('.mix_ml'),
  };

  let solving=false, filling=false, tid=null;

  function enforceOneOf(key){
    const pairs = [['abm','abv'], ['sbm','swv']];
    for (const [a,b] of pairs){
      if (key!==a && key!==b) continue;
      const A=I[a], B=I[b]; if (!A||!B) continue;
      const aTxt=(A.value||'').trim(), bTxt=(B.value||'').trim();
      if (aTxt && bTxt){
        if (key===a && B.value!=='') B.value='';
        if (key===b && A.value!=='') A.value='';
      }
    }
  }

async function recompute(key){
  if (solving || filling) return;
  const T = mixTemp();

  const v = { ABM:num(I.abm), SBM:num(I.sbm), ABV:num(I.abv), SWV:num(I.swv), g:num(I.g), ml:num(I.ml) };
  const isComp = ['abm','sbm','abv','swv'].includes(key);
  const isQty  = ['g','ml'].includes(key);

  let st = getSt(row) || defState();
  if (key==='abm' || key==='sbm') st.lastComp='comp';
  if (key==='abv' || key==='swv') st.lastComp='meas';
  if (key==='g') st.lastQty='g';
  if (key==='ml') st.lastQty='ml';
  setSt(row, st);

  // If user edits only g/mL and we have ρ, mirror immediately
  if (isQty && st.rho>0 && !(['abm','sbm','abv','swv'].some(k=>k===key))){
    filling=true; mirrorQty(st, I); filling=false; calcMixDebounced(); return;
  }

  // If we have a composition pair, enforce domain immediately (no API wait)
  const haveCompPair = (v.ABM!=null && v.SBM!=null);
  const haveMeasPair = (v.ABV!=null && v.SWV!=null);

  if ((key==='abm' || key==='sbm') && haveCompPair && !inDomain_ABM_SBM(v.ABM, v.SBM)){
    setSt(row, defState());
    markInvalid(row, true);
    showToast('Invalid composition: ABM/SBM outside allowed domain.');
    zeroQuantities(row, {abv:I.abv, swv:I.swv}, 'comp');
    calcMixDebounced();
    return;
  }

  // If nothing to solve yet, stop
  if (!haveCompPair && !haveMeasPair) return;

  // De-dupe solves
  const sig = JSON.stringify({ T:+T.toFixed(3), compKey:st.lastComp, abm:v.ABM, sbm:v.SBM, abv:v.ABV, swv:v.SWV });
  if (isComp && st._sig===sig) return;

  solving=true;
  try{
    let ok=false;
    if (st.lastComp==='comp' && haveCompPair){
      filling=true; ok=!!(await onComp(row, v.ABM, v.SBM, T, {abv:I.abv, swv:I.swv})); filling=false;
    } else if (st.lastComp==='meas' && haveMeasPair){
      filling=true; ok=!!(await onMeas(row, v.ABV, v.SWV, T, {abm:I.abm, sbm:I.sbm})); filling=false;
    } else {
      if (haveCompPair){
        filling=true; ok=!!(await onComp(row, v.ABM, v.SBM, T, {abv:I.abv, swv:I.swv})); filling=false;
      } else if (haveMeasPair){
        filling=true; ok=!!(await onMeas(row, v.ABV, v.SWV, T, {abm:I.abm, sbm:I.sbm})); filling=false;
      }
    }
    if (!ok){
      // make sure quantities are cleared if backend gave nonsense (e.g., zeros)
      zeroQuantities(row, null, null);
      calcMixDebounced();
      return;
    }

    const st2 = getSt(row);
    filling=true; mirrorQty(st2, I); filling=false;
    st._sig = sig;
    calcMixDebounced();
  } finally { solving=false; }
}


  function onEdit(key){ return () => {
    if (filling) return;
    enforceOneOf(key);
    clearTimeout(tid);
    tid = setTimeout(() => { recompute(key); ensureTrailingBlankRow(); }, 150);
  };}

  for (const [k, el] of Object.entries(I)){
    if (!el) continue;
    el.addEventListener('input', onEdit(k));
    el.addEventListener('change', onEdit(k));
  }
}

// ---------- bind all rows on load ----------
function bindExistingRows(){ $$('#mix_rows tr').forEach(tr => { bindRow(tr); }); }

// ---------- totals ----------
let mixTmr=null;
function calcMixDebounced(){ clearTimeout(mixTmr); mixTmr=setTimeout(calcMix, 120); }

function setCell(id, v){ const el=document.getElementById(id); if (el) el.textContent = v ?? ''; }

async function calcMix(){
  const T = mixTemp();
  // Reset visual invalid on totals
  const tRow = document.querySelector('#mix_table tfoot tr.totals-row');
  tRow?.classList.remove('mix_invalid');

  let totalG=0, alcG=0, sugG=0;

  for (const tr of $$('#mix_rows tr')){
    const st = getSt(tr);
    const abmEl = tr.querySelector('.mix_abm');
    const sbmEl = tr.querySelector('.mix_sbm');
    const abvEl = tr.querySelector('.mix_abv');
    const swvEl = tr.querySelector('.mix_swv');
    const gEl   = tr.querySelector('.mix_g');
    const mlEl  = tr.querySelector('.mix_ml');

    let s = st;
    if ((!s || s.rho==null) && !rowIsEmpty(tr)){
      const ABM=num(abmEl), SBM=num(sbmEl), ABV=num(abvEl), SWV=num(swvEl);
      if (ABM===0 && SBM===100){
        s = defState(); s.abm=0; s.sbm=100; s.rho=RHO_SUGAR_BULK; s.isDry=true; setSt(tr, s);
      } else if (ABM!=null && SBM!=null){
        s = await onComp(tr, ABM, SBM, T, {abv:abvEl, swv:swvEl});
      } else if (ABV!=null && SWV!=null){
        s = await onMeas(tr, ABV, SWV, T, {abm:abmEl, sbm:sbmEl});
      }
    }
    if (!s || !(s.rho>0) || s.abm==null || s.sbm==null) continue;

    let g=num(gEl);
    if (g==null){
      const ml=num(mlEl);
      if (ml!=null) g = ml * s.rho;
    }
    if (!(g>0)) continue;

    totalG += g;
    alcG   += (s.abm/100)*g;
    sugG   += (s.sbm/100)*g;
  }

  if (!(totalG>0)){
    // clear cells
    ['mix_total_weight_cell','mix_total_volume_cell','mix_total_abm_cell','mix_total_sbm_cell','mix_total_abv_cell','mix_total_swv_cell']
      .forEach(id => setCell(id,''));
    
    return;
  }

  const ABMmix = 100*(alcG/totalG);
  const SBMmix = 100*(sugG/totalG);

  // Forward to get reportables; detect domain failure
  const j = await solveFrom_ABM_SBM(ABMmix, SBMmix, T);
  if (!j || j.ok!==true || !j.outputs || !(j.outputs.Density>0)){
    // Invalid mixture — mark totals invalid and toast once
    tRow?.classList.add('mix_invalid');
    ['mix_total_weight_cell','mix_total_volume_cell','mix_total_abm_cell','mix_total_sbm_cell','mix_total_abv_cell','mix_total_swv_cell']
      .forEach(id => setCell(id,'—'));
    
    showToast('Mixture is out of solver domain for the current ABM/SBM.');
    return;
  }

  const o = j.outputs;
  const rho = o.Density;
  const vol = totalG / rho;
  const wbm = 100 - ABMmix - SBMmix;

  setCell('mix_total_weight_cell', totalG.toFixed(1));
  setCell('mix_total_volume_cell', vol.toFixed(1));
  setCell('mix_total_abm_cell',    ABMmix.toFixed(1));
  setCell('mix_total_sbm_cell',    SBMmix.toFixed(1));
  setCell('mix_total_abv_cell',    (o.ABV!=null ? o.ABV.toFixed(1) : ''));
  setCell('mix_total_swv_cell',    (o.Sugar_WV!=null ? o.Sugar_WV.toFixed(1) : ''));

  //Summary Writer
  const sum = document.getElementById('mix_summary');
  if (sum) {
    const b = (x, dp=1) => `<b>${Number(x).toFixed(dp)}</b>`;
    sum.innerHTML =
      `Final mix — ABM ${b(ABMmix)}%, SBM ${b(SBMmix)}%, ` +
      `WBM ${b(100-ABMmix-SBMmix)}%; ` +
      `Density ${b(rho,5)} g/mL; ` +
      `ATC Brix ${o.BrixATC!=null ? `<b>${o.BrixATC.toFixed(2)}</b>` : '—'}; ` +
      `nD ${o.nD!=null ? `<b>${o.nD.toFixed(5)}</b>` : '—'}; ` +
      `reported at ${b(T,1)} °C.`;
  }

}

// mass triplet from a row (grams of ethanol, sugar, water, total mass)
// Uses st.abm/sbm (% mass), and st.rho (for ml→g); ignores rows w/out qty
function rowMasses(tr) {
  const st = getSt(tr);
  if (!st || (st.abm==null && st.sbm==null)) return {E:0,S:0,W:0,G:0, ok:false};

  const I = {
    g:  tr.querySelector('.mix_g'),
    ml: tr.querySelector('.mix_ml'),
  };
  let G = 0;
  const g = num(I.g), ml = num(I.ml);
  if (Number.isFinite(g) && g>0) G = g;
  else if (Number.isFinite(ml) && ml>0 && st.rho>0) G = ml * st.rho;
  else return {E:0,S:0,W:0,G:0, ok:false}; // no qty

  // prefer st.abm/sbm; if missing but st.ABV/Sugar_WV exist, they should
  // already be converted by recompute() into abm/sbm; if not, treat as empty
  const abm = Number(st.abm), sbm = Number(st.sbm);
  if (!Number.isFinite(abm) || !Number.isFinite(sbm)) return {E:0,S:0,W:0,G:0, ok:false};
  const a = Math.max(0, Math.min(1, abm/100));
  const s = Math.max(0, Math.min(1, sbm/100));
  const w = Math.max(0, 1 - a - s);

  return {E: a*G, S: s*G, W: w*G, G, ok:true};
}

// ensure an additive row (kind: 'ethanol' | 'sugar' | 'water'); returns the <tr>
function ensureAdditiveRow(kind) {
  const tbody = document.getElementById('mix_rows');
  // heuristic: reuse an existing row that already looks like that additive
  const rows = Array.from(tbody.querySelectorAll(':scope>tr'));
  let match = null;
  for (const tr of rows) {
    const st = getSt(tr);
    if (!st) continue;
    if (kind==='ethanol' && Math.abs((st.abm||0) - 100) < 1e-6 && (st.sbm||0)===0) { match = tr; break; }
    if (kind==='sugar'   && (st.abm||0)===0 && Math.abs((st.sbm||0) - 100) < 1e-6) { match = tr; break; }
    if (kind==='water'   && Math.abs((st.abm||0)) < 1e-6 && Math.abs((st.sbm||0)) < 1e-6) { match = tr; break; }
  }
  if (match) return match;

  // otherwise make a fresh blank row and set composition
  const tr = makeBlankRow();
  tbody.appendChild(tr);
  bindRow(tr);
  const f = {
    name: tr.querySelector('.mix_name'),
    abm: tr.querySelector('.mix_abm'),
    sbm: tr.querySelector('.mix_sbm'),
    abv: tr.querySelector('.mix_abv'),
    swv: tr.querySelector('.mix_swv'),
    g:   tr.querySelector('.mix_g'),
    ml:  tr.querySelector('.mix_ml'),
  };
  if (kind==='ethanol') { f.abm.value='100'; f.sbm.value='0';    f.abv.value=''; f.swv.value=''; }
  if (kind==='sugar')   { f.abm.value='0';   f.sbm.value='100';  f.abv.value=''; f.swv.value=''; }
  if (kind==='water')   { f.abm.value='0';   f.sbm.value='0';    f.abv.value=''; f.swv.value=''; }
  // clear qty
  f.g.value=''; f.ml.value='';
  return tr;
}

// set grams on a row (clears mL so mirror will fill after next solve if ρ is known)
function setRowGrams(tr, grams) {
  const gEl = tr.querySelector('.mix_g');
  const mlEl = tr.querySelector('.mix_ml');
  if (gEl) gEl.value = grams > 0 ? String(grams.toFixed(2)) : '';
  if (mlEl) mlEl.value = ''; // force mirror later
}


async function matchTargetUsingInputs() {
  // 1) derive target fractions a,s (ABM/SBM) from Desired row
  const dTr = document.getElementById('desired_row');
  if (!dTr) return;
  const d = {
    abm: num(dTr.querySelector('.mix_abm')),
    sbm: num(dTr.querySelector('.mix_sbm')),
    abv: num(dTr.querySelector('.mix_abv')),
    swv: num(dTr.querySelector('.mix_swv')),
    g:   num(dTr.querySelector('.mix_g')),
    ml:  num(dTr.querySelector('.mix_ml')),
    hold: dTr.querySelector('.mix_hold')?.checked
  };
  let a=null, s=null, T = mixTemp();

  // If ABM/SBM were not typed, but ABV/SWV were, call your backend once to convert
  if (Number.isFinite(d.abm) && Number.isFinite(d.sbm)) {
    a = Math.max(0, Math.min(1, d.abm/100));
    s = Math.max(0, Math.min(1, d.sbm/100));
  } else if (Number.isFinite(d.abv) && Number.isFinite(d.swv)) {
    const json = await solve({ mode:'abv_sugarwv', ABV:d.abv, ABV_T:T, Sugar_WV:d.swv, Sugar_WV_T:T, report_T:T }, {endpoint:'/calc/api/solve.php'});
    if (!json || json.ok!==true) { showToast('Cannot interpret Desired ABV/Sugar.'); return; }
    a = Math.max(0, Math.min(1, (json.abm||0)/100));
    s = Math.max(0, Math.min(1, (json.sbm||0)/100));
  } else {
    showToast('Enter Desired as ABM/SBM or ABV/Sugar_WV.'); return;
  }
  const w = Math.max(0, 1 - a - s);
  if (a<=0 && s<=0 && w<=0) { showToast('Desired composition is invalid.'); return; }

  // 2) read existing inputs
  const tbody = document.getElementById('mix_rows');
  const rows  = Array.from(tbody.querySelectorAll(':scope>tr'));
  const held  = [];
  const free  = [];
  for (const tr of rows) {
    // skip trailing empty
    const anyVal = Array.from(tr.querySelectorAll('input'))
      .some(el => (el.type!=='checkbox' && String(el.value||'').trim()!==''));
    if (!anyVal) continue;

    const masses = rowMasses(tr);
    if (!masses.ok) continue;

    const isHeld = tr.querySelector('.mix_hold')?.checked;
    (isHeld ? held : free).push({tr, ...masses});
  }

  const sum = arr => arr.reduce((acc,x)=>({E:acc.E+x.E,S:acc.S+x.S,W:acc.W+x.W,G:acc.G+x.G}),{E:0,S:0,W:0,G:0});
  const H = sum(held), F = sum(free);
  const E0 = H.E + F.E, S0 = H.S + F.S, W0 = H.W + F.W, G0 = H.G + F.G;

  // 3) Decide final mass G* and needed additives
  let Gstar = null;

  if (d.hold && (Number.isFinite(d.g) || Number.isFinite(d.ml))) {
    // Desired quantity is held
    let Gd = Number.isFinite(d.g) ? d.g : null;
    if (Gd==null && Number.isFinite(d.ml)) {
      // estimate rho at target — quick single call
      const j = await solve({ mode:'abm_sbm', ABM:a*100, SBM:s*100, report_T:T }, {endpoint:'/calc/api/solve.php'});
      if (!j || j.ok!==true || !(j.outputs&&j.outputs.Density>0)) { showToast('Cannot estimate target density to convert mL to g.'); return; }
      Gd = d.ml * j.outputs.Density;
    }
    if (!Number.isFinite(Gd) || Gd<=0) { showToast('Desired amount is invalid.'); return; }
    Gstar = Gd;

    // If held rows alone exceed any fraction requirement, infeasible
    if (H.E > a*Gstar || H.S > s*Gstar || H.W > w*Gstar) {
      // try scaling free rows down (r in [0,1])
      const rE = F.E>0 ? (a*Gstar - H.E)/F.E : 1;
      const rS = F.S>0 ? (s*Gstar - H.S)/F.S : 1;
      const rW = F.W>0 ? (w*Gstar - H.W)/F.W : 1;
      const r  = Math.min(rE, rS, rW);
      if (!(r>=0 && isFinite(r))) { showToast('Target infeasible with held amounts.'); return; }
      // scale non-held rows
      for (const it of free) {
        const newG = it.G * Math.max(0, Math.min(1, r));
        setRowGrams(it.tr, newG); // clears mL; mirror will refill on next recalc
      }
      // recompute sums
      const F2 = sum(free.map(x => {
        const newG = x.G * Math.max(0, Math.min(1, r));
        const fa = x.E/x.G, fs = x.S/x.G, fw = x.W/x.G;
        return {E:fa*newG, S:fs*newG, W:fw*newG, G:newG};
      }));
      const E1 = H.E + F2.E, S1 = H.S + F2.S, W1 = H.W + F2.W;
      // additives now (non-negative by construction)
      const addE = Math.max(0, a*Gstar - E1);
      const addS = Math.max(0, s*Gstar - S1);
      const addW = Math.max(0, w*Gstar - W1);
      setRowGrams(ensureAdditiveRow('ethanol'), addE);
      setRowGrams(ensureAdditiveRow('sugar'),   addS);
      setRowGrams(ensureAdditiveRow('water'),   addW);
    } else {
      // held rows OK; use all free rows fully (max extent), and top up with additives if needed
      const addE = Math.max(0, a*Gstar - E0);
      const addS = Math.max(0, s*Gstar - S0);
      const addW = Math.max(0, w*Gstar - W0);
      setRowGrams(ensureAdditiveRow('ethanol'), addE);
      setRowGrams(ensureAdditiveRow('sugar'),   addS);
      setRowGrams(ensureAdditiveRow('water'),   addW);
    }
  } else {
    // Desired qty not held: choose MIN final mass that satisfies target with existing inputs
    // G* >= max(G0, E0/a, S0/s, W0/w) (skip terms with zero denominator)
    const cand = [G0];
    if (a>0) cand.push(E0/a);
    if (s>0) cand.push(S0/s);
    if (w>0) cand.push(W0/w);
    Gstar = Math.max(...cand.filter(x => Number.isFinite(x)));

    const addE = Math.max(0, a*Gstar - E0);
    const addS = Math.max(0, s*Gstar - S0);
    const addW = Math.max(0, w*Gstar - W0);
    setRowGrams(ensureAdditiveRow('ethanol'), addE);
    setRowGrams(ensureAdditiveRow('sugar'),   addS);
    setRowGrams(ensureAdditiveRow('water'),   addW);
  }

  showToast('Target matched using existing inputs; added ethanol/sugar/water as needed.');
  calcMixDebounced();
}


// ---------- Target solver ----------
function bindTargetPanel(){
  const abmEl = $('#target_abm');
  const sbmEl = $('#target_sbm');
  const pair  = $('#target_pair');
  const btn   = $('#target_solve_btn');
  const out   = $('#target_result');
  if (!abmEl || !sbmEl || !pair || !btn || !out) return; // panel not shown in UI — skip binding

  function show(html){ out.innerHTML = html; }

  btn.addEventListener('click', async () => {
    await calcMix(); // make sure cached totals reflect latest
    const T = mixTemp();

    const tABM = Number((abmEl.value||'').replace(',','.'));
    const tSBM = Number((sbmEl.value||'').replace(',','.'));
    if (!Number.isFinite(tABM) || !Number.isFinite(tSBM) || tABM<0 || tSBM<0 || (tABM+tSBM>100)){
      showToast('Enter valid target ABM/SBM (≥0, sum ≤ 100).'); return;
    }
    const t = tABM/100, u = tSBM/100;

    // Build base from rows
    let M0=0, Ab=0, Sb=0;
    for (const tr of $$('#mix_rows tr')){
      const st=getSt(tr);
      if (!st || !(st.rho>0) || st.abm==null || st.sbm==null) continue;
      let g=num(tr.querySelector('.mix_g'));
      if (g==null){
        const ml=num(tr.querySelector('.mix_ml'));
        if (ml!=null) g = ml*st.rho;
      }
      if (!(g>0)) continue;
      M0 += g;
      Ab += (st.abm/100)*g;
      Sb += (st.sbm/100)*g;
    }
    if (!(M0>0)){ showToast('Add at least one valid ingredient before solving.'); return; }

    // Choose additives
    const sel = (pair.value||'water+ethanol');
    let a1=0,s1=0,a2=0,s2=0, L1='', L2='';
    if (sel==='water+ethanol'){ a1=0; s1=0; L1='Water'; a2=1; s2=0; L2='Ethanol'; }
    else if (sel==='water+sugar'){ a1=0; s1=0; L1='Water'; a2=0; s2=1; L2='Dry Sugar'; }
    else if (sel==='ethanol+sugar'){ a1=1; s1=0; L1='Ethanol'; a2=0; s2=1; L2='Dry Sugar'; }
    else { showToast('Unknown additive pair.'); return; }

    // Solve linear system:
    // (a1-t)x + (a2-t)y = t*M0 - Ab
    // (s1-u)x + (s2-u)y = u*M0 - Sb
    const A11=a1-t, A12=a2-t, A21=s1-u, A22=s2-u;
    const B1=t*M0 - Ab, B2=u*M0 - Sb;
    const det = A11*A22 - A12*A21;

    if (Math.abs(det) < 1e-12){ show('<span class="muted">No unique solution for this target/additives.</span>'); return; }
    const x = ( B1*A22 - A12*B2) / det;
    const y = (-B1*A21 + A11*B2) / det;

    if (x < -1e-6 || y < -1e-6){
      show('<span class="muted">Infeasible with non-negative additive grams.</span>');
      showToast('Target infeasible with chosen additives (would require negative grams).');
      return;
    }
    const gx=Math.max(0,x), gy=Math.max(0,y);
    const Mfin = M0 + gx + gy;

    // Get target density at T to report final volume
    const jj = await solveFrom_ABM_SBM(tABM, tSBM, T);
    const rho = (jj && jj.ok && jj.outputs && jj.outputs.Density>0) ? jj.outputs.Density : null;
    const Vfin = rho ? (Mfin / rho) : null;

    show(`
      <div><strong>Add:</strong> ${L1} <b>${gx.toFixed(1)} g</b> &nbsp; ${L2} <b>${gy.toFixed(1)} g</b></div>
      <div class="muted">Final mass ≈ ${Mfin.toFixed(1)} g${Vfin ? `, volume @${T.toFixed(1)}°C ≈ ${Vfin.toFixed(1)} mL` : ''}</div>
      <div class="muted">Target ABM ${tABM.toFixed(1)}%, SBM ${tSBM.toFixed(1)}%</div>
    `);
  });
}

// --- Desired target wiring ---
function readNum(el) {
  if (!el) return null;
  const s = String((el.value||'').trim()).replace(',', '.');
  if (s==='') return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

// Ensures helper rows exist (water/ethanol/sugar). Returns <tr> nodes.
function ensureHelperRow(kind) {
  // kind: 'water' | 'ethanol' | 'sugar'
  const tbody = document.getElementById('mix_rows');
  let label = '', abm=null, sbm=null;
  if (kind==='water')   { label='Water';   abm=0;   sbm=0; }
  if (kind==='ethanol') { label='Ethanol'; abm=100; sbm=0; }
  if (kind==='sugar')   { label='Dry Sugar'; abm=0; sbm=100; }

  // find existing row by name
  const rows = Array.from(tbody.querySelectorAll('tr'));
  for (const tr of rows) {
    const nm = tr.querySelector('.mix_name')?.value?.trim().toLowerCase();
    if (nm === label.toLowerCase()) return tr;
  }

  // else create one blank row and seed comp
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="ingr-cell">
      <input class="mix_name" value="${label}">
      <label class="muted" style="position:absolute; right:4px; bottom:-18px; font-size:12px;">
        <input type="checkbox" class="mix_hold"> hold
      </label>
    </td>
    <td><input class="mix_abm"  value="${abm}"></td>
    <td><input class="mix_sbm"  value="${sbm}"></td>
    <td><input class="mix_abv"></td>
    <td><input class="mix_swv"></td>
    <td><input class="mix_g"></td>
    <td class="pos-rel"><input class="mix_ml"></td>
  `.trim();
  tbody.appendChild(tr);
  // bind your existing row logic + delete button
  if (typeof bindRow === 'function') bindRow(tr);
  
  return tr;
}

// Click handler for "Match Target"
function bindDesiredRow() {
  const btn = document.getElementById('desired_match_btn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const T = Number(document.getElementById('mix_report_T')?.value || 20);
    const tABM = readNum(document.getElementById('desired_abm'));
    const tSBM = readNum(document.getElementById('desired_sbm'));
    if (!(tABM!=null && tSBM!=null)) { showToast('Enter Desired ABM and SBM.'); return; }
    if (tABM < 0 || tSBM < 0 || tABM + tSBM > 100) { showToast('Desired ABM/SBM must be within 0–100 and sum ≤ 100.'); return; }

    // Gather fixed grams from held rows
    const rows = Array.from(document.querySelectorAll('#mix_rows tr'));
    let fixedG = 0, fixedAlcG = 0, fixedSugG = 0;
    for (const tr of rows) {
      const hold = tr.querySelector('.mix_hold')?.checked;
      if (!hold) continue;
      const st = getSt(tr);   //was getRowState(tr);
      if (!st || !(st.rho > 0)) continue;
      const gEl = tr.querySelector('.mix_g');
      const mlEl = tr.querySelector('.mix_ml');
      let g = readNum(gEl);
      if (!(g>0)) {
        const ml = readNum(mlEl);
        if (ml>0) g = ml * st.rho;
      }
      if (!(g>0)) continue;
      fixedG += g;
      fixedAlcG += g * (st.abm/100);
      fixedSugG += g * (st.sbm/100);
    }

    // Optionally, target total quantity from g/mL fields
    const gVal = readNum(document.getElementById('desired_g'));
    const mlVal = readNum(document.getElementById('desired_ml'));
    let targetG = null;
    if (gVal != null) targetG = gVal;
    if (mlVal != null) {
      const j = await solveFrom_ABM_SBM(tABM, tSBM, T);
      if (!j || j.ok!==true || !(j.outputs?.Density > 0)) { showToast('Cannot infer density of Desired at this T.'); return; }
      targetG = mlVal * j.outputs.Density;
    }

    // Unknowns: grams of at most two additives chosen automatically:
    // (water, ethanol, dry sugar). Strategy:
    // - Keep existing held rows fixed (already accounted).
    // - Choose the two directions that reduce the error most: ethanol fixes alcohol,
    //   sugar fixes sugar; if water is also needed for mass closure, it becomes the 2nd.
    //   If targetG is present: use two additive solve (ethanol/sugar) and let water be implied
    //   by the mass equation; if implied water < 0 → infeasible.

    // Choose helpers
    const rE = ensureHelperRow('ethanol');
    const rS = ensureHelperRow('sugar');
    const rW = ensureHelperRow('water');

    // Solve for gE (ethanol) and gS (sugar). Water is implied by mass.
    // Equations on grams:
    // fixedAlcG + gE*1.0            = (fixedG + gE + gS + gW) * (tABM/100)
    // fixedSugG + gS*1.0            = (fixedG + gE + gS + gW) * (tSBM/100)
    // If targetG is set: fixedG + gE + gS + gW = targetG, else gW chosen to satisfy the two eqs.
    //
    // Solve:
    const A = tABM/100, S = tSBM/100;

    let G; // final mass
    if (targetG != null) {
      G = targetG;
    } else {
      // With two equations and 3 unknowns, we’ll pick G that falls out of the two balances:
      // G_alc = (fixedAlcG + gE) / A,  G_sug = (fixedSugG + gS) / S
      // We choose G to minimize discrepancy by solving with gW≥0 later. A simple choice is
      // to treat G unknown and eliminate gW: solve gE and gS from balances and pick G that
      // makes gW = G - (fixedG + gE + gS) ≥ 0. We’ll search a small bracket around fixedG.
      // To keep this first cut simple: if A and S > 0, pick G so both equations agree:
      if (A>0 && S>0) {
        // Solve for gE and gS as functions of G:
        // gE = A*G - fixedAlcG
        // gS = S*G - fixedSugG
        // Then gW = G - (fixedG + gE + gS)
        //        = G - fixedG - (A*G - fixedAlcG) - (S*G - fixedSugG)
        //        = (1 - A - S)*G - fixedG + fixedAlcG + fixedSugG
        // Pick the smallest G ≥ current that yields gW ≥ 0
        const G0 = (fixedG - fixedAlcG - fixedSugG) / (1 - A - S || 1); // starting guess
        G = Math.max(fixedG, isFinite(G0) ? G0 : fixedG);
      } else if (A>0 && S===0) {
        G = Math.max(fixedG, fixedAlcG / A);
      } else if (S>0 && A===0) {
        G = Math.max(fixedG, fixedSugG / S);
      } else {
        // A=S=0 ⇒ water-only; choose current mass
        G = fixedG;
      }
    }

    // Now compute gE,gS,gW from chosen G
    const gE = Math.max(0, A*G - fixedAlcG);
    const gS = Math.max(0, S*G - fixedSugG);
    const gW = G - (fixedG + gE + gS);

    if (gW < -1e-6) {
      showToast('Infeasible with non-negative water. Reduce Desired or free more rows.');
      return;
    }

    // Write grams back, converting to mL using each row’s cached rho
    const writeGMl = (tr, grams) => {
      const st = getSt(tr);  //was getRowState
      const gEl = tr.querySelector('.mix_g');
      const mlEl = tr.querySelector('.mix_ml');
      if (gEl) gEl.value = grams > 0 ? grams.toFixed(1) : '';
      if (mlEl) {
        if (st && st.rho > 0 && grams > 0) mlEl.value = (grams / st.rho).toFixed(1);
        else mlEl.value = '';
      }
    };

    // Ensure helpers have density cached
    await calcMix(); // resolves row states at current T if needed was calculateMixture()

    writeGMl(rE, gE);
    writeGMl(rS, gS);
    writeGMl(rW, Math.max(0, gW));

    showToast('Target matched with ethanol, sugar, and water.');
    calcMix();  //was calculateMixture()
  });
}

// ---------- init (single DOMContentLoaded) ----------
document.addEventListener('DOMContentLoaded', () => {
  // 1) Bind existing ingredient rows
  bindExistingRows();

  // 2) Bind Desired row (behaves like a normal row; totals ignore it)
  bindDesiredRow();
  const desiredRow = document.getElementById('desired_row');
  if (desiredRow) {
    bindRow(desiredRow);
    // keep its edits updating the footer too
    desiredRow.querySelectorAll('input').forEach(el => {
      el.addEventListener('input',  calcMixDebounced);
      el.addEventListener('change', calcMixDebounced);
    });
  }

  // 2.25 Hook up the one "Match Target" button in the Desired section
  document.getElementById('match_target_btn')?.addEventListener('click', (e)=>{
    e.preventDefault();
    matchTargetUsingInputs();
  });


  // 2.5) Trim to at most one empty row present initially
  const tb = document.getElementById('mix_rows');
  if (tb) {
    const rows = Array.from(tb.querySelectorAll(':scope > tr'));
    const empties = rows.filter(tr =>
      !Array.from(tr.querySelectorAll('input'))
        .some(el => (el.type !== 'checkbox' && el.type !== 'button') &&
                    (String(el.value||'').trim() !== ''))
    );
    while (empties.length > 1) empties.pop().remove();
  }

  // 3) Ensure there is exactly one trailing blank row
  ensureTrailingBlankRow();

  // 4) Keep totals live for normal rows
  document.querySelectorAll('#mix_rows input').forEach(el => {
    el.addEventListener('input',  calcMixDebounced);
    el.addEventListener('change', calcMixDebounced);
  });

  // 5) Temperature → recompute
  document.getElementById('mix_report_T')?.addEventListener('change', calcMixDebounced);

  // 6) Neutralize legacy buttons (safe if present)
  document.getElementById('mix_calc_btn')?.addEventListener('click', (e) => {
    e.preventDefault();
    calcMixDebounced();
  });

  // 7) Target panel bindings
  bindTargetPanel();

  // 8) First render
  calcMixDebounced();
});


