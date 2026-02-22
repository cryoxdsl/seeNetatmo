# Architecture du projet (métier + technique)

## 1. Objectif métier

`seeNetatmo` est une application web météo PHP destinée à publier les données d'une station météo personnelle (Netatmo) sur un site public, avec:

- consultation en direct (`Live`)
- graphiques interactifs
- historique/export
- climatologie (mensuelle / annuelle)
- enrichissements externes (prévisions, METAR, température de mer)
- back-office sécurisé pour configuration, supervision et sauvegardes

Le projet est conçu pour un hébergement mutualisé (ex: OVH) avec des contraintes pragmatiques:

- pas de framework PHP
- pas de build Node.js
- cron via HTTP
- base météo source existante conservée (table `alldata` ou équivalent)

## 2. Vue d'ensemble (flux métier)

### Flux principal des données météo

1. `cron/fetch.php` interroge Netatmo.
2. Les données brutes sont normalisées.
3. Les champs calculés (`D`, `A`) sont produits.
4. La ligne est insérée/mise à jour dans la table météo (`DateTime` PK).
5. Le front (`index.php`, `charts.php`, `history.php`, `climat.php`) lit cette table.
6. Le back-office supervise via logs / health / backups.

### Flux des enrichissements externes

1. Le front tente de lire les caches (`forecast`, `sea_temp`, `metar`).
2. Si cache absent/expiré et si autorisé, appel distant.
3. Résultat stocké en cache persistant (`settings` JSON).
4. Le front affiche le résultat ou un fallback propre.

### Flux analytics / observabilité front

1. Le front injecte un tracker JS (audience + erreurs client).
2. `track.php` enregistre sessions/pages vues/temps passé.
3. `front-log.php` journalise les erreurs navigateur dans `app_logs`.
4. L'admin consulte `stats.php` et `logs.php`.

## 3. Structure technique

### 3.1 Points d'entrée publics

- `index.php` : dashboard live (page principale)
- `charts.php` : graphiques interactifs
- `history.php` : historique + export CSV
- `climat.php` : climatologie
- `terms.php` : conditions d'utilisation
- `robots.php` / `sitemap.php` : SEO
- `track.php` : endpoint analytics audience
- `front-log.php` : endpoint erreurs navigateur
- `front_cache_refresh.php` : refresh asynchrone des caches externes

### 3.2 Back-office (`admin-meteo13/`)

- `login.php`, `2fa.php`, `logout.php`
- `index.php` (dashboard admin)
- `site.php` (configuration site/station/front)
- `security.php` (gestion 2FA / codes)
- `netatmo.php`, `netatmo_callback.php`
- `health.php` (état système)
- `logs.php` (journaux applicatifs)
- `stats.php` (statistiques audience)
- `backups.php` (exports SQL)

### 3.3 Modules `inc/`

- `bootstrap.php` : bootstrap global (session, locale, unités, headers)
- `session.php` : session + CSRF
- `auth.php` : auth admin, 2FA, trusted device
- `db.php` : connexion PDO MySQL
- `config.php` : lecture config/secrets install
- `settings.php` : settings applicatifs (DB + cache mémoire)
- `data.php` : requêtes métier et calculs météo/climat
- `weather_math.php` : formules météo
- `weather_condition.php` : classification météo + icônes SVG
- `forecast.php`, `metar.php`, `sea_temp.php` : intégrations externes
- `logger.php` : journalisation applicative
- `helpers.php` : helpers transverses (URL, IP, rate limit, release tag, etc.)
- `view.php` : layout HTML front (SEO + footer + scripts injectés)
- `units.php` : SI/IMP
- `i18n.php` : dictionnaire FR/EN
- `lock.php` : locks fichiers (crons / refresh)

## 4. Données métier (table météo source)

Le projet s'appuie sur une table existante (par défaut `alldata`) dont la clé primaire doit être `DateTime`.

### Champs utilisés (observés dans le code)

- `DateTime` : horodatage (clé primaire)
- `T` : température
- `Tmax` : max jour (recalculé)
- `Tmin` : min jour (recalculé)
- `H` : humidité
- `D` : point de rosée (calculé)
- `W` : vent moyen
- `G` : rafale
- `B` : direction vent (degrés)
- `RR` : pluie horaire / incrément
- `R` : cumul pluie jour Netatmo
- `P` : pression
- `A` : température apparente (calculée)
- `S` : champ affiché dans l'historique (non recalculé par l'app)

