# Contribuer et maintenir

Une seule source de suivi : ce dépôt GitHub. Aucun miroir GitLab actif n’est annoncé.

1. Ouvrir un ticket ou préciser le ticket existant, le résultat attendu et le périmètre.
2. Travailler sur une branche ; ne pas réécrire l’historique public.
3. Ajouter un test reproductible. Distinguer fixtures unitaires et recette réelle.
4. Ne jamais joindre clés, bases clients, comptes de test actifs, cookies ou traces HAR.
5. Faire relire le changement, mettre à jour le changelog et la version pour une livraison.
6. Relier commit, ticket, test et version ; ne clore qu’après preuve du résultat.

## Construire

Prérequis : Bash, PHP 8.1+, Node 20+, zip/unzip. Aucun service fournisseur nécessaire.

```sh
bash tools/build-release.sh
```

`release-files.txt` est la liste exacte des fichiers distribués. Le ZIP ne contient
pas les workflows, tests, secrets de site ni archives de développement. Le build vérifie
PHP/JS et le ZIP, normalise les dates et produit la somme SHA-256. Les sources lisibles
des bibliothèques tierces et leurs licences sont incluses ; voir THIRD-PARTY.md.

La CI vérifie PHP 8.1 et 8.3 (syntaxe, pas compatibilité fonctionnelle complète), teste
le navigateur et les réponses asynchrones, puis publie une version d’évaluation immuable.
Une version existante n’est jamais écrasée : incrémenter la version et le changelog.
Une exécution verte ne vaut pas approbation WordPress.org ni audit de sécurité complet.

## Triage et notifications

Le workflow `Route incoming requests` ajoute une catégorie indicative, `needs-triage`,
affecte Youzurz et publie un accusé de réception automatique. Il n’analyse pas le contenu
avec une IA, ne ferme rien et ne prétend pas avoir reproduit le problème.
L’équipe doit surveiller GitHub et configurer ses propres notifications. Les emails et
un canal privé de sécurité doivent être testés avant toute promesse de réception.
