# YUZ-TRA — trajectoire commerciale réelle

## Situation vérifiée

Le cœur du plugin reste utilisable gratuitement : éditeur visuel, catalogue Gettext,
publication contrôlée, mémoire approuvée, glossaire et fournisseur local. Cette branche
ne contient ni clé d’activation, ni paiement, ni offre Pro simulée. Le dépôt public 1.5.2
n’est pas modifié par cette préparation.

Le code dispose déjà d’un registre quotidien des caractères, requêtes, réussites, échecs,
cache, tokens mesurés et durée. La présente branche ajoute un calcul financier fondé sur
ces mesures. Il ne lit pas une facture OpenAI et ne doit pas être présenté comme le solde
réel du compte : le solde, les tarifs et la valeur vendue sont des hypothèses saisies par
l’administrateur ou importées plus tard depuis une source authentifiée.

## Fonction payante prioritaire : YUZ-TRA Cloud

Le client pourrait utiliser le plugin sans compte avec Ollama local ou ses propres clés.
Une offre hébergée séparée fournirait une passerelle de traduction opérée par YOUZURZ :

- authentification de site et révocation ;
- compteurs d’entrée/sortie par site et par période ;
- réserve de sécurité et arrêt avant dépassement ;
- facturation à l’usage ou forfait avec plafond explicite ;
- résultats en attente de relecture, sans publication automatique trompeuse ;
- export des consommations et suppression documentée ;
- isolation des sites, limitation de débit et journal d’incidents.

Le plugin ne doit jamais envoyer le contenu vers cette passerelle sans activation
explicite. Une erreur fournisseur doit produire une erreur et une consommation identifiable,
jamais un succès de façade.

## Extensions commerciales possibles

Les fonctions organisationnelles peuvent être proposées comme add-on GPL ou service :

1. équipe de traduction : rôles, assignation, commentaires et double validation ;
2. gouvernance linguistique : versions de glossaire, règles de marque et audit exportable ;
3. exploitation : sauvegardes, supervision, mises à jour et accompagnement ;
4. entreprise : multisite, headless, CI/CD, SSO et contrat de support.

Le code libre ne doit pas être dégradé pour fabriquer une pénurie artificielle. La valeur
payante doit venir de l’infrastructure, du service, de la coordination, de la garantie
opérationnelle et de la consommation réellement fournie.

## Comptabilité interne

Dans le back-office, les prix par million de tokens entrants/sortants, le prix de vente,
les coûts fixes, le solde disponible et la réserve minimale sont explicitement configurés.
Le tableau affiche coût variable, valeur estimée, marge et franchissement du seuil. Tant
qu’aucun prix n’est renseigné, la marge ne doit pas être interprétée comme un résultat
commercial. Pour OpenAI, les tarifs doivent être datés et vérifiés sur la page officielle
avant saisie ; les tokens d’entrée et de sortie sont des unités distinctes.

## Ordre de développement

- **P0** : comptabilité locale, réserve fail-closed, tests de non-consommation et écran de
  consommation ; cette branche l’implémente.
- **P1** : passerelle Cloud authentifiée, environnement de test isolé, journal de facturation
  immuable et mode prépayé ; aucun appel de production ne doit être activé avant cela.
- **P2** : paiement récurrent ou portefeuille, remboursements, taxes, conditions et support.
- **P3** : équipe, gouvernance, RAG hébergé et engagements d’exploitation.

## Sources de référence

- [Tarification API OpenAI](https://openai.com/api/pricing/)
- [Comprendre les tokens et leur coût](https://help.openai.com/en/articles/4936856)
- [Règles détaillées des extensions WordPress.org](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
