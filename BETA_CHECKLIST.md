# Beta Checklist (alpha -> beta)

Objectif: passer en beta avec un niveau de stabilité mesurable, sans bloquer inutilement le projet.

## 1. Freeze fonctionnel

- [ ] Geler les nouvelles features non critiques (UI/UX “nice to have”)
- [ ] Lister explicitement les modules inclus dans la beta
- [ ] Marquer les fonctions expérimentales (si conservées)

## 2. Critères de sortie alpha (obligatoires)

- [ ] 0 bug bloquant connu (front ou admin)
- [ ] 0 erreur fatale PHP sur 7 jours
- [ ] Cron `fetch`, `daily`, `external` exécutés sans incident bloquant sur 7 jours
- [ ] Temps de préparation `index.php?perf=1` stable (objectif défini)
- [ ] Sauvegarde SQL + restauration testée au moins 1 fois

## 3. Tests automatisés minimaux (priorité haute)

- [ ] Ajouter un dossier `tests/` (même sans framework au départ)
- [ ] Tests calculs météo critiques (pluie, extrêmes, tendances)
- [ ] Tests conversions unités (SI/IMP)
- [ ] Tests parsing/décodage METAR
- [ ] Tests migrations `upgrade.php` (idempotence de base)
- [ ] Tests utilitaires sécurité (rate-limit / helpers)

## 4. Smoke tests manuels (avant chaque beta RC)

- [ ] Installation neuve via `/install/`
- [ ] Connexion admin + 2FA + appareil de confiance
- [ ] Cron HTTP (`fetch`, `daily`, `external`)
- [ ] Page live `index.php`
- [ ] Page `charts.php` (interactions, tooltips, périodes)
- [ ] Page `history.php` (pagination + export CSV)
- [ ] Page `climat.php`
- [ ] Page `terms.php` (encodage UTF-8)
- [ ] Admin `health.php`, `logs.php`, `stats.php`, `backups.php`
- [ ] Changement langue `FR/EN`
- [ ] Changement unités `SI/IMP`
- [ ] Thème clair/sombre + switch manuel

## 5. Observabilité / exploitation

- [ ] Vérifier que les canaux de logs sont cohérents (`cron.*`, `front.*`, `admin.*`)
- [ ] Définir les warnings tolérés (ex: source externe indisponible)
- [ ] Définir les erreurs non tolérées (fatales, DB, migration)
- [ ] Vérifier la lisibilité de `health.php` pour diagnostic rapide
- [ ] Conserver une routine de revue des logs (quotidienne ou hebdo)

## 6. Sécurité / confidentialité (beta)

- [ ] Vérifier HTTPS forcé en production
- [ ] Vérifier 2FA admin actif
- [ ] Vérifier rotation / stockage des secrets Netatmo
- [ ] Vérifier endpoints publics protégés (rate-limit, CSRF si applicable)
- [ ] Vérifier admin non indexé (meta + `X-Robots-Tag` + `robots`)
- [ ] Documenter la collecte d’audience (IP, géoloc approx, pages, durée) dans les CGU
- [ ] Définir une durée de conservation des logs/stats

## 7. Sauvegarde / restauration

- [ ] Export `alldata` testé
- [ ] Export base complète testé
- [ ] Import de restauration testé sur base de test
- [ ] Vérifier intégrité minimale après restore (front OK + admin OK)
- [ ] Documenter la procédure de restauration (10-15 lignes)

## 8. Compatibilité d’environnement (OVH / mutualisé)

- [ ] Valider PHP 8.1+
- [ ] Valider extensions requises (`pdo_mysql`, `curl`, `json`, `openssl`, etc.)
- [ ] Vérifier timeouts curl réalistes pour hébergement mutualisé
- [ ] Vérifier droits d’écriture (`config/`, `logs/`, `assets/uploads/`)
- [ ] Vérifier cron HTTP configurés et sécurisés (clés)

## 9. Release management (beta)

- [ ] Choisir la nomenclature de version beta (`v1.0.0-beta.1`, etc.)
- [ ] Mettre à jour `APP_VERSION` / tag release
- [ ] Publier `config/release_tag.txt` si pas d’accès git côté hébergement
- [ ] Rédiger un changelog court (ajouts, fixes, risques connus)
- [ ] Préparer un plan de rollback (version précédente + backup DB)

## 10. Known issues (acceptables en beta)

- [ ] Lister les dépendances externes instables (METAR / forecast / mer)
- [ ] Lister les dégradations UX acceptées en cas de timeout externe
- [ ] Lister les limites connues (quota export, perf gros historiques, etc.)
- [ ] Associer chaque limite à une mitigation (cache, retry, fallback)

## 11. Go / No-Go beta

- [ ] Tous les points sections 2, 4, 7 sont validés
- [ ] Aucun incident bloquant ouvert
- [ ] Logs 7 jours revus
- [ ] Restore validé
- [ ] Version taggée et déployée en beta

## Notes de suivi

- Date cible beta:
- Version cible:
- Responsable validation:
- Dernière revue checklist:

