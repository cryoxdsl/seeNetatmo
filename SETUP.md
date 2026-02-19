# PHP 8 Development & Deployment Guide

## Repo Layout for OVH Deployment

Your project is already well-organized for OVH shared hosting deployment. Here's how it maps to `/www`:

```
OVH /www (Document Root)
│
├── index.php                    # Main entry point
├── charts.php                   # Public pages
├── history.php
├── upgrade.php
├── README.md
├── .gitignore                   # Git exclusions
├── .github/                     # CI/CD workflows (not served)
│   └── workflows/
│       └── ci.yml
├── .vscode/                     # IDE config (not served)
│   ├── settings.json
│   ├── launch.json
│   └── tasks.json
├── admin-meteo13/               # Admin panel (protected path)
│   ├── index.php
│   ├── login.php
│   ├── netatmo.php
│   └── ...
├── assets/                      # Public CSS, JS
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── chart.min.js
│       └── charts.js
├── config/                      # Configuration (NOT committed)
│   ├── config.php               # ⛔ .gitignored
│   ├── secrets.php              # ⛔ .gitignored
│   ├── config.php.example       # Template (committed)
│   └── secrets.php.example      # Template (committed)
├── cron/                        # Cron jobs (can be restricted)
│   ├── fetch.php
│   ├── daily.php
│   └── external.php
├── inc/                         # Internal includes (not web-accessible)
│   ├── bootstrap.php
│   ├── auth.php
│   ├── db.php
│   ├── config.php
│   └── ...
├── install/                     # Installation script (optional)
│   ├── index.php
│   └── schema.sql
└── logs/                        # Application logs (NOT committed)
    └── .gitkeep
```

**Key Points:**
- All public files at root level or in `assets/`
- Admin panel: `/admin-meteo13/` (configure htaccess or firewall)
- Config files: Never committed, created locally from `.example` files
- Logs & storage: Generated at runtime, git-ignored
- `inc/` folder: Protected from direct web access (use htaccess if needed)

---

## Git Workflow

### Branch Structure

```
main (production)
 └─ Protected branch
    └─ Requires PR review before merge
    └─ All checks must pass (lint CI)

dev (development)
 └─ Integration branch
    └─ Merges from feature branches
    └─ Creates PR to main when ready

feature/... (feature branches)
 └─ Create from: dev
 └─ Merge back to: dev via PR
 └─ Naming: feature/login-2fa, feature/api-call, etc.
    
hotfix/... (emergency fixes)
 └─ Create from: main
 └─ Merge to: main AND dev via PR
 └─ Naming: hotfix/security-patch, hotfix/db-migration
```

### Typical Workflow

1. **Feature Development:**
   ```bash
   git checkout dev
   git pull origin dev
   git checkout -b feature/my-feature
   # Make changes
   git add .
   git commit -m "feat: add feature description"
   git push origin feature/my-feature
   # Create PR on GitHub: feature/my-feature → dev
   ```

2. **Integration & Testing:**
   - PR triggers CI/CD (PHP lint)
   - Team reviews code
   - Merge to dev when approved

3. **Release to Production:**
   ```bash
   # When dev is stable
   git checkout dev
   git pull origin dev
   git checkout -b release/v1.x.x
   # Optional: bump version in README
   git commit -m "chore: version bump v1.x.x"
   git push origin release/v1.x.x
   # Create PR: release/v1.x.x → main
   # After approval/merge: automatic OVH deploy
   ```

4. **Hotfix:**
   ```bash
   git checkout main
   git pull origin main
   git checkout -b hotfix/critical-fix
   # Make fix
   git push origin hotfix/critical-fix
   # Create PR: hotfix/critical-fix → main
   # Merge to main AND dev after approval
   ```

### Branch Protection Rules for `main`

Configure in GitHub > Settings > Branches > Branch protection rules:

✅ **Require pull request reviews:**
   - Dismiss stale PR approvals when new commits are pushed
   - Allow force pushes: ❌ No
   
✅ **Require status checks:**
   - CI lint workflow must pass
   - Require branches to be up to date before merging

