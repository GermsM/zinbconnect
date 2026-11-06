# Mise en service à Kitutu (RB2011 + EAP225 + Starlink)

Procédure à suivre **dans l'ordre**, le jour de l'installation. Les commandes
de contrôle détaillées sont dans `install/verification-rb2011.md` ; ici on ne
garde que le déroulé et les points de rupture.

> Règle d'or : le mot de passe administrateur et le tunnel se règlent **avant**
> que le routeur ne voie Internet, et on ne quitte le site qu'après une
> sauvegarde et un test réel avec un code.
>
> Une grande partie de ce document se déroule **à Bukavu, avant le départ**
> (VPS, tunnel, portail, codes) : voir `install/azure-vps-et-test-local.md`.
> Sur site, il ne reste que le matériel et Starlink.

---

## 0. À préparer avant de quitter Bukavu

| Élément | Pourquoi |
|---|---|
| `install/rb2011-base.rsc` avec `IP_DU_VPS` et `CLE_PUBLIQUE_DU_VPS` renseignés | sans ces deux valeurs le tunnel ne se crée pas (le script continue, mais sans accès distant) |
| `install/rb2011-hotspot.rsc` | portail captif sur le bridge `lan` |
| `install/export-usage.rsc` | export de l'usage des tickets et du volume Starlink WAN vers `zinb_usage.csv` |
| Dossier `install/routeros-hotspot/zinbconnect/` (5 pages html) | pages servies par le routeur, aucun CDN |
| `install/verification-rb2011.md` | la checklist à dérouler sur place |
| Accès SSH au VPS (mot de passe ou clé) | déclarer la clé publique du routeur, vérifier `wg show` |
| PC portable + câble Ethernet + adaptateur USB-Ethernet | WinBox et configuration de l'EAP |
| Adaptateur **24 V 1,2 A** du RB2011 | l'EAP225 (10,5 W) tire sur le PoE passif d'ETH10 |
| Un lot de codes déjà généré dans l'admin | pour tester le portail avec un vrai ticket |

Vérifier aussi, côté application, que le prochain lot peut être généré : sans
Internet fiable sur place, mieux vaut l'avoir d'avance.

---

## 1. Câblage (routeur hors tension)

| Port | Vers | Remarque |
|---|---|---|
| ETH1 `eth-wan` | routeur Starlink (LAN) | câble Ethernet standard |
| ETH2–ETH4 | LAN filaire éventuel | laissés actifs (clients) |
| ETH5 | PC portable d'administration | `10.20.50.1/24`, hors hotspot |
| ETH6–ETH9 | — | désactivés dans la config |
| ETH10 `eth-ap` | EAP225-Outdoor | PoE passif, câble **< 30 m** (chute de tension) |
| Alimentation | adaptateur 24 V 1,2 A | alimente aussi le PoE d'ETH10 |

L'EAP doit être **configuré avant** d'être branché sur ETH10 (étape 2).

Après l'import, l'administration ne passe plus que par **ETH5** ou par le
tunnel : le réseau des clients (`10.20.30.0/24`) n'a plus accès à WinBox, SSH
ni WebFig. C'est voulu — sinon n'importe quel client du portail pourrait s'y
connecter.

Le routeur Starlink reste en mode routeur : le RB2011 reçoit une adresse en
`100.64.x.x` par DHCP. C'est normal (CGNAT), et c'est précisément pourquoi
l'accès distant passe par le tunnel WireGuard.

---

## 2. Configurer l'EAP225-Outdoor (avant de le brancher au RB2011)

L'EAP n'est pas géré par un contrôleur : il travaille en autonome.

