# =====================================================================
# Zinbconnect - Kitutu (RDC)
# RB2011UiAS-2HnD-IN - hotspot / portail captif (rb2011-hotspot.rsc)
# Cible : RouterOS 7.25.1, architecture mipsbe (RB2011UiAS-2HnD-IN).
# ---------------------------------------------------------------------
# A IMPORTER APRES rb2011-base.rsc (il suppose : bridge "lan",
# 10.20.30.1/24, DHCP actif, ETH10-PoE vers l'EAP225-Outdoor).
#
# Avant l'import (une seule fois) : WinBox > Files, deposer le dossier
# "zinbconnect" (install/routeros-hotspot/zinbconnect de ce depot) en
# gardant son nom, puis :
#   /import file-name=rb2011-hotspot.rsc
#
# Pages requises par RouterOS 7 (a la racine du dossier "zinbconnect") :
#   login.html, alogin.html, status.html, logout.html, error.html
# md5.js n'est PAS necessaire : login.html embarque son propre MD5.
#
# Le script est rejouable : chaque objet est cree s'il manque, mis a
# jour s'il existe (aucune erreur "already have such..." au re-import).
# =====================================================================

# ---------- 1. Profil hotspot ----------
# login-by=http-chap : le routeur tire un defi a chaque affichage de la
# page de login (variables $(chap-id) et $(chap-challenge)), et le
# navigateur renvoie MD5(defi + code). Le code ne circule donc jamais en
# clair, ce qui compte sur un Wi-Fi ouvert (aucun WPA2).
#
# Valeurs valides en RouterOS 7 : cookie, http-chap, http-pap, https,
# mac, mac-cookie, trial.
# Reconexion transparente sans retaper le code :
# mac-cookie et cookie permettent a RouterOS de se souvenir de l'adresse MAC
# du telephone pendant 3 jours (ou la duree du forfait). Si l'utilisateur part
# et revient, il est reconnecte automatiquement et son forfait continue sans ressaisie !
:if ([:len [/ip hotspot profile find name=zinbconnect]] = 0) do={
  /ip hotspot profile add name=zinbconnect hotspot-address=10.20.30.1 html-directory=zinbconnect login-by=http-chap,mac-cookie,cookie http-cookie-lifetime=3d
} else={
  /ip hotspot profile set [find name=zinbconnect] hotspot-address=10.20.30.1 html-directory=zinbconnect login-by=http-chap,mac-cookie,cookie http-cookie-lifetime=3d
}

# ---------- 2. Serveur hotspot ----------
# Cible le bridge "lan" (ETH2-4 filaire + ETH10/PoE : tout le Wi-Fi de
# l'EAP225 passe par la). address-pool par defaut : les adresses restent
# servies par le DHCP existant de rb2011-base.rsc.
:if ([:len [/ip hotspot find name=hotspot1]] = 0) do={
  /ip hotspot add name=hotspot1 interface=lan profile=zinbconnect disabled=no
} else={
  /ip hotspot set [find name=hotspot1] interface=lan profile=zinbconnect disabled=no
}

# ---------- 3. Walled garden (Autorisations avant login) ----------
# Avant connexion, le DNS local et le diagnostic passent.
# De plus, le VPS Azure (20.164.1.221) est autorise a la fois au niveau HTTP
# (/ip hotspot walled-garden) et au niveau IP (/ip hotspot walled-garden ip)
# pour permettre aux revendeurs et administrateurs d'ouvrir reseller.php et admin.php
# depuis le Wi-Fi sans etre interceptes par le portail !

# Walled-garden HTTP (interception port 80 - indispensable pour http://20.164.1.221/)
:if ([:len [/ip hotspot walled-garden find dst-host="20.164.1.221"]] = 0) do={
  /ip hotspot walled-garden add dst-host=20.164.1.221 comment="Acces VPS Azure Zinbconnect"
}
:if ([:len [/ip hotspot walled-garden find dst-host="10.100.0.1"]] = 0) do={
  /ip hotspot walled-garden add dst-host=10.100.0.1 comment="Acces WireGuard VPS"
}

