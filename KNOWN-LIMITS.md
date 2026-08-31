# Transparence — périmètre de la version 1.5.0

Cette livraison améliore l’atelier, le conditionnement et l’accompagnement. Elle
**n’est pas déclarée conforme à l’annuaire WordPress.org ni prête pour tous les sites**.

## Ce qui reste à traiter avant une demande d’admission

- Le contrôle Plugin Check sur la base 1.4.2 a remonté 431 erreurs et 2 022 avertissements.
  368 erreurs concernent le décalage `yuz-translation` / slug `yuz-tra` ; renommer sans
  migration casserait les traductions déjà identifiées par domaine. Ce travail doit être testé.
- Les alertes SQL, validation, nonces et échappement nécessitent examen manuel. Une
  alerte statique n’établit pas une faille exploitable ; elle ne peut pas être ignorée non plus.
- Les dépendances sont désormais locales avec licences et sources lisibles. Vue 2 reste
  une dépendance historique en fin de maintenance communautaire ; migration nécessaire.
- L’interface historique n’est pas entièrement internationalisée. Plusieurs libellés
  du nouveau catalogue restent en français. Multisite et toutes les versions minimales
  déclarées ne sont pas certifiés par cette recette.
- Les anciennes traces de debug et la politique d’effacement des données méritent
  une consolidation. Pas de suppression automatique des données personnelles par personne.
- Aucun compte WordPress.org, slug accepté, revue de l’équipe, dépôt SVN ou circuit de
  mise à jour WordPress.org n’est confirmé par la publication GitHub.
- La réception des emails/alertes privés par le mainteneur doit être confirmée humainement.

## Limites fonctionnelles à connaître

- Une application front indépendante de WordPress n’est pas traduite automatiquement.
- Images, PDF et textes JS arbitraires hors mécanismes WordPress ne sont pas un catalogue Gettext.
- L’éditeur visuel historique peut avoir collecté de gros blocs contenant du CSS/texte mal
  segmenté. Cette livraison ne purge ni ne réécrit arbitrairement les traductions existantes.
- Google et DeepL sont présents mais ne sont pas certifiés par une recette fournisseur réelle
  dans cette livraison. LibreTranslate et Ollama ont fait l’objet de contrôles ciblés antérieurs.
- Un modèle ou un fournisseur peut produire une mauvaise traduction. « Publié » ne signifie
  pas automatiquement « validé pour la mémoire » et une mémoire validée ne remplace pas la relecture.
- Les préférences de disposition sont locales au navigateur : elles ne se synchronisent pas
  entre machines. Sur mobile, les panneaux s’empilent plutôt que de devenir trop étroits.

Voir les tickets et la recette pour les preuves, le périmètre exact et les suites.
