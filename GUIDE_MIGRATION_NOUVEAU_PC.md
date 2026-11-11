# GUIDE DE MIGRATION VERS UN NOUVEAU PC — ZINBCONNECT

Ce guide explique pas à pas comment réinstaller et reconfigurer votre environnement complet d'administration de **Zinbconnect** si vous achetez un nouvel ordinateur portable ou si vous changez de machine.

---

## 1. Les 3 logiciels indispensables à installer

Sur votre nouveau PC Windows, téléchargez et installez ces 3 outils gratuits :

1. **Git pour Windows** :
   - Téléchargement : https://git-scm.com/download/win
   - Lors de l'installation, laissez toutes les options par défaut.
2. **WinBox 64-bit** (pour administrer le routeur MikroTik) :
   - Téléchargement officiel : https://mikrotik.com/download
   - Placez simplement `WinBox64.exe` sur votre Bureau.
3. **WireGuard pour Windows** (pour vous connecter au routeur à distance depuis Bukavu) :
   - Téléchargement officiel : https://www.wireguard.com/install/

---

## 2. Cloner le projet depuis votre GitHub

Ouvrez **PowerShell** ou **Invite de commandes** sur le nouveau PC :

```powershell
# 1. Allez sur votre disque ou dossier préféré (ex: D: ou C:\Users\VotreNom)
cd C:\Users\VotreNom\Documents

# 2. Téléchargez tout votre projet depuis votre dépôt officiel GitHub :
git clone https://github.com/GermsM/zinbconnect.git

# 3. Entrez dans le dossier :
cd zinbconnect
```

Vous avez instantanément l'intégralité du code source, des scripts MikroTik, des templates de tickets, du portail captif et des guides techniques !

---

## 3. Configurer l'accès SSH au VPS Azure sur le nouveau PC

Pour vous connecter au serveur Azure (`20.164.1.221`) depuis le nouveau PC, deux méthodes simples :

### Méthode A (La plus rapide : copier votre ancienne clé SSH)
Si vous avez encore votre ancien PC :
1. Sur l'ancien PC, allez dans le dossier `C:\Users\mwang\.ssh\`
2. Copiez les deux fichiers `id_ed25519` et `id_ed25519.pub` sur une clé USB.
3. Sur le nouveau PC, collez-les dans `C:\Users\VotreNom\.ssh\`
4. Testez la connexion en ouvrant PowerShell :
   ```powershell
   ssh.exe azureuser@20.164.1.221
   ```

### Méthode B (Générer une nouvelle clé SSH)
Si vous n'avez plus l'ancien PC :
1. Dans PowerShell sur le nouveau PC, tapez :
   ```powershell
   ssh-keygen -t ed25519
   ```
   (Appuyez sur Entrée 3 fois).
2. Affichez votre nouvelle clé publique :
   ```powershell
   cat ~/.ssh/id_ed25519.pub
   ```
3. Connectez-vous sur le **Portail Microsoft Azure** (https://portal.azure.com) :
   - Ouvrez votre machine virtuelle `vps-zinbconnect`.
   - Dans le menu de gauche, descendez jusqu'à **Aide > Réinitialiser le mot de passe** (ou **Reset password**).
   - Choisissez **Réinitialiser la clé publique SSH**.
   - Nom d'utilisateur : `azureuser`.
   - Collez votre nouvelle clé publique et cliquez sur **Mettre à jour**.
4. Vous pouvez désormais vous connecter immédiatement !

---

## 4. Configurer WireGuard sur le nouveau PC (Accès distant Bukavu ➔ Kitutu)

1. Ouvrez l'application **WireGuard** sur votre nouveau PC.
2. Cliquez sur **Ajouter un tunnel** > **Ajouter un tunnel vide...**
3. Nommez le tunnel : `Zinbconnect`
4. WireGuard génère automatiquement une clé privée et une clé publique.
5. Collez la configuration suivante dans la fenêtre :

```ini
[Interface]
PrivateKey = (Laisser la clé privée générée par WireGuard sur votre nouveau PC)
Address = 10.100.0.10/24
DNS = 1.1.1.1

[Peer]
# VPS Azure Relais
PublicKey = e3lbURoU6V/ySoAnQwTn0UQWIFttaDN4uKJNLH9cJg4=
Endpoint = 20.164.1.221:51820
AllowedIPs = 10.100.0.0/24
PersistentKeepalive = 25
```

6. **Déclarer votre nouveau PC sur le VPS Azure** :
   - Dans la fenêtre WireGuard de votre PC, notez votre **Clé publique** affichée en haut.
   - Connectez-vous en SSH au VPS :
     ```bash
     ssh.exe azureuser@20.164.1.221
     ```
   - Éditez `/etc/wireguard/wg0.conf` :
     ```bash
     sudo nano /etc/wireguard/wg0.conf
     ```
   - Sous la section `[Peer]` de votre PC admin (`10.100.0.10`), remplacez la ligne `PublicKey = ...` par la nouvelle clé publique de votre nouveau PC.
   - Sauvegardez (`Ctrl + O`, `Entrée`, puis `Ctrl + X`).
   - Redémarrez WireGuard sur le VPS :
     ```bash
     sudo systemctl restart wg-quick@wg0
     ```

7. **Test immédiat** :
   - Dans WireGuard sur votre PC, cliquez sur **Activer**.
   - Ouvrez PowerShell et tapez :
     ```powershell
     ping 10.100.0.1
     ```
   - La réponse doit être immédiate (0 % de perte).

---

## 5. Lancer WinBox et administrer le routeur à distance

1. Lancez **WinBox**.
2. Dans le champ **Connect To**, tapez : `10.100.0.2`
3. Dans **Login**, tapez : `admin`
4. Saisissez votre mot de passe administrateur MikroTik.
5. Cliquez sur **Connect**.
6. Vous êtes connecté sur le routeur de Kitutu exactement comme si vous étiez assis à côté avec un câble !

---

## 6. Récapitulatif des identifiants & liens à garder en favoris

Enregistrez ces liens dans les favoris du navigateur de votre nouveau PC :
* **Console d'administration Zinbconnect** : http://20.164.1.221/admin.php
* **Espace Revendeurs** : http://20.164.1.221/reseller.php
* **Dépôt GitHub officiel** : https://github.com/GermsM/zinbconnect
* **Portail Azure** : https://portal.azure.com

