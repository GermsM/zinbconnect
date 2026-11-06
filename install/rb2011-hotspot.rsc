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
# ATTENTION : l'ancien script utilisait "login-by=http-pam", qui n'existe
# pas. L'import echouait sur cette ligne, le profil n'etait pas cree et
# le hotspot non plus : aucun portail ne fonctionnait.
#
# Si un jour le portail passe en HTTPS, ajouter : login-by=https,http-chap
# cookie peut etre ajoute (login-by=http-chap,cookie) pour eviter de
# ressaisir le code a chaque reveil du telephone.
:if ([:len [/ip hotspot profile find name=zinbconnect]] = 0) do={
  /ip hotspot profile add name=zinbconnect hotspot-address=10.20.30.1 html-directory=zinbconnect login-by=http-chap
} else={
  /ip hotspot profile set [find name=zinbconnect] hotspot-address=10.20.30.1 html-directory=zinbconnect login-by=http-chap
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

# ---------- 3. Walled garden (minimal en Voie 1) ----------
# Avant connexion, seul le DNS local et le diagnostic passent : la page
# de login est servie PAR le routeur, aucun site externe n'est requis.
:if ([:len [/ip hotspot walled-garden ip find comment="DNS vers la passerelle avant login"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 dst-address=10.20.30.1 protocol=udp dst-port=53 comment="DNS vers la passerelle avant login"
}
:if ([:len [/ip hotspot walled-garden ip find comment="DNS TCP avant login"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 dst-address=10.20.30.1 protocol=tcp dst-port=53 comment="DNS TCP avant login"
}
:if ([:len [/ip hotspot walled-garden ip find comment="Diagnostic ping"]] = 0) do={
  /ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 protocol=icmp comment="Diagnostic ping"
}

# ---------- 4. Profil utilisateurs hotspot (les codes) ----------
# shared-users=1 : un code = UN appareil connecte a la fois. Un second
# appareil qui se connecte avec le meme code est refuse tant que le
# premier est en session (c'est la limite demandee cote commerce).
# Les limites de temps et de donnees restent posees utilisateur par
# utilisateur par l'export .rsc de l'application (limit-uptime,
# limit-bytes-total), le profil ne fait que fixer le nombre d'appareils.
:if ([:len [/ip hotspot user profile find name=code-usable]] = 0) do={
  /ip hotspot user profile add name=code-usable shared-users=1 comment="Tickets zinbconnect : un appareil a la fois"
} else={
  /ip hotspot user profile set [find name=code-usable] shared-users=1
}

# ---------- 5. Validite des tickets (fenetre des la 1re connexion) ----------
# Probleme resolu ici : limit-uptime compte le temps CONNECTE cumule. Un
# ticket "24h" utilise 10 minutes par jour resterait valable des semaines.
# RouterOS n'a aucun champ "expire le ...", on le fabrique :
#
#   - l'application ecrit dans le commentaire de chaque code :
#         exp=0000000000 w=00086400 <lot>
#     (exp = date de 1re connexion, 0 = pas encore commence ; w = duree
#      de validite en secondes, sur 8 chiffres ; puis la reference du lot)
#   - toutes les 2 minutes, ce planificateur :
#       1. horodate les codes qui viennent de servir (bytes-in > 0) ;
#       2. supprime ceux dont exp + w est depasse.
#
# Le code ne peut donc plus etre utilise apres la fenetre, meme s'il lui
# restait du temps : c'est voulu ("24h apres la premiere utilisation").
# Si la synchro NTP n'a pas encore eu lieu, l'horodatage est repousse au
# prochain passage (aucune date fausse enregistree).
#
# Format et longueurs : exp= + 10 chiffres + " w=" + 8 chiffres + " " + lot
#   positions 0-3 "exp=", 4-13 date, 14 espace, 15-16 "w=", 17-24 duree.
/system scheduler remove [find name=code-validite]
/system scheduler add name=code-validite interval=2m policy=read,write on-event=":foreach u in=[/ip hotspot user find where comment~\"exp=\"] do={ :local c [/ip hotspot user get \$u comment]; :local e [:tonum [:pick \$c 4 14]]; :if (\$e = 0) do={ :if ([/ip hotspot user get \$u bytes-in] > 0) do={ :local n [:timestamp]; :if (\$n > 1600000000) do={ /ip hotspot user set \$u comment=(\"exp=\" . \$n . [:pick \$c 14 [:len \$c]]) } } } else={ :local w [:tonum [:pick \$c 17 25]]; :if ([:timestamp] > (\$e + \$w)) do={ /ip hotspot user remove \$u } } }"

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
