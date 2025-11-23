// /recipes/spoken.js
// Generalized spoken-amount formatter driven by a user/profile config.

let ACTIVE = {
  stPour: 30,            // standard pour (mL)
  nUnit: 1,              // 1 = named unit, 0 = rounded mL
  unitName: 'ounce',     // label for the named unit
  hAccuracy: 1,          // higher accuracy below one pour
  fatShort: 1,           // use fat/short decorations
  unitDiv: 4,            // named: divisions above one pour (4/5/8/10); rounded mL: step size (mL)
  hAunitDiv: 8,          // named: divisions below one pour; rounded mL: step size (mL) below one pour
  useBarspoon: 1,
  useDash: 1,
  useDrop: 1,
  barspoon: 3.75,
  dash: 0.82,
  drop: 0.05
};
let ACTIVE_NAME = 'Default';

export function setSpokenProfile(config = {}, name = '') {
  ACTIVE = { ...ACTIVE, ...config };
  if (typeof ACTIVE.nUnit === 'string') ACTIVE.nUnit = Number(ACTIVE.nUnit) ? 1 : 0;
  if (typeof ACTIVE.hAccuracy === 'string') ACTIVE.hAccuracy = Number(ACTIVE.hAccuracy) ? 1 : 0;
  if (typeof ACTIVE.fatShort === 'string') ACTIVE.fatShort = Number(ACTIVE.fatShort) ? 1 : 0;
  ACTIVE_NAME = name || ACTIVE_NAME;
}

const EPS = 1e-6;

function approxEq(a, b, eps = EPS) { return Math.abs(a - b) <= eps; }
function clamp(x, lo, hi){ return Math.max(lo, Math.min(hi, x)); }
function roundToStep(x, step) {
  if (!step || step <= 0) return x;
  return Math.round(x / step) * step;
}
function gcd(a, b) {
  a = Math.abs(a); b = Math.abs(b);
  while (b) { const t = b; b = a % b; a = t; }
  return a || 1;
}
function reduceFraction(n, d) {
  if (d === 0) return [0, 0];
  const g = gcd(n, d);
  return [n / g, d / g];
}
function formatMixedFraction(numer, denom) {
  if (denom <= 0) return String(numer);
  const whole = Math.floor(numer / denom);
  const rem = numer % denom;
  if (rem === 0) return String(whole);
  if (whole === 0) return `${rem}/${denom}`;
  return `${whole} and ${rem}/${denom}`;
}
function pluralize(word, n) {
  // simple pluralization; customize if needed
  if (n === 1) return word;
  return word + 's';
}

// ---------- Small-unit region ----------
function formatSmallUnits(ml, cfg) {
  const { useBarspoon, useDash, useDrop, barspoon, dash, drop, fatShort } = cfg;

  // 0) Guard: "less than smallest unit" => raw mL shown (handled by caller)
  // 1) If we have a barspoon and amount is near that — use barspoon
  if (useBarspoon && barspoon > 0) {
    const regionMax = barspoon * 1.334; // as specified
    if (ml < regionMax) {
      // near exactly 1 barspoon?
      if (dash > 0 && ml > (barspoon - dash / 2)) {
        if (fatShort && ml > barspoon * 1.667) {
          return 'fat barspoon';
        }
        return '1 barspoon';
      }
      // else dashes?
      if (useDash && dash > 0 && ml > dash) {
        // round to nearest 0.5 dash
        const halfDashCount = Math.round( (ml / dash) * 2 ) / 2; // increments of 0.5
        if (approxEq(halfDashCount, 0.5)) return '½ dash';
        if (approxEq(halfDashCount, 1))   return '1 dash';
        const whole = Math.floor(halfDashCount);
        const half  = (halfDashCount - whole) > 0 ? ' and a half' : '';
        return `${whole}${half} dashes`;
      }
      // else drops?
      if (useDrop && drop > 0) {
        const n = Math.max(1, Math.round(ml / drop));
        return `${n} ${pluralize('drop', n)}`;
      }
      // fallback
      return `${ml.toFixed(2)} ml`;
    }
  }
  // If barspoon not used, spec says smaller units disabled via UI; fallback is handled by caller.
  return null;
}


// ---------- Rounded mL style ----------
function formatRoundedMl(ml, cfg) {
  const { stPour, hAccuracy, fatShort } = cfg;
  const stepAbove = Number(cfg.unitDiv) || 5;            // mL step ≥ 1 pour
  const stepBelow = Number(cfg.hAunitDiv) || stepAbove;  // mL step < 1 pour (if HA)
  const threshold = stPour + (stepAbove / 3);            // spec: < stPour + unitDiv/3
  const useBelow  = (hAccuracy && ml < threshold);
  const baseStep  = useBelow ? stepBelow : stepAbove;

  const decimals = (Math.abs(baseStep - Math.round(baseStep)) < 1e-9) ? 0 : 1;

  if (!fatShort) {
    const rounded = Math.round(ml / baseStep) * baseStep;
    return `${rounded.toFixed(decimals)} ml`;
  }

  // fat/short: quantize in thirds of the chosen step
  const micro = baseStep / 3;
  if (micro <= 0) {
    const rounded = Math.round(ml / baseStep) * baseStep;
    return `${rounded.toFixed(decimals)} ml`;
  }

  const nThirds = Math.max(0, Math.round(ml / micro));   // <-- use the same rounded thirds
  const mod     = nThirds % 3;
  const prefix  = mod === 1 ? 'fat ' : (mod === 2 ? 'short ' : '');
  const divisionsCount = Math.floor((nThirds + 1) / 3);  // <-- bump with +1, then floor
  const valueMl = divisionsCount * baseStep;

  return `${prefix}${valueMl.toFixed(decimals)} ml`.trim();
}


