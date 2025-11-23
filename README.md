# Liquid Intelligence Calculator (PHP/MySQL starter)

This is a minimal PHP 8.3 app that:
- Loads your CSV (`ALC_SUGForCalc.csv`) into MySQL
- Solves for ABM/SBM given **two** measurements (possibly at different temperatures)
- Returns ABV, SBM/ABM, Sugar_WV, Density, nD, BrixATC at any output temperature
- Handles `9999` sentinels for BrixATC and nD (stored as NULL)
- Warns when inputs are out-of-range and shows valid ranges *conditioned* on the other input (approximate band)

> **No Composer, no frameworks**, to play nice with DreamHost ModSecurity.

## Quick start

1. **Create DB & user** (you already did). Note the DB hostname, name, username, password.
2. **Copy config:**
   - Upload all files to your site root: `/home/<youruser>/liquidintelligence.cookingissues.com/`
   - Rename `config.sample.php` → `config.php`
   - Edit credentials inside.
3. **Protect /admin** (you already created `~/.htpasswd_liquid` with user `liadmin`).
   - Edit `admin/.htaccess` and replace `REPLACE_WITH_YOUR_USER` with your panel user (e.g., `cocktail_user`).
4. **Create table:** Either run `schema.sql` in phpMyAdmin, or just visit `/admin/diag.php` (it will auto-create table if missing).
5. **Load your CSV:**
   - Visit `/admin/load_csv.php` (will prompt for basic-auth `liadmin`).
   - Upload `ALC_SUGForCalc.csv` and import.
6. **Use the app:** Visit `/` (index.php), choose a mode, enter the two measurements (with their temperatures), and the output temperature.

## CSV expectations

- Header row present, columns (case-insensitive): `T_C, ABM, SBM, ABV, Sugar_WV, nD, Density, BrixATC`
- If `BrixATC` or `nD` is `9999`, they are saved as NULL.
- Primary key: `(ABM, SBM, T_C)` — later uploads replace existing rows for the same triplet.

## Modes

- **abv_brix**: inputs = `ABV@T1`, `BrixATC@T2`
- **abv_density**: inputs = `ABV@T1`, `Density@T2`
- **brix_density**: inputs = `BrixATC@T1`, `Density@T2`
- **abv_sugar**: inputs = `ABV@T1`, `Sugar_WV@T2`
- **abm_sbm**: inputs = `ABM` (temp independent), `SBM` (temp independent)

For temp-dependent inputs, you **must** enter the measurement temperature for each.
For outputs, you can pick any `T_out` (10–30 °C); values are temperature-corrected by **linear interpolation** between the nearest tabulated temperatures.

## Out-of-range behavior

When no `(ABM,SBM)` pair can explain both measurements within tolerance, the API:
- returns `ok:false`
- includes `explain` (why) and **approximate valid ranges** for each input conditioned on the other, computed from a near-level band around the provided value.

## Files

- `config.sample.php` → copy to `config.php` and fill in DB credentials.
- `schema.sql` → table DDL.
- `admin/load_csv.php` → upload & import CSV (protected by basic auth).
- `admin/diag.php` → DB sanity checks.
- `api/calc.php` → solver API (POST).
- `index.php` → simple front-end form.
