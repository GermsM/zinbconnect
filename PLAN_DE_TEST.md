# PLAN DE TEST COMPLET — ZINBCONNECT KITUTU

Ce document est le référentiel de validation sur banc d'essai (Bukavu) et sur site (Kitutu). Chaque protocole est numéroté pour un suivi rigoureux.

---

## Matériel requis pour le banc de test
- **Routeur MikroTik RB2011UiAS-2HnD-IN** (RouterOS 7.25.1)
- **Antenne extérieure TP-Link EAP225-Outdoor** (alimentée en PoE Passif 24V sur ETH10)
- **Liaison WAN** (Port ETH1 connecté au Starlink ou partagé par le PC)
- **PC d'administration** (Windows avec WireGuard et WinBox)
- **Smartphones de test** (Android et iOS pour valider la détection captive et la caméra)

---

## Grille des Tests de Qualification

### Test 1 : Détection automatique du portail captif (CNA)
- **Objectif** : S'assurer que le smartphone détecte le Wi-Fi et ouvre la page de bienvenue sans que l'utilisateur ait à taper une adresse IP.
- **Protocole** :
  1. Activer le Wi-Fi sur le smartphone et sélectionner le SSID `Zinbconnect-Public`.
  2. Constater l'apparition de la notification système *"Se connecter au réseau Wi-Fi"*.
  3. Taper sur la notification : la page d'accueil violette Zinbconnect s'ouvre automatiquement.
- **Résultat attendu** : Le portail captif s'affiche en plein écran avec les logos, la liste des tarifs et le champ de saisie du code.
- **Important** : la fenêtre captive est décidée par Android/iOS/Windows et ne
  peut pas être garantie dans tous les cas. Si elle n'apparaît pas, ouvrir
  manuellement `http://10.20.30.1/login.html` ou une page HTTP non chiffrée.

---

### Test 2 : Connexion manuelle par saisie de code
- **Objectif** : Valider l'authentification avec un code de ticket valide généré depuis la console admin.
- **Protocole** :
  1. Saisir un code de 12 caractères valide (ex: `K7M2PQRS9TUV`).
  2. Cliquer sur **« Se connecter »**.
- **Résultat attendu** :
  - Le bouton affiche *"Connexion en cours…"*.
  - La page `status.html` s'affiche avec le récapitulatif du temps restant et des données.
  - Internet est instantanément débloqué sur le smartphone.
  - Dans WinBox > **IP > Hotspot > Active**, le client apparaît avec son IP, sa MAC et son code.

---

