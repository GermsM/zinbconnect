# Zinbconnect

Portail captif de tickets Wi-Fi et scripts d'infrastructure pour MikroTik RouterOS 7.
Alimenté par Internet par satellite Starlink pour les hubs communautaires au Sud-Kivu (RDC).

- **Matériel cible** : MikroTik RB2011UiAS-2HnD-IN (RouterOS 7.25.1) + TP-Link EAP225-Outdoor (PoE passif 24V sur ETH10).
- **Architecture** : Authentification par tickets (codes uniques 12 caractères, 60 bits d'entropie), import périodique .rsc sans API distante, tunnel WireGuard d'administration.
- **Documentation et guides de déploiement** : voir le dossier `install/`.