✅ **Restrict who can push:**
   - Allow only admins to push directly (emergency only)

✅ **Require signed commits:** (Optional, recommend for security)

---

## Local Development Setup

### Prerequisites
- PHP 8.x (verify: `php --version`)
- MySQL 5.7+ or MariaDB (optional, can use SQLite for testing)
- Git

### 1. Clone Repository
```bash
git clone https://github.com/cryoxdsl/seeNetatmo.git
cd seeNetatmo
```

### 2. Configure Local Environment
```bash
# Copy default config files
cp config/config.php.example config/config.php
cp config/secrets.php.example config/secrets.php

# Edit config files with your local settings
code config/config.php
code config/secrets.php
```

**Example `config/config.php` (modify as needed):**
```php
<?php
return [
    'db' => [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => 'password',  // or empty for SQLite
        'name' => 'meteo13_dev',
    ],
    'netatmo' => [
        'api_url' => 'https://api.netatmo.com',
    ],
];
```

**Example `config/secrets.php`:**
```php
<?php
return [
    'netatmo_client_id'     => 'your_client_id',
    'netatmo_client_secret' => 'your_secret',
    'totp_secret'           => 'your_totp_secret_or_empty',
];
```

### 3. Set Up Database (Optional)

**Option A: MySQL/MariaDB**
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE meteo13_dev;"

# Import schema
mysql -u root -p meteo13_dev < install/schema.sql
```

**Option B: Docker (Clean Environment)**
```bash
docker run --name mysql-meteo13 \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=meteo13_dev \
  -p 3306:3306 \
  -d mysql:8.0

# Import schema
mysql -h 127.0.0.1 -u root -proot meteo13_dev < install/schema.sql
```

### 4. Run Built-in PHP Server

**Option A: Command Line**
```bash
php -S localhost:8000 -t .
# Visit: http://localhost:8000
```

**Option B: VS Code**
- Open Command Palette: `Ctrl+Shift+P`
- Run task: `"Start PHP Built-in Server"`
- Terminal shows: `Development Server started`
- Visit: `http://localhost:8000`

**Debugging with Xdebug:**
- Install Xdebug extension: `pecl install xdebug`
- Add to `php.ini`:
  ```ini
  zend_extension=xdebug.so
  xdebug.mode=debug
  xdebug.start_with_request=yes
  xdebug.client_port=9003
  ```
- In VS Code: Press `F5` or go to Run > Start Debugging
- Set breakpoints and debug

### 5. Open in VS Code

```bash
code .
```

**VS Code Extensions (Recommended):**
- PHP Intelephense (bmewburn.vscode-intelephense-client)
- Thunder Client or REST Client (for API testing)
- MySQL extension (if using database)

---

## Deployment to OVH

### How OVH Git Deployment Works

OVH provides Git-based deployment:
1. You push to the main branch
2. OVH Git webhook is triggered
3. Automatic deployment pulls latest main → `/www`
4. **Important:** Only committed files are deployed

### Pre-Deployment Checklist

✅ **Verify .gitignore excludes:**
```bash
# These must NOT be in git
git check-ignore config/config.php        # Should match
git check-ignore config/secrets.php       # Should match
git check-ignore config/installed.lock    # Should match
git check-ignore logs/                    # Should match
git check-ignore storage/                 # Should match
```

✅ **Commit template files instead:**
```bash
# Committed to repo (tracked)
config/config.php.example
config/secrets.php.example

# Can push instructions in README:
echo "After deployment: copy config/config.php.example to config/config.php"
```

✅ **No conflicts with runtime files:**
All `.gitignore`d files are **safe from overwriting** because they're not tracked.

### Deployment Steps

1. **Code Review & Merge to main:**
   ```bash
   # On GitHub: PR approved and merged to main
   # CI workflow runs automatically
   # All lint checks pass ✓
   ```

2. **OVH Auto-Deploys:**
   - Webhook triggered
   - `git pull origin main` in `/www`
   - Server automatically updates

