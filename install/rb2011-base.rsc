# =====================================================================
# Zinbconnect - Kitutu (RDC)
# RB2011UiAS-2HnD-IN - configuration de base (rb2011-base.rsc)
# Cible : RouterOS 7.25.1, architecture mipsbe (RB2011UiAS-2HnD-IN).
# ---------------------------------------------------------------------
# A appliquer sur un routeur REINITIALISE sans config par defaut :
#   WinBox > System > Reset Configuration > cocher "No Default Configuration"
#   puis, dans New Terminal :
#   /import file-name=rb2011-base.rsc
#
# Cables :
#   ETH1  : vers le routeur Starlink (WAN)
#   ETH2-4: LAN filaire libre (clients, non authentifie par le hotspot)
#   ETH5  : port d'ADMINISTRATION (PC admin, 10.20.50.1/24)
#   ETH6-9: desactives
#   ETH10 : vers l'EAP225-Outdoor (PoE passif 24 V, "eth-ap")
#
# Pourquoi un port d'administration separe (ETH5) :
#   Le Wi-Fi des clients et le LAN filaire partagent le meme sous-reseau
#   (10.20.30.0/24). Autoriser WinBox/SSH depuis 10.20.30.0/24 revenait donc
#   a ouvrir l'administration du routeur a TOUS les clients du hotspot. Les
#   services d'administration ne sont plus accessibles que par le tunnel
#   WireGuard (10.20.40.0/24) et par ce port dedie (10.20.50.0/24).
#   Sur site, sans tunnel encore etabli, on administre via ETH5.
#
# Notes materiel verifie sur les fiches MikroTik / TP-Link :
#   - ETH10 sort la tension d'entree MOINS 2 V ; avec l'adaptateur 24 V 1,2 A
#     fourni, l'EAP recoit ~22 V 0,5 A (10,5 W max) : OK mais garder le
#     cable ETH10 court (< 30 m) pour limiter la chute de tension.
#   - ETH10 est un port 10/100 : l'EAP se synchronisera a 100 Mbit/s.
#     Largement suffisant devant Starlink (~100 Mbit/s en pointe mais
#     500 Go/mois sont la vraie contrainte).
#   - Ce script ne touche PAS au hotspot : voir rb2011-hotspot.rsc.
# =====================================================================

# ---------- 1. Identite, horloge, notes, IPv6 et usure Flash ----------
/system identity set name=zinbconnect-kitutu
/system clock set time-zone-autodetect=no time-zone-name=Africa/Kigali
# RouterOS 7 : le client NTP se configure uniquement par la liste "servers"
# (primary-ntp / secondary-ntp ont disparu depuis la v7).
/system ntp client set enabled=yes servers=216.239.35.0,216.239.35.4
/system notes set show-at-login=yes note="Zinbconnect Kitutu - config voir install/rb2011-base.rsc"

# Desactiver IPv6 (empeche tout contournement du portail captif via IPv6 de Starlink)
:do { /ipv6 settings set disable-ipv6=yes } on-error={ }

# Journaux en memoire uniquement (limite l'usure de la memoire flash NAND du RB2011)
/system logging action set memory memory-lines=1000
:do { /system logging set [find default=yes] action=memory } on-error={ }
:do { /system logging set [find action=disk] action=memory } on-error={ }

# ---------- 2. Ports Ethernet ----------
# WAN Starlink = ETH1 (client DHCP, le routeur Starlink fait la translation)
/interface ethernet set [ find default-name=ether1 ] name=eth-wan comment="WAN Starlink"
/interface ethernet set [ find default-name=ether2 ] comment="LAN filaire"
/interface ethernet set [ find default-name=ether3 ] comment="LAN filaire"
/interface ethernet set [ find default-name=ether4 ] comment="LAN filaire"
# ETH5 : port de service, hors du bridge clients (voir en-tete).
/interface ethernet set [ find default-name=ether5 ] name=eth-admin comment="Administration (PC admin)"
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
# ETH2-4 (filaire) + ETH10 (EAP225). ETH5 reste HORS du bridge : c'est le
# point qui separe l'administration des clients.
/interface bridge add name=lan comment="Bridge LAN (eth2-4 filaire + eth10 AP Wi-Fi)"
/interface bridge port add bridge=lan interface=ether2
/interface bridge port add bridge=lan interface=ether3
/interface bridge port add bridge=lan interface=ether4
/interface bridge port add bridge=lan interface=eth-ap
# Radio 2,4 GHz integree desactivee : l'EAP225-Outdoor est le point d'acces unique.
:do { /interface wireless set wlan1 disabled=yes } on-error={ }

# ---------- 5. Adressage LAN clients ----------
/ip address add address=10.20.30.1/24 interface=lan comment="Passerelle clients"
/ip pool add name=lan-pool ranges=10.20.30.10-10.20.30.254
/ip dhcp-server add name=lan-dhcp interface=lan address-pool=lan-pool lease-time=1h disabled=no comment="DHCP clients"
/ip dhcp-server network add address=10.20.30.0/24 gateway=10.20.30.1 dns-server=10.20.30.1 comment="Reseau clients"

