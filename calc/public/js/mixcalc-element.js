// /public/js/mixcalc-element.js
import { solve, payloadFromForm } from './mixcalc-core.js';

const template = document.createElement('template');
template.innerHTML = `
  <form class="mixcalc-form">
    <div class="row">
      <label>Mode
        <select name="mode">
          <option value="brix_density">BrixATC & Density</option>
          <option value="abv_brix">ABV & BrixATC</option>
          <option value="abv_density">ABV & Density</option>
          <option value="abv_sugarwv">ABV & Sugar_WV</option>
          <option value="abm_sbm">ABM & SBM</option>
        </select>
      </label>
      <label>Report T&nbsp;(°C)
        <input name="report_t" type="number" step="0.1" value="20">
      </label>
    </div>

    <div class="grid inputs">
      <fieldset data-mode="brix_density abv_brix abv_density abv_sugarwv">
        <legend>BrixATC</legend>
        <label>Value <input name="brixatc" type="number" step="0.01"></label>
        <label>Temp °C <input name="brixatc_t" type="number" step="0.1"></label>
      </fieldset>

      <fieldset data-mode="brix_density abv_density">
        <legend>Density (g/mL)</legend>
        <label>Value <input name="density" type="number" step="0.00001"></label>
        <label>Temp °C <input name="density_t" type="number" step="0.1"></label>
      </fieldset>

      <fieldset data-mode="abv_brix abv_density abv_sugarwv">
        <legend>ABV (% vol)</legend>
        <label>Value <input name="abv" type="number" step="0.01"></label>
        <label>Temp °C <input name="abv_t" type="number" step="0.1"></label>
      </fieldset>

      <fieldset data-mode="abv_sugarwv">
        <legend>Sugar_WV (g/L)</legend>
        <label>Value <input name="sugar_wv" type="number" step="0.1"></label>
        <label>Temp °C <input name="sugar_wv_t" type="number" step="0.1"></label>
      </fieldset>

      <fieldset data-mode="abm_sbm">
        <legend>Composition</legend>
        <label>ABM (% mass) <input name="abm" type="number" step="0.01"></label>
        <label>SBM (% mass) <input name="sbm" type="number" step="0.01"></label>
      </fieldset>
    </div>

    <div class="row actions">
      <button type="submit">Solve</button>
      <span class="status" aria-live="polite"></span>
    </div>
  </form>

  <div class="mixcalc-results" hidden>
    <h4>Composition</h4>
    <div class="kv">
      <div>ABM</div><div data-out="abm"></div>
      <div>SBM</div><div data-out="sbm"></div>
    </div>

    <h4>Reported at <span data-out="T_C"></span> °C</h4>
    <div class="kv">
      <div>ABV (% vol)</div><div data-out="ABV"></div>
      <div>Sugar_WV (g/L)</div><div data-out="Sugar_WV"></div>
      <div>Density (g/mL)</div><div data-out="Density"></div>
      <div>BrixATC (°Bx)</div><div data-out="BrixATC"></div>
      <div>nD</div><div data-out="nD"></div>
    </div>

    <details class="diag">
      <summary>Diagnostics</summary>
      <pre class="json"></pre>
    </details>
  </div>
`;

function showMode(root, mode) {
  root.querySelectorAll('fieldset').forEach(fs => {
    const m = (fs.getAttribute('data-mode') || '').split(/\s+/);
    fs.hidden = !m.includes(mode);
  });
}

class MixCalcElement extends HTMLElement {
  static get observedAttributes() { return ['endpoint','default-mode']; }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.shadowRoot.append(template.content.cloneNode(true));
  }

  attributeChangedCallback() {
    // nothing special; read on connect
  }

  connectedCallback() {
    const form = this.shadowRoot.querySelector('form');
    const select = form.querySelector('select[name="mode"]');
    const status = form.querySelector('.status');
    const results = this.shadowRoot.querySelector('.mixcalc-results');
    const diag = results.querySelector('.json');
    const endpoint = this.getAttribute('endpoint') || '/calc/api/solve.php';

    const defaultMode = this.getAttribute('default-mode') || 'brix_density';
    select.value = defaultMode;
    showMode(this.shadowRoot, defaultMode);
    select.addEventListener('change', () => showMode(this.shadowRoot, select.value));

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      results.hidden = true;
      status.textContent = 'Solving…';
      try {
        const payload = payloadFromForm(form);
        // normalize expected keys casing (api normalizes anyway)
        // add report_T default if blank handled by API
        const json = await solve(payload, { endpoint });
        status.textContent = '';
        results.hidden = false;

        // composition
        results.querySelector('[data-out="abm"]').textContent = json.abm.toFixed(4);
        results.querySelector('[data-out="sbm"]').textContent = json.sbm.toFixed(4);
        // outputs
        const o = json.outputs || {};
        for (const k of ['T_C','ABV','Sugar_WV','Density','BrixATC','nD']) {
          const el = results.querySelector(`[data-out="${k}"]`);
          if (!el) continue;
          let v = o[k];
          if (typeof v === 'number') {
            if (k === 'Density') v = v.toFixed(5);
            else if (k === 'Sugar_WV') v = v.toFixed(1);
            else if (k === 'nD') v = v.toFixed(5);
            else if (k === 'ABV' || k === 'BrixATC') v = v.toFixed(3);
          }
          el.textContent = v ?? '';
        }
        // diagnostics (optional)
        diag.textContent = JSON.stringify(json.diagnostics ?? {}, null, 2);
      } catch (err) {
        status.textContent = (err instanceof Error ? err.message : String(err));
      }
    });
  }
}
customElements.define('mix-calc', MixCalcElement);
