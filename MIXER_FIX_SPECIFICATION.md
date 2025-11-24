# Mixer Tab Fixes - Detailed Specification for Claude Code

## Files to Modify:
- `calc/index.html` - Remove "(beta)" text
- `calc/public/js/mixcalc-mixer.js` - Fix validation and Match Target logic

---

## Fix 1: Remove "(beta)" Text

### File: calc/index.html

**Lines 130 and 1212** contain "(beta)" text that should be removed.

**Current:**
```html
<button id="tab_mixer" ... >Mixer (beta)</button>
```
```html
<h2 class="align-with-fields">Mixture Builder (beta)</h2>
```

**Change to:**
```html
<button id="tab_mixer" ... >Mixer</button>
```
```html
<h2 class="align-with-fields">Mixture Builder</h2>
```

---

## Fix 2: Add Input Validation with Toast Messages

### File: calc/public/js/mixcalc-mixer.js

**Location:** In the `recompute` function (around line 257-323)

**Problem:** No validation feedback when user enters:
- Only ABM (missing SBM)
- Only ABV (missing Sugar_WV)  
- Invalid combinations (ABM + Sugar_WV)

**Solution:** Add validation checks BEFORE line 290 ("If nothing to solve yet, stop"):

```javascript
  // NEW VALIDATION LOGIC (add after line 288, before line 290)
  
  // Check for incomplete or invalid input combinations
  const hasABM = v.ABM !== null;
  const hasSBM = v.SBM !== null;
  const hasABV = v.ABV !== null;
  const hasSWV = v.SWV !== null;
  
  // Case 1: Only ABM entered (need SBM)
  if (hasABM && !hasSBM && !hasABV && !hasSWV && isComp) {
    showToast('Enter SBM to complete the composition pair.');
    return;
  }
  
  // Case 2: Only SBM entered (need ABM)
  if (!hasABM && hasSBM && !hasABV && !hasSWV && isComp) {
    showToast('Enter ABM to complete the composition pair.');
    return;
  }
  
  // Case 3: Only ABV entered (need Sugar_WV)
  if (!hasABM && !hasSBM && hasABV && !hasSWV && isComp) {
    showToast('Enter Sugar_WV to complete the measurement pair.');
    return;
  }
  
  // Case 4: Only Sugar_WV entered (need ABV)
  if (!hasABM && !hasSBM && !hasABV && hasSWV && isComp) {
    showToast('Enter ABV to complete the measurement pair.');
    return;
  }
  
  // Case 5: Invalid mix - ABM + Sugar_WV or SBM + ABV
  if ((hasABM && hasSWV) || (hasSBM && hasABV)) {
    showToast('Invalid combination. Use either ABM+SBM OR ABV+Sugar_WV pairs.');
    return;
  }
  
  // Case 6: Invalid mix - three values from different pairs
  if ((hasABM || hasSBM) && (hasABV || hasSWV)) {
    const compCount = (hasABM ? 1 : 0) + (hasSBM ? 1 : 0);
    const measCount = (hasABV ? 1 : 0) + (hasSWV ? 1 : 0);
    if (compCount === 1 && measCount === 1) {
      showToast('Invalid combination. Use either ABM+SBM OR ABV+Sugar_WV pairs.');
      return;
    }
  }

  // If nothing to solve yet, stop (EXISTING LINE 290)
  if (!haveCompPair && !haveMeasPair) return;
```

---

## Fix 3: Rewrite Match Target Logic

### File: calc/public/js/mixcalc-mixer.js

**Location:** Replace the entire `matchTargetUsingInputs` function (lines 513-635)

**Problem:** Current logic always adds Ethanol/Sugar/Water rows regardless of whether they're needed, and doesn't calculate correctly.

**Required Behavior:**

1. Read target composition (ABM/SBM or convert from ABV/Sugar_WV)
2. Read all input rows, separate into "held" and "free"
3. Calculate total ethanol, sugar, water masses in inputs
4. **If target quantity is held:**
   - Calculate final mass from target ABM and available ethanol
   - Check if we have enough sugar; if not, calculate how much to add
   - Add water to balance
   - If inputs have too much of any component, scale down free rows
5. **If target quantity is NOT held:**
   - Use ALL inputs at full quantity
   - Calculate minimum mass needed to satisfy target ratios
   - Add only the minimum Ethanol/Sugar/Water needed
6. **Only create additive rows if their quantity > 0**
7. Toast clear success/failure messages

**New Implementation:**

