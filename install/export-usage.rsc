# =====================================================================
# Zinbconnect - Kitutu (RDC)
# RB2011UiAS-2HnD-IN - Export de l'usage des tickets et du volume Starlink
# Cible : RouterOS 7.25.1
# ---------------------------------------------------------------------
# Ce script lit la consommation en temps et en octets de tous les tickets
# hotspot sur le routeur et les compteurs de l'interface WAN Starlink,
# puis ecrit le resultat dans le fichier "zinb_usage.csv" (WinBox > Files).
#
# L'administrateur telecharge ensuite "zinb_usage.csv" et l'importe dans
# la console admin de Zinbconnect (onglet "Consommation").
#
# Execution manuelle en New Terminal :
#   /import file-name=export-usage.rsc
# Ou clic sur "Run Script" dans WinBox > System > Scripts > export-usage
# =====================================================================

:local fileName "zinb_usage.csv"
:local wanRx 0
:local wanTx 0

# Lecture des compteurs WAN (interface eth-wan vers Starlink)
:do {
  :set wanRx [/interface get [find name=eth-wan] rx-byte]
  :set wanTx [/interface get [find name=eth-wan] tx-byte]
} on-error={
  :log warning "export-usage : impossible de lire eth-wan"
}

:local content ("#WAN_RX=" . $wanRx . ",WAN_TX=" . $wanTx . "\r\ncode,uptime,bytes_in,bytes_out,bytes_total,comment\r\n")

# Parcours des utilisateurs hotspot
:foreach i in=[/ip hotspot user find] do={
  :local uName [/ip hotspot user get $i name]
  :local uTime [/ip hotspot user get $i uptime]
  :local bIn   [/ip hotspot user get $i bytes-in]
  :local bOut  [/ip hotspot user get $i bytes-out]
  :local bTot  ($bIn + $bOut)
  :local uCom  [/ip hotspot user get $i comment]
  
  :if ([:len $uTime] = 0) do={ :set $uTime "0s" }
  :if ([:len $bIn] = 0)   do={ :set $bIn 0 }
  :if ([:len $bOut] = 0)  do={ :set $bOut 0 }
  :if ([:len $bTot] = 0)  do={ :set $bTot 0 }

  :local row ($uName . "," . $uTime . "," . $bIn . "," . $bOut . "," . $bTot . ",\"" . $uCom . "\"\r\n")
  :set content ($content . $row)
}

# Nettoyage des anciens exports pour eviter les doublons ou conflits
:do { /file remove [find name="zinb_usage.csv"] } on-error={}
:do { /file remove [find name="zinb_usage.csv.txt"] } on-error={}
:do { /file remove [find name="flash/zinb_usage.csv"] } on-error={}
:do { /file remove [find name="flash/zinb_usage.csv.txt"] } on-error={}

# 1. Methode RouterOS 7 native (/file add)
:local created false
:do {
  /file add name="zinb_usage.csv" contents=$content
  :set created true
} on-error={}

# 2. Methode RouterOS classique de secours (/file print puis /file set)
:if ($created = false) do={
  /file print file="zinb_usage.csv"
  :delay 1s
  :local f [/file find name="zinb_usage.csv"]
  :if ([:len $f] = 0) do={ :set f [/file find name="zinb_usage.csv.txt"] }
  :if ([:len $f] = 0) do={ :set f [/file find name="flash/zinb_usage.csv"] }
  :if ([:len $f] = 0) do={ :set f [/file find name="flash/zinb_usage.csv.txt"] }
  :if ([:len $f] > 0) do={
    /file set $f contents=$content
  }
}

:log info ("Zinbconnect : export de l'usage termine avec succes (WAN RX=" . $wanRx . " TX=" . $wanTx . "). Fichier disponible dans Files.")
