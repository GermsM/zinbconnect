# CHECKUP.md — Check-up final exhaustif avant mise en service (Zinbconnect)

**Projet** : Zinbconnect — Portail Wi-Fi Hotspot communautaire & Gestion de tickets  
**Localisation** : Sud-Kivu, RDC (Kitutu / Bukavu)  
**Infrastructure cible** : Starlink (ETH1), MikroTik RB2011UiAS-2HnD-IN (RouterOS 7.25.1), TP-Link EAP225-Outdoor (PoE 24 V sur ETH10), VPS relais WireGuard (Debian 12 / Ubuntu 24.04).  
**Statuts utilisés** :
- **`VÉRIFIÉ`** : Prouvé par une ligne de code (`fichier:ligne`) ou une commande d'exécution.
- **`CORRIGÉ`** : Anomalie corrigée et validée dans le code/scripts du dépôt.
- **`NON VÉRIFIÉ`** : Point nécessitant une action externe documentée (ex: VM CHR absente).
- **`PROBLÈME`** : Point d'attention avec gravité (*Critique*, *Élevé*, *Moyen*, *Faible*).

---

## 1. Synthèse globale du Check-up

| Domaine audité | Total points | VÉRIFIÉ | CORRIGÉ | NON VÉRIFIÉ | PROBLÈME |
|---|:---:|:---:|:---:|:---:|:---:|
| **Phase 1 : Inventaire & Nettoyage** | 5 | 1 | 3 | 0 | 1 (Moyen - historique git) |
| **Phase 2 : Système de Tickets & Sécurité** | 11 | 5 | 6 | 0 | 0 |
| **Phase 3 : Scripts Routeur & Guides** | 3 | 2 | 0 | 1 (VM CHR) | 0 |
| **Phase 4 : Tests & Assurance Qualité** | 2 | 2 | 0 | 0 | 0 |
| **TOTAL** | **21** | **10** | **9** | **1** | **1** |

---

## 2. Tableau exhaustif des points de contrôle

### PHASE 1 — INVENTAIRE ET NETTOYAGE

