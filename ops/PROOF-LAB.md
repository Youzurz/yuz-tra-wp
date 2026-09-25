# Banc de preuve YUZ-TRA

Exécuter depuis le dépôt : `node tools/proof-lab.cjs`.
Ajouter `--wordpress` pour exécuter la recette SQL dans un réseau Docker et une base dédiés puis détruits. Les fichiers de recette sont conservés dans un dossier temporaire unique. Les images Docker et WordPress sont téléchargés si nécessaire; aucune API IA appelée. Les identifiants de recette sont exclusivement jetables, aucun port de base n'est publié.
Playwright doit être disponible (dépendance tests/browser/package.json et navigateur installé).
Si installé ailleurs, fournir NODE_PATH explicitement. Aucun secret ni fournisseur requis.

Le programme crée un nouveau dossier privé temporaire, avec page HTML, JSON et journaux.
Il ne publie rien, ne modifie pas le ZIP soumis et n'utilise aucun WordPress de production.
Chaque scénario contient code stable, type de preuve, commande, durée, résultat et empreinte du journal.
Un timeout, signal ou échec de lancement est un échec, jamais un succès.
PARTIAL signifie que le profil a réussi mais ne couvre pas tout le produit; NOT_TESTED ne vaut pas PASS.
Les journaux sont privés : les examiner avant une diffusion publique.

## Sources et limites

- Historique Git du dépôt et sources actuelles : provenance enregistrée à chaque exécution.
- RECETTE-FINALE-20260904.md (répertoire parent) : historique, pas preuve actuelle.
- tests/review-runtime.php : vrai WordPress/SQL, distinct des scénarios navigateur interceptés.
- tests/browser : vrais actifs JavaScript, serveur et traductions simulés.
- Découverte des tâches Codex/ChatGPT effectuée le 15 septembre : ne constitue pas une lecture exhaustive de tous les échanges.
- /mnt/gvadata est vide sur ce serveur. Les specs PDF/PPTX évoquées sur /Volumes/GVADATA ne sont pas intégrées; aucune conformité à ces documents n'est revendiquée.

## Plan directeur

1. Régressions locales reproductibles et limites visibles : ce profil.
2. Recette jetable WordPress/SQL intégrée avec `--wordpress`, sans identifiants de production.
3. Relier chaque exigence des specs accessibles à un scénario; distinguer exigence, historique et observation.
4. Corpus multilingue évalué humainement, essais fournisseur sur budget autorisé séparé.

Le banc mesure des comportements. Il ne prouve ni qualité linguistique, ni rentabilité, ni absence de vulnérabilités par son seul verdict.

## Exigences de référence et suivi

| Code | Attente issue de l'historique local | Preuve apportée |
|---|---|---|
| TRA-EDIT-01 | Ne pas écraser une saisie récente par une réponse tardive | Méthodes réelles, dépendances simulées |
| TRA-UI-01/02/03 | Panneaux accessibles, alignés, ajustables, brouillons préservés | Navigateur réel, serveur simulé |
| TRA-HTTP-01 | Erreur HTML/transport non présentée comme traduction réussie | Transport intercepté dans le navigateur |
| TRA-WP-01 | Sauvegarde/relecture catalogue, droits et protection des diagnostics | WordPress 6.5 et MariaDB jetables, option --wordpress |
| TRA-REL-01 | Cohérence version/header/readme | Fichiers réels et altérations contrôlées |
| TRA-SEC-01 | Prévenir les régressions de la revue | Contrôles de sources, pas audit exhaustif |
| TRA-AI-01 | Traduction pertinente, terminologie et coût mesurés | Non testé dans ce banc |
| TRA-CRON-01 | Lots, reprise et concurrence du worker | Non testé dans ce banc |
| TRA-DOC-01 | Correspondance avec les specs originales | Non vérifiée : GVADATA non monté ici |

Les anciennes recettes sont des témoignages datés et non des validations automatiquement reconduites.
Les fonctions WooCommerce, SEO multilingue, pluriels et compatibilité multi-CMS nécessitent des scénarios supplémentaires : aucun label de couverture globale n'est émis.

## Registre exécutable

`requirements.json` dans chaque rapport indexe tous les paragraphes du README et de KNOWN-LIMITS, avec empreinte du document et ligne. Le README est classé déclaration produit; KNOWN-LIMITS contient des limites historiques (version 1.5.0) et n'est pas assimilé au résultat actuel. Une correspondance de section est PARTIAL_MAPPING, jamais une attestation de conformité; UNMAPPED marque les scénarios encore absents. Ce périmètre documentaire n'est pas l'intégralité des spécifications historiques.

Le point 4 utilise `tests/corpus.json` et [HUMAN-EVALUATION.md](HUMAN-EVALUATION.md).
`node tools/check-human-review.cjs /chemin/vers/evaluations.json` refuse un dossier absent ou incomplet. Il contrôle la structure et les empreintes, pas l'identité réelle du relecteur ni la réalité d'une facture. Le budget doit être autorisé séparément et les évaluations doivent être fournies par des humains; aucune génération de faux avis.