### Hypothèses de format

- `DateTime` stocké en fuseau applicatif (`Europe/Paris`) côté usage métier
- granularité typique: 5 minutes (arrondi via `floor_5min()` au cron fetch)

## 5. Calculs métier (données calculées)

### 5.1 Calculs physiques (`inc/weather_math.php`)

#### Point de rosée `D`
- Formule: Magnus
- Entrées: `T`, `H`
- Retour: `null` si valeurs invalides (`H <= 0` ou `>100`)

#### Température apparente `A`
- Approximation température / humidité / vent
- Vent converti km/h -> m/s pour le calcul

### 5.2 État de connexion station (`last_update_state`)

- Lit la dernière ligne météo
- Calcule l'âge en minutes
- `disconnected = true` si âge > `DISCONNECT_THRESHOLD_MINUTES`

Usage:
- badge de statut
- état “offline”
- griser certaines cards

### 5.3 Cumuls de pluie (`rain_totals`)

#### Règle métier de cumul

- **Jour**: privilégie `MAX(R)` (cumul journalier Netatmo)
- fallback: `SUM(RR)`

#### Totaux calculés

- `day`
- `month`
- `year`
- `rolling_year` (365 jours glissants)

#### Méthode pour mois/année/365j

1. reconstruit un cumul journalier par date:
   - `COALESCE(MAX(R), SUM(RR), 0)`
2. somme ces cumuls journaliers sur la période

#### Cache

- cache JSON dans `settings`
- TTL court (`120s`)

### 5.4 Références pluie historiques (`rain_reference_averages`)

Calcule des moyennes de référence sur les autres années:

- `day_avg` : moyenne du cumul du même jour (`MM-DD`)
- `month_avg` : moyenne du cumul du même mois
- `year_to_date_avg` : moyenne cumulée jusqu'à la même date (`MM-DD`)
- `rolling_365_avg` : moyenne des cumuls glissants 365j à la même date sur autres années

Retourne aussi les tailles d'échantillon:

- `day_samples`
- `month_samples`
- `year_samples`
- `rolling_samples`

### 5.5 Climatologie (`climat_monthly_stats`, `climat_yearly_stats`)

Agrégats calculés:

- `T` min/max
- `P` min/max
- `RR` max (pluie 1h max)
- `W` max
- `G` max
- `D` min/max
- `A` min/max
- `rain_total` (reconstruit à partir des cumuls journaliers)

### 5.6 Extrêmes journaliers

#### Température min/max du jour (`current_day_temp_range`)

- Fenêtre jour courant `[00:00 ; +1 jour[`
- SQL: `MIN(T)`, `MAX(T)`

#### Heure des extrêmes (`current_day_temp_extreme_times`)

- Min: première occurrence de `T` minimale
- Max: première occurrence de `T` maximale

#### Référence historique du même jour (`current_day_temp_reference`)

- moyenne des min/max journalières du même `MM-DD`
- exclut l'année courante si possible
- fallback toutes années si aucun historique

### 5.7 Vent

#### Min/max vent moyen du jour (`current_day_wind_avg_range`)

- `MIN(W)`, `MAX(W)` sur la journée

#### Rosace des vents (`wind_rose_for_period`)

- 16 secteurs (22.5°)
- calcul à partir de `B` (direction en degrés)
- périodes:
  - `1j` (jour)
  - `1s` (7 jours)
  - `1m` (30 jours)
  - `1a` (365 jours)

Retour:

- `counts[16]`
- `max`
- `total`
- `from`, `to`

### 5.8 Épisode de pluie (`current_day_rain_episode`)

#### Règle métier actuelle

Un épisode de pluie est retenu lorsqu'il pleut au moins `1.0 mm` sur 1h (`RR >= 1.0`).

#### Détails

- Analyse sur hier + aujourd'hui
- Permet d'identifier un épisode démarré la veille
- Retour:
  - `start`
  - `end`
  - `ongoing`
  - `start_is_yesterday`

### 5.9 Tendance de pression (`pressure_trend_snapshot`)

- Fenêtre glissante par défaut: `90 min`
- Compare pression la plus ancienne vs la plus récente de la fenêtre
- Seuil par défaut: `0.5 hPa`

Retour:

- `trend`: `up`, `down`, `stable`, `unknown`
- `delta`

## 6. Intégrations externes (métier enrichi)

### 6.1 Prévisions météo (`inc/forecast.php`)

