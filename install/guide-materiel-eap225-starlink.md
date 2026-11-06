# Guide de configuration matérielle : EAP225-Outdoor & Starlink

Ce guide explique pas à pas comment interconnecter et paramétrer l'antenne extérieure **TP-Link EAP225-Outdoor** et le kit satellite **Starlink** avec le routeur **MikroTik RB2011UiAS-2HnD-IN** pour le site de Kitutu.

---

## 1. Antenne Wi-Fi Extérieure : TP-Link EAP225-Outdoor

### A. Câblage et alimentation PoE 24 V (Port ETH10)
- **Principe** : Le port **ETH10** du MikroTik RB2011 est configuré en `poe-out=forced-on`. Il délivre directement du courant continu 24 V passif sur le câble réseau.
- **Raccordement** :
  - Relie un câble Ethernet direct (catégorie 5e ou 6 blindé pour extérieur) entre le port **ETH10** du MikroTik et le port **Ethernet/PoE** situé sous le capot étanche de l'EAP225.
  - **Important** : Tu n'as **PAS besoin de l'injecteur d'alimentation noir TP-Link**. Le RB2011 alimente l'antenne tout seul. Cela économise une prise électrique et réduit le risque de panne.
  - **Longueur maximale du câble** : Moins de 30 mètres pour éviter les chutes de tension.

### B. Configuration de l'EAP225 (Mode Point d'Accès Autonome / Standalone)
L'antenne ne doit servir que de « porte-voix Wi-Fi ». C'est le MikroTik qui gère toute l'intelligence (DHCP, portail captif, coupure des impayés).

1. **Connexion initiale à l'antenne** :
   - Dès la mise sous tension, l'EAP225 diffuse deux réseaux Wi-Fi ouverts d'usine : `TP-Link_2.4GHz_XXXXXX` et `TP-Link_5GHz_XXXXXX`.
   - Connecte ton PC portable ou ton smartphone à ce Wi-Fi.
   - Ouvre ton navigateur et saisis l'adresse : `http://tplinkeap.net` (ou télécharge l'application gratuite **TP-Link Omada** sur ton téléphone).
2. **Initialisation** :
   - Définis un nom d'utilisateur et un mot de passe administrateur pour l'antenne (ex: `admin` / un mot de passe fort que tu conserves).
3. **Paramétrage des réseaux sans fil (SSID)** :
   - **Nom du réseau (SSID)** : Définis le même nom sur les deux bandes : `Zinbconnect-Public`
   - **Sécurité** : Choisis **None** (réseau ouvert, sans mot de passe WPA).
     > *Raison* : C'est le portail captif MikroTik qui assure le verrouillage. Si tu mettais un mot de passe WPA, les clients devraient saisir deux mots de passe différents, ce qui compliquerait tout.
4. **Désactiver le DHCP sur l'antenne** :
   - Dans le menu Réseau de l'antenne, assure-toi que son adressage IP est configuré en **DHCP Client** (Dynamic IP). L'antenne recevra ainsi automatiquement une IP locale du MikroTik (`10.20.30.X`).
   - L'antenne ne doit en aucun cas distribuer d'adresses IP elle-même.
5. **Optimisation des canaux radio pour Kitutu** :
   - **Bande 2,4 GHz** : Régler sur **Largeur de canal 20 MHz** (meilleure pénétration à travers les murs et tôles, moins de sensibilité aux interférences). Choisir le canal 1, 6 ou 11.
   - **Bande 5 GHz** : Régler sur **Largeur 40 ou 80 MHz** (débit maximal pour les smartphones récents à proximité immédiate).

---

## 2. Connexion Internet Satellite : Starlink

