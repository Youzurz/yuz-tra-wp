# Intégrité des versions

## Chaîne actuellement opérationnelle

`version.json → header PHP + readme contrôlés → commit GitHub → CI → ZIP + manifeste → tag vX.Y.Z`

`version.json` est la source canonique de la version, des prérequis et du dépôt.
Le build refuse toute divergence avec le header PHP et le Stable tag du readme.
La constante PHP runtime est lue dans le header, pas dans une deuxième valeur figée.

La CI vérifie son commit et l’absence de modifications suivies, puis produit :

- un ZIP installable contenant `build-provenance.json`, le commit source et les
  empreintes des fichiers distribués ;
- un manifeste externe `yuz-tra-X.Y.Z.manifest.json` reliant version, tag, commit,
  URL, taille et SHA-256 du ZIP ;
- une somme SHA-256 simple pour les outils usuels.

Le SHA-256 du ZIP est extérieur au ZIP pour éviter une référence circulaire.
Les tags et archives existants ne sont pas remplacés : toute correction publiée
nécessite une nouvelle version. Une construction locale hors CI est explicitement
marquée `local-unverified`, sans inventer de commit de provenance.

Contrôle complet d’un téléchargement :

```sh
node tools/release-integrity.cjs --verify-archive dist/yuz-tra-X.Y.Z.zip dist/yuz-tra-X.Y.Z.manifest.json
```

Le manifeste détecte les divergences ; il n’est pas une signature indépendante.
La confiance reste liée à l’accès au dépôt, à GitHub et à la chaîne CI. Le bandeau
WordPress identifie la version et le commit déclarés, pas l’intégrité permanente
d’une installation modifiable. Il n’installe aucune mise à jour automatiquement.

## GitLab interne : raccordement non encore effectué

Aucune URL ou authentification GitLab propre à YUZ-TRA n’a été trouvée lors de
cette publication. Il serait trompeur d’annoncer une synchronisation active.

Contrat recommandé, à mettre en place après identification du dépôt interne :

1. Choisir GitLab comme dépôt de travail, et une branche de publication explicitement
   nettoyée de tout secret, donnée client ou configuration d’infrastructure.
2. Valider une seule fois le commit de publication. Le transmettre à GitHub en
   fast-forward, sans rebase, squash ou nouveau commit de version côté miroir.
3. Exiger l’égalité des SHA GitLab/GitHub sur cette branche. Si leurs historiques
   ne partagent pas le même commit, organiser d’abord la migration ; ne jamais forcer
   le miroir pour masquer la divergence.
4. Garder un seul constructeur/publicateur de ZIP (la CI GitHub actuelle), ou
   transporter exactement le même artefact si cette responsabilité migre à GitLab.
5. Après publication, vérifier tag → commit, manifeste → SHA-256 et téléchargement
   anonyme. En cas de différence, arrêter la publication, pas simuler une réussite.

Prévoir branches/tags protégés, permissions minimales, rotation des jetons et
revue humaine. Ces protections et la synchronisation GitLab ne sont pas déclarées
actives tant que leur configuration et leur essai ne sont pas vérifiés.
