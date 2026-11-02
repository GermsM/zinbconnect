# AUDIT.md — Zinbconnect, phase 1 : inspection (aucune modification du code)

> Audit réalisé en lecture seule le 2 novembre 2026. Exécution vérifiée en local avec PHP 8.3.36 (`php -S 127.0.0.1:8090`).
> Constat central : **l'application telle qu'elle est dans ce dépôt ne peut pas démarrer**, et même réparée, **elle ne contrôle jamais réellement l'accès Internet** (aucune intégration MikroTik).

---

## 1. Cartographie

| Fichier | Lignes | Rôle | État vérifié |
|---|---|---|---|
| `index.php` | 1 233 | Portail public : saisie du code, « achat », panel revendeur, API JSON | ❌ Erreur fatale PHP 8 ; API publique non protégée |
| `admin.php` | 1 581 | Tableau de bord admin (activation de codes, transactions, revenus, revendeurs) | ⚠️ Exige des bibliothèques absentes du dépôt |
| `dashboard.php` | 1 146 | Tableau de bord revendeur (activer code, gains, transactions) | ❌ HTTP 500 en local |
| `reseller.php` | 392 | Login revendeur **et** admin (réponse JSON) + page HTML | ⚠️ Rendu OK mais non sécurisé |
| `reseller-s.php` | 351 | Inscription libre d'un revendeur (JSON) + page HTML | ⚠️ Rendu OK mais non sécurisé |
| `success.php` | 687 | Page « Connexion réussie », 100 % HTML/JS sans PHP | ⚠️ Liens cassés, code « template » résiduel |
| `validate_code.php` | 19 | Endpoint JSON de validation d'un code | ❌ Doublon qui casse (fork de index.php) |
| `purchase_subscription.php` | 19 | Endpoint JSON d'achat | ❌ Doublon qui casse (fork de index.php) |
| `routeros_api.class.php` | 438 | Client API MikroTik v1.6 (bibliothèque tierce) | 🟡 **Jamais appelé** par aucun autre fichier |
| `download.png` | — | Favicon | OK |
| `README.md` | 8 | Présentation d'une ligne | Absence totale de doc d'installation |

### Base de données
MySQL chez **InfinityFree** (`sql307.infinityfree.com:3306`). **Cinq fichiers** recréent chacun leur connexion `mysqli` avec leurs propres constantes. Aucun schéma SQL n'est fourni dans le dépôt ; en croisant les requêtes, les tables attendues sont :
- `access_codes(code, …)` — stock de codes vierges (index.php:29, dashboard.php:92)
- `current_codes(code, contact, contact_type, subscription_id, created_at, expiration_date)` — codes actifs (index.php:57, dashboard.php:170, admin.php:294)
- `subscriptions(id, name, rate, duration_days)` — forfaits (index.php:36, dashboard.php:129)
- `transactions(reseller_id, subscription_id, buyer_contact, access_code, amount, commission, created_at)` (dashboard.php:174, admin.php:398)
- `resellers(id, name, phone, email, address, password[haché], status, created_at)` (reseller-s.php:53, dashboard.php:40)
- `admins(id, password[en clair], phone, email)` (reseller.php:27)
- `users(id, name)` — référencée par admin.php:193 alors que le login lit la table `admins` → incohérence probablement résiduelle d'une ancienne version.

### Dépendances externes
- CDN chargés sur presque chaque page : `tailwindcss@2.2.19` via jsDelivr **et** le générateur à la volée `cdn.tailwindcss.com` (double chargement), FontAwesome kit, Chart.js + particles.js (dashboard/admin), `xlsx.full.min.js` chargé **inutilement** sur success.php.
- Modules PHP requis sans condition par admin.php:16-31 : `mysqli, curl, openssl, mbstring`.
- Bibliothèques attendues dans le dossier mais **absentes du dépôt** : `lib/PHPMailer/*`, `lib/Twilio/autoload.php` (admin.php:43-95).

---