### Test 3 : Connexion par scan direct du QR Code
- **Objectif** : Valider que le client peut scanner son ticket papier avec la caméra sans taper le code.
- **Protocole (2 méthodes)** :
  1. **Méthode A (Depuis le portail captif)** :
     - Cliquer sur le bouton **« Scanner le QR code »**.
     - L'appareil photo du smartphone s'ouvre directement en plein écran.
     - Viser le QR code du ticket physique et valider la photo.
     - Le moteur hors-ligne `jsqr.min.js` (embarqué sur le routeur) décode le QR code en 50 ms.
  2. **Méthode B (Depuis l'application Appareil Photo normale du téléphone)** :
     - Ouvrir l'application Photo normale du smartphone et viser le QR code de la carte.
     - Une bulle apparaît : *"Ouvrir http://10.20.30.1/login.html?c=..."*.
     - Taper sur la bulle : le navigateur s'ouvre et connecte le smartphone automatiquement.
- **Résultat attendu** : Le code est validé et la connexion s'établit sans saisie manuelle.

---

### Test 4 : Message d'erreur humanisé et auto-estompage (5 secondes)
- **Objectif** : Vérifier que les messages d'erreur sont clairs en français et disparaissent sans polluer l'écran.
- **Protocole** :
  1. Sur le portail captif, taper un faux code inventé (ex: `TEST-FAUX-1234`).
  2. Cliquer sur **« Se connecter »**.
  3. Observer le message : *"Code invalide ou inexistant. Vérifiez bien les 12 caractères..."*.
  4. Ne toucher à rien : le message s'estompe et disparaît tout seul au bout de 5 secondes.
  5. Si l'utilisateur commence à retaper son code avant 5 secondes, le message disparaît immédiatement.
- **Résultat attendu** : Aucun message technique en anglais (*« invalid username or password »*), disparition fluide.

---

### Test 5 : Protection du forfait Starlink (Bridage de débit 2 Mbit/s / 512 kbit/s)
- **Objectif** : Empêcher qu'un utilisateur ne sature la bande passante Starlink en téléchargeant à plein régime.
- **Protocole** :
  1. Connecter le smartphone avec un ticket actif.
  2. Lancer un test de débit sur `fast.com` ou `speedtest.net`.
- **Résultat attendu** :
  - Vitesse Download : Strictement limitée à **2,0 Mbit/s**.
  - Vitesse Upload : Strictement limitée à **512 kbit/s**.
  - Dans WinBox > **Queues > Simple Queues**, la file d'attente dynamique du client est active.

---

### Test 6 : Unicité stricte d'un ticket (`shared-users=1`)
- **Objectif** : Prouver qu'un même ticket ne peut pas être utilisé simultanément par deux personnes.
- **Protocole** :
  1. Connecter le Smartphone A avec le code de test.
  2. Prendre le Smartphone B, se connecter au Wi-Fi et taper le même code.
- **Résultat attendu** : Le Smartphone B est rejeté (*« Appareil déjà connecté »*), tandis que le Smartphone A continue de naviguer sans coupure.

---

### Test 7 : Blocage du partage pirate (Anti-Tethering TTL=1)
- **Objectif** : Empêcher un client d'acheter 1 ticket et de le redistribuer via son partage de connexion Wi-Fi mobile.
- **Protocole** :
  1. Smartphone A est connecté avec un ticket actif.
  2. Activer le "Point d'accès mobile" sur Smartphone A.
  3. Connecter Smartphone B au Wi-Fi émis par Smartphone A.
  4. Tenter d'ouvrir une page web sur Smartphone B.
- **Résultat attendu** : Smartphone B n'a aucun accès Internet. La règle Mangle MikroTik force le TTL à 1, provoquant la destruction immédiate des paquets relayés.

---

### Test 8 : Validité calendaire et protection contre le démarrage à froid (Cold Boot)
- **Objectif** : S'assurer que le forfait s'éteint après sa durée calendaire et qu'aucun ticket n'est effacé lors d'une coupure électrique sans horloge NTP.
- **Protocole** :
  1. **Fenêtre calendaire** : Se connecter avec un code 1h (`w=3600`). Couper le Wi-Fi après 5 minutes. Attendre 1 heure : le script supprime le ticket de la base même si le temps en ligne réel n'était que de 5 minutes.
  2. **Cold Boot (Coupure de courant)** : Débrancher ETH1 (Internet) et la prise 24V. Rebrancher : le routeur démarre en 1970. Aucun ticket n'est supprimé grâce au garde-fou `:if ($now > 1700000000)`. Dès que le câble Internet est rebranché et que l'heure NTP se synchronise, le nettoyage reprend.
- **Résultat attendu** : Les forfaits s'épuisent dans le temps réel sans perte de données en cas de panne de courant.

---

### Test 9 : Accès libre au panneau de gestion Azure (Walled Garden)
- **Objectif** : Permettre aux revendeurs et administrateurs d'accéder à `reseller.php` et `admin.php` depuis le Wi-Fi sans acheter de ticket.
- **Protocole** :
  1. Connecter un smartphone au Wi-Fi `Zinbconnect-Public` sans entrer de code de ticket.
  2. Ouvrir le navigateur et aller sur : `http://20.164.1.221/reseller.php`.
- **Résultat attendu** :
  - La page de connexion revendeur s'affiche immédiatement sans redirection vers le portail captif.
  - Les règles Walled Garden HTTP (`dst-host=20.164.1.221`) et IP (`dst-address=20.164.1.221`) laissent passer le trafic d'administration.

---

### Test 10 : Administration à distance par WireGuard (Test Bukavu -> Kitutu)
- **Objectif** : Piloter et dépanner le routeur de Kitutu depuis votre PC à Bukavu à travers le tunnel VPN chiffré.
- **Pré-requis VPS** : Sur le VPS Azure, activer le routage inter-pairs :
  ```bash
  sudo sysctl -w net.ipv4.ip_forward=1
  sudo iptables -A FORWARD -i wg0 -o wg0 -j ACCEPT
  sudo systemctl restart wg-quick@wg0
  ```
- **Protocole** :
  1. Activer le tunnel WireGuard sur votre PC Windows (IP `10.100.0.10`).
  2. Dans PowerShell, tester la connectivité :
     ```powershell
     ping 10.100.0.1  # Ping vers le VPS
     ping 10.100.0.2  # Ping vers le MikroTik
     ```
  3. Lancer WinBox et se connecter sur `10.100.0.2`.
- **Résultat attendu** : Les pings répondent sans perte. WinBox s'ouvre à distance à travers le tunnel.

---

### Test 11 : Exportation de la consommation réelle et jauge Starlink 500 Go
- **Objectif** : Extraire la consommation des tickets et le volume Starlink vers la console d'administration.
- **Protocole** :
  1. Dans WinBox > **System > Scripts**, sélectionner `export-usage` et cliquer sur **Run Script** (ou en New Terminal : `/import file-name=export-usage.rsc`).
  2. Dans WinBox > **Files**, récupérer le fichier `zinb_usage.csv`.
  3. Aller sur `http://20.164.1.221/admin.php` > Onglet **Consommation & Starlink**.
  4. Téléverser `zinb_usage.csv` ou coller son texte.
- **Résultat attendu** :
  - Le fichier `zinb_usage.csv` est créé immédiatement à la racine de Files.
  - La console admin met à jour la jauge Starlink et les mégaoctets consommés pour chaque ticket.

---

### Test 12 : Lots d'essai gratuit pour les notables (30 cartes TEST à 0 FC)
- **Objectif** : Générer 30 tickets d'essai pour le lancement à Kitutu sans fausser la comptabilité financière.
- **Protocole** :
  1. Dans `admin.php` > **Lots**, cocher *"Lot d'essai gratuit (Tickets TEST, 0 FC)"*.
  2. Générer 30 tickets de 24h ou 3 jours.
  3. Télécharger le PDF : chaque carte porte la mention rouge *"ESSAI GRATUIT (TEST)"*.
  4. Importer le `.rsc` dans le routeur et tester un code sur le portail captif.
- **Résultat attendu** : Le code fonctionne parfaitement, et le chiffre d'affaires financier reste strictement à 0 FC.

---

### Test 13 : Reconnexion automatique sans ressaisie du code (Cookie MAC)
- **Objectif** : Éviter aux usagers de devoir retaper leur code chaque fois qu'ils s'éloignent du Wi-Fi.
- **Protocole** :
  1. Smartphone A est connecté avec un ticket actif.
  2. Couper le Wi-Fi sur Smartphone A (ou mode avion) pendant 5 minutes.
  3. Dans WinBox > **IP > Hotspot > Cookies**, vérifier que l'adresse MAC du smartphone est mémorisée.
  4. Réactiver le Wi-Fi sur Smartphone A et ouvrir une page web ou WhatsApp.
- **Résultat attendu** : Le portail captif ne réapparaît pas ; le smartphone est reconnecté automatiquement et en toute transparence.
