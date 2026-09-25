# Maintenir l'efficacité des contrôles

État : procédure définie, pas une mise à jour de production effectuée ni une preuve
de surveillance quotidienne déjà active. Le scan de chaque release reste obligatoire;
le suivi continu le complète, il ne le remplace pas.

## Inventaire et mesures

Pour chaque composant installé : hôte/périmètre, version/digest, source officielle,
responsable à attribuer, dernier examen, candidate, compatibilité, sauvegarde et
preuve de restauration.

- Wazuh : manager, indexer, dashboard, agents, Filebeat, règles/décodeurs locaux.
- Détection : fraîcheur du contenu CTI et inventaire Syscollector; connecteur/indexation.
  Mesurer la mise à jour réussie, pas seulement un timer configuré.
- CI : Plugin Check/WPCS, analyseurs PHP/JS, Gitleaks, Trivy/base CVE, SBOM.
- Réception : dernier événement canari et accusé de réception de notification.
  Inaccessible = NOT_TESTED/échec, jamais sain; dater chaque observation.

Objectifs internes à mettre en service, pas SLA client : vérifier quotidiennement
nouveaux avis et fraîcheur, relancer avant release les contrôles du ZIP exact.
Seuil initial d'alerte : 24 h sans succès pour les bases/contrôles quotidiens;
adapter au mécanisme réel et documenter. Ne pas extrapoler un ancien résultat.

## Mise à jour progressive

1. Lire notes officielles/compatibilité; épingler versions/digests et vérifier
   signatures/empreintes. Pas de `latest` en production.
2. Sauvegarder configuration, règles et état; vérifier restauration isolée.
3. Tester en isolation : événements malveillants ET légitimes, régressions WordPress,
   charge/bruit. Pas de suppression silencieuse de règle pour obtenir du vert.
4. Canari autorisé, puis déploiement progressif en fenêtre de maintenance.
   Ne pas couper tous les contrôles ni mettre tous les agents à jour simultanément.
5. Prouver collecte, détection, indexation, notification et prise en charge réelle;
   tester aussi panne de collecte, expiration d'exception et retour arrière.
6. Archiver avant/après et réévaluer les artefacts distribués.

Wazuh demande des composants centraux de version identique, patch compris, et un
manager de version au moins égale aux agents. La compatibilité Filebeat dépend de
la version cible : vérifier le guide. Sources : [mise à jour officielle](https://documentation.wazuh.com/current/upgrade-guide/index.html),
[détection de vulnérabilités](https://documentation.wazuh.com/current/user-manual/capabilities/vulnerability-detection/how-it-works.html).

## Silence utile, pas silence des pannes

Dédupliquer par composant/artefact + défaut + état/gravité; conserver les observations.
Notifier apparition/aggravation, échéance dépassée, rétablissement ou panne de contrôle.
Les critiques ne deviennent pas silencieuses par simple acquittement. Préserver les
preuves et suivre le risque; aucun arrêt, bannissement, rollback de production ou
envoi externe de test sans autorisation applicable.