## 2. Rôle réel de chaque fichier (et ce qui vous concerne)

- **`reseller.php`** — unique point d'authentification de l'application. Il logue à la fois l'admin (table `admins`, mot de passe en clair) et les revendeurs (table `resellers`, mot de passe haché), puis renvoie en JSON la page de destination (`admin.php` ou `dashboard.php`).
- **`reseller-s.php`** — inscription *publique* comme revendeur, compte créé désactivé (`status=FALSE`) en attente d'approbation. Pour vous : vous êtes le seul admin et vous n'avez pas de revendeurs à recruter en ligne → **supprimable**.
- **`purchase_subscription.php`** — copie de 19 lignes de l'action `purchase_subscription` de `index.php`. Doublon → **supprimable**.
- **`validate_code.php`** — copie de l'action `validate_code` de `index.php` (elle ré-include carrément `index.php`, ce qui exécute tout le portail). Doublon → **supprimable**.
- **`success.php`** — page statique d'après-connexion qui relit le cookie. Contient du code résiduel de template de vente de fichiers XLSX jamais nettoyé. Fondamentalement superflue : son contenu peut être fusionné dans `index.php`.
- **`routeros_api.class.php`** — bibliothèque correcte mais **jamais invoquée** : aucune page n'instancie `RouterosAPI`. **Conclusion : l'application ne parle pas au MikroTik.** La « connexion » d'un client est simulée : redirection vers `https://www.google.com` (index.php:1021, avec le commentaire `// Replace with router API call`). Il n'y a donc aucun contrôle d'accès réel sur le hotspot.

---

## 3. Exécution locale vérifiée (PHP 8.3.36, serveur intégré)

| Requête | Résultat observé |
|---|---|
| `GET /index.php` | **Erreur fatale** `ArgumentCountError: define() expects at least 2 arguments` (index.php:7) |
| `GET /reseller.php` | 200, page de login rendue |
| `GET /reseller-s.php` | 200, page d'inscription rendue |
| `GET /success.php` | 200, mais liens vers `reseller.html` / `index.html` inexistants (success.php:546, 640) |
| `GET /admin.php` | `{"success":false,…,"debug":"Missing PHP modules: mysqli, curl, openssl, mbstring"}` (admin.php:23). Avec les modules installés, planterait ensuite sur les bibliothèques PHPMailer/Twilio absentes. |
| `GET /dashboard.php` | **HTTP 500**, réponse vide (même faille `define()` masquée en silence) |
| `php -l` sur les 9 fichiers PHP | « No syntax errors » partout — le bug passe la compilation mais pas l'exécution |

Cause commune : `define('DB_PORT', )` (avec la virgule finale et aucune valeur) dans index.php:7, dashboard.php:20, admin.php:71. Accepté par le parseur (virgule finale autorisée depuis PHP 7.3), mais `define()` est appelée avec un seul argument → erreur fatale à chaque requête sur ces trois pages.

De plus, dans ces trois fichiers, `DB_HOST/USER/PASS/NAME` sont des chaînes **vides** : les identifiants ont été **retirés avant publication dans ce dépôt** (probablement au moment de mettre « testnet » sur GitHub). Seuls `reseller.php` et `reseller-s.php` ont gardé les vrais identifiants — en dur et publiés (section 4).

---

## 4. Secrets trouvés (⚠️ à traiter avant tout le reste)

