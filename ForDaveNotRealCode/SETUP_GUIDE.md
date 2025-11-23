# Local Development Setup - Complete Guide

## Files to Update

You now have two files in your outputs folder that need to be copied to your local Laragon installation:

### 1. config.php
**Copy to:** `C:\laragon\www\liquidintelligence\config.php`

This file automatically detects whether it's running locally or on Dreamhost:
- **Local (Laragon):** Uses `127.0.0.1`, `root` user, empty password
- **Production (Dreamhost):** Uses `mysql.cookingissues.com`, your credentials

**IMPORTANT:** Before uploading to Dreamhost, edit the production section with your actual credentials:
```php
'user'    => 'your_actual_dreamhost_username',
'pass'    => 'your_actual_dreamhost_password',
```

### 2. test_connection.php  
**Copy to:** `C:\laragon\www\liquidintelligence\test_connection.php`

This is a diagnostic script to verify your database connection works.

---

## Setup Steps

### Step 1: Copy Files
1. Download both files from this chat (links provided below)
2. Copy `config.php` to `C:\laragon\www\liquidintelligence\`
3. Copy `test_connection.php` to `C:\laragon\www\liquidintelligence\`

### Step 2: Verify Laragon is Running
1. Open Laragon
2. Click "Start All"
3. Verify Apache and MySQL are both running (green indicators)

### Step 3: Access Your Site
Laragon should automatically create a virtual host. Try these URLs in your browser:
- `http://liquidintelligence.test`
- `http://localhost/liquidintelligence`

If neither works, check Laragon's menu → Apache → Virtual Hosts → Auto

### Step 4: Test Database Connection
Visit: `http://liquidintelligence.test/test_connection.php`

You should see:
- ✓ Connection successful!
- MySQL version (8.4.3)
- List of all tables with row counts

**If you see an error:**
- Check that HeidiSQL shows `liquidintelligencedb` exists
- Verify the database has tables (expand the database in HeidiSQL's left panel)
- Check that MySQL is running in Laragon

### Step 5: Test the Calculator
Visit: `http://liquidintelligence.test/calc/index2.html`

This should load the mixer calculator interface.

### Step 6: Test the Cocktail Database
Visit: `http://liquidintelligence.test/recipes/index.html`

This should load the cocktail analyzer interface.

---

## How Environment Detection Works

The `config.php` file checks the server name:
```php
$isLocal = (
    $_SERVER['SERVER_NAME'] === 'localhost' ||
    $_SERVER['SERVER_NAME'] === 'liquidintelligence.test' ||
    // ... other checks
);
```

When you access via `liquidintelligence.test` or `localhost`, it uses Laragon settings.  
When you access via `liquidintelligence.cookingissues.com`, it uses Dreamhost settings.

**This means you never need to manually edit config.php when switching between local and production!**

---

## Uploading Changes to Dreamhost

When you're ready to push changes to production:

1. **Update Dreamhost credentials** in `config.php` first (the production section)
2. Upload modified files via:
   - Dreamhost's File Manager, OR
   - FTP/SFTP client (FileZilla, WinSCP, etc.)

3. The same `config.php` works on both local and production

---

## Troubleshooting

### "Can't connect to MySQL server"
- Open HeidiSQL from Laragon
- Verify `liquidintelligencedb` exists in the database list
- Check that MySQL is running (Laragon main window)

### "Access denied for user 'root'@'localhost'"
Laragon's default MySQL credentials are:
- Username: `root`
- Password: (empty/blank)

If you changed these, update the local section in `config.php`

### "Table doesn't exist"
Your database import may not have completed. In HeidiSQL:
1. Click on `liquidintelligencedb`
2. You should see tables like: `mix_data`, `drinks`, `ingredients`, `recipes`, etc.
3. If empty, re-import your .sql file

### Site shows directory listing instead of page
- Check that `index.html` or `index.php` exists in the folder
- For the calculator: `calc/index2.html` is the main page
- For cocktails: `recipes/index.html` is the main page

### "Fatal error: Class 'PDO' not found"
PDO should be enabled by default in Laragon. If not:
1. Right-click Laragon → PHP → php.ini
2. Find `;extension=pdo_mysql` and remove the semicolon
3. Restart Laragon

---

## Next Steps

Once your local environment is working, you can:
1. Make changes to files in `C:\laragon\www\liquidintelligence\`
2. Test them locally at `http://liquidintelligence.test`
3. Upload working changes to Dreamhost when ready

You can now tell me about specific issues you're experiencing with the calculator or cocktail database, and I can examine the code and provide fixes!
