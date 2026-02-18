# seeNetatmo (PHP 8, OVH hosting-starter)

## FR - Présentation
Application PHP 8 sans framework pour:
- Récupérer les données météo Netatmo (OAuth2)
- Enregistrer dans une table existante `alldata`
- Afficher un front responsive (live, graphiques, historique + export CSV)
- Fournir un back-office sécurisé (mot de passe + TOTP 2FA)
- Installer via assistant web
- Mettre à jour via `upgrade.php`
- Exécuter la collecte via cron-job.org

Contraintes respectées:
- PHP uniquement
- Aucune dépendance Composer
- Aucun Node.js
- Compatible mutualisé OVH

## FR - Installation
1. Uploader les fichiers sur l’hébergement.
2. Ouvrir `/install/index.php`.
3. Suivre les 5 étapes:
- Vérification environnement
- Connexion DB + vérification table `alldata`
- Création admin + suffixe `/admin-<suffix>/`
- Saisie client Netatmo
- Finalisation (génération des clés, config, lock)
4. Configurer cron-job.org avec:
- `https://votre-domaine.tld/cron/fetch.php?key=CRON_KEY_FETCH` toutes les 5 min
- `https://votre-domaine.tld/cron/daily.php?key=CRON_KEY_DAILY` chaque jour à 00:10
5. Supprimer ou protéger fortement `/install/`.

## FR - URL principales
- `/` dashboard live
- `/charts.php` graphiques périodes (24h, 7j, 30j, mois, année, 365 jours glissants)
- `/history.php` historique paginé + export CSV filtré
- `/admin-<suffix>/` back office
- `/upgrade.php` migrations (session admin requise)

## FR - Sécurité
- Session admin timeout: 30 min
- Cookies: Secure (HTTPS), HttpOnly, SameSite=Lax
- 2FA TOTP obligatoire
- Verrouillage après 10 échecs en 10 min
- Secrets chiffrés en base (`secrets`) via AES-256-CBC + HMAC

## FR - Données / cron
`cron/fetch.php`:
- Arrondi du timestamp à l’inférieur sur 5 minutes (Europe/Paris)
- UPSERT avec `ON DUPLICATE KEY UPDATE`
- Ne remplace jamais une valeur existante par `NULL`
- Calcule `Tmax`, `Tmin`, `D` (Magnus), `A` (apparent)

`cron/daily.php`:
- Recalcule `Tmax`/`Tmin` du jour
- Recalcule `D` et `A`

Les deux crons utilisent un lock fichier pour éviter l’exécution concurrente.

## EN - Overview
PHP 8 application (no framework) that:
- Fetches Netatmo weather data through OAuth2
- Stores into existing `alldata` table
- Provides responsive front office
- Provides secure admin with password + mandatory TOTP 2FA
- Includes web installer and upgrade system
- Uses cron-job.org endpoints for scheduling

Constraints:
- PHP only
- No Composer dependencies
- No Node.js
- OVH hosting-starter compatible

## EN - Setup
1. Upload project files.
2. Open `/install/index.php`.
3. Complete wizard steps.
4. Configure cron-job.org:
- `https://your-domain.tld/cron/fetch.php?key=CRON_KEY_FETCH` every 5 minutes
- `https://your-domain.tld/cron/daily.php?key=CRON_KEY_DAILY` daily at 00:10
5. Delete or lock down `/install/`.

## EN - Project tree
- `install/` installer + SQL schema
- `admin-template/` admin template files copied to `admin-<suffix>/` during install
- `cron/` fetch + daily jobs
- `inc/` shared libraries
- `config/` generated runtime config and lock file
- `assets/` CSS/JS

## EN - New DB tables (created by installer)
- `users`
- `settings`
- `secrets`
- `login_attempts`
- `backup_codes`
- `app_logs`
- `schema_migrations`

Definitions are in `install/schema.sql`.