1. Brancher le PC directement sur l'EAP (ou sur son injecteur PoE), ouvrir
   l'interface web de l'EAP (adresse par défaut `192.168.0.254`, voir
   l'étiquette du boîtier).
2. Définir le mot de passe d'administration de l'EAP (à noter, il ne va pas
   dans ce dépôt).
3. Réseau Wi-Fi : SSID `Zinbconnect Kitutu`.
   - **Recommandé : réseau ouvert**, l'accès étant contrôlé par le code du
     portail (un mot de passe WPA2 en plus ferait deux barrières à expliquer
     au client).
   - Si vous préférez un WPA2, gardez-le simple à dicter ; le hotspot
     s'applique dans les deux cas.
4. Bande 2,4 GHz uniquement, canal fixe **1, 6 ou 11** (le moins encombré sur
   place), puissance au maximum.
5. Désactiver tout DHCP/serveur sur l'EAP : c'est le RB2011 qui distribue.
6. Débrancher, puis brancher l'EAP sur **ETH10**.

---

## 3. Réinitialiser et sécuriser le routeur

1. Mettre sous tension, attendre le démarrage (~1 min).
2. WinBox par MAC (onglet **Neighbors**), ou `192.168.88.1` si une config par
   défaut subsiste.
3. **System > Reset Configuration**, cocher **No Default Configuration**,
   puis **Reset Configuration**. Le routeur redémarre vierge (pas de WAN, pas
   d'utilisateur autre que `admin` sans mot de passe).
4. Reconnecter WinBox, puis **immédiatement** :

```
/user set admin password="VOTRE-MOT-DE-PASSE"
```

5. Ce n'est qu'ensuite que l'on branche le câble Starlink sur ETH1.
6. Après l'import de `rb2011-base.rsc`, continuer en WinBox par **MAC**
   (Neighbors) : les services IP ne répondent plus qu'à `10.20.50.x` et au
   tunnel. Pour repasser en IP, brancher le PC sur ETH5 (il reçoit une adresse
   en `10.20.50.x`).

À ce stade le routeur est injoignable depuis Internet (aucune config WAN) :
c'est l'ordre voulu.

---

## 4. Ordre des imports

### 4.1 Déposer les fichiers

Dans WinBox > **Files**, glisser-déposer :

- `rb2011-base.rsc`
- `rb2011-hotspot.rsc`
- `export-usage.rsc`
- le dossier **`zinbconnect`** en gardant son nom, avec les 5 pages
  `login.html`, `alogin.html`, `status.html`, `logout.html`, `error.html`
  à l'intérieur (pas de sous-dossier).

Si le glisser-déposer échoue, alternative en terminal :

```
/tool fetch url="http://<IP-DU-PC>:8000/rb2011-base.rsc" mode=http
```

(serveur HTTP lancé sur le PC, dossier des fichiers servi).

### 4.2 Base

```
/import file-name=rb2011-base.rsc
```

Attendre la fin, puis vérifier tout de suite que le WAN monte :

```
/ip dhcp-client print
```

`status: bound` = le routeur a Internet. Tant que ce n'est pas le cas, ne pas
continuer : les étapes suivantes supposent NTP et DNS opérationnels.

### 4.3 Hotspot

```
/import file-name=rb2011-hotspot.rsc
```

Le log doit afficher `rb2011-hotspot.rsc : hotspot actif sur lan ...`. S'il
affiche `a VERIFIER`, le dossier de pages n'est pas au bon endroit :
`/ip hotspot profile print` doit montrer `html-directory: zinbconnect`.

### 4.4 Clé publique du routeur → VPS

```
/interface wireguard print
```

Copier la `public-key` de `wg-relay` dans le `wg0.conf` du VPS, côté pair
`kitutu`, puis sur le VPS :

```
wg set wg0 peer <clé-du-routeur> allowed-ips 10.20.40.2/32
wg-quick save wg0        # ou éditer wg0.conf puis systemctl restart wg-quick@wg0
wg show
```

Le handshake doit apparaître dans la seconde (le routeur envoie un keepalive
toutes les 25 s).

### 4.5 Premier lot de codes

Dans l'admin : générer/vendre un petit lot de test (1 ou 2 codes), cliquer
**Import MikroTik (.rsc)**, puis déposer le fichier sur le routeur et :

```
/import file-name=zinbconnect_<lot>.rsc
/ip hotspot user print count-only
```

Le fichier téléchargé porte le nom `zinbconnect_<référence du lot>.rsc` (le
même nom que celui affiché par le bouton **Import MikroTik**).

---

## 5. Tests avant de quitter le site

Dérouler `install/verification-rb2011.md`. Minimum vital :

1. `/interface wireguard peers print detail` → `last-handshake` récent.
2. `/ip service print` → `telnet`/`ftp`/`api` désactivés, WinBox limité au LAN
   et au tunnel.
3. Téléphone : Wi-Fi → page `zinbconnect` → code → navigation Internet.
4. `/ip hotspot active print` montre le code connecté.
5. WinBox depuis le tunnel (`10.20.40.2`) **depuis un autre réseau** si
   possible — sinon, ce test se fait depuis Bukavu le soir même.
6. EAP : liaison `eth-ap` avec le drapeau **R** et PoE actif.

---

## 6. Rollback (par ordre de gravité)

### Niveau 1 — une règle est fausse, le routeur répond

Réimporter le script concerné : les deux sont écrits pour être rejoués sans
casser l'existant (`:do { } on-error={ }` empêche les doublons d'échouer).

```
/import file-name=rb2011-base.rsc
/import file-name=rb2011-hotspot.rsc
```

### Niveau 2 — retour à l'état d'avant la modification

Si une sauvegarde a été faite (`/export file-name=avant-verif` +
`/system backup save name=avant-verif`) :

```
/import file-name=avant-verif.rsc          # configuration lisible
/system backup load name=avant-verif.backup  # restauration complète
```

Attention : une `.backup` n'est rechargeable que sur la **même version** de
RouterOS. Ici, 7.25.1.

### Niveau 3 — le portail bloque les clients (urgence commerciale)

Le hotspot peut être coupé sans toucher au reste : les clients reçoivent une
adresse et naviguent librement, le temps de corriger.

```
/ip hotspot set [find name=hotspot1] disabled=yes
```

Réactivation : `disabled=no`. À n'utiliser qu'en dépannage : c'est du Wi-Fi
gratuit.

### Niveau 4 — le routeur ne répond plus (WinBox, MAC, série)

1. **Bouton reset** : maintenir enfoncé à la mise sous tension jusqu'à ce que
   la LED `ACT` clignote ; relâcher. Config remise à zéro, puis reprendre à
   l'étape 3.
2. **Netinstall** (dernier recours, PC Windows + câble direct sur ETH1) :
   réinstalle RouterOS 7.25.1 proprement. Les scripts du dépôt sont la source
   de vérité, tout est reconstructible en ~10 minutes.

### Ce qu'il ne faut PAS faire

- **Ne pas revenir en RouterOS 6** : les scripts ciblent la v7 (WireGuard, NTP,
  syntaxe), et les sauvegardes v7 ne se restaurent pas en v6.
- Ne pas désactiver `/ip service set winbox address=...` avant d'avoir testé
  le tunnel, sous peine de se couper l'accès depuis Bukavu.

---

## 7. Après le départ du site

- Depuis Bukavu : WinBox sur `10.20.40.2`, tunnel activé.
- À chaque lot vendu : export `.rsc` dans l'admin → import sur le routeur.
  Un code révoqué disparaît au réimport du lot.
- Sauvegardes régulières : `/export file-name=hebdo` et
  `/system backup save name=hebdo`.
- Surveiller la consommation Starlink (500 Go/mois) : le hotspot ne la compte
  pas, il applique seulement les quotas par ticket.
