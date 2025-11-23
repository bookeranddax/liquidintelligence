// /recipes/app.js
import { makeCombo } from '/recipes/combobox.js';
import { setSpokenProfile as applySpokenProfile, toSpokenProfiled, getSpokenProfile } from '/recipes/spoken.js';

const API_BASE = '/recipes/api';
console.info('[Cocktail Analyzer] UI v=optui-17');

// ---------- helpers ----------
const num    = (x) => { const n = Number(x); return Number.isFinite(n) ? n : 0; };
const fmtNum = (x, dp = 2) => { if (x == null || x === '') return ''; const n = Number(x); return Number.isFinite(n) ? n.toFixed(dp) : ''; };

// small HTML escape for option text
const escapeHtml = (s) => String(s)
  .replaceAll('&','&amp;').replaceAll('<','&lt;')
  .replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;');


// unit conversions
const ML_PER_OZ   = 30.0;
const ML_PER_BSP  = 3.75;
const ML_PER_DASH = 0.82;
const ML_PER_DROP = 0.05;

function plural(n, s, p) { return `${n} ${n===1 ? s : (p || s+'s')}`; }

// ---- Spoken profile wiring ----
let SPOKEN_ACTIVE_NAME = null;
let SPOKEN_ACTIVE_ID = null; // if backend returns it
let CURRENT_DRINK_ID = null;      // ← use this for posts


  // ===== Dilution config defaults =====
  const DILUTION_DEFAULTS = {
    poly: { a: -1.57, b: 1.74, c: 0.20 },       // new base polynomial
    mult: { shake: 1.00, stir: 0.72, built: 0.51 },
    direct: { blender: 1.80 }                    // direct dilution fraction
  };
  let DILUTION_CFG = JSON.parse(JSON.stringify(DILUTION_DEFAULTS));

function SPOKEN_DEFAULTS(){
  return {
    stPour: 30, nUnit: 1, unitName: 'ounce',
    unitDiv: 4, hAccuracy: 1, hAunitDiv: 8,
    fatShort: 1,
    useBarspoon: 1, useDash: 1, useDrop: 1,
    barspoon: 3.75, dash: 0.82, drop: 0.05
  };
}


async function fetchSpokenProfile(){
  const r = await fetch('/recipes/api/spoken_config.php', { credentials: 'same-origin' });
  const text = await r.text();

  let j;
  try { j = JSON.parse(text); }
  catch {
    // Don’t crash init on non-JSON; just apply defaults.
    console.warn('spoken_config non-JSON:', text.slice(0,200));
    applySpokenProfile({}, '');
    const statusBox = document.getElementById('status');
    if (statusBox) statusBox.textContent = '';
    return { config:{}, name:'' };
  }

  if (!r.ok) {
    const msg = j.detail || j.error || 'Failed to load spoken profile';
    throw new Error(msg);
  }

  applySpokenProfile(j.config || {}, j.name || '');
  // Merge any server-stored dilution config (optional)
  try {
    const dc = (j.config && j.config.dilution) ? j.config.dilution : null;
    if (dc) {
      DILUTION_CFG = {
        poly:  { ...DILUTION_DEFAULTS.poly,  ...(dc.poly  || {}) },
        mult:  { ...DILUTION_DEFAULTS.mult,  ...(dc.mult  || {}) },
        direct:{ ...DILUTION_DEFAULTS.direct, ...(dc.direct|| {}) },
      };
    } else {
      DILUTION_CFG = JSON.parse(JSON.stringify(DILUTION_DEFAULTS));
    }
  } catch { DILUTION_CFG = JSON.parse(JSON.stringify(DILUTION_DEFAULTS)); }

  // remember active (optional)
  SPOKEN_ACTIVE_NAME = j.name || null;
  SPOKEN_ACTIVE_ID   = (j.profile_id != null ? j.profile_id : (j.id != null ? j.id : null));

  const statusBox = document.getElementById('status');
  if (statusBox && j.name) statusBox.textContent = `Spoken profile: ${j.name}`;
  return j;
}


// Legacy fallback (kept for UI preview)
function toSpoken(ml) {
  if (!Number.isFinite(ml) || ml <= 0) return '';
  if (ml < ML_PER_DASH) {
    const drops = Math.max(1, Math.round(ml / ML_PER_DROP));
    return plural(drops, 'drop');
  }
  if (ml < 3.5) {
    const halfDashes = Math.max(1, Math.round((ml / ML_PER_DASH) * 2));
    const whole = Math.floor(halfDashes / 2);
    const half  = halfDashes % 2;
    if (half) return whole === 0 ? '½ dash' : `${whole} ½ dashes`;
    return plural(whole, 'dash');
  }
  if (ml < 31.3) {
    const n24 = Math.max(3, Math.min(25, Math.round(ml / 1.25)));
    const M = {
      3:['','','barspoon'],4:['fat','','barspoon'],
      5:['short','1/4','ounce'],6:['','1/4','ounce'],7:['fat','1/4','ounce'],
      8:['short','3/8','ounce'],9:['','3/8','ounce'],10:['fat','3/8','ounce'],
      11:['short','1/2','ounce'],12:['','1/2','ounce'],13:['fat','1/2','ounce'],
      14:['short','5/8','ounce'],15:['','5/8','ounce'],16:['fat','5/8','ounce'],
      17:['short','3/4','ounce'],18:['','3/4','ounce'],19:['fat','3/4','ounce'],
      20:['short','7/8','ounce'],21:['','7/8','ounce'],22:['fat','7/8','ounce'],
      23:['short','','ounce'],24:['','','ounce'],25:['fat','','ounce']
    };
    const [pre, numTxt, unit] = M[n24] || ['', '', 'ounce'];
    return [pre, numTxt, unit].filter(Boolean).join(' ');
  }
  const Int12 = Math.max(0, Math.round(ml / 2.5));
  const mod3 = Int12 % 3;
  const prefix = (mod3 === 1) ? 'fat' : (mod3 === 2 ? 'short' : '');
  const whole = Math.floor((Int12 + 1) / 12);
  const qIdx  = Math.floor(((Int12 + 1) % 12) / 3);
  const frac = ['ounces', '1/4 ounces', '1/2 ounces', '3/4 ounces'][qIdx];
  return [prefix, String(whole), frac].filter(Boolean).join(' ');
}

// ---- Spoken config dialog wiring ----
function $(id){ return document.getElementById(id); }

function showSpokenModal(show){ $('spokenModal')?.classList.toggle('hidden', !show); }

function readDialogConfig(){
  const stPour = Number($('sp_stPour').value) || 30;
  const nUnit  = ($('sp_mode').value === 'named') ? 1 : 0;

  let useBarspoon = $('sp_useBsp').checked ? 1 : 0;
  let useDash     = $('sp_useDash').checked && useBarspoon ? 1 : 0;
  let useDrop     = $('sp_useDrop').checked && useDash ? 1 : 0;

  let barspoon = Math.max(0, Number($('sp_bsp').value) || 3.75);
  let dash     = Math.max(0, Number($('sp_dash').value) || 0.82);
  let drop     = Math.max(0, Number($('sp_drop').value) || 0.05);

  if (useDash) dash = Math.min(dash, barspoon/2);
  if (useDrop) drop = Math.min(drop, dash/2);
  if (!useBarspoon){ useDash=0; useDrop=0; }

  let unitName = 'ounce';
  if (nUnit){
    const preset = $('sp_unitPreset').value;
    unitName = (preset === 'custom') ? ($('sp_unitCustom').value.trim() || 'unit') : preset;
  } else {
    unitName = 'ml';
  }

  let unitDiv, hAunitDiv, hAccuracy;
  if (nUnit){
    unitDiv   = Number($('sp_unitDiv_named').value) || 4;
    hAccuracy = $('sp_hAcc').checked ? 1 : 0;
    hAunitDiv = hAccuracy ? (Number($('sp_hAunitDiv_named').value) || unitDiv) : 0;
  } else {
    unitDiv   = Number($('sp_unitDiv_ml').value) || 5;
    hAccuracy = $('sp_hAcc_ml').checked ? 1 : 0;
    hAunitDiv = hAccuracy ? (Number($('sp_hAunitDiv_ml').value) || unitDiv) : 0;
  }

  const fatShort = $('sp_fatShort').checked ? 1 : 0;

  return {
    stPour, nUnit, unitName, hAccuracy, fatShort,
    unitDiv, hAunitDiv,
    useBarspoon, useDash, useDrop,
    barspoon, dash, drop
  };
}

function setDialogFromConfig(cfg){
  $('sp_stPour').value = cfg.stPour ?? 30;
  $('sp_mode').value   = (cfg.nUnit ? 'named' : 'ml');

  $('sp_namedGroup').style.display = cfg.nUnit ? '' : 'none';
  $('sp_mlGroup').style.display    = cfg.nUnit ? 'none' : '';

  const name = (cfg.unitName || 'ounce').toLowerCase();
  if (cfg.nUnit){
    if (name === 'ounce' || name === 'part'){ $('sp_unitPreset').value = name; }
    else { $('sp_unitPreset').value = 'custom'; }
    $('sp_unitCustom').value = (name !== 'ounce' && name !== 'part') ? (cfg.unitName || '') : '';
    $('sp_unitCustom').disabled = ($('sp_unitPreset').value !== 'custom');

    $('sp_unitDiv_named').value = String(cfg.unitDiv ?? 4);
    $('sp_hAcc').checked = !!cfg.hAccuracy;
    $('sp_hAccRow_named').style.display = cfg.hAccuracy ? '' : 'none';
    $('sp_hAunitDiv_named').value = String(cfg.hAunitDiv || cfg.unitDiv || 8);
  } else {
    $('sp_unitDiv_ml').value = String(cfg.unitDiv ?? 5);
    $('sp_hAcc_ml').checked = !!cfg.hAccuracy;
    $('sp_hAccRow_ml').style.display = cfg.hAccuracy ? '' : 'none';
    $('sp_hAunitDiv_ml').value = String(cfg.hAunitDiv || cfg.unitDiv || 2.5);
  }

  $('sp_fatShort').checked = !!cfg.fatShort;

  $('sp_useBsp').checked = !!cfg.useBarspoon;
  $('sp_useDash').checked = !!cfg.useDash;
  $('sp_useDrop').checked = !!cfg.useDrop;

  $('sp_bsp').value  = cfg.barspoon ?? 3.75;
  $('sp_dash').value = cfg.dash ?? 0.82;
  $('sp_drop').value = cfg.drop ?? 0.05;

  refreshSmallText();
  refreshPreview();
}