// ---------- Named-unit style ----------
function shortOfNextWholeCheck(ml, cfg) {
  // "Anything above stPour gets called 'short <N+1> unit' if (ml + stepFS) modulo stPour ≈ 0"
  const { stPour, unitDiv, fatShort } = cfg;
  if (!fatShort || !unitDiv || unitDiv <= 0) return null;
  if (ml < stPour - EPS) return null;

  const stepFS = stPour / (unitDiv * 3);
  const rem = (ml + stepFS) % stPour;
  if (approxEq(rem, 0, stepFS / 2)) {
    const next = Math.round(ml / stPour) + 1;
    return { prefix: 'short ', whole: next, numer: 0, denom: 1 };
  }
  return null;
}

function formatNamed(ml, cfg) {
  const { stPour, unitName, hAccuracy, unitDiv, hAunitDiv, fatShort } = cfg;
  const name = unitName || 'unit';

  // Handle the special "short of next whole" case first (>= stPour)
  const shortCase = shortOfNextWholeCheck(ml, cfg);
  if (shortCase) {
    const unitWord = pluralize(name, shortCase.whole);
    return `${shortCase.prefix}${shortCase.whole} ${unitWord}`.trim();
  }

  // Determine which division to use
  // Spec (below one pour band when hAccuracy on): < stPour + stPour/(unitDiv/3)
  const belowBandThreshold = stPour + (stPour * 3 / (unitDiv || 4));
  const useBelow = (hAccuracy && ml < belowBandThreshold);

  const div = useBelow ? (hAunitDiv || unitDiv || 4) : (unitDiv || 4);
  const divInt = Math.max(1, Math.round(div));

  if (!fatShort) {
    // Round to nearest division of unit
    const divisionCount = Math.max(0, Math.round( ml / (stPour / divInt) ));
    // Reduce divisionCount / divInt to lowest terms, then format mixed
    const [rn, rd] = reduceFraction(divisionCount, divInt);
    const text = formatMixedFraction(rn, rd);
    const valNum = rn / rd; // numeric count of units
    const unitWord = pluralize(name, valNum >= 1 ? Math.round(valNum) : 0);
    return `${text} ${unitWord}`.trim();
  }

  // fat/short enabled: use micro-steps (thirds of a division)
const microDiv = divInt * 3;
const microStepMl = stPour / microDiv;
const nMicro = Math.max(0, Math.round(ml / microStepMl)); // nearest micro-steps
const mod3 = nMicro % 3;
const prefix = mod3 === 1 ? 'fat ' : (mod3 === 2 ? 'short ' : '');
const divisionCount = Math.floor((nMicro + 1) / 3);       // +1 bump

  const [rn, rd] = reduceFraction(divisionCount, divInt);

  // If >= 1, format mixed. If exactly integer, no fraction part remains in formatMixedFraction.
  const text = formatMixedFraction(rn, rd);
  // Pluralization rule: plural when text parses to >= 2 OR text contains "and" with leading integer >= 1
  // Simpler: find the integer part in the mixed number:
  let whole = 0, remNumer = rn % rd;
  if (rd > 0) whole = Math.floor(rn / rd);
  const unitWord = pluralize(name, whole >= 1 ? whole : 0);

  return `${prefix}${text} ${unitWord}`.trim();
}

// ---------- Main entry ----------
export function toSpokenProfiled(ml) {
  const cfg = ACTIVE;
  if (!Number.isFinite(ml) || ml <= 0) return '';

  // 1) "Less than smallest unit" => raw mL (unrounded)
  let smallest = 0;
  if (cfg.useBarspoon) {
    if (cfg.useDrop) smallest = cfg.drop;
    else if (cfg.useDash) smallest = cfg.dash;
    else smallest = cfg.barspoon;
  } else {
    // per spec: if no small units selected, smallestUnit = barspoon
    smallest = cfg.barspoon || 3.75;
  }
  if (ml < smallest - EPS) {
    return `${ml.toFixed(2)} ml`;
  }

  // 2) Try small units region (if barspoon is enabled)
  if (cfg.useBarspoon) {
    const smallTxt = formatSmallUnits(ml, cfg);
    if (smallTxt) return smallTxt;
  }

  // 3) Named vs rounded mL
  if (!cfg.nUnit) {
    return formatRoundedMl(ml, cfg);
  }
  return formatNamed(ml, cfg);
}

export function getSpokenProfile() {
  // return a plain copy of current active config
  return { ...ACTIVE };
}