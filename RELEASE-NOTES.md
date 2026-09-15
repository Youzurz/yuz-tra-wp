# YUZ-TRA 1.5.6 — candidat de durcissement

Télécharger **yuz-tra-1.5.6.zip** ci-dessous, pas l’archive « Source code ».
La somme SHA-256 est jointe. Le ZIP contient le dossier `yuz-tra`, directement
installable depuis Extensions → Ajouter → Téléverser dans WordPress.

- 1.5.6 : rejet des traductions exécutables, publication globale réservée aux administrateurs, protection du catalogue JavaScript historique, santé privée, crédit soumis au consentement administrateur, scripts chargés par WordPress, buffer de template encadré, Select2 4.1.0 et documentation des services externes.
- 1.5.5 : journaux sans secrets, clés non réaffichées, import CSV contrôlé, routes anonymes réduites aux lectures front nécessaires et tests de sécurité ciblés.
- 1.5.4 : métadonnées WordPress.org durables, auteur `YOUZURZ (YUZ CLA GPT)`, changelog et consignes de mise à jour alignés.
- 1.5.3 : domaine de traduction normalisé sur le slug `yuz-tra`, en-tête WordPress.org sans serveur de mise à jour tiers et contrôles de paquet renforcés.
- 1.5.2 : bandeau de version, téléchargement et parcours d’aide dans l’administration ; cohérence manifeste/header/readme contrôlée, provenance du commit et manifeste SHA-256 externe.
- Correctif 1.5.1 conservé : le panneau visuel rejoint aussi l’atelier lorsqu’un lien direct ouvre les chaînes avant le chargement de Vue. Un panneau détruit n’est jamais réinséré.
- Atelier commun : visuel à gauche, chaînes à droite, alignés en haut.
- Largeur/hauteur réglables, poignées souris/clavier, préférences locales et mobile empilé.
- Conservation des saisies du catalogue à la fermeture/réouverture et raccourcis isolés.
- Bibliothèques locales, licences, confidentialité, coûts et guide pas à pas.
- Formulaires dédiés bug, évolution et aide ; routage vers le triage humain.

**Sauvegarder et tester sur staging. Pas de promesse de traduction parfaite ni de support
sous délai garanti. La disponibilité WordPress.org dépend de sa revue officielle.**

[Démarrage](https://github.com/Youzurz/yuz-tra-wp/blob/main/GETTING-STARTED.md) ·
[Limites connues](https://github.com/Youzurz/yuz-tra-wp/blob/main/KNOWN-LIMITS.md) ·
[Confidentialité](https://github.com/Youzurz/yuz-tra-wp/blob/main/PRIVACY.md) ·
[Support](https://github.com/Youzurz/yuz-tra-wp/issues/new/choose).

Les tests automatisés de disposition/transport utilisent des fixtures explicitement
isolées. La recette authentifiée sur le backend WordPress réel est distincte et n’utilise
pas de réponses fournisseur simulées. Voir QA-1.5.0.md et QA-1.5.1.md.