function readDilutionDialogConfig(){
  const shake = Number(document.getElementById('dl_mult_shake')?.value) || 1.00;
  const stir  = Number(document.getElementById('dl_mult_stir') ?.value) || 0.72;
  const built = Number(document.getElementById('dl_mult_built')?.value) || 0.51;
  const blend = Number(document.getElementById('dl_direct_blender')?.value) || 1.80;

  // poly editor may be hidden; if blank, keep current values
  const a = document.getElementById('dl_coef_a')?.value;
  const b = document.getElementById('dl_coef_b')?.value;
  const c = document.getElementById('dl_coef_c')?.value;

  const poly = {
    a: (a===''||a==null) ? DILUTION_CFG.poly.a : Number(a),
    b: (b===''||b==null) ? DILUTION_CFG.poly.b : Number(b),
    c: (c===''||c==null) ? DILUTION_CFG.poly.c : Number(c)
  };

  return {
    poly,
    mult: { shake, stir, built },
    direct: { blender: blend }
  };
}

function setDilutionDialogFromConfig(cfg){
  document.getElementById('dl_mult_shake').value = (cfg.mult?.shake ?? 1.00);
  document.getElementById('dl_mult_stir').value  = (cfg.mult?.stir  ?? 0.72);
  document.getElementById('dl_mult_built').value = (cfg.mult?.built ?? 0.51);
  document.getElementById('dl_direct_blender').value = (cfg.direct?.blender ?? 1.80);

  document.getElementById('dl_coef_a').value = (cfg.poly?.a ?? -1.57);
  document.getElementById('dl_coef_b').value = (cfg.poly?.b ??  1.74);
  document.getElementById('dl_coef_c').value = (cfg.poly?.c ??  0.20);
}

// Reveal/Hide poly editor
document.getElementById('dl_edit_poly')?.addEventListener('click', () => {
  const box = document.getElementById('dl_poly_editor');
  box?.classList.toggle('hidden');
});



function refreshVisibility(){
  const mode = $('sp_mode').value;
  $('sp_namedGroup').style.display = (mode === 'named') ? '' : 'none';
  $('sp_mlGroup').style.display    = (mode === 'ml')    ? '' : 'none';

  $('sp_unitCustom').disabled = ($('sp_unitPreset').value !== 'custom');

  $('sp_hAccRow_named').style.display = $('sp_hAcc').checked ? '' : 'none';
  $('sp_hAccRow_ml').style.display    = $('sp_hAcc_ml').checked ? '' : 'none';
}

function refreshSmallText(){
  const c = readDialogConfig();
  let smallestText = '';
  if (c.useBarspoon){
    const smallest = c.useDrop ? c.drop : (c.useDash ? c.dash : c.barspoon);
    const label = c.useDrop ? 'drop' : (c.useDash ? 'dash' : 'barspoon');
    smallestText = `Measurements less than the ${label} (${smallest.toFixed(2)} mL) will not be rounded.`;
  } else {
    smallestText = `Measurements less than the barspoon (${(c.barspoon||3.75).toFixed(2)} mL) will not be rounded.`;
  }
  $('sp_smallText').textContent = smallestText;
}

function refreshPreview(){
  const cfg = readDialogConfig();
  applySpokenProfile(cfg, '(session)');
  const samples = [0.7, 0.9, 3.6, 7.5, 12, 20, cfg.stPour*0.75, cfg.stPour, cfg.stPour*1.25];
  const parts = samples.map(v => `${v.toFixed(2)} mL → ${toSpokenProfiled(v)}`);
  $('sp_preview').textContent = 'Preview: ' + parts.join(' • ');
}

async function saveProfileToServer(cfg){
  const name = prompt('Save as (profile name):', 'My spoken setup');
  if (name === null) return; // cancelled

  const r = await fetch('/recipes/api/spoken_profiles_save.php', {
    method:'POST',
    credentials:'same-origin',
    headers:{
      'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
      'Accept': 'application/json'
    },
    body:new URLSearchParams({
      action:'update_or_clone',
      name,
      config: JSON.stringify(cfg)
    })
  });

  const ct = r.headers.get('content-type') || '';
  const text = await r.text();

  // Try JSON if content-type looks right, else fall back to raw text
  let j = null;
  if (ct.includes('application/json')) {
    try { j = JSON.parse(text); } catch { /* fall through to error */ }
  }
  if (!j) {
    // Surface the server’s HTML/message verbatim so we can see warnings/notices
    throw new Error(text || `Non-JSON response (HTTP ${r.status})`);
  }

  if (!r.ok || j.error) {
    throw new Error(j.detail || j.error || `Save failed (HTTP ${r.status})`);
  }

  if (j.id){
    await fetch('/recipes/api/spoken_profile_select.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:new URLSearchParams({ profile_id: j.id })
    });
  }
  return j;
}


function debounce(fn, ms=250){ let t; return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); }; }