### A. Raccordement au MikroTik (Port ETH1)
- Le câble réseau venant de Starlink se branche **obligatoirement sur le port ETH1** (`eth-wan`) du RB2011.
- **Selon ta version de matériel Starlink** :
  - **Starlink Standard Motorisé (Gen 2 - antenne rectangulaire motorisée)** : Le routeur Starlink blanc d'origine n'a pas de prise réseau. Tu dois insérer l'**Adaptateur Ethernet Starlink officiel** entre le câble de l'antenne et le routeur Starlink, puis brancher un câble RJ45 vers ETH1 du RB2011.
  - **Starlink Standard (Gen 3 - antenne fixe avec béquille)** : Le routeur Starlink Gen 3 possède deux ports RJ45 sous son capot arrière. Branche directement un câble RJ45 standard entre le routeur Starlink et le port ETH1 du RB2011.

### B. Configuration de Starlink dans l'application mobile Starlink
Une fois l'antenne Starlink alignée et connectée au réseau satellitaire :

1. Ouvre l'application **Starlink** sur ton smartphone (connecté au Wi-Fi Starlink initial).
2. Va dans **Paramètres** (Settings) > **Avancé** (Advanced).
3. Active le **Mode Dérivation** (Bypass Mode).
   - L'application va te demander une confirmation.
   - **Ce que fait le Mode Bypass** : Il désactive le Wi-Fi interne du boîtier Starlink et confie l'intégralité de la connexion directement au port ETH1 du MikroTik.
   - **Avantages majeurs** :
     - Élimine le double routeur (double NAT).
     - Le MikroTik reçoit directement l'adresse IP publique/CGNAT de Starlink.
     - Le tunnel WireGuard vers Azure s'établit avec la meilleure latence possible.
     - Réduit la consommation électrique sur le site.
4. *Remarque* : Si tu ne souhaites pas activer le Bypass Mode, cela fonctionne aussi : le routeur Starlink fournira une IP `192.168.1.X` au port ETH1 du MikroTik par DHCP, et notre script `rb2011-base.rsc` routra automatiquement tout le trafic.

---

## 3. Schéma de synthèse des branchements à Kitutu

```
                          [ Antenne Satellite Starlink ]
                                        │
                                        │ (Câble d'origine)
                                        ▼
                           [ Boîtier / Alim Starlink ]
                                        │
                                        │ (Câble RJ45 depuis port LAN / Adaptateur)
                                        ▼
╔═══════════════════════════════════════════════════════════════════════════════════╗
║                      MikroTik RB2011UiAS-2HnD-IN                                  ║
║                                                                                   ║
║  [Port 1 / ETH-WAN] ◄── Entrée Internet Starlink (DHCP Client automatique)       ║
║                                                                                   ║
║  [Port 2 à 4 / LAN] ──► Prises réseau libres (PC local, imprimante...)            ║
║                                                                                   ║
║  [Port 5 / Admin]   ──► Prise de maintenance directe (PC portable 10.20.50.X)     ║
║                                                                                   ║
║  [Port 10 / PoE]    ──► Alimentation 24 V passive + Données                       ║
╚═══════════════════════════════════════╤═══════════════════════════════════════════╝
                                        │
                                        │ (Câble RJ45 extérieur blindé < 30 m)
                                        ▼
                         [ TP-Link EAP225-Outdoor ]
                       (Sur mât / toit à 4-6 m de haut)
                                        │
                                        ▼  (( Wi-Fi ))
                           SSID : Zinbconnect-Public
                                        │
                                        ▼
                             [ Clients / Smartphones ]
                                        │
                         Redirection automatique vers :
                           http://10.20.30.1/login.html
```

---

## 4. Précautions électriques et climatiques à Kitutu
1. **Protection contre la foudre et surtensions** : Le Sud-Kivu subit de fréquents orages. Installe un parafoudre / parasurtenseur secteur entre la prise murale et le bloc 24 V du MikroTik ainsi que sur l'alimentation Starlink.
2. **Onduleur ou batterie régulée** : Un onduleur (UPS) de 600 à 1000 VA est indispensable pour maintenir le réseau actif pendant les coupures ou fluctuations du groupe électrogène / panneaux solaires.
3. **Étanchéité EAP225** : Bien refermer le cache étanche inférieur en caoutchouc autour du câble réseau pour empêcher l'humidité et les insectes de pénétrer dans l'antenne.
