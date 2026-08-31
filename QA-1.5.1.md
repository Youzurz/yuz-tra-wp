# Recette corrective 1.5.1 — 31 août 2026

Le contrôle final d’un lien direct a révélé une course : le catalogue pouvait ouvrir
l’atelier avant que Vue rende l’éditeur visuel. La version 1.5.0 ne le rattachait alors
pas au dialogue. Cette anomalie a été reproduite, puis corrigée en 1.5.1.

- Test isolé `workspace-delayed.cjs` : échoue avant correction, réussit après ; montage
  différé et initialement masqué, retour du focus, pas de résurrection après destruction.
- Régressions workspace, overlay et transport : réussies localement.
- Recette authentifiée du lien direct sur WordPress réel : deux panneaux présents,
  réponses AJAX JSON HTTP 200, pas d’erreur JavaScript, compte temporaire supprimé.

Les fixtures des tests isolés ne constituent pas une démonstration de traduction IA.
Ce correctif ne modifie aucun fournisseur ni n’active la traduction silencieuse.
Les limites de QA-1.5.0.md et KNOWN-LIMITS.md restent applicables ; aucune approbation
WordPress.org ni compatibilité exhaustive n’est revendiquée.
