# Zinbconnect

Portail captif de tickets Wi-Fi et scripts d'infrastructure pour MikroTik RouterOS 7.
Alimenté par Internet par satellite Starlink pour les hubs communautaires au Sud-Kivu (RDC).

- **Matériel cible** : MikroTik RB2011UiAS-2HnD-IN (RouterOS 7.25.1) + TP-Link EAP225-Outdoor (PoE passif 24V sur ETH10).
- **Architecture** : Authentification par tickets (codes uniques 12 caractères, 60 bits d'entropie), import périodique .rsc sans API distante, tunnel WireGuard d'administration.
- **Documentation et guides de déploiement** : voir le dossier `install/`.

## État de validation du matériel

La configuration a été contrôlée sur un RB2011UiAS-2HnD-IN sous RouterOS 7.25.1.
Le profil hotspot utilise `http-chap`, `mac-cookie` et `cookie` ; `http-pap` est
désactivé. L'option DHCP captive RFC 8910 (code 114) annonce
`http://10.20.30.1/login.html`. L'API RouterOS (API et API-SSL) n'est pas
nécessaire au fonctionnement de l'application et doit rester désactivée.

Les tests applicatifs locaux passent actuellement à 18/18. Les tests qui
dépendent du site et de l'opérateur (WAN, handshake WireGuard, connexion réelle
d'un téléphone, détection captive par Android/iOS/Windows/macOS, expiration et
reconnexion MAC) doivent encore être exécutés avec le WAN et le point d'accès
branchés.

### Limite importante concernant HTTPS

Un hotspot ne peut pas rediriger silencieusement une URL HTTPS vers une page
HTTP sans provoquer une erreur de certificat ou un avertissement de sécurité.
La détection captive repose donc sur l'option DHCP 114 et sur les sondes HTTP
des systèmes clients. Si la fenêtre captive ne s'ouvre pas automatiquement,
la procédure fiable est d'ouvrir `http://10.20.30.1/login.html` manuellement.
Cette limite est imposée par TLS et ne peut pas être supprimée par le portail.

