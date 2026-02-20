# SETUP Guide (Domain-Agnostic)

Ce document décrit l’installation, le développement et le déploiement de `seeNetatmo` sans dépendre d’un nom de domaine spécifique.
La terminologie est alignée avec `README.md`.

## 1) Architecture du projet

```text
project-root/
├── index.php
├── charts.php
├── climat.php
├── history.php
├── terms.php
├── upgrade.php
├── admin-meteo13/              # Administration (modifiable via constante)
├── assets/                     # CSS/JS/images publics
├── cron/                       # Points d’entrée HTTP pour jobs
├── inc/                        # Code applicatif interne
├── install/                    # Assistant d'installation + schema SQL
├── config/                     # Config runtime (non versionnée)
├── logs/                       # Logs runtime (non versionnés)
├── README.md
└── SETUP.md
```

## 2) Pré-requis

- PHP 8.1+ (8.2 recommandé)
- MySQL/MariaDB
- Extensions PHP:
  - `pdo_mysql`
  - `curl`
  - `json`
  - `mbstring`
  - `openssl` (ou libsodium selon environnement)

Permissions en écriture:

- `config/`
- `logs/`
- `assets/uploads/` (si upload favicon)

## 3) Installation (nouveau projet)

1. Déployer le code dans le document root.
2. Créer la base de données.
3. Vérifier la table météo cible (par défaut `alldata`) avec PK `DateTime`.
4. Ouvrir:
   - `https://your-domain.tld/install/index.php`
5. Suivre l'assistant.
6. Configurer Netatmo OAuth Redirect URI:
   - `https://your-domain.tld/admin-meteo13/netatmo_callback.php`
7. Configurer les jobs cron HTTP (section 7).

## 4) Configuration runtime

Fichiers sensibles générés/maintenus hors Git :

- `config/config.php`
- `config/secrets.php`
- `config/installed.lock`
- `logs/*`

Vérifier `.gitignore` pour exclure ces fichiers.

## 5) Local development

### 5.1 Clone

```bash
git clone <your-repo-url>
cd seeNetatmo
```

### 5.2 Configuration locale

```bash
cp config/config.php.example config/config.php
cp config/secrets.php.example config/secrets.php
```

Puis adapter `config/config.php` et `config/secrets.php` à l’environnement local.

### 5.3 Base de données

```bash
# Exemple MySQL
mysql -u <user> -p -e "CREATE DATABASE seennetatmo_dev;"
mysql -u <user> -p seennetatmo_dev < install/schema.sql
```

### 5.4 Lancer l'application

```bash
php -S localhost:8000 -t .
```

- Interface publique: `http://localhost:8000/index.php`
- Install: `http://localhost:8000/install/index.php`

## 6) Déploiement production

### 6.1 Principe général

- Déployer uniquement le code versionné.
- Garder les secrets/config runtime sur le serveur.
- Exécuter `/upgrade.php` après déploiement si nécessaire.

### 6.2 Checklist post-déploiement

1. Vérifier les permissions (`config`, `logs`, `assets/uploads`).
2. Vérifier l’accès admin:
   - `https://your-domain.tld/admin-meteo13/`
3. Vérifier les points d’entrée publics:
   - `index.php`, `charts.php`, `history.php`, `climat.php`
4. Lancer migrations:
   - `https://your-domain.tld/upgrade.php`

## 7) Cron recommandés

Exemples de fréquence:

- Toutes les 5 minutes:
  - `https://your-domain.tld/cron/fetch.php?key=CRON_KEY_FETCH`
- Quotidien (ex: 00:10):
  - `https://your-domain.tld/cron/daily.php?key=CRON_KEY_DAILY`
- Toutes les 10-15 minutes:
  - `https://your-domain.tld/cron/external.php?key=CRON_KEY_EXTERNAL`

## 8) Sécurité

- Administration protégée par session + CSRF.
- 2FA TOTP activable côté admin.
- Protection anti-bruteforce (verrouillage temporaire).
- Cookies sécurisés (`HttpOnly`, `SameSite`, `Secure` si HTTPS).
- Secrets chiffrés côté application.
- Éviter toute exposition directe de `config/`.

## 9) Performance

- Stratégie cache prioritaire pour prévisions / mer / METAR.
- Rafraîchissement asynchrone des caches après rendu.
- Caches applicatifs pour agrégats lourds.
- Mesure ponctuelle:
  - `https://your-domain.tld/index.php?perf=1`

## 10) Flux Git (CI/CD et branches)

Stratégie recommandée:

- `main`: production
- `dev`: intégration
- `feature/*`: développement
- `hotfix/*`: correctifs urgents

Protection recommandée sur `main`:

- PR obligatoire
- contrôles CI obligatoires
- push direct restreint

## 11) Dépannage rapide

- Erreur de langue:
  - vérifier `Accept-Language` navigateur et paramètres `?lang=`.
- METAR/prévisions indisponibles:
  - vérifier connectivité sortante + coordonnées station.
- Déconnexions admin:
  - vérifier timeout session et horloge serveur.
- Accents corrompus:
  - vérifier UTF-8 des contenus saisis et DB/collation.

## 12) Mise à jour applicative

Après déploiement d'une nouvelle version:

1. Se connecter à l'admin.
2. Ouvrir `https://your-domain.tld/upgrade.php`.
3. Exécuter les migrations en attente.

## 13) Commandes utiles

```bash
# Rechercher rapidement dans le projet
rg "pattern" .

# Afficher changements en cours
git status
git diff

# Vérifier hooks/actions (selon repo)
# Voir .github/workflows/
```

## 14) Variables et URL à personnaliser

Remplacer systématiquement:

- `your-domain.tld`
- clés cron `CRON_KEY_*`
- credentials DB
- credentials Netatmo OAuth

## 15) Notes OVH (optionnel)

Le projet reste compatible OVH mutualisé, mais ce guide est volontairement générique.
Adapter:

- chemin webroot
- méthode de déploiement (Git/FTP/CI)
- planification cron (OVH ou service tiers)
