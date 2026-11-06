# CODE_MORT.md — Inventaire du code mort, fichiers obsolètes et résidus

Ce document recense les fichiers, fonctions et configurations identifiés comme code mort ou résidus d'anciennes architectures dans le projet **Zinbconnect**, avec la preuve formelle de leur inutilité.

Conformément à la règle de sécurité et de traçabilité, le nettoyage s'effectue en deux temps :
1. **Identification et preuve** (ce document).
2. **Suppression dans un commit dédié**, facile à annuler si besoin.

---

## 1. Fichiers à supprimer (avec preuve d'inutilité)

### 1.1 `routeros_api.class.php` (400 lignes)
- **Rôle historique** : Ancien client API RouterOS v1.6 (connexion via le port TCP 8728 de MikroTik).
- **Preuve d'inutilité** :
  - Recherche globale de `RouterosAPI` et `routeros_api.class.php` dans tout le dépôt : **0 occurrence** dans le code applicatif (`lib/`, `admin.php`, `dashboard.php`, `index.php`). Seul `AUDIT.md` le mentionnait comme jamais appelé.
  - L'architecture retenue et déployée est l'import périodique de fichiers `.rsc` (générés par `lib/routeros.php` et importés via New Terminal / WinBox Files). Le port API 8728 est d'ailleurs explicitement désactivé dans `install/rb2011-base.rsc` (`/ip service set api disabled=yes`).
- **Décision** : **À SUPPRIMER**.

### 1.2 `purchase_subscription.php` (24 lignes)
- **Rôle historique** : Ancien point d'entrée pour attribuer des codes gratuitement en ligne. Renvoyait déjà une réponse `410 Gone` (`purchaseDisabled`).
- **Preuve d'inutilité** :
  - Aucune page, aucun formulaire, aucun script JavaScript n'appelle `purchase_subscription.php`.
  - La vente des tickets s'effectue physiquement par carte imprimée (`admin.php` > lots) ou via l'interface revendeur (`dashboard.php`).
- **Décision** : **À SUPPRIMER**.

### 1.3 `debug.log` (32 lignes)
- **Rôle historique** : Fichier journal laissé à la racine du dépôt le 2 novembre 2026 suite aux premiers tests locaux (`define('DB_PORT')`).
- **Preuve d'inutilité** :
  - L'application écrit désormais tous ses événements dans la table de base de données `audit_log` via la fonction `audit()` (`lib/security.php`). Plus aucun fichier ne tente d'écrire dans `debug.log`.
  - Un fichier `.log` à la racine web représente un risque de fuite d'informations en production s'il est mal filtré.
- **Décision** : **À SUPPRIMER**.

### 1.4 Scripts temporaires et de débogage dans `tools/`
- **Fichiers concernés** :
  - `tools/pdf_demo.php` : script de démonstration de génération PDF.
  - `tools/qr_cw.php` et `tools/qr_dump.php` : scripts de test lors de l'implémentation manuelle du générateur QR code.
  - `tools/_codes.txt` : liste de deux codes de test créés manuellement.
- **Preuve d'inutilité** :
  - Aucun de ces scripts n'est inclus ou requis par le système en production. Les tests automatisés réels sont couverts par la suite de tests PHP.
- **Décision** : **À SUPPRIMER**.

---

## 2. Références à des services abandonnés (à nettoyer dans le code)

### 2.1 Ancien hébergement gratuit (InfinityFree / great-site.net)
- **Emplacements identifiés** :
  - `.env.example` (lignes 19-23) : `DB_HOST=sql307.infinityfree.com`, `DB_NAME=if0_39009379_zinbconnect`, `DB_USER=if0_39009379`.
  - `.htaccess` (ligne 1) : `# Zinbconnect — protection Apache (hébergement mutualisé type great-site.net)`.
  - `README.md` (ligne 7) : `https://zinbotech.great-site.net`.
  - `install/schema.mysql.sql` (ligne 2) : `-- Zinbconnect — schéma MySQL (InfinityFree, MySQL 5.7+/8.x)`.
  - `install/upgrade_existing_db.php` (ligne 3) : mention de production InfinityFree.
- **Correction** : Remplacer par des paramètres d'exemple neutres et sécurisés (ex. `localhost`, `db_zinbconnect`, etc.).

### 2.2 Matériel et plateformes hors périmètre (hEX, CPE220, RB951, Kamatera)
- **Vérification** :
  - Aucune référence active à Kamatera, hEX, CPE220 ou RB951 n'a été trouvée dans les scripts de configuration actuels (`rb2011-base.rsc`, `rb2011-hotspot.rsc`).
  - Le matériel cible unique et vérifié est :
    - Routeur : **MikroTik RB2011UiAS-2HnD-IN** (RouterOS 7.25.1, mipsbe).
    - Point d'accès extérieur : **TP-Link EAP225-Outdoor** (alimenté en PoE passif 24 V sur ETH10).
    - WAN : **Starlink Satellite Internet** (sur ETH1).
    - VPS relais : **Debian 12 / Ubuntu 24.04** (relais WireGuard sur Azure for Students ou VPS standard).

---

## 3. Tables de base de données obsolètes (historiques)

- **Tables `access_codes` et `current_codes`** :
  - Dans la version initiale du projet (v1), les codes étaient stockés dans `access_codes` (codes vierges) et migrés dans `current_codes` (codes actifs).
  - Depuis la Phase 3, l'application utilise exclusivement `batches` (les lots) et `tickets` (tous les tickets avec leur cycle de vie complet : `available`, `sold`, `active`, `used`, `expired`, `revoked`).
  - Les tables `access_codes` et `current_codes` sont vides (0 ligne). Elles sont conservées uniquement dans les scripts de migration pour compatibilité descendante avec de vieilles bases non migrées, mais ne sont plus appelées par le code runtime.