3. **Post-Deployment on OVH:**
   - SSH into OVH server
   ```bash
   # One-time setup after first deployment:
   cd /www
   cp config/config.php.example config/config.php
   cp config/secrets.php.example config/secrets.php
   
   # Edit files with production values
   nano config/config.php
   nano config/secrets.php
   
   # Run installer if first time
   php install/index.php
   
   # Set permissions
   chmod 755 logs/ storage/
   chmod 644 config/config.php
   chmod 644 config/secrets.php
   ```

4. **Verify Deployment:**
   - Visit: `https://yourdomain.com`
   - Check admin: `https://yourdomain.com/admin-meteo13/`
   - Test login and functionality

### Avoiding Overwrites

**Files that will NOT be overwritten (git-ignored):**
- `config/config.php` (production DB credentials)
- `config/secrets.php` (API keys, TOTP)
- `logs/*` (existing logs preserved)
- `storage/*` (runtime data preserved)

**Safe to deploy frequently** - No data loss on re-deploy.

### Emergency Rollback

```bash
# On OVH server
cd /www
git log --oneline | head -5     # See recent commits
git revert <commit-hash>         # Revert to previous state
# Or: git reset --hard <commit>  (⚠️ careful!)
```

---

## VS Code Configuration Files Guide

### `.vscode/settings.json`
- **PHP version:** Set to 8.0, 8.1, or 8.2
- **Formatting:** Uses Intelephense, formats on save
- **Validation:** Real-time syntax checking
- **Exclude patterns:** Ignores node_modules, vendor, logs

### `.vscode/launch.json`
- **Listen for Xdebug:** Debug remotely or built-in server
- **Client port:** 9003 (standard Xdebug port)
- **Path mapping:** Maps remote `/` to your workspace

### `.vscode/tasks.json`
- **PHP Lint All Files:** Syntax check current file (Ctrl+Shift+B)
- **PHP Lint Workspace:** Check all PHP files in project
- **Start PHP Server:** Launch built-in server in background

### Usage:
```bash
# Run lint (default build task)
Ctrl+Shift+B

# Debug
F5 → Select "Listen for Xdebug"

# Start server
Ctrl+Shift+P → Tasks: Run Task → Start PHP Built-in Server
```

---

## GitHub Actions CI Workflow

File: `.github/workflows/ci.yml`

**Runs on:**
- Every push to main or dev
- Every PR to main

**Checks:**
- Setup PHP 8.2 environment
- Run `php -l` on all PHP files
- Report syntax errors (blocks merge if failed)

**Example Output:**
```
✓ Setup PHP 8.2
✓ PHP Lint - Check all PHP files for syntax errors
✓ No syntax errors found!
```

**To skip CI (not recommended):**
```bash
git commit --no-verify  # ⚠️ Not recommended
```

---

## Common Issues & Solutions

### Issue: `config/config.php` not found
```bash
# Fix: Run install or copy template
cp config/config.php.example config/config.php
```

### Issue: OVH deployment not triggering
- Check Repository Settings > Webhooks (OVH control panel)
- Ensure branch is `main`
- Verify SSH key added to OVH account

### Issue: Permission denied on logs folder
```bash
ssh user@ovh
cd /www
chmod 755 logs/
chmod 755 storage/
```

### Issue: Xdebug not working
- Verify `php -i | grep xdebug` shows enabled
- Check firewall allows port 9003
- Restart VS Code debugger if hanging

---

## Summary Checklist

- [x] Repo layout organized for OVH `/www`
- [x] `.vscode/settings.json` - PHP 8 validation & formatting
- [x] `.vscode/launch.json` - Xdebug debugging
- [x] `.vscode/tasks.json` - Lint and server tasks
- [x] `.github/workflows/ci.yml` - Automated lint checks
- [x] `.gitignore` - Excludes secrets and runtime files
- [x] Git workflow with main/dev/feature branches
- [x] Branch protection rules documented
- [x] Local development setup steps
- [x] OVH deployment process explained
- [x] CI/CD integration ready

You're all set! 🚀
