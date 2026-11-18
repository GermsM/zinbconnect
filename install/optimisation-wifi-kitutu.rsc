# ====================================================================
# Optimisation Wi-Fi Interne RB2011 - Kitutu (Zinbconnect)
# 1. Pleine puissance pour traverser les murs des voisins proches
# 2. Plafond strict a 15 stations max pour proteger le CPU
# 3. Access List anti-client collant (ejection sous -73 dBm)
# ====================================================================

/interface wireless
set [ find default-name=wlan1 ] tx-power-mode=default max-station-count=15

:do { /interface wireless access-list remove [find interface=wlan1] } on-error={ }

/interface wireless access-list
add comment="Clients proches avec bon signal" forwarding=yes interface=wlan1 signal-range=-73..120
add authentication=no comment="Ejection automatique vers TP-Link Outdoor" forwarding=no interface=wlan1 signal-range=-120..-74
