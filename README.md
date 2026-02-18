# meteo13-netatmo

PHP 8 weather application for OVH shared hosting (`hosting-starter`), without framework, Composer, or Node.js.

- Domain: `meteo13.fr`
- Admin path: `/admin-meteo13/` (fixed)
- License: CC BY 4.0

## FR - Fonctionnalités
- Collecte Netatmo OAuth2 (station météo)
- Insertion dans table existante `alldata` (structure non modifiée)
- Front responsive:
  - `/index.php` (dashboard live)
  - `/charts.php` (24h, 7j, 30j, mois courant, année courante, 365j glissants)
  - `/history.php` (pagination + export CSV filtré)
- Back office sécurisé `/admin-meteo13/`:
  - login + mot de passe
  - 2FA TOTP obligatoire
  - lockout 10 échecs / 10 min (username + IP)
  - timeout session 30 min
  - cookies `Secure`, `HttpOnly`, `SameSite=Strict`
- Installer web one-shot: `/install/`
- Upgrade: `/upgrade.php` (migrations PHP embarquées)
- Cron HTTP via cron-job.org:
  - `/cron/fetch.php?key=...` (5 min)
  - `/cron/daily.php?key=...` (00:10)
  - `/cron/external.php?key=...` (10-15 min, vigilance + sea temperature cache)

## FR - Déploiement OVH (Git vers /www)
1. Créer la base MySQL OVH.
2. Vérifier que la table `alldata` existe déjà avec PK `DateTime`.
3. Déployer ce dépôt via OVH Git deployment dans `/www`.
4. Vérifier droits d’écriture sur `/www/config` et `/www/logs`.
5. Ouvrir `https://meteo13.fr/install/index.php`.
6. Suivre les 8 étapes:
   - prérequis serveur
   - connexion DB
   - vérification table `alldata` + PK `DateTime`
   - création tables applicatives
   - compte admin + setup 2FA (QR + backup codes)
   - credentials Netatmo
   - génération clés master + cron
   - génération `config/config.php`, `config/secrets.php`, `config/installed.lock`
7. Configurer l’app Netatmo avec URI de redirection:
   - `https://meteo13.fr/admin-meteo13/netatmo_callback.php`
8. Configurer cron-job.org:
   - toutes les 5 min: `https://meteo13.fr/cron/fetch.php?key=CRON_KEY_FETCH`
   - tous les jours 00:10: `https://meteo13.fr/cron/daily.php?key=CRON_KEY_DAILY`
   - toutes les 10-15 min: `https://meteo13.fr/cron/external.php?key=CRON_KEY_EXTERNAL`
9. Optionnel: supprimer `/install/` (bloqué de toute façon par `installed.lock`).

## FR - Règles métier météo
- `DateTime` stocké en heure locale `Europe/Paris`, arrondi inférieur à 5 minutes.
- UPSERT: ne remplace jamais une valeur existante par `NULL`.
- Mapping:
  - Outdoor -> `T`, `H`
  - Base station -> `P`
  - Rain -> `RR`, `R`
  - Wind -> `W`, `G`, `B`
- Calculs fetch:
  - `Tmax=T`, `Tmin=T`
  - `D` point de rosée (Magnus)
  - `A` température apparente
- Job daily:
  - `Tmax=max(T)` et `Tmin=min(T)` du jour
  - recalcul `D` et `A`
  - recalc veille optionnelle activée par défaut
- Module déconnecté: champs du module à `NULL`.
- Front: indicateur déconnecté si dernier point > 15 min.

## EN - Features
- Netatmo OAuth2 weather ingestion
- Write into existing `alldata` table (no schema change)
- Responsive front office (`index.php`, `charts.php`, `history.php` + filtered raw CSV export)
- Secure admin `/admin-meteo13/` (password + mandatory TOTP 2FA)
- Lockout: 10 failures / 10 minutes per username+IP
- Session inactivity timeout: 30 minutes
- Installer wizard `/install/`
- Upgrade endpoint `/upgrade.php` with embedded PHP migrations
- Cron-job.org HTTP endpoints for fetch and daily recalculation

## EN - OVH hosting-starter deployment
1. Provision OVH MySQL database.
2. Ensure existing `alldata` table has primary key `DateTime`.
3. Deploy repository to `/www` using OVH Git deployment.
4. Ensure `/www/config` and `/www/logs` are writable.
5. Run `https://meteo13.fr/install/index.php`.
6. Configure Netatmo OAuth redirect URI:
   - `https://meteo13.fr/admin-meteo13/netatmo_callback.php`
7. Configure cron-job.org:
   - every 5 minutes: `/cron/fetch.php?key=...`
   - daily 00:10: `/cron/daily.php?key=...`
   - every 10-15 minutes: `/cron/external.php?key=...`

## Security & secrets
- `config/.htaccess` denies direct access.
- Runtime secrets encrypted in DB (`secrets` table):
  - libsodium XChaCha20-Poly1305 if available
  - fallback OpenSSL AES-256-GCM
- `config/secrets.php` stores only master key.
- `.gitignore` excludes runtime config + lock + logs.

## Upgrade
- Go to `/upgrade.php` with active admin session.
- Missing migrations are executed and recorded in `schema_migrations`.
- `settings.app_version` is updated.

## CSV export
- `history.php?period=<period>&export=csv`
- Exports only selected period.
- Exports raw DB values.
