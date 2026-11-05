# =====================================================================
# Zinbconnect - Kitutu (RDC)
# RB2011UiAS-2HnD-IN - hotspot / portail captif (rb2011-hotspot.rsc)
# Cible : RouterOS 7.25.1 (version installee et verifiee sur le routeur).
# ---------------------------------------------------------------------
# A IMPORTER APRES rb2011-base.rsc (il suppose : bridge "lan",
# 10.20.30.1/24, DHCP actif, ETH10-PoE vers l'EAP225-Outdoor).
#
# Avant l'import (une seule fois) :
#   1. WinBox > Files : deposer le dossier "zinbconnect" des pages du
#      hotspot (install/routeros-hotspot/zinbconnect de ce depot), via
#      FTP ou glisser-deposer dans WinBox, en gardant son nom.
#   2. New Terminal : /import file-name=rb2011-hotspot.rsc
#
# Architecture "import periodique" (Voie 1) :
#   - le routeur est la barriere : il valide les codes dans sa liste
#     utilisateur hotspot et applique temps et quotas ;
#   - l'application PHP exporte les codes des lots (bouton "Import
#     MikroTik" dans l'admin) ; l'admin importe le fichier .rsc depuis
#     Bukavu apres chaque lot vendu ;
#   - retour utilisateur hotspot : /ip hotspot user print
# =====================================================================

# ---------- 1. Profil hotspot ----------
# http-pam : le code saisi par le client est envoyé en clair vers le
# routeur, UNIQUEMENT sur le LAN local (derrière le CGNAT Starlink, le
# trajet reste interne au pont). Sera revu si la Voie 2 passe un jour
# par HTTPS.
/ip hotspot profile add name=zinbconnect hotspot-address=10.20.30.1 html-directory=zinbconnect login-by=http-pam

# ---------- 2. Serveur hotspot ----------
# Cible le bridge "lan" (ETH2-5 filaire + ETH10/PoE : tout le Wi-Fi de
# l'EAP225 passe par la). address-pool par defaut : les adresses restent
# servies par le DHCP existant de rb2011-base.rsc.
/ip hotspot add name=hotspot1 interface=lan profile=zinbconnect disabled=no

# ---------- 3. Walled garden (minimal en Voie 1) ----------
# Avant connexion, seul le DNS local et le diagnostic doivent passer :
# la page de login est servie PAR le routeur, aucun site internet
# externe n'a besoin d'être atteint.
/ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 dst-address=10.20.30.1 protocol=udp dst-port=53 comment="DNS vers la passerelle avant login"
/ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 dst-address=10.20.30.1 protocol=tcp dst-port=53 comment="DNS TCP avant login"
/ip hotspot walled-garden ip add action=accept disabled=no src-address=10.20.30.0/24 protocol=icmp comment="Diagnostic ping"

# ---------- 4. Profile utilisateurs hotspot ----------
# Les "utilisateurs hotspot" sont les codes de tickets : ils n'ont
# aucun droit d'administration. Le profil de base ne limite rien ; les
# limites (temps, données) sont posées par utilisateur dans chaque
# ligne de l'export .rsc généré par l'application.
/ip hotspot user profile add name=code-usable comment="Tickets zinbconnect (limites posées par utilisateur)"

# ---------- 5. Vérification ----------
:if ([:len [/ip hotspot find interface=lan && disabled=no]] > 0) do={
  :log info "rb2011-hotspot.rsc : hotspot actif sur lan (10.20.30.1), pages zinbconnect, validation par code"
} else={
  :log warning "rb2011-hotspot.rsc : a VERIFIER - hotspot absent ou inactif sur lan"
}