document.addEventListener('DOMContentLoaded', () => {
  const statusBox = document.getElementById('status');
  const bodyEl = document.body;

  const TOL_OPT = { abv: 0.25, sugar: 0.50, acid: 0.10, vol: 1.0 };

  // ---------- toast ----------
  let _toastTimer = null;
  function showToast(msg, kind='info', ttl=0){
    if (!statusBox) return;
    const prefix = kind==='error' ? '⚠️ ' : (kind==='warn' ? '⚠️ ' : 'ℹ️ ');
    statusBox.textContent = prefix + String(msg);
    statusBox.style.borderColor = (kind==='error' ? '#ef4444' : (kind==='warn' ? '#f59e0b' : '#3b82f6'));
    statusBox.style.background = (kind==='error' ? '#fef2f2' : (kind==='warn' ? '#fffbeb' : '#eff6ff'));
    if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
    if (ttl && ttl > 0){
      _toastTimer = setTimeout(()=>{
        statusBox.textContent = '';
        statusBox.style.borderColor = '#e5e7eb';
        statusBox.style.background = '#f6f8fa';
        _toastTimer = null;
      }, ttl);
    }
  }

  // ---------- DOM refs ----------
  const tabRecipe = document.getElementById('tabRecipe');
  const tabOptimize = document.getElementById('tabOptimize');

  const drinkInput   = document.getElementById('drinkInput');
  const drinkSelect  = document.getElementById('drinkSelect');
  const ingInput     = document.getElementById('ingInput');
  const ingSelect    = document.getElementById('ingredientSelect');

  const displayUnits = document.getElementById('displayUnits'); // may be null

  const grid      = document.getElementById('grid');
  const gridBody  = grid.querySelector('tbody');

  const t_spoken   = document.getElementById('t_spoken');   // may be null
  const t_ml       = document.getElementById('t_ml');
  const t_alc_ml   = document.getElementById('t_alc_ml');
  const t_sugar_g  = document.getElementById('t_sugar_g');
  const t_acid_g   = document.getElementById('t_acid_g');
  const t_abv_pct  = document.getElementById('t_abv_pct');
  const t_sugar_pct= document.getElementById('t_sugar_pct');
  const t_acid_pct = document.getElementById('t_acid_pct');

  const dilutionMode   = document.getElementById('dilutionMode');
  const dilutionPct    = document.getElementById('dilutionPct');
  const dilutionPctLbl = document.getElementById('dilutionPctLabel');
  
  if (dilutionMode && dilutionMode.value === 'custom' && dilutionPct && !dilutionPct.value) {
    dilutionPct.value = '0';
  }

  const d_spoken     = document.getElementById('d_spoken');  // may be null
  const d_mode_label = document.getElementById('d_mode_label');
  const d_total_ml   = document.getElementById('d_total_ml');
  const d_water_ml   = document.getElementById('d_water_ml');
  const d_abv_pct    = document.getElementById('d_abv_pct');
  const d_sugar_pct  = document.getElementById('d_sugar_pct');
  const d_acid_pct   = document.getElementById('d_acid_pct');

  const targetsMixRow       = document.getElementById('targetsMixRow');
  const targetsDilutedRow   = document.getElementById('targetsDilutedRow');
  const tgt_disp_ml_mix     = document.getElementById('tgt_disp_ml_mix');
  const tgt_disp_abv_mix    = document.getElementById('tgt_disp_abv_mix');
  const tgt_disp_sugar_mix  = document.getElementById('tgt_disp_sugar_mix');
  const tgt_disp_acid_mix   = document.getElementById('tgt_disp_acid_mix');
  const tgt_disp_spoken_dil = document.getElementById('tgt_disp_spoken_dil');
  const tgt_disp_ml_dil     = document.getElementById('tgt_disp_ml_dil');
  const tgt_disp_abv_dil    = document.getElementById('tgt_disp_abv_dil');
  const tgt_disp_sugar_dil  = document.getElementById('tgt_disp_sugar_dil');
  const tgt_disp_acid_dil   = document.getElementById('tgt_disp_acid_dil');

  const drinkTypeSel  = document.getElementById('drinkType');
  const drinkNotes    = document.getElementById('drinkNotes');
  const drinkSource   = document.getElementById('drinkSource');
  const drinkDate     = document.getElementById('drinkDate');
  const drinkLocked   = document.getElementById('drinkLocked');
  const saveDrinkBtn  = document.getElementById('saveDrink');
  const cloneDrinkBtn = document.getElementById('cloneDrink');

  const pUnitsInput   = document.getElementById('pUnits');
  const bUnitsInput   = document.getElementById('bUnits');

  const mlInput   = document.getElementById('ml');
  const ozInput   = document.getElementById('oz');
  const bspInput  = document.getElementById('bsp');
  const dashInput = document.getElementById('dash');
  const dropInput = document.getElementById('drop');
  const spoken    = document.getElementById('spoken');
  const positionInput = document.getElementById('position');
  const addBtn        = document.getElementById('add');

  const optHold = document.getElementById('optHold');
  const optLow  = document.getElementById('optLow');
  const optHigh = document.getElementById('optHigh');
  const clearAllConstraintsBtn = document.getElementById('clearAllConstraints');

  const btnMatch    = document.getElementById('matchTargets');
  const lockVolume  = document.getElementById('lockVolume');
  const spokenQuant = document.getElementById('spokenQuant');
  const basisMix    = document.getElementById('basis_mix');
  const basisDil    = document.getElementById('basis_diluted');
  const tgt_vol     = document.getElementById('tgt_vol');
  const tgt_abv     = document.getElementById('tgt_abv');
  const tgt_sugar   = document.getElementById('tgt_sugar');
  const tgt_acid    = document.getElementById('tgt_acid');
  const copyPasteTargetsBtn = document.getElementById('copyPasteTargets');

  const btnConfig = document.getElementById('configure') || document.getElementById('btnSpokenConfig');
  const btnSave   = document.getElementById('sp_save');
  const btnCancel = document.getElementById('sp_cancel');
  const btnCloseX = document.getElementById('sp_close');

  // NEW: Apply (session) + Reset-to-defaults buttons
  const btnApply    = document.getElementById('sp_apply');
  const btnResetAll = document.getElementById('cfg_reset_all');


  const drinkPublic  = document.getElementById('drinkPublic');
  const visibilityBadge = document.getElementById('visibilityBadge');




  btnConfig?.addEventListener('click', () => {
    try {
      const cfg = (typeof getSpokenProfile === 'function') ? (getSpokenProfile() || {}) : {};
      setDialogFromConfig(cfg);
      // Also populate the Dilution tab from current (or default) config
      setDilutionDialogFromConfig(DILUTION_CFG);
      // Default to the Spoken tab on open
      selectCfgTab('spoken');
      refreshVisibility();
    } catch {}
    showSpokenModal(true);
  });

// ===== Tab switching =====
const tabCfgSpoken = document.getElementById('tabCfgSpoken');
const tabCfgDilution = document.getElementById('tabCfgDilution');
const paneCfgSpoken = document.getElementById('paneCfgSpoken');
const paneCfgDilution = document.getElementById('paneCfgDilution');

function selectCfgTab(which){
  const spoken = (which === 'spoken');
  tabCfgSpoken?.setAttribute('aria-selected', spoken ? 'true' : 'false');
  tabCfgDilution?.setAttribute('aria-selected', spoken ? 'false' : 'true');
  paneCfgSpoken?.classList.toggle('hidden', !spoken);
  paneCfgDilution?.classList.toggle('hidden', spoken);
}
tabCfgSpoken?.addEventListener('click', () => selectCfgTab('spoken'));
tabCfgDilution?.addEventListener('click', () => selectCfgTab('dilution'));

// ===== Spoken visibility toggles =====
['sp_mode','sp_unitPreset','sp_hAcc','sp_hAcc_ml'].forEach(id => {
  const el = document.getElementById(id);
  el?.addEventListener('change', () => { refreshVisibility(); refreshSmallText(); refreshPreview(); });
});
['sp_bsp','sp_dash','sp_drop','sp_unitDiv_named','sp_hAunitDiv_named','sp_unitDiv_ml','sp_hAunitDiv_ml','sp_unitCustom','sp_fatShort','sp_stPour']
.forEach(id => {
  const el = document.getElementById(id);
  el?.addEventListener('input', () => { refreshSmallText(); refreshPreview(); });
});


  btnCancel?.addEventListener('click', () => showSpokenModal(false));
  btnCloseX?.addEventListener('click', () => showSpokenModal(false));
  btnSave?.addEventListener('click', async () => {
    const cfgSpoken = readDialogConfig();             
    const cfgDilution = readDilutionDialogConfig();   
    const fullCfg = { ...cfgSpoken, dilution: cfgDilution };

    try {
      await saveProfileToServer(fullCfg);
      showToast('Configuration saved', 'info', 1200);
    } catch (e) {
      showToast('Save failed: ' + (e?.message || e), 'error');
    } finally {
      showSpokenModal(false);
    }
  });

// Apply (session) – updates runtime only
btnApply?.addEventListener('click', () => {
  const cfgSpoken   = readDialogConfig();
  const cfgDilution = readDilutionDialogConfig();
  applySpokenProfile(cfgSpoken, '(session)');
  DILUTION_CFG = {
    poly:   { ...DILUTION_DEFAULTS.poly,   ...(cfgDilution.poly||{}) },
    mult:   { ...DILUTION_DEFAULTS.mult,   ...(cfgDilution.mult||{}) },
    direct: { ...DILUTION_DEFAULTS.direct, ...(cfgDilution.direct||{}) },
  };
  refreshPreview();
  computeTotals();
  showToast('Applied for this session', 'info', 1000);
});

// Reset to Defaults (session) – resets dialog fields + live state
btnResetAll?.addEventListener('click', () => {
  if (!confirm('Reset ALL configuration to defaults?')) return;
  setDialogFromConfig(SPOKEN_DEFAULTS());
  setDilutionDialogFromConfig(DILUTION_DEFAULTS);
  applySpokenProfile(SPOKEN_DEFAULTS(), '(session)');
  DILUTION_CFG = JSON.parse(JSON.stringify(DILUTION_DEFAULTS));
  refreshPreview();
  computeTotals();
  showToast('Defaults restored (session). Save to persist.', 'info', 1600);
});




  // State
  let LAST_ROWS = [];
  let CURRENT_ROW_ID = null;
  let MODE = 'recipe'; // 'recipe' | 'optimize'
  let COPIED_TARGET = null;
  
   // state for auth / hint
   let DRINK_EDITABLE   = false;
   let LOGGED_IN        = false;
   let LOGIN_HINT_SHOWN = false;

  
  async function verifyCurrentDrinkVisible() {
  if (!CURRENT_DRINK_ID) return;
  try {
    await jget(`${API_BASE}/drinks.php?id=${encodeURIComponent(CURRENT_DRINK_ID)}`);
  } catch {
    // not visible anymore → clear UI
    CURRENT_DRINK_ID = null;
    drinkSelect.value = '';
    drinkInput.value  = '';
    LAST_ROWS = [];
    gridBody.innerHTML = '';
    clearTotals();
    resetAddBox();
    showToast('That drink is private. Selection cleared.', 'warn', 2000);
  }
  }
  
  function resetAddBox() {
  toAllFromML(0, null, { blankWhenZero: true });
  mlInput.value = '';
  if (positionInput) positionInput.value = '';
  CURRENT_ROW_ID = null;
  addBtn.textContent = (MODE === 'optimize') ? 'Add Test ingredient' : 'Add ingredient';
  if (ingSelect) ingSelect.value = '';
  if (ingInput)  ingInput.value  = '';
}

resetAddBox();

  function setMode(m){
    MODE = m;
    tabRecipe?.setAttribute('aria-selected', String(m==='recipe'));
    tabOptimize?.setAttribute('aria-selected', String(m==='optimize'));
    bodyEl.classList.toggle('mode-recipe', m==='recipe');
    bodyEl.classList.toggle('mode-opt', m==='optimize');
    document.getElementById('optPanel')?.classList.toggle('hidden', m!=='optimize');
    document.getElementById('constraintsPanel')?.classList.toggle('hidden', m!=='optimize');
    if (copyPasteTargetsBtn) copyPasteTargetsBtn.textContent = (m==='recipe') ? 'Copy Targets' : 'Paste Targets';
    loadRecipes();
    resetAddBox();
  }

  tabRecipe?.addEventListener('click', ()=> setMode('recipe'));
  tabOptimize?.addEventListener('click', ()=> setMode('optimize'));

  function log(msg){ console.log(msg); if (statusBox) statusBox.textContent = String(msg); }
  const jget  = async (u) => {
    const r = await fetch(u, { credentials: 'same-origin' });
    const t = await r.text();
    try {
      const j = t ? JSON.parse(t) : {};
      if (!r.ok) throw new Error(j.detail || j.error || `HTTP ${r.status}`);
      return j;
    } catch (e) {
      throw new Error(`Non-JSON from ${u} (status ${r.status}): ${t.slice(0,180)}`);
    }
  };

  const jpost = async (u, d) => {
    const r = await fetch(u, {
      method: 'POST',
      body: new URLSearchParams(d),
      credentials: 'same-origin'
    });
    const t = await r.text();
    try {
      const j = t ? JSON.parse(t) : {};
      if (!r.ok) throw new Error(j.detail || j.error || `HTTP ${r.status}`);
      return j;
    } catch (e) {
      throw new Error(`Non-JSON from ${u} (status ${r.status}): ${t.slice(0,180)}`);
    }
  };

  const jdel  = async (u,d)=> { const r=await fetch(u,{method:'DELETE',body:new URLSearchParams(d),credentials:'same-origin'}); const t=await r.text(); if(!r.ok) throw new Error(t || `DEL ${u} failed`); return JSON.parse(t || '{}'); };

  const currentView = () => (MODE === 'optimize' ? 'test' : 'recipe');

  async function loadDrinkTypes(){
    const data = await jget(`${API_BASE}/drink_types.php`);
    drinkTypeSel.innerHTML = (data.items||[]).map(t => `<option value="${t.id}">${t.drink_type ?? t.type_name}</option>`).join('');
  }

  // ----------------- Comboboxes -----------------

// DRINK
// Turn off the browser's native dropdown/autocomplete for this input
drinkInput.autocomplete = 'off';
drinkInput.removeAttribute('list');

makeCombo({
  input:  drinkInput,
  select: drinkSelect,
  fetchList: (q) => jget(`${API_BASE}/drinks.php` + (q ? `?q=${encodeURIComponent(q)}` : '')),
  renderLabel: d => d.drink_name,
  getId: d => d.id ?? d.drink_id,
  // Very important: don't create on Enter unless the list is empty (prevents 401s when logged out)
  allowCreate: true,
  createOnlyWhenEmpty: true,
  minChars: 1,
  prefetch: false,
  debounceMs: 120,
  onCreate: async (name) => {
    // If user is logged out this will 401; that's fine — we only get here when list is empty
    const res = await jpost(`${API_BASE}/drinks.php`, { action:'create', drink_name:name });
    return res.id;
  },


onPick: async (d) => {
  const id = d && (d.id ?? d.drink_id);
  if (!id) return;

  // Clear any stale status styles
  if (statusBox) {
    statusBox.textContent = '';
    statusBox.style.borderColor = '#e5e7eb';
    statusBox.style.background  = '#f6f8fa';
  }

  CURRENT_DRINK_ID = String(id);

  // Fetch header (includes owner_id) and whoami to compute editability
  const row = await jget(`${API_BASE}/drinks.php?id=${encodeURIComponent(id)}`);

  let who = null;
  try { who = await jget(`${API_BASE}/auth.php?action=whoami`); }
  catch { /* not logged in is fine */ }

  LOGGED_IN = !!(who && (who.uid || who.id));
  const myUid   = Number(who?.uid ?? who?.id ?? 0);
  const isAdmin = (who?.role === 'admin');

  DRINK_EDITABLE = (myUid && Number(row.owner_id) === myUid) || isAdmin || false;

  // Load rest of UI
  await loadDrinkHeader(id);
  await loadRecipes(id);
  await loadTargets(id);
  await loadVersionFamily(id);

  // Update Add button disabled state for clarity
  addBtn.disabled = !DRINK_EDITABLE;
  addBtn.title = DRINK_EDITABLE ? '' : 'You do not own this drink (read-only)';
  // Enable/disable header editors
  [drinkTypeSel, drinkNotes, drinkSource, drinkDate, drinkLocked, drinkPublic, saveDrinkBtn, cloneDrinkBtn]
    .filter(Boolean).forEach(el => el.disabled = !DRINK_EDITABLE);
}


});



// INGREDIENT (with ABV/sugar/acid details)
// Turn off the browser's native dropdown/autocomplete for this input
ingInput.autocomplete = 'off';
ingInput.removeAttribute('list');

makeCombo({
  input:  ingInput,
  select: ingSelect,
  fetchList: (q) => jget(`${API_BASE}/ingredients.php` + (q ? `?q=${encodeURIComponent(q)}` : '')),
  renderRow: i => {
    const info = [];
    if (i.ethanol != null)         info.push(`${(i.ethanol*100).toFixed(1)}% ABV`);
    if (i.sweetness != null)       info.push(`${(i.sweetness*100).toFixed(1)}% sugar`);
    if (i.titratable_acid != null) info.push(`${(i.titratable_acid*100).toFixed(2)}% acid`);
    return `<div class="cbx-name">${i.ingredient}</div>` +
           (info.length ? `<div class="cbx-hint" style="font-size:12px;color:#666">${info.join(' • ')}</div>` : '');
  },
  renderLabel: i => i.ingredient,
  getId: i => i.id ?? i.ingredient_id,
  allowCreate: true,
  createOnlyWhenEmpty: true,
  minChars: 1,
  prefetch: false,
  debounceMs: 120,
  onCreate: async (name) => {
    const res = await jpost(`${API_BASE}/ingredients.php`, { action:'create', ingredient:name });
    return res.id;
  },
  onPick: () => { prefillAddBoxIfInRecipe(); loadConstraintsForSelected(); }
});



async function loginHintOnce() {
  if (LOGIN_HINT_SHOWN) return;
  LOGIN_HINT_SHOWN = true;
  try {
    const who = await jget(`${API_BASE}/auth.php?action=whoami`);
    LOGGED_IN = !!(who && (who.uid || who.id));
    if (!LOGGED_IN) {
      //alert('Heads up: you are not logged in.\nYou will only see public drinks and ingredients.\nLog in to access your saved items.');
      showToast('Heads up: you are not logged in. You’ll only see public drinks & ingredients Log in to access your saved items.', 'warn', 4000);
      verifyCurrentDrinkVisible();
    }
  } catch {
    // silently ignore if whoami unavailable
  }
}

// First focus on either combobox → show hint once
drinkInput?.addEventListener('focus', loginHintOnce, { once: true });
ingInput  ?.addEventListener('focus', loginHintOnce, { once: true });

  async function loadVersionFamily(id){
  try {
    const fam = await jget(`${API_BASE}/drinks.php?action=family&id=${encodeURIComponent(id)}`);
    // fam.items: [{id, version_tag, is_current, drink_name}]
    const sel = document.getElementById('versionSelect');
    if (!sel) return;
    if (!fam.items || fam.items.length <= 1) {
      sel.innerHTML = '';
      sel.disabled = true;
      sel.title = 'Single version';
      return;
    }
    sel.disabled = false;
    sel.innerHTML = fam.items.map(d =>
      `<option value="${d.id}" ${d.id==id?'selected':''}>${escapeHtml(d.version_tag || 'Current')}</option>`
    ).join('');
  } catch {}
  }

 document.getElementById('versionSelect')?.addEventListener('change', (e) => {
  const vid = e.target.value;
  if (vid) {
    drinkSelect.value = vid;
    CURRENT_DRINK_ID = String(vid);
    // reload full context for the picked version
    loadDrinkHeader(vid);
    loadRecipes(vid);
    loadTargets(vid);
    loadVersionFamily(vid);
  }
});
 

  async function loadDrinkHeader(id){
    const row = await jget(`${API_BASE}/drinks.php?id=${encodeURIComponent(id)}`);
    if (row.drink_type != null) drinkTypeSel.value = String(row.drink_type);
// --- Auto-select dilution from drink_type (deterministic) ---
try {
  // Use the actual selected option's text (or DB-provided text if you prefer)
  const typeText = (drinkTypeSel.selectedOptions[0]?.textContent || '').trim().toLowerCase();

  // Map of known types → dilution modes we support.
  // Only the four we have models for: 'stir', 'shake', 'built', 'blender'.
  // Everything else defaults to 'custom'.
  const TYPE_TO_MODE = {
    // Built family
    'built':        'built',
    'built stir':   'built',     // your id=3
    'highball':     'built',     // if it ever appears as a type label
    'collins':      'built',     // ditto

    // Stirred
    'stirred':      'stir',

    // Shaken
    'shaken':       'shake',
    'short shaken': 'shake',

    // Blender / Frozen (direct)
    'blender':      'blender',
    'frozen':       'blender',

    // Everything else – explicitly listed here to show intent,
    // but they’ll fall through to default anyway:
    'unknown':      'custom',
    'carbonated':   'custom',
    'julep':        'custom',
    'soda':         'custom',
    'shaved':       'custom',
    'straight':     'custom',
    'low alc':      'custom',
    'non alc':      'custom',
    'batch':        'custom'
  };

  const suggested = TYPE_TO_MODE[typeText] || 'custom';

  if (dilutionMode && !dilutionMode.matches(':focus')) {
    dilutionMode.value = suggested;
    // If we programmatically set to custom, default the % to 0 so it doesn't carry stale values
    if (suggested === 'custom' && dilutionPct) {
      dilutionPct.disabled = false;
      if (!dilutionPct.value || !isFinite(Number(dilutionPct.value))) {
        dilutionPct.value = '0';
      }
    }
    computeTotals();
    updateTargetsDisplay?.();
  }
} catch {}


    const typeText = (drinkTypeSel.selectedOptions[0]?.textContent || '').toLowerCase();

    let suggested = null;

    // 1) Explicit matches for type names
    if (/\bstirred?\b/.test(typeText))  suggested = 'stir';
    if (/\bshak(?:e|en)\b/.test(typeText)) suggested = 'shake';
    if (/\bbuilt\b/.test(typeText))     suggested = 'built';
    if (/\bblender|frozen\b/.test(typeText)) suggested = 'blender';

    // 2) Heuristics by canonical cocktail families (as you had)
    if (!suggested && /(martini|manhattan|old fashioned|negroni|spritz)/.test(typeText)) suggested = 'stir';
    if (!suggested && /(sour|daiquiri|margarita|shake)/.test(typeText)) suggested = 'shake';
    if (!suggested && /(highball|built|collins|spritz)/.test(typeText)) suggested = 'built';
    if (!suggested && /(frozen|blender)/.test(typeText)) suggested = 'blender';

    // Apply if we have a suggestion and user isn't actively editing the control
    if (suggested && dilutionMode && !dilutionMode.matches(':focus')) {
      // only set if that option exists in the select
      const has = Array.from(dilutionMode.options).some(o => o.value === suggested);
      if (has) {
        dilutionMode.value = suggested;
        if (suggested === 'custom' && dilutionPct) {
        dilutionPct.disabled = false;
        if (!dilutionPct.value || !isFinite(Number(dilutionPct.value))) {
          dilutionPct.value = '0';
        }
      }
        computeTotals();
        updateTargetsDisplay?.();
      }
    }


    drinkNotes.value  = row.drink_notes  ?? '';
    drinkSource.value = row.drink_source ?? '';
    drinkDate.value   = row.drink_date   ?? '';
    drinkLocked.checked = !!row.drink_locked;
    if (drinkPublic) drinkPublic.checked = !!row.is_public;

    if (visibilityBadge) {
      const isPub = !!row.is_public;
      visibilityBadge.textContent = isPub ? 'Public' : 'Private';
      visibilityBadge.className = 'pill ' + (isPub ? 'public' : 'private');
    }

  }

  saveDrinkBtn.addEventListener('click', async () => {
    const id = drinkSelect.value; if(!id){ showToast('Pick or create a drink first.', 'warn'); return; }
    try{
     await jpost(`${API_BASE}/drinks.php`, {
       action:'update', id,
       drink_type:  drinkTypeSel.value || '',
       drink_notes: drinkNotes.value,
       drink_source: drinkSource.value,
       drink_date:  drinkDate.value,
       drink_locked: drinkLocked.checked ? 1 : 0,
       is_public: (drinkPublic && drinkPublic.checked) ? 1 : 0
     });

      log('Drink saved');
    }catch(e){ showToast('Save failed: '+e.message, 'error'); }
  });

cloneDrinkBtn.addEventListener('click', async () => {
  const from = drinkSelect.value; if(!from) return;
  const defaultName = (drinkInput.value.trim() ? (drinkInput.value.trim() + ' Copy') : 'Untitled Copy');
  const name = prompt('New drink name:', defaultName);
  if (name === null) return;
  const vtag = prompt('Version tag (optional):', '');

  try {
    // Server will handle parent_drink_id / is_current logic
    const res = await jpost(`${API_BASE}/drinks.php`, {
      action:'clone',
      from_id: from,
      drink_name: name,
      version_tag: vtag || ''
    });

    // Focus the new drink
    drinkSelect.value = res.id;
    drinkInput.value  = name;
    CURRENT_DRINK_ID  = String(res.id);

    await loadDrinkHeader(res.id);
    await loadRecipes(res.id);
    await loadTargets(res.id);
    await loadVersionFamily(res.id);

    log('Variant created');
  } catch(e) {
    showToast('Clone failed: '+ e.message, 'error');
  }
});


  // ---------- Grid ----------
async function loadRecipes(drinkId){
  const id = drinkId || drinkSelect.value;
  if(!id){ gridBody.innerHTML=''; LAST_ROWS = []; clearTotals(); return; }

  const view = currentView();
  const data = await jget(`${API_BASE}/recipes.php?drink_id=${encodeURIComponent(id)}&view=${encodeURIComponent(view)}`);
  const rows = (data.items || []);
  LAST_ROWS = rows;

  const unitsValue = displayUnits ? displayUnits.value : 'both';
  const wantSpoken = (unitsValue === 'both' || unitsValue === 'spoken');
  const wantMl     = (unitsValue === 'both' || unitsValue === 'ml');

  for (const el of document.querySelectorAll('.spoken-col')) el.style.display = wantSpoken ? '' : 'none';
  for (const el of document.querySelectorAll('.ml-col'))     el.style.display = wantMl ? '' : 'none';

  gridBody.innerHTML = rows.map(r => {
    const ml = num(r.ml);
    const spokenTxt = ml ? toSpokenProfiled(ml) : '';

    const controlsHtml = DRINK_EDITABLE
      ? `<button class="mv-up" data-id="${r.id}" title="Move up" style="padding:0 6px">▲</button>
         <button class="mv-down" data-id="${r.id}" title="Move down" style="padding:0 6px">▼</button>`
      : '';

    const deleteHtml = DRINK_EDITABLE ? `<button data-id="${r.id}" class="del">Delete</button>` : '';

    return `
      <tr data-id="${r.id}" data-ingredient-id="${r.ingredient_id}">
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            <div style="display:flex;flex-direction:column;gap:2px;">${controlsHtml}</div>
            <div class="ing-name">${r.ingredient}</div>
          </div>
        </td>
        <td class="num col-spoken spoken-col" style="display:${wantSpoken ? '' : 'none'}">${spokenTxt}</td>
        <td class="num col-ml ml-col" style="display:${wantMl ? '' : 'none'}"><div class="mlval">${fmtNum(ml,1)}</div></td>
        <td class="num">${fmtNum(r.alcohol_ml,2)}</td>
        <td class="num">${fmtNum(r.sugar_g,2)}</td>
        <td class="num">${fmtNum(r.acid_g,2)}</td>
        <td>
          <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;">${deleteHtml}</div>
        </td>
      </tr>`;
  }).join('');

    computeTotals();
    prefillAddBoxIfInRecipe();
    updateTargetsDisplay();
    resetAddBox();
  }

  function clearTotals(){
    const ids = ['t_spoken','t_ml','t_alc_ml','t_sugar_g','t_acid_g','t_abv_pct','t_sugar_pct','t_acid_pct','d_spoken','d_total_ml','d_water_ml','d_abv_pct','d_sugar_pct','d_acid_pct'];
    ids.forEach(id => { const el = document.getElementById(id); if (el) el.textContent = ''; });
  }

  // click row to select ingredient + prefill add box
  gridBody.addEventListener('click', (e) => {
    //if ((upBtn || dnBtn) && !DRINK_EDITABLE) { showToast('Read-only', 'warn', 1000); return; } // Already handled elswhere and not defined in this scope
    if (e.target.closest('button.del')) return;
    if (e.target.closest('button.mv-up')) return;
    if (e.target.closest('button.mv-down')) return;
    const tr = e.target.closest('tr[data-ingredient-id]'); if (!tr) return;
    const ingId = tr.getAttribute('data-ingredient-id');  if (!ingId) return;

    for (const opt of ingSelect.options) {
      if (String(opt.value) === String(ingId)) { ingSelect.value = opt.value; break; }
    }
    const nameCell = tr.querySelector('td:first-child .ing-name');
    if (nameCell) ingInput.value = nameCell.textContent.trim();

    prefillAddBoxIfInRecipe();
    loadConstraintsForSelected();
    document.getElementById('ml')?.focus();
  });

  // --- Reorder helpers ---
  function collectOrder(){
    return Array.from(document.querySelectorAll('#grid tbody tr[data-id]'))
      .map(tr => ({ id: Number(tr.dataset.id) }));
  }

  async function sendOrder(){
    const drink_id = document.getElementById('drinkSelect').value;
    if (!drink_id) throw new Error('No drink selected');

    const order = collectOrder();
    const r = await fetch('/recipes/api/recipes_reorder.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:new URLSearchParams({
        drink_id,
        target: currentView(),
        order: JSON.stringify(order)
      })
    });
    const j = await r.json();
    if (!r.ok || j.ok === false) throw new Error(j.detail || j.error || 'Reorder failed');
  }

  function computeTotals(){
    let T_ml=0, T_alc=0, T_sugar_g=0, T_acid_g=0;
    for (const r of LAST_ROWS){ T_ml+=num(r.ml); T_alc+=num(r.alcohol_ml); T_sugar_g+=num(r.sugar_g); T_acid_g+=num(r.acid_g); }

    if (t_spoken) t_spoken.textContent = toSpokenProfiled(T_ml);
    t_ml.textContent      = fmtNum(T_ml,1);
    t_alc_ml.textContent  = fmtNum(T_alc,2);
    t_sugar_g.textContent = fmtNum(T_sugar_g,2);
    t_acid_g.textContent  = fmtNum(T_acid_g,2);

    const abvFrac   = T_ml ? (T_alc / T_ml) : 0;
    const sugarFrac = T_ml ? (T_sugar_g / T_ml) : 0;
    const acidFrac  = T_ml ? (T_acid_g  / T_ml) : 0;

    t_abv_pct.textContent   = (abvFrac * 100).toFixed(1);
    t_sugar_pct.textContent = (sugarFrac * 100).toFixed(2);
    t_acid_pct.textContent  = (acidFrac  * 100).toFixed(2);

    // === New dilution model ===
const x = abvFrac; // undiluted ABV fraction
const { a, b, c } = DILUTION_CFG.poly;

let d = 0, modeLabel = '';
const mode = dilutionMode?.value;

if (mode === 'shake') {
  const base = (a*x*x) + (b*x) + c;
  d = Math.max(0, base * (DILUTION_CFG.mult.shake ?? 1.00));
  modeLabel = 'Shake';
  dilutionPct && (dilutionPct.disabled = true);
  dilutionPctLbl && (dilutionPctLbl.textContent = 'Percent (computed):');
  dilutionPct && (dilutionPct.value = (d*100).toFixed(1));
} else if (mode === 'stir') {
  const base = (a*x*x) + (b*x) + c;
  d = Math.max(0, base * (DILUTION_CFG.mult.stir ?? 0.72));
  modeLabel = 'Stir';
  dilutionPct && (dilutionPct.disabled = true);
  dilutionPctLbl && (dilutionPctLbl.textContent = 'Percent (computed):');
  dilutionPct && (dilutionPct.value = (d*100).toFixed(1));
} else if (mode === 'built') {
  const base = (a*x*x) + (b*x) + c;
  d = Math.max(0, base * (DILUTION_CFG.mult.built ?? 0.51));
  modeLabel = 'Built';
  dilutionPct && (dilutionPct.disabled = true);
  dilutionPctLbl && (dilutionPctLbl.textContent = 'Percent (computed):');
  dilutionPct && (dilutionPct.value = (d*100).toFixed(1));
} else if (mode === 'blender') {
  d = Math.max(0, (DILUTION_CFG.direct.blender ?? 1.80));
  modeLabel = 'Blender (direct)';
  dilutionPct && (dilutionPct.disabled = true);
  dilutionPctLbl && (dilutionPctLbl.textContent = 'Percent (direct):');
  dilutionPct && (dilutionPct.value = (d*100).toFixed(1));
} else { // custom
  modeLabel = 'Custom';
  if (dilutionPct) {
    dilutionPct.disabled = false;
    d = Math.max(0, num(dilutionPct.value)) / 100.0;
  }
  dilutionPctLbl && (dilutionPctLbl.textContent = 'Custom %:');
}


    const Vd       = T_ml * (1 + d);
    const waterAdd = T_ml * d;
    const abv_d    = abvFrac   / (1 + d);
    const sugar_d  = sugarFrac / (1 + d);
    const acid_d   = acidFrac  / (1 + d);

    if (d_spoken)        d_spoken.textContent   = toSpokenProfiled(Vd);
    if (d_mode_label)    d_mode_label.textContent = modeLabel;
    if (d_total_ml)      d_total_ml.textContent = fmtNum(Vd, 1);
    if (d_water_ml)      d_water_ml.textContent = fmtNum(waterAdd, 1);
    if (d_abv_pct)       d_abv_pct.textContent  = (abv_d * 100).toFixed(1);
    if (d_sugar_pct)     d_sugar_pct.textContent= (sugar_d * 100).toFixed(2);
    if (d_acid_pct)      d_acid_pct.textContent = (acid_d * 100).toFixed(2);
  }

  pUnitsInput?.addEventListener('input', computeTotals);
  bUnitsInput?.addEventListener('input', computeTotals);
  dilutionMode?.addEventListener('change', () => { computeTotals(); updateTargetsDisplay(); });
  dilutionPct?.addEventListener('input',  () => { computeTotals(); updateTargetsDisplay(); });
  displayUnits?.addEventListener('change', () => loadRecipes());

  // ---------- Targets ----------
  async function loadTargets(drinkId){
    const id = drinkId || drinkSelect.value; if(!id) return;
    try{
      const res = await jget(`${API_BASE}/targets.php?drink_id=${encodeURIComponent(id)}`);
      const t = res.item || {};
      tgt_vol  && (tgt_vol.value   = t.target_vol_ml     ?? '');
      tgt_abv  && (tgt_abv.value   = t.target_abv_pct    ?? '');
      tgt_sugar&& (tgt_sugar.value = t.target_sugar_pct  ?? '');
      tgt_acid && (tgt_acid.value  = t.target_acid_pct   ?? '');
      updateTargetsDisplay();
    }catch(e){ /* ok if none */ }
  }

  const debouncedSaveTargets = debounce(async ()=>{
    const id = drinkSelect.value; if(!id) return;
    try{
      await jpost(`${API_BASE}/targets.php`, {
        drink_id: id,
        target_vol_ml:   tgt_vol ? tgt_vol.value : '',
        target_abv_pct:  tgt_abv ? tgt_abv.value : '',
        target_sugar_pct:tgt_sugar ? tgt_sugar.value : '',
        target_acid_pct: tgt_acid ? tgt_acid.value : '',
        granularity_ml:  0
      });
    }catch(e){ /* silent */ }
  }, 300);

  [tgt_vol, tgt_abv, tgt_sugar, tgt_acid].forEach(el => {
    if (!el) return;
    el.addEventListener('input', () => { debouncedSaveTargets(); updateTargetsDisplay(); });
    el.addEventListener('blur',  () => { debouncedSaveTargets(); updateTargetsDisplay(); });
  });

  function updateTargetsDisplay(){
    const basis = (basisMix && basisMix.checked) ? 'undiluted' : 'diluted';
    const vol   = num(tgt_vol?.value   || 0);
    const abv   = num(tgt_abv?.value   || 0);
    const sugar = num(tgt_sugar?.value || 0);
    const acid  = num(tgt_acid?.value  || 0);

    if (targetsMixRow)     targetsMixRow.classList.toggle('hidden', basis!=='undiluted');
    if (targetsDilutedRow) targetsDilutedRow.classList.toggle('hidden', basis!=='diluted');

    if (basis === 'undiluted'){
      if (tgt_disp_ml_mix)     tgt_disp_ml_mix.textContent     = vol   ? vol.toFixed(1)   : '';
      if (tgt_disp_abv_mix)    tgt_disp_abv_mix.textContent    = abv   ? abv.toFixed(2)   : '';
      if (tgt_disp_sugar_mix)  tgt_disp_sugar_mix.textContent  = sugar ? sugar.toFixed(2) : '';
      if (tgt_disp_acid_mix)   tgt_disp_acid_mix.textContent   = acid  ? acid.toFixed(2)  : '';
    } else {
      if (tgt_disp_ml_dil)     tgt_disp_ml_dil.textContent     = vol   ? vol.toFixed(1)   : '';
      if (tgt_disp_spoken_dil) tgt_disp_spoken_dil.textContent = vol   ? toSpokenProfiled(vol) : '';
      if (tgt_disp_abv_dil)    tgt_disp_abv_dil.textContent    = abv   ? abv.toFixed(2)   : '';
      if (tgt_disp_sugar_dil)  tgt_disp_sugar_dil.textContent  = sugar ? sugar.toFixed(2) : '';
      if (tgt_disp_acid_dil)   tgt_disp_acid_dil.textContent   = acid  ? acid.toFixed(2)  : '';
    }
  }

  // Copy / Paste Targets
  copyPasteTargetsBtn?.addEventListener('click', async () => {
    if (MODE === 'recipe'){
      const name = drinkInput.value || 'Unnamed';
      COPIED_TARGET = {
        name,
        vol:   num(tgt_vol?.value||0),
        abv:   num(tgt_abv?.value||0),
        sugar: num(tgt_sugar?.value||0),
        acid:  num(tgt_acid?.value||0),
        basis: (basisMix && basisMix.checked) ? 'undiluted' : 'diluted'
      };
      localStorage.setItem('CA_copied_target', JSON.stringify(COPIED_TARGET));
      showToast(`Copied targets from “${name}”`, 'info', 1200);
    } else {
      try{
        const saved = localStorage.getItem('CA_copied_target');
        const obj = saved ? JSON.parse(saved) : COPIED_TARGET;
        if (!obj){ showToast('No Current Targets to Paste.', 'warn', 1500); return; }
        const ok = confirm(`Paste the Targets from “${obj.name || 'Unknown'}”?`);
        if (!ok) return;
        if (obj.basis === 'undiluted' && basisMix) basisMix.checked = true;
        if (obj.basis === 'diluted' && basisDil)  basisDil.checked = true;
        if (tgt_vol)   tgt_vol.value   = obj.vol;
        if (tgt_abv)   tgt_abv.value   = obj.abv;
        if (tgt_sugar) tgt_sugar.value = obj.sugar;
        if (tgt_acid)  tgt_acid.value  = obj.acid;
        updateTargetsDisplay();
        await debouncedSaveTargets();
        showToast('Targets pasted.', 'info', 1200);
      }catch{ showToast('No Current Targets to Paste.', 'warn', 1500); }
    }
  });

  // ---------- Optimize ----------
  function assessMatch(res, lockVol){
    if (!res || !res.achieved) return { matched:false, msg:'No result' };
    const vol = Number(res.achieved.vol_ml || 0);
    const et  = Number(res.achieved.ethanol_ml || 0);
    const su  = Number(res.achieved.sugar_g || 0);
    const ac  = Number(res.achieved.acid_g || 0);

    const tgtVol   = Number(tgt_vol?.value || 0);
    const tgtABV   = Number(tgt_abv?.value || 0);
    const tgtSugar = Number(tgt_sugar?.value || 0);
    const tgtAcid  = Number(tgt_acid?.value || 0);

    const abvAch   = vol ? (et/vol*100) : 0;
    const sugarAch = vol ? (su/vol*100) : 0;
    const acidAch  = vol ? (ac/vol*100) : 0;

    const dVol     = (tgtVol || tgtVol===0) ? (tgtVol - vol) : 0;
    const dABVpp   = tgtABV   - abvAch;
    const dSugarpp = tgtSugar - sugarAch;
    const dAcidpp  = tgtAcid  - acidAch;

    const tol = TOL_OPT;
    const matchedPct = Math.abs(dABVpp)<=tol.abv && Math.abs(dSugarpp)<=tol.sugar && Math.abs(dAcidpp)<=tol.acid;
    const matchedVol = lockVol ? Math.abs(dVol)<=tol.vol : true;
    const matched = matchedPct && matchedVol;

    const msg = [
      matched ? 'Matched' : 'Not matched',
      `ΔVol: ${(dVol*-1).toFixed(2)} mL (got ${vol ? vol.toFixed(2) : '—'})`,
      `ΔABV: ${dABVpp.toFixed(2)} %`,
      `ΔSugar: ${dSugarpp.toFixed(2)} %`,
      `ΔAcid: ${dAcidpp.toFixed(2)} %`
    ].join('\n');

    return { matched, msg };
  }

  function renderDiagnostics(res){
    const body  = document.getElementById('diagBody');
    const sumEl = document.getElementById('diagSummary');
    const bullets = document.getElementById('diagBullets');
    if (!body || !sumEl) return;
    if (!res || !res.achieved) { body.innerHTML=''; sumEl.textContent=''; if (bullets) bullets.innerHTML=''; return; }

    const vol = Number(res.achieved?.vol_ml ?? 0);
    const et  = Number(res.achieved?.ethanol_ml ?? 0);
    const su  = Number(res.achieved?.sugar_g ?? 0);
    const ac  = Number(res.achieved?.acid_g ?? 0);

    const tgtVol   = Number(tgt_vol?.value || 0);
    const tgtABV   = Number(tgt_abv?.value || 0);
    const tgtSugar = Number(tgt_sugar?.value || 0);
    const tgtAcid  = Number(tgt_acid?.value || 0);

    const abvAch   = vol ? (et/vol*100) : 0;
    const sugarAch = vol ? (su/vol*100) : 0;
    const acidAch  = vol ? (ac/vol*100) : 0;

    const dVol     = (tgtVol || tgtVol===0) ? (tgtVol - vol) : 0;
    const dABVpp   = (res.residual_pct?.abv_pp   ?? (tgtABV   - abvAch));
    const dSugarpp = (res.residual_pct?.sugar_pp ?? (tgtSugar - sugarAch));
    const dAcidpp  = (res.residual_pct?.acid_pp  ?? (tgtAcid  - acidAch));

    const rows = [
      {name:'Volume (mL)', tgt: tgtVol,   ach: vol,      delta: dVol.toFixed(2)+' mL'},
      {name:'ABV (%)',     tgt: tgtABV,   ach: abvAch,   delta: dABVpp.toFixed(2)+' %'},
      {name:'Sugar (%)',   tgt: tgtSugar, ach: sugarAch, delta: dSugarpp.toFixed(2)+' %'},
      {name:'Acid (%)',    tgt: tgtAcid,  ach: acidAch,  delta: dAcidpp.toFixed(2)+' %'},
    ];

    const fmt = (x, dp=2) => Number.isFinite(x) ? x.toFixed(dp) : '—';
    body.innerHTML = rows.map(r => `
      <tr>
        <td>${r.name}</td>
        <td style="text-align:right;">${fmt(r.tgt)}</td>
        <td style="text-align:right;">${fmt(r.ach)}</td>
        <td style="text-align:right;">${r.delta}</td>
      </tr>`).join('');

    const lockVol = !!lockVolume?.checked;
    const tol = TOL_OPT;
    const matchedPct = Math.abs(dABVpp)<=tol.abv && Math.abs(dSugarpp)<=tol.sugar && Math.abs(dAcidpp)<=tol.acid;
    const matchedVol = lockVol ? Math.abs(dVol)<=tol.vol : true;
    const matched = matchedPct && matchedVol;
    sumEl.textContent = matched ? 'Matched within tolerances' : 'Not matched';
    sumEl.style.color = matched ? '#16a34a' : '#dc2626';

    if (bullets){
      const flags = [];
      const qSpk = !!spokenQuant?.checked;
      flags.push('Volume '+(lockVol ? 'locked' : 'unlocked'));
      flags.push('Spoken quantization '+(qSpk ? 'ON' : 'OFF'));
      const bc = (res.binding_constraints||[]).map(b => `${b.name||('id '+b.ingredient_id)} bound ${b.type}${b.at!=null?(' @ '+b.at):''}`);
      const actives = (res.constraints||[]).filter(c=>c.hold||c.low!=null||c.high!=null);
      const ac = actives.map(c => `${c.name||('id '+c.ingredient_id)} `+`${c.hold?'hold ':''}${c.low!=null?'low '+c.low+' ':''}${c.high!=null?'high '+c.high:''}`.trim());
      const lines = [];
      if (flags.length) lines.push('• '+flags.join(' • '));
      if (ac.length)    lines.push('• Active constraints: '+ac.join('; '));
      if (bc.length)    lines.push('• Binding constraints: '+bc.join('; '));
      bullets.innerHTML = lines.map(x=>`<div>${x}</div>`).join('');
    }
  }

  async function runOptimize(){
    const id = drinkSelect.value; if(!id) return;
    await debouncedSaveTargets();

    const basis = (basisMix && basisMix.checked) ? 'undiluted' : 'diluted';
    const payload = {
      drink_id: id,
      basis,
      granularity_ml: 0,
      quantize_spoken: (spokenQuant && spokenQuant.checked) ? '1' : '0',
      lock_volume: (lockVolume && lockVolume.checked) ? '1' : '0'
    };
    if (basis === 'diluted') {
      if (dilutionMode) payload.dilution_mode = dilutionMode.value;
      if (dilutionPct)  payload.custom_pct    = Number(dilutionPct.value || 0);
    }

    try{
      const res = await jpost(`${API_BASE}/optimize.php`, payload);
      loadRecipes(id);
      if (res && Array.isArray(res.warnings) && res.warnings.length){
        const m = res.warnings.map(w=>w.detail||JSON.stringify(w)).join('\n');
        showToast(m, 'warn');
      }
      const assess = assessMatch(res, !!(lockVolume && lockVolume.checked));
      showToast(assess.msg, assess.matched ? 'info' : 'warn');
      renderDiagnostics(res);
    } catch (e) {
      let msg = 'Optimize failed.';
      const raw = e && e.message ? e.message : String(e);
      try {
        const obj = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (obj && (obj.detail || obj.error)) msg = obj.detail || obj.error;
        else if (typeof raw === 'string' && raw.trim()) msg = raw;
      } catch {
        if (typeof raw === 'string' && raw.trim()) msg = raw;
      }
      showToast(msg, 'error');
      console.error('optimize error:', e);
    }
  }

  btnMatch?.addEventListener('click', runOptimize);

  // ---------- Constraints ----------
  async function loadConstraintsForSelected(){
    const drink_id = drinkSelect.value;
    const ingredient_id = ingSelect.value;
    if (!drink_id || !ingredient_id) return;
    try{
      const r = await jget(`${API_BASE}/recipes.php?drink_id=${encodeURIComponent(drink_id)}&view=${encodeURIComponent(currentView())}`);
      const row = (r.items||[]).find(x => String(x.ingredient_id) === String(ingredient_id));
      if (optHold) optHold.checked = !!(row && row.hold);
      if (optLow)  optLow.value    = row && row.testLow  != null ? row.testLow  : '';
      if (optHigh) optHigh.value   = row && row.testHigh != null ? row.testHigh : '';
    }catch{}
  }
  function saveConstraintDebounced(){ debounce(saveCurrentConstraint, 250)(); }
  [optHold, optLow, optHigh].forEach(el => el && el.addEventListener('change', saveConstraintDebounced));

  async function saveCurrentConstraint(){
    const drink_id = drinkSelect.value;
    const ingredient_id = ingSelect.value;
    if (!drink_id || !ingredient_id) return;
    try{
      await jpost(`${API_BASE}/constraints.php`, {
        drink_id, ingredient_id,
        hold: optHold?.checked ? 1 : 0,
        testLow:  optLow?.value || '',
        testHigh: optHigh?.value || ''
      });
      showToast('Constraints saved', 'info', 1000);
      await loadRecipes(drink_id);
    }catch(e){ showToast('Save constraints failed: '+e.message, 'error'); }
  }

  clearAllConstraintsBtn?.addEventListener('click', async ()=>{
    const ok = confirm('Clear ALL constraints for this drink?');
    if (!ok) return;
    const drink_id = drinkSelect.value; if(!drink_id) return;
    try{
      for (const r of LAST_ROWS){
        await jpost(`${API_BASE}/constraints.php`, { drink_id, ingredient_id: r.ingredient_id, hold:0, testLow:'', testHigh:'' });
      }
      showToast('All constraints cleared.', 'info', 1200);
      await loadRecipes(drink_id);
    }catch(e){ showToast('Clear constraints failed: '+e.message, 'error'); }
  });

  // ---------- Add/Update ----------
  function toAllFromML(v, exclude = null, opts = {}) {
    const blankZero = !!opts.blankWhenZero;
    const val = Number.isFinite(v) ? v : 0;
    const shouldBlank = (x) => (blankZero && x === 0);

    if (exclude!=='ml')   mlInput.value   = shouldBlank(val) ? '' : val.toFixed(1);
    if (exclude!=='oz')   ozInput.value   = shouldBlank(val) ? '' : (val/ML_PER_OZ).toFixed(2);
    if (exclude!=='bsp')  bspInput.value  = shouldBlank(val) ? '' : (val/ML_PER_BSP).toFixed(1);
    if (exclude!=='dash') dashInput.value = shouldBlank(val) ? '' : (val/ML_PER_DASH).toFixed(1);
    if (exclude!=='drop') dropInput.value = shouldBlank(val) ? '' : String(Math.round(val/ML_PER_DROP));
    spoken.value = shouldBlank(val) ? '' : toSpokenProfiled(val);
  }

  let activeField = null;
  for (const [fld, el] of Object.entries({ ml: mlInput, oz: ozInput, bsp: bspInput, dash: dashInput, drop: dropInput })) {
    el.addEventListener('focus', () => { activeField = fld; });
    el.addEventListener('blur',  () => {
      if (fld === 'ml'   && el.value) el.value = (num(el.value)).toFixed(1);
      if (fld === 'oz'   && el.value) el.value = (num(el.value)).toFixed(2);
      if (fld === 'bsp'  && el.value) el.value = (num(el.value)).toFixed(1);
      if (fld === 'dash' && el.value) el.value = (num(el.value)).toFixed(1);
      if (fld === 'drop' && el.value) el.value = String(Math.round(num(el.value)));
      if (activeField === fld) activeField = null;
    });
  }

  function baseMLFromActive(){
    switch(activeField){
      case 'ml':   return num(mlInput.value);
      case 'oz':   return num(ozInput.value)   * ML_PER_OZ;
      case 'bsp':  return num(bspInput.value)  * ML_PER_BSP;
      case 'dash': return num(dashInput.value) * ML_PER_DASH;
      case 'drop': return num(dropInput.value) * ML_PER_DROP;
      default: return 0;
    }
  }
  function baseMLFromAny(){
    if (mlInput.value)   return num(mlInput.value);
    if (ozInput.value)   return num(ozInput.value)   * ML_PER_OZ;
    if (bspInput.value)  return num(bspInput.value)  * ML_PER_BSP;
    if (dashInput.value) return num(dashInput.value) * ML_PER_DASH;
    if (dropInput.value) return num(dropInput.value) * ML_PER_DROP;
    return 0;
  }

  const recalcFromActive = debounce(() => {
    if (!activeField) return;
    const v = baseMLFromActive();
    toAllFromML(v, activeField, { blankWhenZero: false });
  }, 250);
  [mlInput, ozInput, bspInput, dashInput, dropInput].forEach(el => el.addEventListener('input', recalcFromActive));

  function prefillAddBoxIfInRecipe(){
    const ingId = String(ingSelect.value || '');
    const row = LAST_ROWS.find(r => String(r.ingredient_id) === ingId);

    const selectedOpt = ingSelect.selectedOptions && ingSelect.selectedOptions[0];
    const pickedName = selectedOpt ? selectedOpt.textContent.trim() : '';
    const hasPick = !!(ingId && pickedName);

    if (row && hasPick) {
      const v = num(row.ml);
      toAllFromML(v, null, { blankWhenZero: false });
      if (positionInput) positionInput.value = row.position ?? '';
      CURRENT_ROW_ID = row.id || null;
      addBtn.textContent = (MODE === 'optimize') ? `Update Test ${pickedName}` : `Update ${pickedName}`;
    } else if (hasPick) {
      toAllFromML(0, null, { blankWhenZero: true });
      mlInput.value = '';
      if (positionInput) positionInput.value = '';
      CURRENT_ROW_ID = null;
      addBtn.textContent = (MODE === 'optimize') ? `Add Test ${pickedName}` : `Add ${pickedName}`;
    } else {
      toAllFromML(0, null, { blankWhenZero: true });
      mlInput.value = '';
      if (positionInput) positionInput.value = '';
      CURRENT_ROW_ID = null;
      addBtn.textContent = (MODE === 'optimize') ? 'Add Test ingredient' : 'Add ingredient';
    }
  }