# ---------- 5 bis. Reseau d'administration (ETH5) ----------
# Un seul poste, aucun client : DHCP limite a 10 adresses, pas de hotspot.
/ip address add address=10.20.50.1/24 interface=eth-admin comment="Passerelle admin"
/ip pool add name=admin-pool ranges=10.20.50.10-10.20.50.20
/ip dhcp-server add name=admin-dhcp interface=eth-admin address-pool=admin-pool lease-time=1h disabled=no comment="DHCP port admin"
/ip dhcp-server network add address=10.20.50.0/24 gateway=10.20.50.1 dns-server=10.20.50.1 comment="Reseau admin"

# ---------- 6. DNS + NAT vers Starlink ----------
/ip dns set allow-remote-requests=yes servers=1.1.1.1,9.9.9.9
# Le routeur Starlink attribue l'adresse WAN en DHCP.
/ip dhcp-client add interface=eth-wan disabled=no comment="Adresse WAN via Starlink"
# Couvre aussi bien les clients (10.20.30.0/24) que le port admin (10.20.50.0/24).
/ip firewall nat add chain=srcnat out-interface=eth-wan action=masquerade comment="NAT vers Starlink"
# Forcer les requetes DNS des clients vers le DNS local (empeche le contournement par DNS externe)
/ip firewall nat add chain=dstnat in-interface=lan protocol=udp dst-port=53 action=redirect to-ports=53 comment="Forcer DNS local UDP"
/ip firewall nat add chain=dstnat in-interface=lan protocol=tcp dst-port=53 action=redirect to-ports=53 comment="Forcer DNS local TCP"

# ---------- 7. Acces distant : tunnel WireGuard vers un VPS relais ----------
# Starlink place le routeur derriere un CGNAT : aucune redirection de port
# entrante n'est possible. Le RB2011 ouvre donc LUI-MEME un tunnel sortant
# (UDP 51820) vers un petit VPS Debian qui, lui, possede une IPv4 publique.
# Depuis Bukavu on joint ensuite le routeur sur 10.20.40.2 (adresse tunnel).
#
# >>> A COMPLETER AVANT IMPORT (2 valeurs) :
#       IP_DU_VPS           = IPv4 publique du VPS
#       CLE_PUBLIQUE_DU_VPS = sortie de "wg show wg0 public-key" sur le VPS
#     Procedure pas a pas : install/azure-vps-et-test-local.md
#
# La cle privee du routeur est generee automatiquement par RouterOS :
# apres import, la lire avec /interface wireguard print (colonne public-key)
# et la declarer cote VPS comme cle publique du pair "kitutu".
/interface wireguard add name=wg-relay listen-port=51820 comment="Tunnel vers VPS relais"
/ip address add address=10.20.40.2/24 interface=wg-relay comment="Adresse du RB2011 dans le tunnel"

# Les deux valeurs sont lues dans des variables pour DETECTER le cas
# "placeholder non remplace" : sans cela, RouterOS creait un pair dont
# l'endpoint s'appelait litteralement "IP_DU_VPS" (accepte comme nom DNS,
# donc jamais joignable) et l'import se terminait sans le moindre message.
#
# Le controle porte sur la LONGUEUR de la cle (44 caracteres base64, toujours)
# et non sur une comparaison de texte : une comparaison du type "la valeur
# vaut-elle IP_DU_VPS ?" deviendrait fausse des que l'on remplace le texte
# partout dans le fichier. Le pair est aussi journalise avec son endpoint.
:local wgVpsIp "IP_DU_VPS"
:local wgVpsKey "CLE_PUBLIQUE_DU_VPS"
:if ([:len $wgVpsKey] != 44) do={
  /log warning "WireGuard : pair VPS NON cree - CLE_PUBLIQUE_DU_VPS non renseignee (44 caracteres attendus, cf. wg show wg0 public-key)"
} else={
  :log info ("WireGuard : pair VPS endpoint=" . $wgVpsIp . ":51820")
  :if ([:len [/interface wireguard peers find interface=wg-relay]] = 0) do={
    :do {
      /interface wireguard peers add interface=wg-relay public-key=$wgVpsKey endpoint-address=$wgVpsIp endpoint-port=51820 allowed-address=10.20.40.0/24 persistent-keepalive=25s comment="VPS relais"
    } on-error={
      /log warning ("WireGuard : pair VPS refuse - verifier l'adresse IP_DU_VPS (" . $wgVpsIp . ")")
    }
  } else={
    # Re-import : on met a jour l'endpoint sans recreer le pair (la cle
    # publique du routeur, elle, ne change pas).
    /interface wireguard peers set [find interface=wg-relay] public-key=$wgVpsKey endpoint-address=$wgVpsIp endpoint-port=51820 allowed-address=10.20.40.0/24 persistent-keepalive=25s
  }
}
# keepalive a 25 s : RouterOS oublie une session UDP apres 10 s d'inactivite,
# et le CGNAT Starlink ferme le mappage : sans keepalive le tunnel meurt.

