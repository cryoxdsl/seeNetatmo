# seeNetatmo

Application météo PHP 8 sans framework (ni Composer, ni Node.js), conçue pour l’hébergement mutualisé.

- Interface publique : `index.php`, `charts.php`, `climat.php`, `history.php`, `terms.php`
- Admin: `APP_ADMIN_PATH` (par défaut `/admin-meteo13`)
- Installation guidée : `/install/`
- Mises à jour de schéma : `/upgrade.php`
- Guide d'installation détaillé: `SETUP.md`

## 1) Fonctionnalités

- Collecte Netatmo via OAuth2.
- Ingestion dans une table météo existante (par défaut `alldata`) sans modifier sa structure métier.
- Tableau de bord en direct, responsive, avec cartes météo.
- Historique avec pagination et export CSV filtré.
- Prévisions externes, température de mer, METAR (avec décodage lisible).
- Administration sécurisée :
  - login/mot de passe
  - 2FA TOTP
  - limitation des tentatives (verrouillage temporaire)
  - timeout de session
  - option « appareil de confiance »
- Cron HTTP pour la collecte et les consolidations.

## 2) Pré-requis

- PHP 8.1+ (8.2 recommandé)
- MySQL / MariaDB
- Extensions PHP recommandées:
  - `pdo_mysql`
  - `curl`
  - `mbstring`
  - `json`
  - `openssl` (ou libsodium selon environnement)
- Écriture autorisée sur:
  - `config/`
  - `logs/`
  - `assets/uploads/` (si upload favicon utilisé)

## 3) Installation (nouveau projet)

1. Déployer le code dans le webroot.
2. Créer la base de données.
3. Vérifier la présence de la table météo cible (ex: `alldata`) avec PK `DateTime`.
4. Ouvrir:
   - `https://your-domain.tld/install/index.php`
5. Suivre l’assistant d’installation.
6. Configurer l’app Netatmo (redirect URI):
   - `https://your-domain.tld/admin-meteo13/netatmo_callback.php`
   - Remplacer `admin-meteo13` si vous avez changé `APP_ADMIN_PATH`.
7. Configurer les cron HTTP (voir section 6).

## 4) Configuration runtime

Fichiers générés à l’installation:

- `config/config.php`
- `config/secrets.php`
- `config/installed.lock`

Ne pas versionner ces fichiers.

## 5) URLs importantes

- Front live: `https://your-domain.tld/index.php`
- Admin: `https://your-domain.tld/admin-meteo13/`
- Upgrade: `https://your-domain.tld/upgrade.php`

## 6) Cron recommandés

Planification typique:

- toutes les 5 min:
  - `https://your-domain.tld/cron/fetch.php?key=CRON_KEY_FETCH`
- quotidien (ex 00:10):
  - `https://your-domain.tld/cron/daily.php?key=CRON_KEY_DAILY`
- toutes les 10-15 min:
  - `https://your-domain.tld/cron/external.php?key=CRON_KEY_EXTERNAL`

## 7) Sécurité

- Zone admin protégée par session + CSRF.
- 2FA TOTP supporté.
- Cookies de session sécurisés (`HttpOnly`, `SameSite`, `Secure` si HTTPS).
- Secrets applicatifs chiffrés en base et/ou protégés via clé maître.
- `config/.htaccess` bloque l’accès direct aux secrets.

## 8) Données météo et calculs

- Timezone applicative: `Europe/Paris`.
- `DateTime` est l’axe principal des séries.
- Les cumuls pluie et deltas climatologiques sont calculés côté SQL/PHP.
- Les cartes externes (prévisions/mer/METAR) utilisent une stratégie cache prioritaire pour accélérer le rendu.

## 9) Performance

- Cache mémoire des paramètres (réduction des requêtes répétées).
- Cache persistant pour certains agrégats lourds (pluie, références).
- Rafraîchissement asynchrone des caches externes après affichage.
- Mode de mesure ponctuel :
  - `https://your-domain.tld/index.php?perf=1`
  - ajoute l’en-tête `Server-Timing`.

## 10) Mise à jour applicative

Après déploiement d’une nouvelle version:

1. Se connecter à l’admin.
2. Ouvrir `https://your-domain.tld/upgrade.php`.
3. Exécuter les migrations en attente.

## 11) Dépannage rapide

- "METAR indisponible":
  - vérifier latitude/longitude station et connectivité sortante.
- Déconnexions admin fréquentes:
  - vérifier timeout session et horloge serveur.
- Texte accentué incorrect:
  - vérifier encodage UTF-8 des contenus admin.

## 12) Développement

- Pas de build frontend requis.
- CSS/JS servis en statique depuis `assets/`.
- Entrées principales:
  - `index.php`
  - `inc/` (métier)
  - `admin-meteo13/` (back office)
  - `cron/` (jobs HTTP)

## Licence

CC BY 4.0