```javascript
async function matchTargetUsingInputs() {
  // 1) Derive target fractions a,s (ABM/SBM) from Desired row
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

  // Convert ABV/SWV to ABM/SBM if needed
  if (Number.isFinite(d.abm) && Number.isFinite(d.sbm)) {
    a = Math.max(0, Math.min(1, d.abm/100));
    s = Math.max(0, Math.min(1, d.sbm/100));
  } else if (Number.isFinite(d.abv) && Number.isFinite(d.swv)) {
    const json = await solve({ 
      mode:'abv_sugarwv', 
      ABV:d.abv, ABV_T:T, 
      Sugar_WV:d.swv, Sugar_WV_T:T, 
      report_T:T 
    }, {endpoint:'./api/solve.php'});
    
    if (!json || json.ok!==true) { 
      showToast('Cannot interpret Desired ABV/Sugar.'); 
      return; 
    }
    a = Math.max(0, Math.min(1, (json.abm||0)/100));
    s = Math.max(0, Math.min(1, (json.sbm||0)/100));
  } else {
    showToast('Enter Desired as ABM/SBM or ABV+Sugar_WV.'); 
    return;
  }
  
  const w = Math.max(0, 1 - a - s);
  if (a<=0 && s<=0 && w<=0) { 
    showToast('Desired composition is invalid.'); 
    return; 
  }

  // 2) Read existing inputs
  const tbody = document.getElementById('mix_rows');
  const rows  = Array.from(tbody.querySelectorAll(':scope>tr'));
  const held  = [];
  const free  = [];
  
  for (const tr of rows) {
    // Skip trailing empty rows
    const anyVal = Array.from(tr.querySelectorAll('input'))
      .some(el => (el.type!=='checkbox' && String(el.value||'').trim()!==''));
    if (!anyVal) continue;

    // Skip additive placeholder rows (Water/Ethanol/Dry Sugar with no quantity)
    const name = tr.querySelector('.mix_name')?.value?.trim().toLowerCase();
    const hasQty = num(tr.querySelector('.mix_g')) || num(tr.querySelector('.mix_ml'));
    if (['water', 'ethanol', 'dry sugar'].includes(name) && !hasQty) continue;

    const masses = rowMasses(tr);
    if (!masses.ok) continue;

    const isHeld = tr.querySelector('.mix_hold')?.checked;
    (isHeld ? held : free).push({tr, ...masses});
  }
  
  if (held.length === 0 && free.length === 0) {
    showToast('Add at least one ingredient before matching target.');
    return;
  }

  const sum = arr => arr.reduce((acc,x)=>({
    E:acc.E+x.E, S:acc.S+x.S, W:acc.W+x.W, G:acc.G+x.G
  }), {E:0, S:0, W:0, G:0});
  
  const H = sum(held);
  const F = sum(free);
  const E0 = H.E + F.E;
  const S0 = H.S + F.S;
  const W0 = H.W + F.W;
  const G0 = H.G + F.G;

  // 3) Determine final mass and needed additives
  let Gstar = null;
  let addE = 0, addS = 0, addW = 0;

  if (d.hold && (Number.isFinite(d.g) || Number.isFinite(d.ml))) {
    // ========== CASE A: Target quantity is HELD ==========
    
    let Gd = Number.isFinite(d.g) ? d.g : null;
    
    // Convert mL to grams if needed
    if (Gd==null && Number.isFinite(d.ml)) {
      const j = await solve({ 
        mode:'abm_sbm', 
        ABM:a*100, SBM:s*100, 
        report_T:T 
      }, {endpoint:'./api/solve.php'});
      
      if (!j || j.ok!==true || !(j.outputs&&j.outputs.Density>0)) { 
        showToast('Cannot estimate target density to convert mL to g.'); 
        return; 
      }
      Gd = d.ml * j.outputs.Density;
    }
    
    if (!Number.isFinite(Gd) || Gd<=0) { 
      showToast('Desired amount is invalid.'); 
      return; 
    }
    
    Gstar = Gd;
    
    // Calculate required masses for target
    const reqE = a * Gstar;
    const reqS = s * Gstar;
    const reqW = w * Gstar;
    
    // Check if held ingredients alone exceed requirements
    if (H.E > reqE || H.S > reqS || H.W > reqW) {
      showToast('Held ingredients alone exceed target requirements. Cannot match target.');
      return;
    }
    
    // Use all held + all free inputs
    const totalE = E0;
    const totalS = S0;
    const totalW = W0;
    
    // Calculate what's needed
    addE = Math.max(0, reqE - totalE);
    addS = Math.max(0, reqS - totalS);
    addW = Math.max(0, reqW - totalW);
    
  } else {
    // ========== CASE B: Target quantity NOT held - use minimum mass ==========
    
    // Calculate minimum final mass that satisfies target with existing inputs
    const candidates = [G0];
    if (a > 0) candidates.push(E0 / a);
    if (s > 0) candidates.push(S0 / s);
    if (w > 0) candidates.push(W0 / w);
    
    Gstar = Math.max(...candidates.filter(x => Number.isFinite(x)));
    
    // Calculate what's needed beyond inputs
    addE = Math.max(0, a * Gstar - E0);
    addS = Math.max(0, s * Gstar - S0);
    addW = Math.max(0, w * Gstar - W0);
  }

  // 4) Add only necessary additive rows
  let addedAny = false;
  
  if (addE > 0.01) {  // threshold to avoid floating point noise
    setRowGrams(ensureAdditiveRow('ethanol'), addE);
    addedAny = true;
  }
  
  if (addS > 0.01) {
    setRowGrams(ensureAdditiveRow('sugar'), addS);
    addedAny = true;
  }
  
  if (addW > 0.01) {
    setRowGrams(ensureAdditiveRow('water'), addW);
    addedAny = true;
  }
  
  // 5) Show appropriate success message
  const finalMass = G0 + addE + addS + addW;
  
  if (addedAny) {
    const parts = [];
    if (addE > 0.01) parts.push(`Ethanol: ${addE.toFixed(1)}g`);
    if (addS > 0.01) parts.push(`Sugar: ${addS.toFixed(1)}g`);
    if (addW > 0.01) parts.push(`Water: ${addW.toFixed(1)}g`);
    showToast(`Target matched! Added: ${parts.join(', ')}. Final mass: ${finalMass.toFixed(1)}g`);
  } else {
    showToast(`Target matched using existing inputs only! Final mass: ${finalMass.toFixed(1)}g`);
  }
  
  calcMixDebounced();
}
```