| Secret | Emplacement | Analyse |
|---|---|---|
| `DB_USER='if0_39009379'`, `DB_PASS='Malinga7'`, `DB_HOST='sql307.infinityfree.com'`, `DB_NAME='if0_39009379_zinbconnect'` | reseller.php:2-7, reseller-s.php:2-7 — **publié sur un dépôt GitHub public** | Identifiants MySQL complets en clair. Quiconque lit le code source du projet sur GitHub possède un accès complet à la base en production. |
| Mot de passe admin **stocké en clair** dans la table `admins`, comparé avec `===` | reseller.php:26-34 | Le commentaire du code l'assume : `// Check admins table first (plain-text passwords)`. |
| `GMAIL_USERNAME/GMAIL_APP_PASSWORD`, `TWILIO_ACCOUNT_SID/TWILIO_AUTH_TOKEN/TWILIO_PHONE_NUMBER` | admin.php:35-39 (vides dans la copie locale, mais le code d'envoi est actif admin.php:316-350) | Montre que l'envoi d'email/SMS était branché sur des comptes Google/Twilio, non inclus. |
| Numéro de téléphone +243 990034591 et e-mail zinboentreprise@gmail.com | success.php:205,267,337 ; résiduels dans index.php | Coordonnées de contact publiques : à reprendre si vous les gardez. |

Hypothèse historique : le dépôt GitHub d'origine (https://github.com/lilkeymub/zinbconnect) a pu contenir les mêmes identifiants **dans des commits antérieurs**. Ils n'apparaissent pas dans le dépôt actuel mais restent dans l'historique git en ligne si jamais ils y ont été committés. À faire dès maintenant :
1. **Changer le mot de passe MySQL sur InfinityFree** (panneau → MySQL → changer le mot de passe de `if0_39009379`), même si vous prévoyez de migrer ailleurs : un accès complet existant à la base reste une menace.
2. Penser à purger/écraser l'historique du dépôt public si les identifiants y figurent (sinon, rien à purger ici : nous avons initialisé un nouveau dépôt).

---

## 5. Failles classées (avec preuve fichier:ligne)

### 🔴 CRITIQUE
1. **Codes gratuits sans paiement et sans authentification.** `POST /index.php {"action":"purchase_subscription"}` attribue un code réel à n'importe qui (index.php:121-128 → `purchaseSubscription()` index.php:62). Idem `{"action":"assign_code"}` (index.php:129-137) et `POST purchase_subscription.php:9-21`. Rien ne vérifie un paiement mobile money : le bouton « Pay » ouvre un choix de forfait puis obtient le code réel (index.php:1085-1130). Concrètement : le site est public, un script automatisé peut vider votre stock de codes en 1 minute.
2. **Aucun contrôle d'accès Internet réel.** `validate_code` renvoie juste `valid:true` (index.php:95-106) ; ensuite le JS redirige vers google.com (index.php:1021). Aucun appel MikroTik n'existe dans tout le code. Volume encore jamais facturé, pas de durée réelle appliquée. Le hotspot laisserait passer tout le monde sans code si on le branchait tel quel.
3. **Mot de passe admin en clair dans la base + comparaison directe.** reseller.php:26-34. Une fuite SQL = compromission admin totale.
4. **Identifiants MySQL publiés en dur** (voir §4) — accès à tous les codes, contacts d'acheteurs et historique des ventes.

### 🟠 ÉLEVÉ
5. **Incohérence `admins` vs `users`.** Le login interroge `admins` (reseller.php:27) puis `admin.php:193` relit `users`. À unifier, sinon l'accès admin peut échouer ou pointer sur une table obsolète.
6. **CSRF absent partout**, notamment sur le login, l'inscription, l'activation/la création de codes, la modification de forfaits : ni jeton CSRF, ni vérification d'origine. Toutes les actions sensibles restent réalisables sans le moindre jeton.
7. **Sessions/cookies non sécurisés.** `setcookie(..., false, true)` = flag `Secure` manquant partout (reseller.php:37 et 60, admin.php:234, index.php:115-116). Pas de `SameSite` explicite. Pas de `session_regenerate_id()` après connexion (fixation de session possible). Cookie `access_code` + `expiration_date` déposés sans `Secure` (index.php:115-116) → lisible en clair par un tiers qui sniffe le Wi-Fi public, et ces pages sont servies en HTTP par défaut sur InfinityFree.
8. **Données personnelles/techniques exposées.** Toutes les réponses d'erreur JSON embarquent `debug: $e->getMessage()` (ex. admin.php:404, 417, 509 ; dashboard.php:246, 285) → détails internes (requêtes SQL, numéros de téléphone…) visibles par n'importe qui. De plus, `debug.log` est écrit à la racine du dossier web (dashboard.php:26, admin.php:10-22) : il contient codes d'accès + numéros ; une simple requête `GET /debug.log` peut le lire si l'hébergeur le sert.
9. **`success.php` fait confiance au cookie `expiration_date` posé par le serveur mais modifiable côté client** (success.php:545-560, 586) : un utilisateur pouvait écrire lui-même un `expiration_date` futur et passer instantanément l'écran « Connecté ». La ré-vérification re-part d'un `validate_code` serveur ensuite (success.php:565) : la fenêtre d'exploit est faible mais le mécanisme reste faux.

### 🟡 MOYEN
10. **Génération de codes imparfaite.** `random_bytes(8)` → 8 caractères A-Z0-9 (admin.php:146-160) : jeu contient les confusibles 0/O/1/I/L ; modulo 36 biaisé ; et le contrôle d'unicité interroge seulement `current_codes` (admin.php:151-155) sans `FOR UPDATE` sur les deux tables → collision possible avec `access_codes`. Codes aussi affichés **en clair dans debug.log**.
11. **Un code n'est jamais consommé.** `validate_code` n'écrit jamais dans la base : le même code peut être partagé librement entre plusieurs téléphones, sans limite de sessions ni terminaison (index.php:95-106). « Usage unique » n'existe dans aucune requête. La revendication commerciale n'est pas tenue.
12. **Aucun plafond de données (limit-bytes-total).** Rien dans `subscriptions`, aucun calcul de volume ; impossible de protéger le forfait Starlink 500 Go/mois.
13. **Forfaits déconnectés de la réalité.** La modale « Reseller » de index.php affiche une table statique « Premium/VIP/Turbo 12 forfaits, de 1 000 à 30 000 CDF » qui ne correspond pas : ni aux forfaits de la table `subscriptions` (dont les ID 1-12 sont supposés exister), ni à vos 4 tarifs réels (1 000/1 500/2 500/5 000 FC, 1 h/12 h/24 h/3 j). Aussi, la modale « Nearby Resellers » affiche des données d'essai figées (John Doe / Jane Smith, index.php:508-519) : vos visiteurs voient d'éventuels « revendeurs » fictifs à Kinshasa/Goma sur votre page d'accueil.
14. **Commission fixée à 25 % en dur** (dashboard.php:130, admin.php:278) : non configurable, déconnectée de vos tarifs.
15. **admin.php exige `lib/PHPMailer` et `lib/Twilio` absents du dépôt** (admin.php:43-95) : il exécute de préférence leur code et die() sinon. L'abonnement email/SMS était un plan d'envoi de facture ; il doit être réécrit (recommandation : le retirer avec Twilio, tout dans un SVG imprimé).
16. **Démarrage de l'app impossible hors InfinityFree** : constants `DB_HOST/…` vides et `DB_PORT` erroné (cf. §3) : rien ne fonctionne en local ni sur un autre hébergeur tel quel.
17. **CDN comme dépendance runtime** : si `cdn.tailwindcss.com` est indisponible depuis le village, la page reste du texte brut (confirmé : la mise en page entière est **générée par un script distant**). Contrainte directe sur la disponibilité garantée au village.
18. **Pas de `limit-uptime`/`rate-limit` configurés nulle part** dans le code : ils devront être créés dans la partie MikroTik (phase 4).

### 🟢 FAIBLE
19. `<meta name="viewport" gioc-content="…">` — attribut corrompu dans reseller.php (ligne 14) à réparer.
20. Liens `reseller.html` / `index.html` vers des fichiers inexistants (success.php:51, 546, 640).
21. Style : chaînes de traduction recopiées 3 langues × 4 fichiers (~250 lignes/ch) ; `id="bundle-premium"` réutilisé plusieurs fois sur la même page (sélecteurs CSS/JS fragiles).
22. Commentaires « Placeholder » jamais traités (index.php:1021, index.php:1204, success.php:611).

---

## 6. Dette technique

- **Aucun test automatisé, aucune CI, aucun linter, aucun `composer.json`** : tout passe par des vérifications manuelles.
- **Duplication :** `getDBConnection()` recopié dans 5 fichiers, logique d'activation de code recopiée 3 fois (dashboard, admin, index), traînée de CSS/JS de mise en page recopiée dans quasiment chaque page (poids inutile transporté à chaque visite sur le réseau du village).
- **Monolithes** PHP + HTML + JS (700 à 1 600 lignes chacun), sans séparation des responsabilités : impossible d'ajouter « limit-bytes-total » sans retoucher 4 endroits.
- **Deux écritures parallèles de l'accès code** (index/store admin vs dashboard reseller) avec des règles de contact légèrement différentes (les regex du téléphone ne correspondent pas entre dashboard.php:156 et admin.php:263).
- Pas de `.env`, pas de `.gitignore`, pas de `.htaccess`, pas de configuration centrale.
- `debug.log` attendu à la racine du webroot et écrit en permanence (dashboard.php:26, admin.php:160-164) : il doit sortir de la racine web (risque de fuite si l'hébergeur le sert tel quel).
- Session « persistée 30 jours » côté client par simple cookie `admin_id`/`reseller_id` (reseller.php:37, 60) : le cookie seul suffit, indépendamment de PHPSESSID → impossible de révoquer côté serveur.

---

## 7. Ce qui fonctionne et mérite d'être gardé

- **Requêtes préparées quasi systématiques** (`bind_param`) : le projet n'a quasiment aucun risque SQLi résiduel vis-à-vis de l'utilisateur : la base est saine.
- `password_hash()` / `password_verify()` déjà implémentés pour les revendeurs (reseller.php:57, reseller-s.php:44) : la bonne pratique existe, elle n'a juste jamais été appliquée à l'admin.
- `random_bytes()` courant pour les codes (bon réflexe, à durcir).
- Gestion en **transaction avec rollback** pour l'activation/services (dashboard.php:88-217, index.php:24-80) : le squelette est prêt pour une consommation « atomique ».
- Journalisation rationnelle (logDebug, niveaux INFO/ERROR), `match(true)` récents, indice de code clair.
- Base de design Tailwind + i18n (3 langues) + dark mode.

## 8. Version minimale de PHP requise

- `match(true)` (dashboard.php:274, admin.php:388) et `str_contains()` (idem) exigent **PHP 8.0 minimum** ; les documents du projet supposaient 7.2+, obsolète.
- Recommandation : **PHP 8.3** (`mysqli` ou `PDO`, `openssl`, `mbstring`, `curl`), chez un hébergeur récent en HTTPS. InfinityFree supporte PHP 8.x via le panneau, mais il n'ouvre pas de connexions sortantes fiables vers une API MikroTik (voir INTEGRATION.md en phase 4).

---

## 9. Conclusion de la phase 1

1. **Rien n'est exécutable tel quel** : 3 fichiers sur 5 plantent à la première ligne à cause du `define('DB_PORT', )`, les identifiants de la moitié du code ont été supprimés, les bibliothèques de l'admin ne sont pas dans le dépôt.
2. **La promesse « usage unique » n'est pas tenue** : le code est re-vérifiable, repartageable, jamais consommé.
3. **Le paiement mobile money n'est pas interprété** : un visiteur anonyme obtient un code gratuit et réel.
4. **L'intégration MikroTik n'existe pas** : le routeur ne bloque ni ne vend rien.
5. **Le mot de passe admin est stocké en clair** et les accès MySQL sont publics sur GitHub.

Vos actions prioritaires, dans l'ordre : (a) mots de passe/DB **changés sur InfinityFree maintenant**, (b) secrets retirés du code et de l'historique, (c) refonte via une seule fonction de connexion + base réelle, (d) intégration MikroTik via l'API v7, (e) puis simplification/reseller-suppression.