# Filet de securite : apres une coupure de courant le RB2011 n'a pas d'horloge
# avant la synchro NTP, et il arrive qu'un handshake reste bloque. Toutes les
# 5 minutes, si le dernier handshake date de plus de 5 minutes, on relance le
# pair (desactiver/reactiver force une nouvelle poignee de main).
# Un pair sain se re-handshake toutes les ~2 minutes (keepalive) : 5 minutes
# de silence signifient un tunnel mort, pas un tunnel calme.
# Relance uniquement ce qui a ete trouve : un pair desactive a la main le
# reste. Controle manuel : /interface wireguard peers print detail
/system scheduler remove [find name=wg-watchdog]
/system scheduler add name=wg-watchdog interval=5m policy=read,write on-event=":local d [/interface wireguard peers find where interface=wg-relay and last-handshake > 0:05:00]; :if ([:len \$d] > 0) do={ :log warning \"WireGuard : handshake perdu depuis >5min, relance du pair\"; /interface wireguard peers disable \$d; :delay 3s; /interface wireguard peers enable \$d }"

# ---------- 8. Securite : filtrage et services d'administration ----------
# IMPORTANT : changez le mot de passe administrateur AVANT de brancher le WAN !
# En New Terminal : /user set admin password="VOTRE-MOT-DE-PASSE"
# (chiffre uniquement dans la config du routeur, jamais dans ce depot)
#
# Les services d'administration n'ecoutent que sur le tunnel et le port de
# service : NI le Wi-Fi clients, NI le LAN filaire clients (10.20.30.0/24).
# Pour autoriser aussi le LAN clients (confort sur site, MAIS tout client du
# hotspot peut alors atteindre WinBox), ajouter ",10.20.30.0/24" ci-dessous.
:do { /ip service set winbox address=10.20.40.0/24,10.20.50.0/24 } on-error={ }
:do { /ip service set ssh    address=10.20.40.0/24,10.20.50.0/24 } on-error={ }
:do { /ip service set www    address=10.20.40.0/24,10.20.50.0/24 } on-error={ }
:do { /ip service set telnet disabled=yes } on-error={ }
:do { /ip service set ftp    disabled=yes } on-error={ }
:do { /ip service set api    disabled=yes } on-error={ }
# api-ssl / www-ssl sont desactives par defaut : rien a faire.
#
# Acces de secours : si le tunnel tombe ET que le mot de passe admin est
# perdu, il reste le bouton reset du RB2011 (config a reimporter). Le
# MAC-WinBox reste actif sur le bridge (impossible de le limiter au cablage
# sans VLAN : les clients Wi-Fi sont sur le meme segment L2) ; il demande le
# mot de passe administrateur. Pour le couper completement :
#   /tool mac-server mac-winbox set allowed-interface-list=none

# Filtrage entrant minimal (sans config par defaut, RouterOS n'en met aucun).
/ip firewall filter
add chain=input action=accept connection-state=established,related comment="Reponses aux connexions ouvertes"
add chain=input action=accept protocol=icmp comment="Ping (diagnostic)"
add chain=input action=accept protocol=udp dst-port=68 in-interface=eth-wan comment="Bail DHCP du routeur Starlink"
add chain=input action=accept protocol=udp dst-port=51820 in-interface=eth-wan comment="WireGuard (handshake cote VPS)"
add chain=input action=accept in-interface=eth-admin comment="Port d'administration (ETH5)"
add chain=input action=accept in-interface=wg-relay comment="Tunnel : acces complet (admin a distance)"
# LAN clients : necessaire au hotspot (DHCP, DNS, portail). Les services
# d'administration restent hors d'atteinte grace au filtre d'adresse de
# /ip service ci-dessus.
add chain=input action=accept in-interface=lan comment="LAN clients (DHCP, DNS, portail)"
add chain=input action=drop in-interface=eth-wan comment="Rien d'autre depuis Starlink (CGNAT)"
add chain=forward action=accept connection-state=established,related comment="Trafic clients etabli"
add chain=forward action=drop connection-state=invalid comment="Trafic client invalide"

# ---------- 8 bis. Anti-tethering (empeche le partage Wi-Fi par smartphone) ----------
# Force le TTL a 1 vers le LAN clients : le telephone du client recoit la connexion,
# mais s'il tente de partager en Wi-Fi / point d'acces, le TTL tombe a 0 et le paquet est jete.
/ip firewall mangle
add chain=postrouting action=change-ttl new-ttl=set:1 out-interface=lan passthrough=no comment="Anti-tethering: empeche le partage hotspot par telephone"

:log info "rb2011-base.rsc : configuration de base appliquee - WAN eth-wan (DHCP), LAN clients 10.20.30.1/24, port admin 10.20.50.1/24 (ETH5), PoE-Out eth10 actif, tunnel wg-relay 10.20.40.2"
