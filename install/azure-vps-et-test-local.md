# VPS Azure (for Students) + tests locaux à Bukavu

Objectif : monter le relais WireGuard sur Azure, puis tout configurer et tester
**ici**, à Bukavu, avant de déplacer le matériel à Kitutu. Seul Starlink reste
à brancher sur place.

Références croisées : `install/acces-distance-wireguard.md` (principe du tunnel,
dépannage), `install/verification-rb2011.md` (contrôles), `install/mise-en-service-kitutu.md`
(la journée sur site).

---

## Partie A — Créer le VPS sur Azure

### A.0 Ce que le pack étudiant couvre (et ne couvre pas)

- **100 $ de crédit, 12 mois**, sans carte bancaire. Réactivable chaque année
  tant que vous êtes étudiant.
- Le crédit est consommé par le serveur **et** par ce qui l'entoure : disque,
  adresse IP publique, trafic sortant. Ce n'est pas « VM gratuite ».
- Ordre de grandeur pour ce projet, région **South Africa North (Johannesburg)** :

| Poste | Taille | Coût indicatif |
|---|---|---|
| VM `Standard_B1ls` (1 vCPU, 0,5 Go) | suffisant pour WireGuard | ≈ 5 $/mois |
| VM `Standard_B1s` (1 vCPU, 1 Go) | plus confortable | ≈ 10 $/mois |
| Disque OS 32 Go, HDD standard | | ≈ 1,5 $/mois |
| IP publique **statique** | obligatoire (voir A.3) | ≈ 3,6 $/mois |

Soit **≈ 10 à 15 $/mois** : le crédit tient 7 à 10 mois. À l'épuisement, Azure
désactive le serveur — donc le tunnel — sans prévenir. Mettez une alerte budget
(A.5). Pour la suite, un VPS à 4 €/mois chez Hetzner revient moins cher ; Azure
est le bon choix **maintenant** parce qu'il ne vous coûte rien.

### A.1 Préparer la clé SSH sur le PC (Git Bash)

```bash
ssh-keygen -t ed25519 -C "zinbconnect"
cat ~/.ssh/id_ed25519.pub
```

Copier la ligne entière (`ssh-ed25519 AAAA... zinbconnect`) : elle sera collée
dans le portail Azure. Ne jamais transmettre `id_ed25519` (sans `.pub`).

### A.2 Créer la machine virtuelle