addBtn?.addEventListener('click', async () => {
  // Use the tracked id, not the raw select (which may be blank by default)
  const drink_id = CURRENT_DRINK_ID;
  if (!drink_id) { showToast('Pick a drink first', 'warn'); return; }

  if (!DRINK_EDITABLE) {
    showToast('You do not own this drink. Log in as the owner to edit.', 'warn');
    return;
  }

  const ingredient_id = ingSelect.value;
  if (!ingredient_id) { showToast('Pick an ingredient', 'warn'); return; }

  const active = baseMLFromActive();
  const any    = baseMLFromAny();
  const mlBase = active || any;

  const positionVal = positionInput ? positionInput.value.trim() : '';
  const payload = { drink_id, ingredient_id };
  if (positionVal !== '') payload.position = Number(positionVal);

  const existingRow = LAST_ROWS.find(r => String(r.ingredient_id) === String(ingredient_id));
  const rowId = CURRENT_ROW_ID || (existingRow && existingRow.id) || null;
  if (rowId) payload.id = rowId;

  if (MODE === 'optimize'){ payload.target = 'test'; payload.mlTest = mlBase; }
  else { payload.ml = mlBase; }

  try{
    await jpost(`${API_BASE}/recipes.php`, payload);

    if (MODE === 'recipe'){
      const mirror = { drink_id, ingredient_id, target:'test', mlTest: mlBase };
      if (positionVal !== '') mirror.position = Number(positionVal);
      await jpost(`${API_BASE}/recipes.php`, mirror);
    }

    await loadRecipes(drink_id);
  }catch(e){
    // Parse server JSON if present
    let msg = 'Error saving row';
    try {
      const obj = JSON.parse(e.message || '');
      msg = obj && (obj.detail || obj.error) || msg;
    } catch {}
    showToast('Error: ' + msg, 'error');
  }
});


  // ===== Reorder: Up/Down + manual position save =====
  grid.addEventListener('click', async (e)=>{
    const upBtn = e.target.closest('button.mv-up');
    const dnBtn = e.target.closest('button.mv-down');
    if (upBtn || dnBtn){
      if (!DRINK_EDITABLE) { showToast('Read-only (you do not own this drink)', 'warn', 1500); return; }
      const id = Number((upBtn||dnBtn).dataset.id);
      if (!id) return;

      const rows = [...LAST_ROWS].sort((a,b) => {
        const ap = (a.position==null ? 1e9 : a.position);
        const bp = (b.position==null ? 1e9 : b.position);
        return ap - bp || (a.id - b.id);
      });

      const idx = rows.findIndex(r => Number(r.id) === id);
      if (idx < 0) return;

      const swapIdx = idx + (upBtn ? -1 : 1);
      if (swapIdx < 0 || swapIdx >= rows.length) return;

      [rows[idx], rows[swapIdx]] = [rows[swapIdx], rows[idx]];

      const order = rows.map(r => ({ id: r.id }));
      try{
        const resp = await fetch('/recipes/api/recipes_reorder.php', {
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
          body: new URLSearchParams({
            drink_id: String(drinkSelect.value || ''),
            target: currentView(),
            order: JSON.stringify(order)
          })
        });

        const text = await resp.text();
        let json;
        try { json = text ? JSON.parse(text) : {}; }
        catch { throw new Error(text.slice(0,180) || 'Non-JSON response'); }
        if (!resp.ok || json.ok === false) {
          throw new Error(json.detail || json.error || `HTTP ${resp.status}`);
        }

        await loadRecipes(drinkSelect.value);
      }catch(err){
        console.error(err);
        showToast('Failed to reorder: ' + (err?.message||err), 'error');
      }
      return;
    }

    // Delete
    const t = e.target;
    if (t && t.classList.contains('del')){
      const id = t.getAttribute('data-id');
      if (confirm('Delete this row?')){
        try{ await jdel(`${API_BASE}/recipes.php`, {id}); await loadRecipes(); }
        catch(e){ showToast('Error: '+e.message, 'error'); }
      }
    }
  });

  // ---------- Auth ----------
