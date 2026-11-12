# Vérification du RB2011 après import (Kitutu)

Commandes à passer dans **New Terminal** de WinBox (ou SSH), dans cet ordre.
Chaque bloc indique ce qui doit apparaître : si le résultat diffère, l'action
corrective est notée juste en dessous.

> Les deux scripts sont appliqués sur un routeur réinitialisé :
> `rb2011-base.rsc` puis `rb2011-hotspot.rsc`.

---

## 0. Sauvegarde avant de toucher à quoi que ce soit

```
/export file-name=avant-verif
/system backup save name=avant-verif
```

Les deux fichiers apparaissent dans **Files** : les récupérer sur le PC
(`avant-verif.rsc` et `avant-verif.backup`). En cas de bêtise :
`/import file-name=avant-verif.rsc`.

---

## 1. L'import s'est-il bien terminé ?

```
/system resource print
/log print where message~"rb2011"
```

- `version:` doit indiquer **7.25.1** ;
- `free-memory:` doit rester au-dessus de ~40 MiB (128 Mo au total) ;
- le log doit contenir **deux** lignes `info` (et non `warning`) :
  - `rb2011-base.rsc : configuration de base appliquee ...`
  - `rb2011-hotspot.rsc : hotspot actif sur lan ...`

Si une ligne `warning` apparaît au lieu de celles-ci, l'import s'est arrêté
avant la fin : relancer `/import file-name=rb2011-base.rsc` (les scripts sont
tolérants au ré-import).

Voir aussi les erreurs éventuelles :

```
/log print where topics~"error"
```

---

## 2. Horloge (indispensable au tunnel et aux logs)

```
/system ntp client print
/system clock print
```

- `enabled: yes` et l'état synchronisé (`status: synchronized`, ou `synced: yes`
  selon la version) ;
- une date/heure réelle (pas 1970).

Si `synced: no` après 2 minutes : `/ip dns print` doit avoir
`allow-remote-requests: yes` et des serveurs ; sinon l'accès Internet (étape 3)
est en cause.

---

## 3. WAN Starlink

```
/ip dhcp-client print
/ip address print where interface=eth-wan
/ping 8.8.8.8 count=4
/resolve google.com
```

- DHCP client `status: bound` avec une adresse en `100.64.x.x` — c'est normal,
  Starlink est en CGNAT ;
- ping 4/4 reçus, résolution DNS OK.

Si `status: searching...` : vérifier que le câble Starlink est sur **ETH1** et
que le routeur Starlink est en mode routeur (pas en bypass).

---

## 4. LAN, DHCP et point d'accès

```
/ip address print
/ip dhcp-server print
/ip dhcp-server lease print
/interface print where name=lan or name=eth-ap
```

