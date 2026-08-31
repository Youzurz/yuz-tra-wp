# Assistance et signalements

[Ouvrir une demande](https://github.com/Youzurz/yuz-tra-wp/issues/new/choose).
Compte GitHub nécessaire pour déposer un ticket ; lecture et téléchargement publics.
Pas de transmission automatique de diagnostics, pas de délai contractuel annoncé.

## Choisir une entrée

- **Bug / régression** : décrire résultat attendu, résultat obtenu, étapes reproductibles,
  versions YUZ/WordPress/PHP/navigateur, domaine Gettext, fournisseur sans clé, portée.
- **Fonctionnalité** : expliquer le besoin et un exemple concret, la solution actuelle,
  le résultat souhaité et un critère d’acceptation.
- **Aide / documentation** : indiquer la tâche visée, l’écran bloquant et les étapes essayées.
- **Sécurité / données exposées** : ne pas publier les détails ; suivre SECURITY.md.

Un problème d’usage peut révéler une interface mal conçue ou une documentation manquante.
Il ne doit pas être rejeté au motif que l’utilisateur « ne sait pas se servir du produit ».

## Triage mainteneur

1. Vérifier d’abord exposition de données, perte de données, indisponibilité et régression.
2. Reproduire sur staging avec configuration minimale et erreur exacte, sans secrets.
3. Si l’information manque : demander précisément ce qui manque, garder la demande ouverte.
4. Classer bug confirmé, besoin de reproduction, évolution ou documentation après examen humain.
5. Relier correctif, test de non-régression, version et changelog au ticket.
6. Clore seulement après preuve du correctif ou réponse vérifiable ; rouvrir si le défaut persiste.

Les formulaires classent la catégorie choisie, pas la légitimité de l’utilisateur.
Le routage automatique ne décide pas si une réclamation est fondée. Pas de clôture IA.
Les mainteneurs doivent activer les notifications GitHub et vérifier leur réception.
Une affectation visible dans GitHub ne prouve pas la réception d’un email externe.