**Key Changes:**
1. Skip existing additive placeholder rows when reading inputs
2. Only create/update additive rows if amount needed > 0.01g (avoids noise)
3. Clear logic flow for held vs not-held quantity
4. Better error messages
5. Success message shows what was added

---

## Testing Checklist:

After implementing these fixes, test:

1. ✓ "(beta)" text removed from button and header
2. ✓ Enter only ABM → toast: "Enter SBM to complete"
3. ✓ Enter ABM + Sugar_WV → toast: "Invalid combination"
4. ✓ Enter valid ABM+SBM pair → correctly calculates ABV/Sugar_WV
5. ✓ Enter quantity → other quantity mirrors correctly
6. ✓ Enter target without held quantity → uses all inputs, adds minimal additives
7. ✓ Enter target with held quantity → calculates final mass correctly
8. ✓ Additives only added when needed (not always)
9. ✓ Success messages are clear and accurate
10. ✓ Additive rows (Ethanol/Sugar/Water) have delete buttons

---

## Fix 4: Ensure Additive Rows Have Delete Buttons

### File: calc/public/js/mixcalc-mixer.js

**Location:** `ensureHelperRow` function (lines 727-763)

**Problem:** This function creates rows with wrong HTML structure - missing the Hold checkbox column and Delete button column. It duplicates logic that already exists correctly in `ensureAdditiveRow` and `makeBlankRow`.

**Solution:** Replace `ensureHelperRow` to use the same correct structure:

```javascript
function ensureHelperRow(kind) {
  // kind: 'water' | 'ethanol' | 'sugar'
  const tbody = document.getElementById('mix_rows');
  let label = '', abm=null, sbm=null;
  if (kind==='water')   { label='Water';   abm=0;   sbm=0; }
  if (kind==='ethanol') { label='Ethanol'; abm=100; sbm=0; }
  if (kind==='sugar')   { label='Dry Sugar'; abm=0; sbm=100; }

  // Find existing row by name
  const rows = Array.from(tbody.querySelectorAll('tr'));
  for (const tr of rows) {
    const nm = tr.querySelector('.mix_name')?.value?.trim().toLowerCase();
    if (nm === label.toLowerCase()) return tr;
  }

  // Use makeBlankRow() to get correct structure with delete button
  const tr = makeBlankRow();
  tbody.appendChild(tr);
  bindRow(tr);
  
  // Set the name and composition
  const nameInput = tr.querySelector('.mix_name');
  const abmInput = tr.querySelector('.mix_abm');
  const sbmInput = tr.querySelector('.mix_sbm');
  
  if (nameInput) nameInput.value = label;
  if (abmInput) abmInput.value = String(abm);
  if (sbmInput) sbmInput.value = String(sbm);
  
  return tr;
}
```

**Why this fix:**
- `ensureHelperRow` was creating rows with wrong HTML structure (7 columns instead of 9)
- Missing Hold checkbox column and Delete button column
- This fix makes it use `makeBlankRow()` which has the correct structure
- Now all rows (user-created and auto-generated) have consistent structure with delete buttons

---

## Testing Checklist:

- The validation logic goes BEFORE the existing "If nothing to solve yet" check
- The Match Target function is a complete replacement
- Helper functions (`rowMasses`, `ensureAdditiveRow`, `setRowGrams`) already exist and work correctly
- The `showToast` function exists and works

---

End of specification. Implement these changes and commit to a new branch for testing.