// ---------- Auth ----------
const authBtn   = document.getElementById('authBtn');   // single button
const loginDlg  = document.getElementById('loginModal');
const loginOpen = () => {
  loginDlg?.classList.remove('hidden');
  liEmail?.focus();  // autofocus when modal opens
};
const loginClose= () => loginDlg?.classList.add('hidden');

// Close login modal on Escape key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') loginClose();
});
const liEmail   = document.getElementById('li_email');
const liPass    = document.getElementById('li_password');
const liGo      = document.getElementById('li_login');
const liCancel  = document.getElementById('li_cancel');

function refreshAuthUI(who) {
  LOGGED_IN = !!(who && (who.uid || who.id));
  const name = (who && (who.email || who.username || who.name)) ? (who.email || who.username || who.name) : '';
  if (authBtn) {
    if (LOGGED_IN) {
      authBtn.textContent = name ? `Log out (${name})` : 'Log out';

      authBtn.title = name ? `Logged in as ${name}` : 'Logged in';
    } else {
      authBtn.textContent = 'Log in';
      authBtn.title = 'Log in to edit and see your private items';
    }
  }
}

async function getWhoamiSafe() {
  try { return await jget(`${API_BASE}/auth.php?action=whoami`); }
  catch { return null; }
}

// Click: single auth button
authBtn?.addEventListener('click', async () => {
  const who = await getWhoamiSafe();
  if (who && (who.uid || who.id)) {
    // Currently logged in → log out immediately
    try {
      await jpost(`${API_BASE}/auth.php?action=logout`, {});
      DRINK_EDITABLE = false;
      refreshAuthUI(null);
      // If you had a drink open, drop edit affordances
      addBtn.disabled = true;
      addBtn.title = 'Log in and open a drink you own to edit';
      showToast('Logged out.', 'info', 1200);
      verifyCurrentDrinkVisible();
    } catch {
      showToast('Logout failed', 'error');
    }
  } else {
    // Not logged in → open login modal
    liEmail && (liEmail.value = '');
    liPass && (liPass.value = '');
    loginOpen();
    liEmail?.focus();
  }
});

