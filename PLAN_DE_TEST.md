# PLAN_DE_TEST.md — Plan de validation et tests sur site (Bukavu & Kitutu)

Ce guide détaille l'ensemble des procédures de tests manuels à exécuter avant et pendant le déploiement sur site du système **Zinbconnect**.

- **Phase A (Bukavu)** : Banc d'essai en laboratoire (derrière box/CGNAT ou connexion 4G, validation matérielle et logicielle).
- **Phase B (Kitutu)** : Mise en service définitive sur site (liaison Starlink, EAP225-Outdoor en PoE 24 V, couverture réelle).

---

## Matériel requis pour les tests

| Équipement | Rôle |
|---|---|
| **MikroTik RB2011UiAS-2HnD-IN** | Routeur cœur (RouterOS 7.25.1) |
| **TP-Link EAP225-Outdoor** | Point d'accès extérieur (port ETH10 du RB2011) |
| **Source WAN** | Starlink (Kitutu) ou Box/Routeur 4G (Bukavu) sur port ETH1 |
| **PC Portable d'administration** | Connecté sur ETH2..ETH5 ou via tunnel WireGuard |
| **2 Smartphones de test** | 1 smartphone Android et 1 iPhone (pour valider la détection captif) |

---

## Grille de tests pas à pas

### Test 1 : Connexion Smartphone & Détection Portail Captif

