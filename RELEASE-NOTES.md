# YUZ-TRA 1.5.28 — candidate locale de revue

Artefact attendu : **yuz-tra-1.5.28.zip**, pas l’archive « Source code ». Cette candidate n’est pas encore publiée ni approuvée par WordPress.org. Les candidates précédentes restent conservées, non publiées.

- Durcissement supplémentaire des réglages, actifs, requêtes SQL et diagnostics signalés par Plugin Check.
- Préfixage des implémentations d’aide avec compatibilité des données existantes.

- Refus HTTP explicite avant tout effet pour les routes AJAX sensibles ; tests négatifs subscriber/author avec nonce valide et absence d’écriture SQL.
- Publication réelle corrigée : aucune réussite annoncée lorsqu’aucune traduction ne correspond.
- Lecture des réglages API sans restitution de secrets et maintenance limitée à la table canonique, jamais à une sauvegarde portant un suffixe.

- Axios navigateur mis à jour vers 1.20.0 depuis le paquet officiel vérifié par SHA-512.

- Lecture publique séparée des appels fournisseur et des opérations de création/publication.
- Filtrage du HTML substitué, validation bornée du callback et réduction des diagnostics.
- URLs d’actifs calculées depuis le fichier du plugin.
- Limite explicite : la lecture publique dynamique est limitée aux posts publics sans mot de passe et aux traductions publiées. La page des articles sans identifiant de post n’est pas couverte par cette nouvelle route.
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