# Walled-garden IP (couche 3 brute et ICMP)
:if ([:len [/ip hotspot walled-garden ip find comment="DNS vers la passerelle avant login"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 dst-address=10.20.30.1 protocol=udp dst-port=53 comment="DNS vers la passerelle avant login"
}
:if ([:len [/ip hotspot walled-garden ip find comment="DNS TCP avant login"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 dst-address=10.20.30.1 protocol=tcp dst-port=53 comment="DNS TCP avant login"
}
:if ([:len [/ip hotspot walled-garden ip find comment="Diagnostic ping"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 protocol=icmp comment="Diagnostic ping"
}
:if ([:len [/ip hotspot walled-garden ip find comment="Acces VPS Azure Zinbconnect"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no dst-address=20.164.1.221 comment="Acces VPS Azure Zinbconnect"
}
:if ([:len [/ip hotspot walled-garden ip find comment="Acces WireGuard VPS"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no dst-address=10.100.0.1 comment="Acces WireGuard VPS"
}

# ---------- 4. Profil utilisateurs hotspot (les codes) ----------
# shared-users=1 : un code = UN appareil connecte a la fois. Un second
# appareil qui se connecte avec le meme code est refuse tant que le
# premier est en session (c'est la limite demandee cote commerce).
# Les limites de temps et de donnees restent posees utilisateur par
# utilisateur par l'export .rsc de l'application (limit-uptime,
# limit-bytes-total), le profil ne fait que fixer le nombre d'appareils.
# rate-limit="512k/2M" : bride chaque client a 2 Mbit/s en telechargement
# et 512 kbit/s en envoi. Suffisant pour WhatsApp, audio et video fluide en 480p,
# mais bloque le streaming 1080p/4K qui vide le quota Starlink en 30 minutes.
:if ([:len [/ip hotspot user profile find name=code-usable]] = 0) do={
  /ip hotspot user profile add name=code-usable shared-users=1 rate-limit="512k/2M"
} else={
  /ip hotspot user profile set [find name=code-usable] shared-users=1 rate-limit="512k/2M"
}

# ---------- 5. Validite des tickets (fenetre des la 1re connexion + expiration des codes inutilises) ----------
# Probleme resolu ici : limit-uptime compte le temps CONNECTE cumule. Un
# ticket "24h" utilise 10 minutes par jour resterait valable des semaines.
# RouterOS n'a aucun champ "expire le ...", on le fabrique :
#
#   - l'application ecrit dans le commentaire de chaque code :
#         exp=0000000000 w=00086400 u=0000000000 <lot>
#     (exp = date de 1re connexion en epoch, 0 = pas encore commence ;
#      w   = duree de validite en secondes apres 1re connexion ;
#      u   = date limite d'expiration si JAMAIS utilise, 0 ou epoch)
#   - toutes les 2 minutes, ce planificateur :
#       1. horodate les codes qui viennent de servir (bytes-in > 0) avec l'epoch
#          actuelle calculee par [:tonsec [:timestamp]] / 1000000000 ;
#       2. supprime ceux dont exp + w est depasse ;
#       3. supprime les codes jamais utilises depassant u (expiration du stock).
#
# Garde-fou horloge : si la synchro NTP n'a pas encore eu lieu au boot du RB2011
# ($now < 1700000000), le script ne fait RIEN. Aucune fausse expiration.
#
# Format et longueurs : exp= + 10 chiffres + " w=" + 8 chiffres + " u=" + 10 chiffres + " " + lot
#   positions 0-3 "exp=", 4-13 date, 14-16 " w=", 17-24 fenetre, 25-27 " u=", 28-37 fin de stock.
/system script remove [find name=code-validite-script]
/system script add name=code-validite-script policy=read,write dont-require-permissions=no source=":local now ([:tonsec [:timestamp]] / 1000000000); :if (\$now > 1700000000) do={ :foreach u in=[/ip hotspot user find where comment~\"exp=\"] do={ :local c [/ip hotspot user get \$u comment]; :local e [:tonum [:pick \$c 4 14]]; :if (\$e = 0) do={ :if ([/ip hotspot user get \$u bytes-in] > 0) do={ /ip hotspot user set \$u comment=(\"exp=\" . \$now . [:pick \$c 14 [:len \$c]]) } else={ :if ([:pick \$c 26 28] = \"u=\") do={ :local uexp [:tonum [:pick \$c 28 38]]; :if (\$uexp > 1700000000 and \$now > \$uexp) do={ /ip hotspot user remove \$u } } } } else={ :local w [:tonum [:pick \$c 17 25]]; :if (\$now > (\$e + \$w)) do={ /ip hotspot user remove \$u } } } }"

/system scheduler remove [find name=code-validite]
/system scheduler add name=code-validite interval=2m policy=read,write on-event="/system script run code-validite-script"

# ---------- 5 bis. Protection anti-brute-force sur le portail ----------
# Limite les rafales de requetes vers le serveur web du hotspot (max 15/min par client)
# pour empecher un assaillant de deviner les codes par force brute.
:if ([:len [/ip firewall filter find comment="Anti-brute-force portail : rythme normal"]] = 0) do={
  :local pos [/ip firewall filter find comment="LAN clients (DHCP, DNS, portail)"]
  :if ([:len $pos] > 0) do={
    /ip firewall filter add chain=input in-interface=lan protocol=tcp dst-port=80 connection-state=new dst-limit=15/1m,5,src-address/1m action=accept comment="Anti-brute-force portail : rythme normal" place-before=($pos->0)
    /ip firewall filter add chain=input in-interface=lan protocol=tcp dst-port=80 connection-state=new action=drop comment="Anti-brute-force portail : blocage rafales" place-before=($pos->0)
  } else={
    /ip firewall filter add chain=input in-interface=lan protocol=tcp dst-port=80 connection-state=new dst-limit=15/1m,5,src-address/1m action=accept comment="Anti-brute-force portail : rythme normal"
    /ip firewall filter add chain=input in-interface=lan protocol=tcp dst-port=80 connection-state=new action=drop comment="Anti-brute-force portail : blocage rafales"
  }
}

# ---------- 6. Verification ----------
# Deux conditions simples plutot qu'un "find where a=1 and b=2" : rien a
# interpreter de travers au re-import.
:do {
  :if ([/ip hotspot get [find name=hotspot1] disabled] = true) do={
    :log warning "rb2011-hotspot.rsc : a VERIFIER - serveur hotspot1 desactive"
  } else={
    :log info "rb2011-hotspot.rsc : hotspot actif sur lan (10.20.30.1), pages zinbconnect, login CHAP, validite des tickets armee"
  }
} on-error={
  :log warning "rb2011-hotspot.rsc : a VERIFIER - serveur hotspot1 absent (rb2011-base.rsc importe ?)"
}
