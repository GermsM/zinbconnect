# =====================================================================
# Zinbconnect - Kitutu (RDC)
# RB2011UiAS-2HnD-IN - configuration de base (rb2011-base.rsc)
# ---------------------------------------------------------------------
# A appliquer sur un routeur REINITIALISE sans config par defaut :
#   WinBox > System > Reset Configuration > cocher "No Default Configuration"
#   puis, dans New Terminal :
#   /import file-name=rb2011-base.rsc
#
# Cables :
#   ETH1  : vers le routeur Starlink (WAN)
#   ETH2-5: LAN filaire (reserve, "eth-lan")
#   ETH10 : vers l'EAP225-Outdoor (PoE passif 24 V, "eth-ap")
#
# Notes materiel verifie sur les fiches MikroTik / TP-Link :
#   - ETH10 sort la tension d'entree MOINS 2 V ; avec l'adaptateur 24 V 1,2 A
#     fourni, l'EAP recoit ~22 V 0,5 A (10,5 W max) : OK mais garder le
#     cable ETH10 court (< 30 m) pour limiter la chute de tension.
#   - ETH10 est un port 10/100 : l'EAP se synchronisera a 100 Mbit/s.
#     Largement suffisant devant Starlink (~100 Mbit/s en pointe mais
#     500 Go/mois sont la vraie contrainte).
#   - Ce script ne touche PAS au hotspot : voir rb2011-hotspot.rsc,
#     a rediger apres le choix de l'hebergement du portail.
# =====================================================================

# ---------- 1. Identite, horloge, notes ----------
/system identity set name=zinbconnect-kitutu
/system clock set time-zone-autodetect=no time-zone-name=Africa/Kigali
# Syntaxe NTP differente entre RouterOS 6 (primary-ntp) et RouterOS 7 (servers) :
# les deux formes sont essayees, l'echec de l'une n'arrete pas l'import.
:do { /system ntp client set enabled=yes primary-ntp=216.239.35.0 secondary-ntp=216.239.35.4 } on-error={ }
:do { /system ntp client set enabled=yes servers=216.239.35.0,216.239.35.4 } on-error={ }
/system notes set show-at-login=yes note="Zinbconnect Kitutu - config voir install/rb2011-base.rsc"

# ---------- 2. Ports Ethernet ----------
# WAN Starlink = ETH1 (client DHCP, le routeur Starlink fait la translation)
/interface ethernet set [ find default-name=ether1 ] name=eth-wan comment="WAN Starlink"
/interface ethernet set [ find default-name=ether2 ] comment="LAN filaire"
/interface ethernet set [ find default-name=ether3 ] comment="LAN filaire"
/interface ethernet set [ find default-name=ether4 ] comment="LAN filaire"
/interface ethernet set [ find default-name=ether5 ] comment="LAN filaire"
/interface ethernet set [ find default-name=ether6 ] disabled=yes comment="reserve"
/interface ethernet set [ find default-name=ether7 ] disabled=yes comment="reserve"
/interface ethernet set [ find default-name=ether8 ] disabled=yes comment="reserve"
/interface ethernet set [ find default-name=ether9 ] disabled=yes comment="reserve"
/interface ethernet set [ find default-name=ether10 ] name=eth-ap comment="PoE-Out 24V vers EAP225-Outdoor"

# ---------- 3. PoE-Out passif sur ETH10 ----------
# La tension suit l'adaptateur 24 V (rien de reglable sur RB2011).
# "forced-on" : alimente des la mise sous tension, sans attendre une
# detection de charge (l'EAP passif ne negocie rien, c'est indispensable).
:do {
  /interface ethernet set eth-ap poe-out=forced-on poe-priority=10
} on-error={
  /log warning "PoE-Out non pris en charge : verifier que le modele est bien un RB2011UiAS (port poe-out inexistant sur les RB2011 sans ecran)."
}

# ---------- 4. Bridge LAN + ports membres ----------
/interface bridge add name=lan comment="Bridge LAN (eth2-5 filaire + eth10 AP Wi-Fi)"
/interface bridge port add bridge=lan interface=ether2
/interface bridge port add bridge=lan interface=ether3
/interface bridge port add bridge=lan interface=ether4
/interface bridge port add bridge=lan interface=ether5
/interface bridge port add bridge=lan interface=eth-ap
# Radio 2,4 GHz integree desactivee : l'EAP225-Outdoor est le point d'acces unique.
:do { /interface wireless set wlan1 disabled=yes } on-error={ }

# ---------- 5. Adressage LAN ----------
/ip address add address=10.20.30.1/24 interface=lan comment="Passerelle clients"
/ip pool add name=lan-pool ranges=10.20.30.10-10.20.30.254
/ip dhcp-server add name=lan-dhcp interface=lan address-pool=lan-pool lease-time=1h disabled=no comment="DHCP clients"
/ip dhcp-server network add address=10.20.30.0/24 gateway=10.20.30.1 dns-server=10.20.30.1 comment="Reseau clients"

# ---------- 6. DNS + NAT vers Starlink ----------
/ip dns set allow-remote-requests=yes servers=1.1.1.1,9.9.9.9
/ip firewall nat add chain=srcnat out-interface=eth-wan action=masquerade comment="NAT vers Starlink"

# ---------- 7. Securite minimale ----------
# IMPORTANT : changez le mot de passe administrateur AVANT de brancher le WAN !
# En New Terminal : /user set admin password="VOTRE-MOT-DE-PASSE"
# (chiffre uniquement dans la config du routeur, jamais dans ce depot)
:log info "rb2011-base.rsc : configuration de base appliquee - WAN eth-wan, LAN 10.20.30.1/24, PoE-Out eth10 actif"