// Login dialog buttons
liCancel?.addEventListener('click', () => loginClose());

liGo?.addEventListener('click', async () => {
  try {
    await jpost(`${API_BASE}/auth.php?action=login`, {
      email: (liEmail?.value || '').trim(),
      password: (liPass?.value || '')
    });
    loginClose();
    const who = await getWhoamiSafe();
    refreshAuthUI(who);
    showToast('Logged in', 'info', 1200);

    // Re-check editability for the currently open drink, if any
    if (CURRENT_DRINK_ID) {
      // re-run the same logic used onPick to compute DRINK_EDITABLE
      const row = await jget(`${API_BASE}/drinks.php?id=${encodeURIComponent(CURRENT_DRINK_ID)}`);
      const myUid   = Number(who?.uid ?? who?.id ?? 0);
      const isAdmin = (who?.role === 'admin');
      DRINK_EDITABLE = (myUid && Number(row.owner_id) === myUid) || isAdmin || false;

      addBtn.disabled = !DRINK_EDITABLE;
      addBtn.title = DRINK_EDITABLE ? '' : 'You do not own this drink (read-only)';
      [drinkTypeSel, drinkNotes, drinkSource, drinkDate, drinkLocked, drinkPublic, saveDrinkBtn, cloneDrinkBtn]
        .filter(Boolean).forEach(el => el.disabled = !DRINK_EDITABLE);
    }
  } catch {
    showToast('Login failed', 'error');
  }
});


  // ---------- Init ----------
  (async function init(){
    try{
      await loadDrinkTypes();
      // establish auth state up front
      const who = await getWhoamiSafe();
    refreshAuthUI(who);
      await fetchSpokenProfile();
      setMode('recipe');
      log('Pick a drink, then add ingredients. Switch to Optimize to balance to targets.');
    }catch(e){ log('Init failed: '+e.message); }
  })();
});