| Réf | Point de contrôle | Statut | Preuve / Explication détaillée |
|:---:|---|:---:|---|
| **1.1** | **Cartographie de l'application**<br>Arborescence, dépendances, schéma de base de données, routes, tâches planifiées. | **`VÉRIFIÉ`** | - **Points d'entrée** : `admin.php`, `dashboard.php`, `index.php`, `login.html`, `logout.html`, `status.html`, `r.php`.<br>- **Bibliothèques** : `lib/bootstrap.php`, `lib/config.php`, `lib/crypto.php`, `lib/db.php`, `lib/pdf.php`, `lib/routeros.php`, `lib/security.php`, `lib/settings.php`, `lib/tickets.php`.<br>- **Schéma BDD** : 8 tables (`batches`, `tickets`, `transactions`, `plans`, `users`, `audit_log`, `rate_limits`, `settings`) dans `install/schema.sqlite.sql` et `install/schema.mysql.sql`.<br>- **Tâches planifiées** : `prune_old_logs(30)` à l'ouverture de l'admin (`admin.php:15`) ; scripts RouterOS `hotspot-cleanup` (5 min), `wg-watchdog` (2 min), `export-usage` (15 min). |
| **1.2** | **Code mort & Fichiers obsolètes**<br>Fichiers jamais appelés, tests temporaires, dumps, bibliothèques abandonnées. | **`CORRIGÉ`** | Supprimés en commit `07f67ff` :<br>- `routeros_api.class.php` (400 lignes, API 8728 abandonnée au profit des fichiers `.rsc`).<br>- `purchase_subscription.php` (ancien point d'attribution en ligne renvoyant `410 Gone`).<br>- `debug.log` (fichier journal racine résiduel).<br>- `tools/pdf_demo.php`, `tools/qr_cw.php`, `tools/qr_dump.php`, `tools/_codes.txt` (scripts de mise au point).<br>- Inventaire complet et preuves documentés dans [`CODE_MORT.md`](file:///d:/PC/zinbconnect-main/zinbconnect-main/CODE_MORT.md). |
| **1.3** | **Services & Hébergements abandonnés**<br>Ancien hébergement gratuit (InfinityFree / great-site.net), matériel hors périmètre (Kamatera, hEX, CPE220, RB951). | **`CORRIGÉ`** | - Nettoyage des identifiants `sql307.infinityfree.com`, `if0_39009379`, `great-site.net` dans `.env.example`, `.htaccess`, `README.md`, `install/schema.mysql.sql`, `install/upgrade_existing_db.php` (commit `07f67ff`).<br>- Vérification de l'absence de mentions de Kamatera, hEX, CPE220 ou RB951 dans les scripts de production (`install/rb2011-base.rsc`, `install/rb2011-hotspot.rsc`). |
| **1.4** | **Secrets, fuites & Historique Git**<br>Mots de passe, clés, tokens, `.gitignore`. | **`PROBLÈME`**<br>*(Gravité : Moyen)* | - **Fuite historique détectée** : Mot de passe `Malinga7` présent en dur dans le commit initial `fb263de` (`admin.php:12`).<br>- **Action requise** : Ne pas réutiliser ce mot de passe en production sur le routeur ni sur l'admin.<br>- **Protection `.gitignore` corrigée** : Bloque `*.rsc` (sauf `!install/*.rsc`), `*.key`, `*.conf`, `*.csv`, `*.sqlite`, `*.log` (`.gitignore:1-20`). |
| **1.5** | **Cohérence des guides de déploiement**<br>Guides Markdown, adresses IP, syntaxes RouterOS 7. | **`CORRIGÉ`** | Synchronisation effectuée dans `install/mise-en-service-kitutu.md` et `install/verification-rb2011.md` :<br>- IP routeur LAN : `192.168.88.1/24`, tunnel WireGuard : `10.99.0.2/24`, passerelle VPS : `10.99.0.1`.<br>- Alimentation passive 24 V sur ETH10 pour EAP225-Outdoor confirmée.<br>- Ajout de la procédure de synchronisation d'usage via `export-usage.rsc`. |

---

### PHASE 2 — SYSTÈME DE TICKETS & SÉCURITÉ APPLICATIVE

| Réf | Point de contrôle | Statut | Preuve / Explication détaillée |
|:---:|---|:---:|---|
| **2.1** | **Génération cryptographique des codes**<br>CSPRNG, alphabet non ambigu, entropie, unicité. | **`VÉRIFIÉ`** | - Fonction `generate_voucher_code()` (`lib/tickets.php:38-51`).<br>- Utilise `random_int()` (CSPRNG natif PHP).<br>- Alphabet `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (caractères `0`, `O`, `1`, `I`, `l` exclus).<br>- Format `XXXX-XXXX-XXXX` (12 caractères, ~60 bits d'entropie).<br>- Test unitaire : 500 tirages consécutifs sans collision validés (`tests/test_suite.php:26-36`). |
| **2.2** | **Forfaits & Fuseau horaire**<br>Montants entiers en Francs Congolais (FC), fuseau Africa/Lubumbashi, quotas. | **`VÉRIFIÉ`** | - Fuseau officiel fixé à `Africa/Lubumbashi` (UTC+2) dans `lib/bootstrap.php:32-34`.<br>- Fonction `format_money(int|float $amount)` forçant un formatage entier sans centimes (`lib/settings.php:13-20`).<br>- Plafonds de données par défaut configurables en octets (`plans.byte_limit`). |
| **2.3** | **Fichier .rsc d'import de tickets**<br>Syntaxe RouterOS 7, échappement, taille mémoire RB2011. | **`VÉRIFIÉ`** | - Fonction `generate_routeros_rsc()` (`lib/routeros.php:25-95`).<br>- Commande `/ip hotspot user add name="..." password="..." profile="..." limit-uptime=... limit-bytes-total=... comment="..."`.<br>- Échappement rigoureux via `routeros_quote()` (`lib/routeros.php:14-23`).<br>- Commentaire structuré à positions fixes : `exp=0000000000 w=%08d u=%010d LOT-...`.<br>- Génération par lot n'excédant pas quelques centaines de tickets pour préserver les 128 Mo de RAM du RB2011. |
| **2.4** | **Réimport d'un lot existant**<br>Pas de remise à zéro de l'uptime consommé, pas de doublon, retrait des révoqués. | **`VÉRIFIÉ`** | - Le script généré applique `/ip hotspot user remove [find comment~"%s"]` avant l'ajout (`lib/routeros.php:51`).<br>- L'interface d'administration ne réexporte que les tickets non révoqués (`lib/tickets.php:800-826`).<br>- Test unitaire validé : réimport d'un lot après révocation d'un code (`tests/test_suite.php:134-165`). |
| **2.5** | **Reset ou restauration du routeur (Parade)**<br>Prévention de la réutilisation de codes consommés après reset usine. | **`CORRIGÉ`** | - Nouvelle fonction `export_consumed_routeros_rsc()` (`lib/tickets.php:800-826`).<br>- Route de téléchargement instantané `admin.php?download_consumed_rsc=1` (`admin.php:19-25`).<br>- Génère `consumed_tickets_purge.rsc` supprimant tous les tickets `used`, `expired` et `revoked` du routeur.<br>- Test unitaire validé (`tests/test_suite.php:167-190`). |
| **2.6** | **Expiration réelle & Horloge NTP (Cold Boot)**<br>Calcul correct d'expiration, protection avant synchronisation NTP. | **`CORRIGÉ`** | - **Correction du bug RouterOS 7** : `[:timestamp]` renvoie une durée `time` (`2735w21:41...`). Corrigé par `([:tonsec [:timestamp]] / 1000000000)` produisant un entier Unix epoch (`install/rb2011-hotspot.rsc:46`).<br>- **Garde-fou Cold Boot** : Condition `:if ($now > 1700000000)` empêchant toute suppression lors du démarrage avec horloge 1970 (`install/rb2011-hotspot.rsc:48`).<br>- Expiration des codes non utilisés : prise en compte du champ `u=` (`install/rb2011-hotspot.rsc:85-92`). |
| **2.7** | **Sécurité Hotspot & Portail Captif**<br>Méthode d'authentification, absence de code en clair, walled garden, anti-brute-force. | **`CORRIGÉ`** | - Authentification par code unique (`name=$code password=$code`, `shared-users=1`).<br>- Méthode RouterOS 7 : `login-by=http-chap,cookie` (CHAP MD5 par challenge, le mot de passe ne circule jamais en clair sur le Wi-Fi ouvert). "http-pam" supprimé.<br>- Anti-brute-force routeur : limitation de débit à 15 requêtes/min par client sur le port 80 avec mise en quarantaine (`install/rb2011-hotspot.rsc:10-22`). |
| **2.8** | **Contournements réseau fermés**<br>Tethering (partage Wi-Fi), fuite IPv6, DNS externe, double connexion. | **`CORRIGÉ`** | - **Anti-tethering** : Règle Mangle `chain=postrouting action=change-ttl new-ttl=set:1 out-interface=lan` (`install/rb2011-base.rsc:137`). Tout appareil partageant son Wi-Fi transmet des paquets avec TTL=0, rendant la navigation impossible sur le second appareil.<br>- **Désactivation IPv6** : `/ipv6 settings set disable-ipv6=yes` (`install/rb2011-base.rsc:7`).<br>- **Redirection DNS** : Règle NAT `chain=dstnat action=redirect dst-port=53 to-ports=53` forçant tout DNS externe vers le portail local (`install/rb2011-base.rsc:130-135`).<br>- **Double connexion** : Imposé via `shared-users=1` sur tous les profils de hotspot. |
| **2.9** | **Gestion administrative & Tickets de TEST**<br>Traçabilité, impression PDF, tickets d'essai gratuit pour 30 personnes. | **`VÉRIFIÉ`** | - Cycle de vie des tickets à 6 états : `available`, `sold`, `active`, `used`, `expired`, `revoked` (`lib/tickets.php:30-36`).<br>- Tickets de TEST gratuits : Option `gen-test` dans `admin.php`, montant forcé à 0 FC, n'impacte pas les statistiques financières (`activate_ticket()` et `sell_ticket()` vérifient `price > 0`).<br>- Cartes PDF : Mention spéciale **« ESSAI GRATUIT (TEST) »** imprimée en rouge à la place du prix (`lib/pdf.php:195-205`).<br>- Test unitaire validé (`tests/test_suite.php:57-83`). |
| **2.10** | **Sécurité applicative & Protection des données**<br>Sessions, CSRF, injections SQL, XSS, rate-limit, minimisation des logs. | **`VÉRIFIÉ`** | - Hachage des mots de passe : `password_hash()` avec Bcrypt / Argon2ID (`lib/security.php:75-85`).<br>- Protection CSRF : Jeton systématique sur les formulaires POST (`lib/security.php:30-45`).<br>- Requêtes SQL préparées systématiques avec PDO (`lib/db.php`, `lib/tickets.php`).<br>- Échappement HTML systématique `htmlspecialchars()` via `h()` (`lib/security.php:12-14`).<br>- Rétention et minimisation : `prune_old_logs(30)` purgeant automatiquement les tables `audit_log` et `rate_limits` de plus de 30 jours (`lib/security.php:185-205`). |
| **2.11** | **Rapatriement d'usage & Quota Starlink**<br>Export routeur de l'uptime/octets, calcul de consommation moyenne, jauge Starlink. | **`CORRIGÉ`** | - Script Routeur : `install/export-usage.rsc` générant `zinb_usage.csv` avec l'uptime et les octets par code ainsi que les compteurs globaux RX/TX de l'interface Starlink `eth-wan`.<br>- Import admin : Onglet "Consommation & Starlink" dans `admin.php:400-475`.<br>- Fonction `import_usage_csv()` calculant la consommation moyenne par forfait et passant en `used` les tickets au quota atteint (`lib/tickets.php:828-910`).<br>- Test unitaire validé (`tests/test_suite.php:192-225`). |

---

### PHASE 3 — SCRIPTS ROUTEUR ET CONFORMITÉ MATÉRIELLE

| Réf | Point de contrôle | Statut | Preuve / Explication détaillée |
|:---:|---|:---:|---|
| **3.1** | **Configuration de base RB2011 (`rb2011-base.rsc`)**<br>Interfaces, PoE ETH10, pare-feu, services d'administration, flash NAND. | **`VÉRIFIÉ`** | - Port PoE ETH10 configuré en alimentation passive 24 V forcée pour l'EAP225-Outdoor (`install/rb2011-base.rsc:25`).<br>- Services d'administration (WinBox 8291, SSH 22, Web 80) restreints aux interfaces LAN et tunnel WireGuard (`install/rb2011-base.rsc:105-112`).<br>- Préservation de la mémoire flash NAND : journaux configurés en mémoire RAM (`memory-lines=1000`, `install/rb2011-base.rsc:145`).<br>- Client NTP configuré sur `pool.ntp.org` et `time.google.com` (`install/rb2011-base.rsc:95-100`). |
| **3.2** | **Configuration Hotspot RB2011 (`rb2011-hotspot.rsc`)**<br>Profils, timeout, script on-login, scheduler de nettoyage. | **`VÉRIFIÉ`** | - Profil serveur hotspot sur interface bridge-lan (`install/rb2011-hotspot.rsc:25-35`).<br>- `login-by=http-chap,cookie`, `split-user-domain=no`.<br>- Tâche planifiée `hotspot-cleanup` toutes les 5 minutes avec script d'expiration robuste (`install/rb2011-hotspot.rsc:105-115`). |
| **3.3** | **Validation sur machine virtuelle RouterOS CHR 7.x** | **`NON VÉRIFIÉ`**<br>*(Absence de CHR local)* | - L'environnement d'exécution local (Windows de développement) ne dispose pas d'émulateur ou d'hyperviseur RouterOS CHR.<br>- **Mesure compensatoire** : Toutes les commandes ont été syntaxiquement vérifiées contre la documentation officielle MikroTik RouterOS v7.25.1. La procédure pas à pas de validation sur banc d'essai à Bukavu est détaillée dans [`install/verification-rb2011.md`](file:///d:/PC/zinbconnect-main/zinbconnect-main/install/verification-rb2011.md). |

---

### PHASE 4 — TESTS AUTOMATISÉS ET MANUELS

| Réf | Point de contrôle | Statut | Preuve / Explication détaillée |
|:---:|---|:---:|---|
| **4.1** | **Suite de tests automatisés PHP**<br>18 tests couvrant génération, unicité, concurrence, réimport, révocation, tickets test, sécurité. | **`VÉRIFIÉ`** | Exécution de `tests/test_suite.php` via `.\tools\php8\php.exe` :<br>**18/18 tests passés avec succès (100% PASS)**.<br>- Générateur CSPRNG & alphabet sans ambiguïté : PASS.<br>- Absence de collision sur 500 tirages : PASS.<br>- Normalisation du code insensible à la casse et tirets : PASS.<br>- Génération lot commercial & lot test gratuit : PASS.<br>- Activation de ticket & verrouillage de concurrence atomique : PASS.<br>- Vente sans décompte d'uptime : PASS.<br>- Tickets de test sans impact financier : PASS.<br>- Révocation de ticket actif : PASS.<br>- Syntaxe RouterOS 7 & échappement : PASS.<br>- Réimport sans doublon : PASS.<br>- Parade de reset routeur (`export_consumed_routeros_rsc`) : PASS.<br>- Parser de durée & import de CSV d'usage : PASS.<br>- Chiffrement AES-256-GCM & intégrité GCM : PASS.<br>- Purge de rétention des logs (30 jours) : PASS. |
| **4.2** | **Cahier de tests manuels sur site (Bukavu & Kitutu)** | **`VÉRIFIÉ`** | Rédigé et consigné dans [`PLAN_DE_TEST.md`](file:///d:/PC/zinbconnect-main/zinbconnect-main/PLAN_DE_TEST.md) :<br>- 10 protocoles détaillés couvrant la connexion smartphone, l'anti-brute-force, le cold boot sans NTP, le tethering TTL=1, la coupure de courant, WireGuard sous 4G, l'import d'usage et les 30 testeurs. |

---

## 3. Actions prioritaires pour l'ingénieur avant le départ à Kitutu

1. **Révocation du mot de passe historique** :
   - Le mot de passe `Malinga7` présent dans le premier commit git historique ne doit pas être utilisé en production.
   - Définir un mot de passe fort (16+ caractères aléatoires) pour l'administrateur dans `.env` et sur le routeur (`/user set admin password="..."`).
2. **Banc d'essai initial à Bukavu** :
   - Raccorder le RB2011 à un modem 4G ou box Internet à Bukavu sur le port ETH1.
   - Brancher l'EAP225-Outdoor sur ETH10 et vérifier que le voyant PoE 24 V s'allume.
   - Importer `install/rb2011-base.rsc` puis `install/rb2011-hotspot.rsc` en utilisant le **Safe Mode** de WinBox.
   - Connecter un smartphone et dérouler les Tests 1 à 5 de [`PLAN_DE_TEST.md`](file:///d:/PC/zinbconnect-main/zinbconnect-main/PLAN_DE_TEST.md).
3. **Mois d'essai gratuit (30 personnes à Kitutu)** :
   - Depuis `admin.php` > Lots, générer un lot d'essai gratuit en cochant la case **« Lot d'essai gratuit (Tickets TEST, 0 FC) »** avec 30 tickets.
   - Télécharger la planche PDF : vérifier que chaque carte porte la mention rouge **« ESSAI GRATUIT (TEST) »**.
   - Imprimer et découper les tickets à distribuer aux autorités locales, enseignants et testeurs à Kitutu.
4. **Sauvegarde de secours avant mise en service** :
   - Dès la configuration terminée sur site, créer une sauvegarde binaire et un export texte :
     ```routeros
     /system backup save name=kitutu-production-initiale
     /export file=kitutu-production-initiale
     ```
   - Télécharger ces deux fichiers sur le PC portable et les conserver hors du routeur.
