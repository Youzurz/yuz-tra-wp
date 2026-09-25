# Tableau de bord HTTP

Service utilisateur `yuz-tra-proof-dashboard`, port 8767.
Écoute uniquement 127.0.0.1 et 100.64.0.5 (VPN); pas d'écoute publique.
La page est en lecture seule, sans authentification applicative : son accès repose sur le réseau privé.
Ne pas la relayer publiquement sans ajouter un contrôle d'accès.

Le service lit le rapport archivé `20260915-plan/report.json` à chaque actualisation.
Le bouton actualise la lecture; il ne relance pas les tests et affiche la date des mesures.
Seuls HTML, JS, CSS, santé et JSON filtré sont servis. Aucun journal brut ni explorateur de fichiers.

`systemctl --user status yuz-tra-proof-dashboard`

Arrêt : `systemctl --user stop yuz-tra-proof-dashboard`.
Service transitoire supervisé, relancé en cas de panne, mais non installé pour survivre à un redémarrage de l'hôte.
Sur un ordinateur distant, localhost désigne cet ordinateur, pas le serveur. Utiliser l'adresse VPN ou un transfert SSH du port 8767.
