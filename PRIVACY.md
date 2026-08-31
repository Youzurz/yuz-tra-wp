# Confidentialité — YUZ-TRA 1.5.1

Cette notice décrit le logiciel, pas une certification RGPD ni la politique juridique
complète de votre site. L’exploitant détermine les finalités, la base légale, les durées
de conservation, les destinataires et les modalités d’exercice des droits selon son contexte.

## Ce qui reste dans WordPress

Sources, contextes/domaines, traductions, états de publication, mémoire approuvée,
glossaire, identifiant du validateur et dates sont stockés dans la base du site.
Les réglages fournisseur peuvent contenir des clés API : limiter les droits administrateur
et protéger les sauvegardes. Les tâches persistantes peuvent contenir du texte et leurs
résultats. Les journaux historiques peuvent contenir des textes ou erreurs fournisseur :
désactiver le debug en production et contrôler leur accès et leur rétention.

Les tables, tâches historiques et options ne sont pas effacées à la désactivation ni à
la suppression des fichiers. La désactivation retire les événements cron YUZ. Pas de
promesse d’effacement automatique : l’administrateur doit organiser export, suppression
ciblée et purge des sauvegardes. Le plugin n’offre pas encore d’outil complet d’effacement
par personne ; éviter d’y indexer des données personnelles sans procédure adaptée.

## Ce qui peut sortir du serveur

Une traduction manuelle, le démarrage automatique de l’éditeur visuel selon sa
configuration, ou un worker explicitement activé peut envoyer le texte au fournisseur
configuré. Les codes de langue et les identifiants nécessaires à l’API l’accompagnent.
Le fournisseur observe aussi les métadonnées réseau, notamment l’IP du serveur.
Le test de connexion et la découverte des langues/modèles contactent le fournisseur.

Ollama reçoit également les exemples humains approuvés et les termes du glossaire
sélectionnés dans le même périmètre. « Local » dépend de l’endpoint choisi : une adresse
distante n’est pas rendue privée par le plugin. Les règles du fournisseur, son hébergement,
les sous-traitants et les éventuels transferts internationaux doivent être vérifiés avant
activation. Les URLs officielles sont dans readme.txt.

Le catalogue ne contacte pas un fournisseur à son ouverture. Le plugin ne transmet pas
automatiquement les signalements à GitHub. Les liens de documentation et de support
sont ouverts seulement à l’initiative de l’utilisateur, et GitHub applique sa propre
[politique de confidentialité](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

## Dans le navigateur

Le nouvel atelier mémorise uniquement largeur, hauteur et séparation sous la clé
`yuz-workspace-v1:<identifiant utilisateur>` du stockage local de l’origine courante.
Aucun texte de traduction n’y est enregistré par ce mécanisme. « Réinitialiser la
disposition » rétablit les dimensions ; effacer les données du site dans le navigateur
supprime ces préférences. Les brouillons non sauvegardés du catalogue restent seulement
dans la mémoire de la page et sont perdus à sa fermeture confirmée.

L’éditeur visuel historique sauvegarde automatiquement certaines saisies : il ne faut
pas le traiter comme un bac à sable. Les cookies d’authentification sont ceux de WordPress.

## Support et captures

Les tickets GitHub sont publics. Ne jamais joindre mot de passe, clé API, cookie, nonce,
commande client, export de base ou fichier HAR brut. Retirer les noms, domaines privés
et données personnelles des captures et journaux. Pour un problème de sécurité, suivre
SECURITY.md. La conservation du ticket dépend aussi du compte et des règles GitHub.