#### Sources supportées

- `openmeteo`
- `metno` (MET Norway)

#### Sélection multi-source

- plusieurs sources configurables
- ordre défini en settings
- première réponse “disponible” utilisée

#### Données exposées au front

- température actuelle
- type météo + libellé i18n (aujourd'hui / demain)
- min/max aujourd'hui / demain
- probabilité de pluie aujourd'hui / demain (si disponible)
- `source`, `source_url`, `updated_at`

#### Cache / fallback

- cache JSON par source dans `settings`
- TTL `1800s`
- anti-retry (5 min) via `forecast_last_try_*`
- raisons standard:
  - `cache_only`
  - `retry_later`
  - `fetch_failed`
  - `no_data`
  - `no_station_coords`

### 6.2 Température de mer (`inc/sea_temp.php`)

Source:
- Open-Meteo Marine API

Fonctionnement:
- nécessite les coordonnées station
- lit cache `sea_temp_cache_json`
- si autorisé: appel remote
- calcule distance station -> point marin renvoyé (haversine)

Sortie typique:
- `available`
- `value_c`
- `time`
- `distance_km`
- `sea_lat`, `sea_lon`
- `source_url`

### 6.3 METAR (`inc/metar.php`)

#### Stratégie d'acquisition

1. API JSON aviationweather (nearby, bbox/rayon)
2. fallback XML radial
3. fallback par ICAO par défaut (setting)
4. cache `metar_cache_json`

#### Sélection

- choisit le METAR de la station/aéroport le plus proche des coordonnées station

#### Données METAR exposées

- `raw_text`
- `airport_icao`
- `observed_at`
- `distance_km`
- `headline`, `weather`, `sky`
- décodage humain détaillé (FR/EN)

#### Décodage humain (`metar_decode_human`)

Produit des phrases lisibles:
- date/heure UTC
- station AUTO ou non
- vent (direction, noeuds, km/h)
- indice Beaufort
- visibilité (m/km)
- température / point de rosée
- humidité relative
- pression, etc.

## 7. Front office (détail technique)

### 7.1 `index.php` (dashboard live)

Page server-side qui compose plusieurs cards:

- état connexion + dernière mise à jour
- condition météo synthétique
- température actuelle (+ flèche tendance)
- min/max du jour
- station (coordonnées, altitude, lien OSM)
- extrêmes & éphéméride (lever/coucher/durée du jour, tooltips)
- pluviométrie cumulée + deltas historiques
- métriques par typologie
- rosace des vents (périodes 1j/1s/1m/1a)
- prévisions
- température de mer
- METAR + décodage humain

#### Perf optionnelle

`?perf=1` active une instrumentation interne (mesure de durée par sous-bloc) et peut alimenter `Server-Timing`.

### 7.2 `charts.php` + `assets/js/charts.js`

#### Modèle

- PHP prépare `window.METEO_DATA`
- JS canvas custom rend les graphes

#### Graphes rendus

- Température (`T`)
- Humidité (`H`)
- Pression (`P`)
- Pluie (série composite `RR + R`)
- Vent (série composite `max(W, G)`)
- Direction vent (`B`)

#### Fonctionnalités UI

- axes gradués X/Y
- tooltip au survol
- ligne verticale + horizontale de suivi curseur
- responsive (re-render au resize)
- densité d'affichage (`auto`, `compact`, `dense`) persistée en `localStorage`
- watermark en fond (nom du site)

### 7.3 `history.php`

- Affichage tabulaire des mesures sur période
- Pagination
- Export CSV filtré
- Quota export + taille max
- Signature discrète dans le CSV:
  - source
  - date de génération
  - domaine

### 7.4 `climat.php`

- Vue mensuelle / annuelle
- Agrégats climatologiques SQL
- Format de plage `min / max` par métrique

## 8. Back office (détail technique)

### 8.1 Authentification / sécurité

#### Session (`inc/session.php`)

- cookie `meteo13_sid`
- `HttpOnly`
- `SameSite=Strict`
- `Secure` auto selon HTTPS / reverse proxy
- timeout session configurable
- rotation session id

#### CSRF

- token stocké en session
- vérification POST via `require_csrf()`

#### Login + 2FA (`inc/auth.php`)

- auth mot de passe: `password_verify`
- lockout sur `username + IP`
- TOTP chiffré en base (`totp_secret_enc`)
- codes de secours (hashés)
- trusted devices:
  - cookie selector.token
  - token hashé en DB
  - UA hashé
  - rotation du token à chaque usage

### 8.2 Configuration site (`admin-meteo13/site.php`)

Permet de gérer:

- nom du site / titre navigateur
- favicon URL + upload (MIME + taille + stockage protégé)
- email contact
- table météo cible
- locale par défaut
- coordonnées station / altitude / ZIP
- verrouillage de position
- sources de prévision
- ICAO METAR par défaut
- contenu des CGU (rich text, assaini)

### 8.3 Supervision (`health.php`, `logs.php`, `stats.php`)

#### `health.php`

Expose:
- état DB
- dernier relevé
- derniers runs cron
- erreurs 24h
- release tag + source
- position station et mode verrouillage

#### `logs.php`

Affiche `app_logs` avec filtres:
- niveau
- canal
- texte (`message/context`)
- période
- pagination

#### `stats.php`

Statistiques de consultation:
- sessions
- IP uniques
- pages vues
- durées
- top pages
- répartition géographique
- sessions récentes

## 9. Analytics audience & logs front

### 9.1 Tracking audience (`track.php`, `inc/analytics.php`)

#### Front injecté (`front_footer`)

Le script JS:
- génère un `visitor_id` (`localStorage`)
- maintient un `session_token` (`sessionStorage`)
- envoie:
  - `start`
  - `ping` périodique (~15s)
  - `page_end` sur hide/unload

#### Stockage DB (création auto)

- `app_visit_sessions`
- `app_visit_pageviews`
- `app_ip_geo_cache`

#### Données enregistrées

- IP, pays/région/ville (best effort)
- user-agent, langue
- entry/exit path
- page count
- durée de session
- temps passé par page

### 9.2 Logs erreurs navigateur (`front-log.php`)

Le front remonte:
- erreurs JS runtime
- `unhandledrejection`
- erreurs de chargement de ressources

Le serveur journalise en `app_logs` (`channel = front.client`).

Protection:
- rate limit endpoint
- throttling/déduplication côté JS
- throttling session côté serveur

## 10. Crons / traitements batch

### 10.1 `cron/fetch.php`

Rôle:
- fetch Netatmo
- préparation des champs
- upsert dans la table météo
- mise à jour éventuelle des coordonnées station (si non verrouillées)

Sécurité:
- auth par clé cron (`Bearer` ou query fallback)
- lock fichier non bloquant

### 10.2 `cron/daily.php`

Rôle:
- recalcul des champs dérivés sur la journée (et J-1 optionnel)
- `Tmax`, `Tmin`, `D`, `A`

### 10.3 `cron/external.php`

Rôle:
- préchauffage des caches externes (actuellement temp. mer; extensible)
- auth par clé cron dédiée

## 11. Modèle de données applicatif (hors table météo source)

Tables créées par `install/schema.sql`:

- `users`
- `settings`
- `secrets`
- `login_attempts`
- `backup_codes`
- `trusted_devices`
- `app_logs`
- `schema_migrations`

Tables créées dynamiquement (analytics):

- `app_visit_sessions`
- `app_visit_pageviews`
- `app_ip_geo_cache`

## 12. Installation et mise à jour

### 12.1 Installateur (`install/index.php`)

Étapes:

1. checks environnement
2. connexion DB
3. validation table météo (PK `DateTime`)
4. création tables applicatives
5. création admin + 2FA + backup codes
6. saisie credentials Netatmo (optionnel à ce stade)
7. génération clés (master + cron)
8. finalisation (inserts + écriture `config/`)

Fichiers écrits:

- `config/config.php`
- `config/secrets.php`
- `config/installed.lock`

### 12.2 Upgrade (`upgrade.php`)

- migrations SQL versionnées
- suivi dans `schema_migrations`
- mise à jour `settings.app_version`

## 13. Sécurité et robustesse (principes en place)

### Sécurité

- sessions sécurisées
- CSRF admin
- 2FA TOTP + backup codes
- trusted devices
- secrets chiffrés
- admin noindex (`meta` + `X-Robots-Tag`)
- robots bloque admin/install/config/endpoints techniques
- endpoints publics rate-limited
- quotas d'export

### Robustesse

- locks sur crons/refresh async
- logs applicatifs structurés par canal
- fallbacks multi-source externes
- caches persistants pour limiter latence et dépendance réseau
- UI dégradée propre en cas d'absence de données

## 14. Front / UX (aspects transverses)

- rendu HTML server-side (SEO-friendly)
- i18n FR/EN
- unités SI/IMP
- thème clair/sombre + auto
- graphiques canvas responsifs (axes, tooltips, watermark)
- auto-refresh dashboard + refresh asynchrone des caches

## 15. Limites et choix structurants

- Dépendance à la structure de la table météo source (PK `DateTime`)
- Pas de framework => faible overhead, mais nécessité de discipline de revue/tests
- Caches applicatifs stockés dans `settings` (simple, efficace, mais mélange config/cache)
- Crons HTTP (pratique mutualisé) mais moins robustes qu'un cron CLI natif
- Sources externes non garanties => stratégie cache/fallback indispensable

## 16. Pistes d'amélioration (beta/production)

- Ajouter une suite de tests automatisés (calculs + auth + migrations)
- Séparer caches volumineux des settings (table dédiée optionnelle)
- Ajouter rétention configurable pour logs et analytics
- Support de clés cron via header `Authorization: Bearer` (déjà compatible) + possibilité de désactiver les query keys
- Documenter officiellement le dictionnaire de données de la table météo source

## 17. Dictionnaire de données (table météo source `alldata`)

Note: la table source est externe au projet et peut contenir d'autres colonnes. Le dictionnaire ci-dessous couvre les champs consommés/calculés par l'application.

### 17.1 Vue synthétique

| Champ | Type métier | Unité base | Origine | Calculé par l'app | Usage principal |
|---|---|---:|---|---|---|
| `DateTime` | Horodatage mesure | - | Netatmo / ingestion | Non | Axe temporel global |
| `T` | Température air | `°C` | Netatmo | Non | Live, graphiques, climat, historique |
| `Tmax` | Température max jour | `°C` | Cron app | Oui (`cron/daily`) | Live, climat, historique |
| `Tmin` | Température min jour | `°C` | Cron app | Oui (`cron/daily`) | Live, climat, historique |
| `H` | Humidité relative | `%` | Netatmo | Non | Live, graphiques, calculs |
| `D` | Point de rosée | `°C` | Cron app | Oui (`Magnus`) | Historique, climat, métriques |
| `W` | Vent moyen | `km/h` | Netatmo | Non | Live, graphiques, climat |
| `G` | Rafale | `km/h` | Netatmo | Non | Live, graphiques, climat |
| `B` | Direction vent (provenance) | `°` | Netatmo | Non | Live, rosace, graphiques |
| `RR` | Pluie 1h / incrément | `mm` | Netatmo | Non | Live, graph pluie, épisodes |
| `R` | Cumul pluie jour | `mm` | Netatmo | Non | Cumuls pluie jour/mois/an |
| `P` | Pression | `hPa` | Netatmo | Non | Live, graphique, tendance pression |
| `A` | Température apparente | `°C` | Cron app | Oui | Historique, climat, métriques |
| `S` | Champ station (legacy) | N/A | Table source | Non | Historique (affichage brut) |

### 17.2 Détail par champ

#### `DateTime`

- Rôle:
  - clé primaire fonctionnelle de la table
  - axe temporel de toutes les séries
- Contraintes attendues:
  - `PRIMARY KEY(DateTime)` (validé à l'installation)
- Alimentation:
  - `cron/fetch.php` écrit une ligne par tranche 5 minutes (arrondie)
- Usages:
  - tri de la dernière mesure (`latest_row`, `latest_rows`)
  - filtres période (`period_rows`, climat, stats du jour)
  - libellés graphiques et historique

#### `T` (température)

- Signification: température de l'air mesurée
- Unité en base: `°C`
- Source: Netatmo (module extérieur)
- Usages:
  - affichage live (température actuelle)
  - tendance température (comparaison à la mesure précédente)
  - min/max jour (calcul SQL)
  - climatologie (`MIN/MAX`)
  - calculs `D` (point de rosée) et `A` (temp apparente)
  - graphiques `T`

#### `Tmax` / `Tmin`

- Signification: extrêmes journaliers recopiés sur chaque ligne du jour
- Unité en base: `°C`
- Source:
  - initialement remplis au `cron/fetch` avec `T`
  - consolidés par `cron/daily.php`
- Règle:
  - `cron/daily.php` recalcule les extrema de la journée et les applique à chaque ligne du jour
- Usages:
  - historique tabulaire
  - climatologie (plages par mois/année)

#### `H` (humidité relative)

- Signification: humidité relative
- Unité en base: `%`
- Source: Netatmo
- Usages:
  - classification météo heuristique (nuageux / ensoleillé)
  - calcul `D` / `A`
  - graphiques `H`
  - historique / métriques

#### `D` (point de rosée)

- Signification: point de rosée calculé
- Unité en base: `°C`
- Calcul:
  - formule de Magnus (`dew_point_magnus`)
  - dépend de `T` et `H`
- Producteur:
  - `cron/fetch.php` (au fil de l'eau)
  - `cron/daily.php` (recalcul/consolidation)
- Usages:
  - historique
  - climatologie (min/max)
  - métriques par typologie

#### `W` (vent moyen)

- Signification: vitesse moyenne du vent
- Unité en base: `km/h`
- Source: Netatmo (module vent)
- Usages:
  - card live / métriques
  - min/max vent moyen du jour
  - climatologie (`MAX(W)`)
  - graphiques (composite vent avec `G`)

#### `G` (rafale)

- Signification: vitesse de rafale
- Unité en base: `km/h`
- Source: Netatmo (module vent)
- Usages:
  - card live / métriques
  - climatologie (`MAX(G)`)
  - graphiques (composite vent)

#### `B` (direction vent)

- Signification métier:
  - direction du vent en degrés (provenance)
- Unité en base: `°` (0-360)
- Source: Netatmo (module vent)
- Usages:
  - affichage orientation (texte + flèche/boussole)
  - rosace des vents (`wind_rose_for_period`)
  - graphique `B` (direction dans le temps)
- Notes UI:
  - l'orientation texte affichée peut être exprimée comme provenance (`De secteur N`)
  - l'icône/flèche peut représenter le sens physique du flux (vers où il va)

#### `RR` (pluie 1h / incrément)

- Signification:
  - intensité/cumul court terme (selon mapping Netatmo existant dans la table)
- Unité en base: `mm`
- Source: Netatmo (module pluie)
- Usages:
  - graphes pluie
  - climatologie `RR max`
  - détection épisode pluie (`RR >= 1.0 mm` sur 1h)
  - fallback de calculs pluie si `R` indisponible

#### `R` (cumul pluie jour)

- Signification:
  - cumul journalier pluie (Netatmo)
- Unité en base: `mm`
- Source: Netatmo (module pluie)
- Usages:
  - total jour (via `MAX(R)`)
  - reconstruction des cumuls mois/année/365j (agrégés par jour)
  - graph pluie (série affichée / combinée)
- Règle métier importante:
  - l'app privilégie `MAX(R)` pour la robustesse du cumul journalier
  - fallback sur `SUM(RR)` si `R` absent/incomplet

#### `P` (pression)

- Signification: pression atmosphérique
- Unité en base: `hPa`
- Source: Netatmo
- Usages:
  - card live / métriques
  - tendance de pression (`pressure_trend_snapshot`)
  - graphique `P`
  - climatologie (plage min/max)

#### `A` (température apparente)

- Signification: température ressentie / apparente calculée
- Unité en base: `°C`
- Calcul:
  - `apparent_temp(T, H, W)`
- Producteur:
  - `cron/fetch.php`
  - `cron/daily.php`
- Usages:
  - historique
  - climatologie
  - métriques par typologie

#### `S` (champ legacy / source)

- Signification:
  - champ présent dans la table source, non recalculé par l'application
- Unité:
  - non normalisée par l'application (affichage brut)
- Usages:
  - affichage dans `history.php` (colonne `S`)
- Note:
  - la sémantique dépend de votre schéma historique source

### 17.3 Conversion d'unités (affichage)

La base est supposée en unités SI. Les conversions sont réalisées uniquement à l'affichage (`inc/units.php`):

- Température (`T`, `Tmax`, `Tmin`, `D`, `A`): `°C -> °F`
- Pression (`P`): `hPa -> inHg`
- Vent (`W`, `G`): `km/h -> mph`
- Pluie (`RR`, `R`): `mm -> in`
- `H`: `%` inchangé
- `B`: `°` inchangé

### 17.4 Écrans consommateurs (par champ)

#### Dashboard (`index.php`)

- `T`, `H`, `W`, `G`, `B`, `RR`, `R`, `P`
- extrêmes jour: `T` (min/max + horaires)
- rosace: `B`
- pluie: `R`, `RR`
- tendances:
  - température via `T` (delta mesure précédente)
  - pression via `P` (fenêtre glissante)

#### Graphiques (`charts.php`)

- Séries directes: `T`, `H`, `P`, `RR`, `R`, `W`, `G`, `B`
- Séries composites UI:
  - pluie: `RR + R`
  - vent: `max(W, G)`

#### Historique (`history.php`)

- `DateTime`, `T`, `Tmax`, `Tmin`, `H`, `D`, `W`, `G`, `B`, `RR`, `R`, `P`, `S`, `A`

#### Climat (`climat.php`)

- Agrégats issus de:
  - `T`, `P`, `RR`, `W`, `G`, `D`, `A`
  - pluie totale reconstruite via `R`/`RR`

## 18. Annexe SQL / performance

Cette section décrit les requêtes importantes, leur coût probable et les leviers d'optimisation.

### 18.1 Hypothèses de volumétrie

Avec une mesure toutes les 5 minutes:

- ~288 lignes / jour
- ~8 640 lignes / mois (30j)
- ~105 000 lignes / an

La table reste exploitable en mutualisé, mais certaines requêtes analytiques (surtout climat/pluie historique) deviennent sensibles sans index adaptés.

### 18.2 Requêtes fréquentes (temps réel / front)

#### Dernière mesure

- `latest_row()` / `latest_rows()`
- Pattern:
  - `ORDER BY DateTime DESC LIMIT n`
- Coût:
  - faible si `DateTime` est PK / indexé (ce qui est requis)

#### Périodes graphiques / historique

- `period_rows()` / `period_rows_between()`
- Pattern:
  - `WHERE DateTime BETWEEN :f AND :to ORDER BY DateTime ASC`
- Coût:
  - correct si index sur `DateTime` (PK)
  - volume potentiellement élevé sur `365d` ou custom large

#### Min/max du jour / vent du jour

- `current_day_temp_range()`
- `current_day_wind_avg_range()`
- Pattern:
  - `WHERE DateTime >= day_start AND DateTime < day_end`
- Coût:
  - bon si filtre intervalle sur `DateTime` (évite `DATE(DateTime)` sur colonne)

#### Heures de min/max du jour

- `current_day_temp_extreme_times()`
- Pattern:
  - sous-requêtes triées par `T` puis `DateTime`
- Coût:
  - modéré (fenêtre journalière seulement)

#### Tendance pression

- `pressure_trend_snapshot()`
- 2 requêtes:
  - plus ancien et plus récent `P` sur fenêtre glissante
- Coût:
  - bon (fenêtre courte)

### 18.3 Requêtes analytiques (plus coûteuses)

#### Cumuls pluie (`rain_totals`)

- Requêtes de type:
  - `GROUP BY DATE(DateTime)` puis `SUM(day_total)`
- Problème classique:
  - `DATE(DateTime)` empêche l'usage optimal de l'index sur `DateTime` pour certaines optimisations
- Mitigation actuelle:
  - cache 120s (`rain_totals_cache_json`)

#### Références pluie historiques (`rain_reference_averages`)

- Plusieurs agrégats imbriqués:
  - moyenne même jour (`MM-DD`)
  - moyenne même mois
  - moyenne YTD même date
  - rolling 365 sur autres années (partie PHP après extraction de cumuls journaliers)
- Coût:
  - potentiellement élevé sur longues historiques
- Mitigation actuelle:
  - cache persistant en `settings`

#### Climat mensuel/annuel

- Agrégations `GROUP BY DATE_FORMAT(...)`, `YEAR(...)`, `MONTH(...)`
- Coût:
  - modéré à élevé selon volumétrie
- Mitigation:
  - usage interactif ponctuel (pas à chaque refresh live)

### 18.4 Requêtes externes et coût réseau

#### Prévisions (`forecast.php`)

- HTTP vers Open-Meteo / MET Norway
- Timeouts courts (2-5s)
- Cache TTL 1800s + anti-retry 300s

#### Température de mer (`sea_temp.php`)

- HTTP Open-Meteo marine
- Timeouts très courts (1-2s)
- Cache TTL 1800s + anti-retry 300s

#### METAR (`metar.php`)

- Plusieurs tentatives/fallbacks possibles (JSON, XML, ICAO)
- Peut devenir bruyant/instable selon source distante
- Cache + anti-retry limitent l'impact sur la page live

### 18.5 Caches applicatifs (actuels)

Le projet stocke plusieurs caches JSON directement dans la table `settings`.

#### Caches métier / front

- `rain_totals_cache_json`
- `rain_refs_cache_json`
- `current_day_temp_ref_cache_json`
- `sea_temp_cache_json`
- `metar_cache_json`
- `forecast_cache_json_*` (par source)

#### Métadonnées de tentative / throttling

- `*_last_try`
- `front_cache_refresh_last_try`
- `front_cache_refresh_last_done`

#### Avantages

- simplicité de déploiement (pas de Redis / FS cache dédié)
- persistance entre requêtes
- compatible mutualisé

#### Limites

- mélange config métier + cache technique dans `settings`
- table `settings` devient un “KV store” opportuniste
- pas de TTL natif, TTL géré dans le code

### 18.6 Mesure de performance intégrée

Le dashboard live supporte un mode de mesure ponctuelle (`?perf=1`) avec timings par sous-bloc:

- ex: `rain_totals`, `rain_reference_averages`, `current_day_temp_range`, `metar_cached`, `forecast_cached`, etc.

Objectif:
- identifier les calculs les plus coûteux
- valider l'efficacité des caches
- alimenter l'optimisation avant beta

### 18.7 Index recommandés (table météo source)

### Minimum (indispensable)

- `PRIMARY KEY (DateTime)`  
  Déjà requis par l'installation.

### Recommandés (si volumétrie > 1 an et perfs qui se dégradent)

Comme la plupart des requêtes filtrent sur `DateTime`, l'index PK couvre déjà beaucoup. Les gains supplémentaires se jouent surtout sur des usages analytiques spécifiques:

- index secondaire sur `T` (facultatif)
  - utile uniquement si les requêtes “heure des extrêmes” deviennent coûteuses (peu probable car fenêtre jour)
- index secondaire sur `P, DateTime` (facultatif)
  - peut aider certaines lectures de tendance si la volumétrie est élevée
- index secondaire sur `B, DateTime` (facultatif)
  - intérêt limité pour la rosace (fenêtre + simple lecture)

En pratique, avant d'ajouter des index:

1. mesurer (`?perf=1`)
2. identifier le vrai goulot
3. n'ajouter que les index justifiés (mutualisé => écrire moins, indexer juste)

### 18.8 Optimisations SQL structurelles possibles (futures)

#### Option A - Table de cumuls journaliers matérialisés

Créer une table de consolidation journalière (ex: `daily_stats`) alimentée par `cron/daily`:

- date
- rain_day_total
- t_min / t_max
- w_min / w_max
- p_min / p_max
- etc.

Effet:
- accélère fortement `rain_totals`, `rain_reference_averages`, `climat`
- réduit les `GROUP BY DATE(DateTime)` répétés

#### Option B - Colonnes générées (si hébergement/DB compatible)

Ajouter des colonnes indexables dérivées:

- `date_only` (`DATE(DateTime)`)
- `year_num`, `month_num`, `month_day`

Effet:
- simplifie et accélère certaines agrégations historiques
- mais modifie le schéma métier source (souvent non souhaité dans votre contexte)

#### Option C - Cache dédié (table ou fichiers)

Séparer les caches techniques de `settings`:

- table `app_cache(key, value_json, expires_at, updated_at)`

Effet:
- meilleure gouvernance des caches
- nettoyage/rétention plus simple
- `settings` reste purement configuration

### 18.9 Points de vigilance performance (front)

- `history.php?period=365d` + export CSV:
  - volumétrie élevée
  - quota et taille max déjà en place
- `index.php` sans cache chaud:
  - plusieurs modules externes peuvent déclencher des timeouts
  - le design prévoit des fallbacks (cache_only / retry_later)
- `charts.php` sur période custom large:
  - beaucoup de points => coût rendu canvas et lisibilité
  - atténué par le mode densité et les graduations adaptatives

### 18.10 Checklist d'audit performance (opérationnelle)

- [ ] Mesurer `index.php?perf=1` cache froid / cache chaud
- [ ] Mesurer `rain_reference_averages` sur la vraie volumétrie
- [ ] Mesurer `current_day_temp_extreme_times` et `wind_rose_for_period(1a)`
- [ ] Vérifier taille de `settings` (caches JSON) dans le temps
- [ ] Vérifier volume `app_logs` / `app_visit_*` (rétention)
- [ ] Tester export CSV sur `365d`
- [ ] Tester charge cron (`fetch`, `daily`, `external`) sur 7 jours