- **Objectif** : Vérifier que le portail captif s'ouvre automatiquement à la connexion Wi-Fi, que l'authentification par code unique fonctionne (CHAP sécurisé) et que l'utilisateur accède à Internet.
- **Protocole** :
  1. Activer le Wi-Fi sur le smartphone et se connecter au SSID `Zinbconnect-Public`.
  2. Constater l'apparition immédiate de la notification système ("Se connecter au réseau Wi-Fi").
  3. Cliquer sur la notification : la page d'accueil Zinbconnect doit s'afficher (avec logo, tarifs et champ de saisie du code).
  4. Saisir un code de ticket valide (ex. un ticket 2h / 1 Go généré depuis l'admin).
  5. Cliquer sur **« Se connecter »**.
- **Résultat attendu** :
  - La page de succès s'affiche avec le récapitulatif (statut connecté, temps restant).
  - La navigation Internet est immédiatement débloquée (tester l'ouverture de `https://google.com` ou WhatsApp).
  - Dans WinBox > **IP > Hotspot > Active**, le client apparaît avec son adresse IP, son adresse MAC et son code unique.

---

### Test 2 : Saisie de code erroné et protection anti-brute-force

- **Objectif** : Vérifier le rejet propre des mauvais codes et le blocage automatique des attaques par force brute côté routeur.
- **Protocole** :
  1. Se connecter au Wi-Fi `Zinbconnect-Public`.
  2. Saisir un code invalide inventé (ex: `AAAA-BBBB-CCCC`).
  3. Constater le message d'erreur : le portail doit indiquer que le code est invalide ou expiré sans faire planter le routeur.
  4. Tenter 16 soumissions consécutives de faux codes en moins d'une minute depuis le même smartphone.
- **Résultat attendu** :
  - À partir de la 16ème tentative, la règle de pare-feu `/ip firewall filter` (chaîne `input`, port 80, limite 15 req/min) bloque temporairement le client pour 15 minutes.
  - La page ne répond plus pendant la période de pénitence, protégeant le CPU du RB2011 contre le déni de service.

---

### Test 3 : Validité calendaire et expiration réelle

- **Objectif** : Prouver que l'expiration s'applique selon l'horloge réelle et que le routeur ne supprime aucun ticket par erreur lors du démarrage sans horloge NTP.
- **Protocole** :
  1. **Test fenêtre glissante** : Se connecter avec un code ayant un forfait `w=86400` (validité 24h après 1ère connexion) et `uptime=2h`.
  2. Rester connecté 10 minutes puis couper le Wi-Fi sur le smartphone.
  3. Vérifier dans WinBox > **IP > Hotspot > Users** que le commentaire du ticket a été horodaté : `exp=[timestamp]`.
  4. Simuler l'avancement du temps ou attendre la fin de la fenêtre calendaire (24h) : même s'il reste 1h50 d'uptime non consommé, le script `hotspot-cleanup` doit expirer le ticket car la fenêtre temporelle est écoulée.
  5. **Test Cold Boot sans NTP** :
     - Redémarrer le RB2011 en débranchant le câble Starlink (ETH1).
     - Le routeur démarre avec son horloge non calibrée (année 1970).
     - Vérifier dans les logs et la table des utilisateurs : aucun ticket ne doit être supprimé car la condition `:if ($now > 1700000000)` protège l'ensemble de la base.
     - Rebrancher ETH1 : dès que le client NTP se synchronise, le nettoyage reprend normalement.

---

### Test 4 : Concurrence et tentative de double connexion (`shared-users=1`)

- **Objectif** : Prouver qu'un même ticket ne peut pas être partagé entre deux personnes en même temps.
- **Protocole** :
  1. Connecter le smartphone A avec le code de test.
  2. Sur le smartphone B, se connecter au même Wi-Fi et saisir le même code.
  3. Cliquer sur **« Se connecter »**.
- **Résultat attendu** :
  - Le smartphone B reçoit un message d'erreur RouterOS : `"already logged in"` ou `"user already active"`.
  - Le smartphone A reste connecté sans interruption.
  - La tentative frauduleuse est bloquée.

---

### Test 5 : Tentatives de contournement (Tethering, IPv6, DNS externe)

- **Objectif** : Vérifier que les failles classiques de contournement de portail captif sont hermétiquement fermées.
- **Protocole** :
  1. **Contournement par partage Wi-Fi (Tethering)** :
     - Smartphone A est connecté avec son code valide.
     - Activer le "Point d'accès Wi-Fi mobile" sur Smartphone A.
     - Connecter Smartphone B au Wi-Fi du Smartphone A.
     - Essayer de naviguer sur Smartphone B.
     - **Résultat attendu** : Smartphone B n'a aucun accès Internet. La règle Mangle `change-ttl new-ttl=set:1` fait expirer le paquet IP (TTL=0) dès le passage par Smartphone A.
  2. **Contournement par IPv6** :
     - Vérifier les paramètres réseau du smartphone connecté.
     - **Résultat attendu** : Aucune adresse IPv6 globale n'est attribuée (désactivé via `/ipv6 settings set disable-ipv6=yes`). Aucune fuite possible en dehors du portail IPv4.
  3. **Contournement par DNS personnalisé** :
     - Sur le smartphone, configurer manuellement le DNS `8.8.8.8` ou `1.1.1.1` au lieu du DNS DHCP.
     - Tenter de résoudre un domaine externe sans être authentifié.
     - **Résultat attendu** : La règle NAT `redirect dst-port=53` intercepte la requête et la renvoie vers le DNS local du RB2011, forçant la redirection vers la page de login.

---

### Test 6 : Coupure brutale d'alimentation électrique

- **Objectif** : Valider la résilience du système face aux coupures de courant fréquentes dans le Sud-Kivu.
- **Protocole** :
  1. Connecter 2 smartphones avec des tickets actifs.
  2. Débrancher directement la fiche d'alimentation 24 V du RB2011 (simulation de coupure du groupe ou onduleur).
  3. Attendre 30 secondes et rebrancher.
- **Résultat attendu** :
  - Le routeur démarre en moins de 45 secondes sans corruption de sa mémoire NAND (grâce aux journaux configurés en RAM).
  - L'EAP225-Outdoor redémarre alimenté par le port PoE ETH10.
  - Dès la resynchronisation NTP via Starlink, les smartphones se reconnectent au SSID et peuvent reprendre leur session sans perte de leurs droits.

---

### Test 7 : Administration à distance via tunnel WireGuard

- **Objectif** : Vérifier que l'ingénieur peut administrer le routeur à distance depuis Bukavu ou l'extérieur via une connexion 4G.
- **Protocole** :
  1. Activer le partage 4G mobile sur le PC portable (hors du réseau Wi-Fi local).
  2. Établir le tunnel WireGuard vers le VPS relais (`10.99.0.1`).
  3. Lancer WinBox et tenter de se connecter sur l'IP `10.99.0.2` (IP WireGuard du RB2011).
  4. Lancer une session SSH : `ssh admin@10.99.0.2`.
- **Résultat attendu** :
  - Connexion WinBox et SSH instantanée.
  - Les ports d'administration (WinBox 8291, SSH 22, Web 80) sont accessibles uniquement depuis le subnet WireGuard `10.99.0.0/24` et le LAN local, tout accès depuis l'IP publique Starlink étant rejeté.
  - Débrancher temporairement le câble Internet puis le rebrancher : le script `wg-watchdog` rétablit le tunnel sous 2 minutes.

---

### Test 8 : Export d'usage et synchronisation dans l'interface Admin

- **Objectif** : Rapatrier les données réelles de consommation et surveiller le quota Starlink mensuel.
- **Protocole** :
  1. Dans WinBox, exécuter manuellement le script : `/system script run export-usage`.
  2. Vérifier dans **Files** la présence du fichier `zinb_usage.csv`.
  3. Télécharger le fichier sur le PC d'administration.
  4. Se connecter à l'interface d'administration Zinbconnect (`admin.php`) > Onglet **« Consommation & Starlink »**.
  5. Téléverser le fichier `zinb_usage.csv` dans le formulaire d'import.
- **Résultat attendu** :
  - L'interface affiche le volume total WAN consommé sur le mois avec la jauge d'alerte Starlink (seuil vert < 800 Go, orange > 800 Go, rouge > 1000 Go).
  - Les tickets ayant épuisé leur quota d'octets ou d'uptime sont automatiquement marqués `used` (consommés) dans la base.
  - Le tableau récapitulatif affiche la consommation moyenne par forfait (Mo moyens et durée moyenne).

---

### Test 9 : Parade de réinitialisation usine (Reset ou restauration d'ancienne sauvegarde)

- **Objectif** : Démontrer qu'une réinitialisation ou une restauration d'un vieux backup routeur ne permet jamais à un ancien code d'être réutilisé gratuitement.
- **Protocole** :
  1. Dans `admin.php` > Onglet **« Consommation & Starlink »**, cliquer sur **« Télécharger le script de purge (codes consommés) »**.
  2. Le fichier généré `consumed_tickets_purge.rsc` contient les ordres `/ip hotspot user remove [find name="..."]` pour tous les tickets consommés, expirés ou révoqués.
  3. Simuler une restauration d'ancien backup sur le RB2011 (où d'anciens codes réapparaîtraient).
  4. Déposer `consumed_tickets_purge.rsc` sur le routeur et exécuter : `/import consumed_tickets_purge.rsc`.
- **Résultat attendu** :
  - Tous les anciens codes sont immédiatement supprimés du Hotspot.
  - Aucun utilisateur malveillant ne peut rejouer un ticket déjà consommé.

---

### Test 10 : Lots d'essai gratuit pour les 30 testeurs (Mois d'essai)

- **Objectif** : Valider que les 30 tickets d'essai gratuit fonctionnent pour les usagers sans fausser la comptabilité financière.
- **Protocole** :
  1. Dans `admin.php` > Lots > **« Générer un lot »**, cocher la case **« Lot d'essai gratuit (Tickets TEST, 0 FC) »**.
  2. Saisir une quantité de 30 tickets avec le forfait 24h ou 1 mois.
  3. Cliquer sur **« Générer le lot »**.
  4. Vérifier la référence du lot : préfixe `TEST-...`.
  5. Télécharger la fiche PDF d'impression :
     - Constater que chaque carte affiche en rouge **« ESSAI GRATUIT (TEST) »** à la place du prix en FC.
  6. Se connecter avec l'un de ces tickets sur le portail captif : la connexion fonctionne parfaitement.
  7. Vérifier le rapport des ventes dans l'administration :
     - Les ventes en FC restent strictement à **0 FC**.
     - Aucune transaction financière fictive n'est enregistrée.

---

## Fiche de validation finale avant départ pour Kitutu

- [ ] Script de base `rb2011-base.rsc` injecté et testé.
- [ ] Script hotspot `rb2011-hotspot.rsc` injecté avec les profils de forfaits.
- [ ] Port PoE ETH10 alimente correctement l'EAP225-Outdoor (voyant vert PoE allumé).
- [ ] Mangle anti-tethering (TTL=1) actif.
- [ ] Redirection DNS dstnat 53 active.
- [ ] Désactivation IPv6 confirmée.
- [ ] WireGuard joignable depuis l'extérieur sur `10.99.0.2`.
- [ ] Sauvegarde locale effectuée (`/system backup save name=kitutu-init`).
- [ ] Lot de 30 tickets TEST imprimé et découpé pour les notables et testeurs de Kitutu.