- `10.20.30.1/24` sur `lan` ;
- DHCP `enabled: yes` sur `lan` ;
- dès qu'un téléphone se connecte au Wi-Fi, une ligne `bound` apparaît ;
- `eth-ap` doit avoir le drapeau **R** (lien monté avec l'EAP225).

Alimentation PoE du port 10 :

```
/interface ethernet print detail where name=eth-ap
```

`poe-out: forced-on` doit être affiché.

Si `eth-ap` n'est pas **R** : câble, ou EAP non alimenté (l'adaptateur 24 V
1,2 A est obligatoire ; l'EAP225-Outdoor demande 10,5 W max).

---

## 5. Tunnel WireGuard (l'accès depuis Bukavu)

```
/interface wireguard print
/interface wireguard peers print detail
/ip address print where interface=wg-relay
```

- `wg-relay`, `listen-port: 51820`, une `public-key` de 44 caractères ;
- côté pair : `endpoint-address` = IP de votre VPS, `persistent-keepalive: 25s`,
  et surtout **`last-handshake` de quelques secondes/minutes** — pas vide ni
  « 1h » ;
- `10.100.0.2/24` présent.

```
/ping 10.100.0.1 count=4
```

Le VPS doit répondre. S'il ne répond pas alors que `last-handshake` est frais,
c'est le pare-feu du VPS qui filtre l'ICMP, pas le tunnel : ajouter
`ufw allow in on wg0` sur le VPS.

Watchdog et relance :

```
/system scheduler print
/log print where message~"WireGuard"
```

Le planificateur `wg-watchdog` doit exister (`interval: 5m`). Aucune ligne
WireGuard dans le log = tunnel sain. Une ligne `WireGuard : pair VPS non cree`
signifie que `IP_DU_VPS` / `CLE_PUBLIQUE_DU_VPS` n'ont pas été renseignés dans
`rb2011-base.rsc` avant l'import : corriger le fichier, réimporter, puis
`/interface wireguard peers print` pour confirmer.

**Test final, le seul qui compte** : depuis le PC de Bukavu, tunnel WireGuard
activé, se connecter en WinBox sur **10.100.0.2**. Si ça passe, l'accès distant
fonctionne.

---

## 6. Sécurité (services et filtrage)

```
/ip service print
```

- `telnet`, `ftp`, `api` : drapeau **X** (désactivés) ;
- `winbox`, `www`, `ssh` : colonne `address` = `10.100.0.0/24,10.20.50.0/24`.
  Autrement dit : le tunnel (`.40`) et le port d'administration ETH5 (`.50`),
  **jamais** `10.20.30.0/24`.

> C'est volontaire : `10.20.30.0/24`, c'est le réseau des **clients** du
> hotspot (Wi-Fi de l'EAP225). L'autoriser reviendrait à laisser chaque
> client du portail tenter de joindre WinBox sur 10.20.30.1.
> Sur site, sans tunnel : PC branché sur **ETH5**, adresse `10.20.50.x`.

```
/ip firewall filter print stats
```

- l'ordre doit être : accepts, puis `drop` depuis `eth-wan` en dernier ;
- le compteur de la règle **drop** augmente lentement (balayages Internet
  bloqués) : normal ;
- les règles `accept in-interface=lan` (DHCP, DNS et portail des clients) et
  `accept in-interface=wg-relay` comptent beaucoup : normal ;
- `accept in-interface=eth-admin` : trafic du portable d'administration.

```
/ip firewall nat print stats
```

- règle `srcnat` : `action=masquerade` sortant vers `eth-wan` ;
- règles `dstnat` : redirection des requêtes DNS (UDP/TCP port 53) vers le DNS local (empêche le contournement par DNS externe).

```
/ip firewall mangle print
```

- règle `postrouting` vers `lan` : `action=change-ttl new-ttl=set:1` (anti-tethering : empêche le partage de connexion Wi-Fi par smartphone).

> Vérifier aussi que `/ip service print` n'affiche **pas** `api` activé : le
> bouton « Import MikroTik » de l'admin produit un fichier `.rsc`, il n'utilise
> pas l'API.

---

## 7. Hotspot et codes

```
/ip hotspot print
/ip hotspot profile print
/ip hotspot walled-garden ip print
/ip hotspot user print count-only
```

- hotspot `hotspot1` sur `lan`, profil `zinbconnect`, `enabled: yes` ;
- profil avec `html-directory: zinbconnect` et `login-by: http-chap,mac-cookie,cookie` ;
- profil utilisateur `code-usable` avec `shared-users: 1` ;
- les entrées walled garden DNS, ICMP, VPS Azure et WireGuard ;
- le compteur d'utilisateurs doit augmenter du **nombre de codes du lot
  importé** (les codes des lots précédents restent présents, ils ne sont pas
  effacés ; seuls les codes révoqués sont retirés).

Détail d'un code précis (remplacer par un code réel du lot) :

```
/ip hotspot user print detail where name="ABC234DEF567"
```

Doit afficher `comment: <référence du lot>` (le code vient bien de l'import
attendu) et `limit-uptime` / `limit-bytes-total` cohérents avec le forfait
vendu (ex. `1h`, `104857600`).

Le commentaire doit ressembler à `exp=0000000000 w=00086400 u=1780000000 <lot>` :
`exp` à zéro = code jamais utilisé encore, `w` = fenêtre de validité en
secondes (86400 = 24 h), `u` = date limite d'expiration si le code n'est jamais utilisé
(90 jours par défaut). Après la première connexion du client, `exp` est
remplacé par la date réelle (epoch) et le code disparaît une fois `exp + w` dépassé.
Si le code n'est jamais utilisé, il disparaît dès que la date `u` est atteinte.

Vérifier que le mécanisme tourne :

```
/system scheduler print
```

`code-validite` (`interval: 2m`) et `wg-watchdog` (`interval: 5m`) doivent y
être. Sans `code-validite`, un ticket « 24 h » resterait valable des semaines
(le temps n'est décompté que connecté).

Un code révoqué dans l'application doit **disparaître** de cette liste après
réimport du lot : c'est ainsi que la révocation arrive au routeur.

### Test réel, avec un téléphone

1. Se connecter au Wi-Fi de l'EAP225.
2. Ouvrir `http://10.20.30.1` (ou n'importe quel site http) : la page
   **zinbconnect** doit s'afficher, sans élément cassé (elle est autonome,
   aucun CDN).
3. Saisir un code du lot → page de succès, puis navigation Internet.

Pendant la session :

```
/ip hotspot active print
```

Le code doit y apparaître avec `uptime` qui augmente ; après déconnexion,
`/ip hotspot user print` montre le temps consommé côté `uptime`.

```
/ip hotspot host print
```

liste les appareils vus par le hotspot (utile pour repérer un client qui
tourne en boucle sur la page de login).

---

## 8. Sauvegarde finale (une fois tout vérifié)

```
/export file-name=apres-verif
/system backup save name=apres-verif
```

Récupérer les deux fichiers, et **modifier le mot de passe admin avant de
quitter le site** si ce n'est pas déjà fait :

```
/user set admin password="VOTRE-MOT-DE-PASSE"
```

---

## Récapitulatif : les 6 commandes à retenir

```
/system resource print
/ip dhcp-client print
/interface wireguard peers print detail
/ip service print
/ip hotspot print
/ip hotspot user print count-only
```

Si ces six-là sont bonnes, le routeur est en service : WAN, tunnel, verrouillage
des accès et codes sont en place.
