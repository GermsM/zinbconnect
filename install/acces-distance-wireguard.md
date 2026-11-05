# Accès distant au RB2011 depuis Bukavu (tunnel WireGuard via VPS)

Starlink met le routeur derrière un CGNAT : **aucune redirection de port entrante
n'est possible**. La solution retenue est d'inverser le sens de la connexion :

```
PC admin (Bukavu)  ──UDP 51820──►  VPS Debian (IPv4 publique)  ◄──UDP 51820──  RB2011 (Kitutu)
      10.20.40.10                        10.20.40.1                            10.20.40.2
```

Le RB2011 ouvre lui-même le tunnel vers le VPS et le maintient ouvert
(`persistent-keepalive=25s`). Depuis le PC admin, on administre ensuite le
routeur sur **10.20.40.2** (WinBox, WebFig, SSH) comme s'il était sur le réseau
local.

Le VPS ne sert qu'à cela : il ne fait que relayer le tunnel, il ne voit pas le
trafic des clients Wi-Fi (pas de NAT, pas de route par défaut imposée).

---

## 1. Côté routeur (déjà prêt dans `rb2011-base.rsc`)

La section 7 du script crée :

- l'interface `wg-relay` (port d'écoute 51820) ;
- l'adresse tunnel `10.20.40.2/24` ;
- le pair « VPS relais » en keepalive 25 s ;
- un watchdog qui relance le pair toutes les 5 min si le handshake a plus de
  5 min (utile après une coupure de courant : le RB2011 n'a pas d'horloge tant
  que le NTP n'a pas resynchronisé).

**Deux valeurs à compléter avant l'import** dans `install/rb2011-base.rsc` :

| À remplacer | Par |
|---|---|
| `IP_DU_VPS` | l'IPv4 publique du VPS (étape 2) |
| `CLE_PUBLIQUE_DU_VPS` | la clé publique du VPS (étape 2) |

Rien d'autre : la clé **privée** du routeur est générée par RouterOS.

Après import, récupérer la clé publique du routeur (elle servira à l'étape 3) :

```
/interface wireguard print
```

colonne `public-key` de l'interface `wg-relay`.

---

## 2. Le VPS

N'importe quel petit VPS Debian 12 avec **une IPv4 publique** convient
(1 vCPU / 512 Mo suffisent très largement : le relais ne fait que du chiffrement
de tunnel).

### 2.1 Installation

```bash
apt update && apt install -y wireguard-tools
wg genkey | tee /etc/wireguard/vps.key | wg pubkey > /etc/wireguard/vps.pub
chmod 600 /etc/wireguard/vps.key
cat /etc/wireguard/vps.pub      # ← CLE_PUBLIQUE_DU_VPS pour le routeur
```

Générer aussi la paire du PC admin (sur le VPS, c'est plus simple à copier) :

```bash
wg genkey | tee /etc/wireguard/bukavu.key | wg pubkey > /etc/wireguard/bukavu.pub
chmod 600 /etc/wireguard/bukavu.key
```

### 2.2 `/etc/wireguard/wg0.conf`

```ini
[Interface]
Address    = 10.20.40.1/24
ListenPort = 51820
PrivateKey = <contenu de /etc/wireguard/vps.key>

[Peer]
# kitutu (RB2011)
PublicKey  = <public-key lue sur le routeur, étape 1>
AllowedIPs = 10.20.40.2/32

[Peer]
# bukavu (PC admin)
PublicKey  = <contenu de /etc/wireguard/bukavu.pub>
AllowedIPs = 10.20.40.10/32
```

### 2.3 Routage entre les deux pairs

Le VPS doit laisser passer les paquets d'un pair vers l'autre :

```bash
echo 'net.ipv4.ip_forward = 1' > /etc/sysctl.d/99-wireguard.conf
sysctl --system
```

Pare-feu (n'ouvrir que ce qui sert) :

```bash
ufw allow 22/tcp
ufw allow 51820/udp
ufw route allow in on wg0 out on wg0
ufw enable
```

Sans `ufw`, en nftables, autoriser `udp dport 51820` en entrée et le
`forward` sur l'interface `wg0`.

### 2.4 Démarrage

```bash
systemctl enable --now wg-quick@wg0
wg show            # doit lister les 2 pairs
```

---

## 3. Le PC admin (Bukavu)

Installer le client WireGuard (Windows : application officielle « WireGuard »),
puis créer un tunnel avec ce contenu :

```ini
[Interface]
PrivateKey = <contenu de /etc/wireguard/bukavu.key>
Address    = 10.20.40.10/24
# DNS = 10.20.30.1        # facultatif : résolution via le routeur du site

[Peer]
PublicKey           = <CLE_PUBLIQUE_DU_VPS>
Endpoint            = <IP_DU_VPS>:51820
AllowedIPs          = 10.20.40.0/24
PersistentKeepalive = 25
```

`AllowedIPs = 10.20.40.0/24` uniquement : le tunnel ne sert qu'à l'administration,
la navigation normale du PC ne passe pas par le VPS.

Activer le tunnel, puis :

```
ping 10.20.40.2            # le RB2011 repond
WinBox → 10.20.40.2
```

Les services d'administration du routeur sont limités au LAN et au tunnel
(section 8 de `rb2011-base.rsc`) : depuis Internet, il faut donc être dans le
tunnel, et le mot de passe admin reste la seconde barrière.

---

## 4. Vérifications et dépannage

| Symptôme | À vérifier |
|---|---|
| `wg show` : pas de handshake sur `kitutu` | le routeur a-t-il bien la bonne IP et la bonne clé publique du VPS ? `/log print where message~"WireGuard"` |
| Tunnel monté mais WinBox muet | `/ip service print` : `winbox` doit lister `10.20.40.0/24` dans `address` |
| Tunnel tombé après une coupure de courant | normal pendant 1–2 min : le watchdog relance le pair, puis le NTP remet l'horloge |
| Tunnel instable | garder `persistent-keepalive=25s` des deux côtés : le CGNAT Starlink oublie les sessions UDP inactives |
| Besoin d'atteindre aussi les équipements du LAN (EAP, PC du site) | ajouter `10.20.30.0/24` dans `AllowedIPs` du pair « kitutu » côté VPS et du PC admin, puis `/ip firewall filter` : autoriser `forward` depuis `wg-relay` vers `lan` |

## 5. Sécurité

- Le VPS ne détient **aucune** clé privée du routeur : le vol du VPS ne donne pas
  le mot de passe du RB2011.
- Clés : `600` sur le VPS, jamais commitées dans ce dépôt.
- Seul le port **UDP 51820** est ouvert vers l'extérieur, le SSH du VPS mérite
  une clé (pas de mot de passe) et éventuellement un `fail2ban`.
- Révoquer un accès = supprimer le `[Peer]` correspondant (VPS et/ou routeur) ;
  inutile de changer le reste de la configuration.