1. <https://portal.azure.com> → se connecter avec le compte étudiant.
2. **Create a resource** → **Virtual machine** → *Create*.
3. Onglet **Basics** :
   - **Subscription** : `Azure for Students` (si vous voyez « Pay-As-You-Go »,
     c'est le mauvais abonnement).
   - **Resource group** : *Create new* → `rg-zinbconnect`.
   - **Virtual machine name** : `vps-zinbconnect`.
   - **Region** : `(Africa) South Africa North`. C'est la région la plus proche
     de la RDC avec une bonne connectivité ; si indisponible, `France Central`.
   - **Availability options** : *No infrastructure redundancy required*.
   - **Security type** : `Standard`.
   - **Image** : `Debian 12 "Bookworm"` (chercher dans *See all images*).
     Si absente, `Ubuntu Server 24.04 LTS` : les commandes de la partie B sont
     identiques.
   - **Size** : *See all sizes* → `Standard_B1ls` (0,5 Go) ou `Standard_B1s`
     (1 Go). Éviter tout ce qui a un « p »/GPU.
   - **Authentication type** : `SSH public key`.
   - **Username** : `azureuser`.
   - **SSH public key source** : *Use existing public key* → coller la ligne de
     A.1.
   - **Public inbound ports** : `SSH (22)`.
4. Onglet **Disks** : *OS disk type* = `Standard HDD` (le moins cher ; un disque
   lent ne gêne pas un relais WireGuard). Supprimer la ligne « data disk » si
   elle apparaît.
5. Onglet **Networking** :
   - **Public IP** : *Create new* → **Assignment: Static**. C'est le point à ne
     pas rater : une IP dynamique change si vous arrêtez la VM, et le tunnel du
     routeur pointe alors dans le vide.
   - **NIC network security group** : `Basic`, ports `SSH (22)`.
   - **Delete public IP and NIC when VM is deleted** : décocher (on veut garder
     l'IP).
6. Onglet **Management** : **Auto-shutdown** = *Off* (le routeur doit joindre le
   VPS en permanence).
7. **Review + create** → *Create*. Le déploiement prend 1 à 3 minutes.
8. **Go to resource** → noter **Public IP address** (ex. `20.x.x.x`) : c'est
   `IP_DU_VPS`.

> Si le portail refuse la taille avec « quota exceeded » : *Subscription →
> Usage + quotas → Total Regional vCPUs → Request increase* (demander 2 vCPU),
   ou changer de région. Un abonnement étudiant neuf part parfois avec un quota
   de 0 vCPU sur la région choisie.

### A.3 Ouvrir le port WireGuard

Le pare-feu d'Azure (NSG) bloque tout par défaut : sans cette règle, aucun
handshake ne passera, et c'est l'erreur la plus fréquente.

1. VM → **Networking** → **Add inbound port rule**.
2. *Source* `Any`, *Source port ranges* `*`, *Destination* `Any`,
   *Destination port ranges* `51820`, *Protocol* `UDP`, *Action* `Allow`,
   *Priority* `310`, *Name* `wireguard`.
3. **Add**. Vérifier que la règle `SSH` (22/TCP) est toujours présente.

Sur Azure, **ne pas activer `ufw`** en plus : le NSG fait déjà le filtrage. Deux
pare-feux = deux occasions de se verrouiller dehors. (C'est la seule différence
avec `acces-distance-wireguard.md`, qui suppose un VPS nu.)

### A.4 Alerte budget (2 minutes, à ne pas sauter)

**Cost Management + Billing** → **Budgets** → *Add* → montant `50 $`, alerte à
50 %, 80 %, 100 %, destinataire votre e-mail. Sans cela, la coupure sera
silencieuse.

### A.5 Se connecter

```bash
ssh azureuser@IP_DU_VPS
```

Première connexion : répondre `yes` à l'empreinte.

---

## Partie B — Installer WireGuard sur le VPS

Tout en `sudo` sur le VPS.

```bash
sudo apt update && sudo apt install -y wireguard
sudo mkdir -p /etc/wireguard && sudo chmod 700 /etc/wireguard
cd /etc/wireguard
sudo umask 077
sudo sh -c 'wg genkey | tee privatekey | wg pubkey > publickey'
sudo cat publickey
```

Noter cette ligne : c'est `CLE_PUBLIQUE_DU_VPS`. Générer aussi le trousseau du
PC admin (le client Windows le fait tout seul) et du routeur (il le fait à
l'import) : vous les collerez au fur et à mesure.

Créer `/etc/wireguard/wg0.conf` :

```bash
sudo nano /etc/wireguard/wg0.conf
```

```
[Interface]
Address = 10.20.40.1/24
ListenPort = 51820
PrivateKey = <CONTENU_DE_privatekey>

# Routeur RB2011 a Kitutu (initiateur, derriere le CGNAT Starlink)
[Peer]
PublicKey = <CLE_PUBLIQUE_DU_ROUTEUR>
AllowedIPs = 10.20.40.2/32

# PC portable admin a Bukavu
[Peer]
PublicKey = <CLE_PUBLIQUE_DU_PC>
AllowedIPs = 10.20.40.10/32
```

Puis :

```bash
sudo sh -c 'echo "net.ipv4.ip_forward=1" > /etc/sysctl.d/99-wireguard.conf'
sudo sysctl --system
sudo systemctl enable --now wg-quick@wg0
sudo wg show
```

`ip -4 addr show wg0` doit montrer `10.20.40.1/24`. Tant qu'aucun pair n'a
envoyé de paquet, `wg show` n'affiche aucune ligne `peer` : c'est normal.

> Les clés du routeur et du PC ne sont pas encore connues à ce stade. Le plus
> simple : ajouter le bloc `[Peer]` correspondant **plus tard**, puis
> `sudo systemctl restart wg-quick@wg0`. Ou à chaud, sans redémarrer :
> ```bash
> sudo wg set wg0 peer <CLE> allowed-ips 10.20.40.2/32
> ```
> (à chaud, mais à reporter aussi dans `wg0.conf`, sinon perdu au redémarrage).

---

## Partie C — Configurer le routeur ici, à Bukavu

Le RB2011 se comporte exactement comme à Kitutu, et mieux : branché derrière
votre box locale, il est **en double NAT**, c'est-à-dire dans la même situation
qu'un CGNAT. Si le tunnel monte ici, il montera à Kitutu.

1. Brancher ETH1 (`eth-wan`) sur un port LAN de votre box actuelle.
2. Appliquer `install/mise-en-service-kitutu.md` §3 (reset sans config par
   défaut, mot de passe) puis §4.1–4.3, avec `IP_DU_VPS` et
   `CLE_PUBLIQUE_DU_VPS` renseignés dans `rb2011-base.rsc`.
3. Lire la clé publique du routeur :

```
/interface wireguard print
```

4. La déclarer côté VPS (Partie B, bloc `[Peer]` routeur), redémarrer
   `wg-quick@wg0`.
5. Vérifier des deux côtés :

```
# routeur
/interface wireguard peers print detail     # last-handshake recent
/ping 10.20.40.1 count=4
```

```bash
# VPS
sudo wg show          # ligne "peer" avec latest handshake
ping -c4 10.20.40.2
ssh admin@10.20.40.2  # acces admin sans aucune redirection de port
```

Le `ssh admin@10.20.40.2` depuis le VPS est **la preuve** que l'administration
à distance fonctionne : le routeur est joignable de l'extérieur sans ouvrir un
seul port chez Starlink.

---

## Partie D — Tester depuis un « Bukavu » simulé

Objectif : vérifier ce que vous ferez plus tard depuis chez vous, quand le
routeur sera à Kitutu.

1. Installer **WireGuard pour Windows** sur le PC.
2. *Add tunnel* → *Add empty tunnel* → renseigner :

```
[Interface]
PrivateKey = <générée par l'application>
Address = 10.20.40.10/32

[Peer]
PublicKey = <CLE_PUBLIQUE_DU_VPS>
Endpoint = IP_DU_VPS:51820
AllowedIPs = 10.20.40.0/24
PersistentKeepalive = 25
```

3. Copier la clé publique affichée par l'application → l'ajouter au `wg0.conf`
   du VPS (pair PC) → `sudo systemctl restart wg-quick@wg0`.
4. **Se déconnecter du Wi-Fi du routeur** et se connecter au partage 4G du
   téléphone : c'est ce qui rend le test honnête (sinon on passe par le LAN).
5. Activer le tunnel → ouvrir WinBox → `10.20.40.2` → ça doit répondre.
6. Ouvrir `http://10.20.30.1` : la page du portail doit s'afficher.

Si l'étape 5 échoue alors que `wg show` sur le VPS voit le PC : c'est le
pare-feu du VPS ou l'`AllowedIPs` côté routeur (doit couvrir `10.20.40.10`).

---

## Partie E — Tester le portail et les codes

1. EAP225 branché sur ETH10, configuré selon `mise-en-service-kitutu.md` §2.
2. Téléphone : Wi-Fi `Zinbconnect Kitutu` → la page de connexion s'ouvre
   (sinon taper `http://10.20.30.1`).
3. Générer un lot de test dans l'admin (1 à 2 codes) → **Import MikroTik** →
   déposer le `.rsc` dans WinBox *Files* → `/import file-name=zinbconnect_<lot>.rsc`.
4. Se connecter avec un code → navigation Internet OK.
5. Contrôles :

```
/ip hotspot active print              # le code apparait, uptime qui monte
/ip hotspot user print count-only     # a augmente du nombre de codes du lot
```

6. Tester aussi un **mauvais code** : le portail doit refuser, sans bloquer le
   téléphone définitivement.

---

## Partie F — Ce qui ne peut être testé qu'à Kitutu

- Le câble Starlink et l'obtention d'une adresse WAN (`/ip dhcp-client print`
  → `bound` en `100.64.x.x`).
- La portée réelle du Wi-Fi (emplacement de l'EAP, canal 2,4 GHz).
- Le watchdog du tunnel sur une vraie coupure (couper l'alimentation du routeur
  à Bukavu pour le vérifier : `wg-watchdog` doit rétablir le handshake en
  moins de 5 minutes).

Tout le reste — VPS, tunnel, pare-feu, portail, codes — se valide ici.

---

## Partie G — Économiser le crédit pendant la phase de test

- **Arrêter (désallouer)** la VM quand vous ne testez pas : *VM → Stop*. Les
  heures de calcul cessent d'être facturées ; le disque et l'IP statique
  continuent (≈ 5 $/mois).
- **Ne pas** supprimer la VM pour économiser : vous perdriez l'IP et la config.
- Une fois le routeur à Kitutu, la VM doit rester **allumée** : c'est elle qui
  rend le routeur joignable depuis Bukavu.
