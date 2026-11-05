# =====================================================================
# Zinbconnect - Kitutu (RDC)
# RB2011UiAS-2HnD-IN - configuration de base (rb2011-base.rsc)
# Cible : RouterOS 7.25.1 (version installee et verifiee sur le routeur).
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
# RouterOS 7 : le client NTP se configure uniquement par la liste "servers"
# (primary-ntp / secondary-ntp ont disparu depuis la v7).
/system ntp client set enabled=yes servers=216.239.35.0,216.239.35.4
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
# Le routeur Starlink attribue l'adresse WAN en DHCP.
/ip dhcp-client add interface=eth-wan disabled=no comment="Adresse WAN via Starlink"
/ip firewall nat add chain=srcnat out-interface=eth-wan action=masquerade comment="NAT vers Starlink"

# ---------- 7. Acces distant : tunnel WireGuard vers un VPS relais ----------
# Starlink place le routeur derriere un CGNAT : aucune redirection de port
# entrante n'est possible. Le RB2011 ouvre donc LUI-MEME un tunnel sortant
# (UDP 51820) vers un petit VPS Debian qui, lui, possede une IPv4 publique.
# Depuis Bukavu on joint ensuite le routeur sur 10.20.40.2 (adresse tunnel).
#
# >>> A COMPLETER AVANT IMPORT (2 valeurs) :
#       IP_DU_VPS          = IPv4 publique du VPS
#       CLE_PUBLIQUE_DU_VPS = sortie de "wg show wg0 public-key" sur le VPS
#     Procedure pas a pas : install/acces-distance-wireguard.md
#
# La cle privee du routeur est generee automatiquement par RouterOS :
# apres import, la lire avec /interface wireguard print (colonne public-key)
# et la declarer cote VPS comme cle publique du pair "kitutu".
/interface wireguard add name=wg-relay listen-port=51820 comment="Tunnel vers VPS relais"
/ip address add address=10.20.40.2/24 interface=wg-relay comment="Adresse du RB2011 dans le tunnel"
# on-error : si les deux valeurs ne sont pas encore renseignees, l'import
# continue et un avertissement est ecrit dans le log (pas de blocage).
:do {
  /interface wireguard peers add interface=wg-relay public-key="CLE_PUBLIQUE_DU_VPS" endpoint-address=IP_DU_VPS endpoint-port=51820 allowed-address=10.20.40.0/24 persistent-keepalive=25s comment="VPS relais"
} on-error={
  /log warning "WireGuard : pair VPS non cree - renseigner IP_DU_VPS et CLE_PUBLIQUE_DU_VPS dans rb2011-base.rsc"
}
# keepalive a 25 s : RouterOS oublie une session UDP apres 10 s d'inactivite,
# et le CGNAT Starlink ferme le mappage : sans keepalive le tunnel meurt.

# Filet de securite : apres une coupure de courant le RB2011 n'a pas d'horloge
# avant la synchro NTP, et il arrive qu'un handshake reste bloque. Toutes les
# 5 minutes, si aucun handshake n'a eu lieu depuis 5 minutes, on relance le
# pair (desactiver/reactiver force une nouvelle poignee de main).
# Volontairement sans variable : la commande ne fait rien quand le tunnel est
# sain, et rien ne peut casser au re-import (aucune variable a echapper).
# Controle manuel : /interface wireguard peers print (colonne last-handshake)
/system scheduler add name=wg-watchdog interval=5m policy=read,write,test on-event="/interface wireguard peers disable [find where interface=wg-relay and last-handshake > 0:05:00]; :delay 3s; /interface wireguard peers enable [find where interface=wg-relay]"

# ---------- 8. Securite : filtrage et services d'administration ----------
# IMPORTANT : changez le mot de passe administrateur AVANT de brancher le WAN !
# En New Terminal : /user set admin password="VOTRE-MOT-DE-PASSE"
# (chiffre uniquement dans la config du routeur, jamais dans ce depot)
#
# Les services d'administration n'ecoutent que sur le LAN et le tunnel :
# depuis Internet, il faut deja etre dans le tunnel WireGuard.
:do { /ip service set winbox address=10.20.30.0/24,10.20.40.0/24 } on-error={ }
:do { /ip service set ssh    address=10.20.30.0/24,10.20.40.0/24 } on-error={ }
:do { /ip service set www    address=10.20.30.0/24,10.20.40.0/24 } on-error={ }
:do { /ip service set telnet disabled=yes } on-error={ }
:do { /ip service set ftp    disabled=yes } on-error={ }
:do { /ip service set api    disabled=yes } on-error={ }

# Filtrage entrant minimal (sans config par defaut, RouterOS n'en met aucun).
/ip firewall filter
add chain=input action=accept connection-state=established,related comment="Reponses aux connexions ouvertes"
add chain=input action=accept protocol=icmp comment="Ping (diagnostic)"
add chain=input action=accept protocol=udp dst-port=68 in-interface=eth-wan comment="Bail DHCP du routeur Starlink"
add chain=input action=accept protocol=udp dst-port=51820 in-interface=eth-wan comment="WireGuard (handshake cote VPS)"
add chain=input action=accept in-interface=lan comment="LAN : acces complet (site)"
add chain=input action=accept in-interface=wg-relay comment="Tunnel : acces complet (admin a distance)"
add chain=input action=drop in-interface=eth-wan comment="Rien d'autre depuis Starlink (CGNAT)"
add chain=forward action=accept connection-state=established,related comment="Trafic clients etabli"
add chain=forward action=drop connection-state=invalid comment="Trafic client invalide"

:log info "rb2011-base.rsc : configuration de base appliquee - WAN eth-wan (DHCP), LAN 10.20.30.1/24, PoE-Out eth10 actif, tunnel wg-relay 10.20.40.2"
