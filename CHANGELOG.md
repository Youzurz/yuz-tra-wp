# 1.5.5 — 2026-09-05

- Les clés fournisseur et les réglages complets ne sont plus sérialisés dans les journaux de diagnostic.
- Les champs de clés sont désormais en écriture seule : une valeur enregistrée n’est jamais réinjectée dans le HTML.
- L’import CSV contrôle l’erreur d’envoi, le fichier temporaire, le nom, le type réel et la taille avant lecture.
- Les redirections de l’import utilisent `wp_safe_redirect` et des tests ciblés empêchent le retour de ces défauts.
- Les routes AJAX anonymes dynamiques sont remplacées par une liste de deux lectures front ; les probes et écritures de logs exigent authentification, capacité et nonce.
- Les métriques front ne conservent plus d’extraits des contenus source/cible ; les probes et la télémétrie RUM sont désactivés par défaut.

# 1.5.4 — 2026-09-04

- Métadonnées durables pour la soumission WordPress.org, sans présentation de placeholder d’évaluation.
- Auteur du paquet normalisé sur `YOUZURZ (YUZ CLA GPT)`.
- Changelog et consignes de mise à jour alignés sur le stable tag.
- Recette fraîche du ZIP exact dans WordPress 7.1 avec Plugin Check 2.1.0.

# 1.5.3 — 2026-09-04

- Domaine de traduction normalisé sur le slug `yuz-tra`.
- En-tête de mise à jour tiers retiré du paquet WordPress.org.
- Build GitHub Actions reproductible avec empreinte et manifeste de provenance externes.

# 1.5.2 — 2026-08-31

- Version installée, téléchargement et parcours documentaire visibles dans les réglages et le catalogue direct.
- Version canonique dans version.json, header/readme contrôlés avant construction ; constante runtime lue dans le header.
- Provenance du build et manifeste externe reliant tag, commit source et SHA-256 du ZIP.
- Aucune intégration GitLab revendiquée sans accès et synchronisation vérifiés.

# 1.5.1 — 2026-08-31

- Lien direct vers l’atelier : le visuel rejoint les chaînes même si Vue termine son chargement plus tard.
- Retour du focus au bouton Strings dans ce parcours ; un éditeur explicitement détruit n’est jamais réinséré.
- Test de non-régression sur montage différé/masqué, en plus des contrôles souris, clavier et mobile.

# 1.5.0 — 2026-08-31

- Atelier commun : éditeur visuel à gauche, catalogue à droite, alignés en haut dans une seule couche native.
- Séparation et coin redimensionnables à la souris et au clavier, disposition mémorisée localement, empilement mobile.
- Déplacement des véritables éditeurs sans clonage ; saisies conservées et retour au visuel à la fermeture.
- Bibliothèques navigateur embarquées avec leurs sources lisibles/licences ; suppression du recours CDN.
- Notice GPL, readme WordPress, confidentialité, coûts, support et suivi des versions.
- Retrait des anciennes promesses commerciales non vérifiées ; liens vers le dépôt de suivi.
- Distribution indépendante d’évaluation ; admission WordPress.org et audit complet non acquis.

# 1.4.2 — 2026-08-31

- Catalogue flottant dans un dialogue natif au premier plan, indépendant du z-index de l'éditeur visuel ; catalogue administrateur toujours intégré à sa page.
- Focus contenu dans le catalogue, retour au bouton d'ouverture, Échap et raccourcis isolés de l'éditeur visuel.
- Fermer et rouvrir conserve les saisies non enregistrées des deux éditeurs ; fermeture différée pendant une opération.
- Catalogue exclu des outils de sélection/traduction de la page ; réponses automatiques tardives ignorées après une nouvelle saisie, sélection ou langue. Un accusé de sauvegarde ancien ne supprime plus une saisie plus récente.

# 1.4.1 — 2026-08-31

- Catalogue administrateur séparé des anciens bundles et outils de sélection visuelle.
- AJAX relatif au domaine courant, sous-répertoire WordPress préservé, refus d'envoyer le nonce à une autre origine.
- Erreurs HTTP/HTML et réponses incomplètes signalées sans faux succès ; délai dépassé explicite.
- Recherche/langue dans les liens directs, provenance des traductions affichée.
- Compatibilité CSS avec les thèmes d'administration : contrôles masqués et sélecteurs.
- Démo authentifiée sur b2f.cla : récupération native WooCommerce, publication et rendu réel ; appel LibreTranslate réel enregistré à relire, vérifié par rechargement et SQL.

# 1.4.0 — 2026-08-31

- Suppression des faux succès fournisseur, batch, HTML, persistance et connexion sans test.
- Ancien module IA simulé remplacé par le gestionnaire fournisseur, des tâches persistantes et des compteurs réels ; aucun suffixe ou avancement chronométré.
- Erreurs et résultats partiels explicites, identifiants conservés, contrôle de droits sur les anciens endpoints.
- Mémoire humaine approuvée, révocation sur modification/archivage, glossaire CSV atomique et contexte lexical réellement injecté dans Ollama.
- Adaptateur Ollama privé, modèle configurable, schéma JSON strict, tokens mesurés, limites CPU et génération, relecture obligatoire.
- Variantes de locales préservées ; conversion régionale non prise en charge signalée, variantes DeepL cibles conservées.
- Langue source configurable par domaine ; états du scan historique issus du scan réel.
- Addons d’exemple non fonctionnels retirés ; activation refusée sans chargement effectif.
- Suppression des téléchargements CDN implicites, de la géolocalisation IP externe et de l’ID de page de diagnostic propre à un site.
- Anciennes copies JS exclues du ZIP ; schéma additif version 2, sans migration automatique des textes publiés vers la mémoire approuvée.

# 1.3.0 — 2026-08-31

- Catalogue Gettext persistant : identité domaine/contexte/original/pluriel, recherche, pagination et états de relecture.
- Scan incrémental du cœur, des extensions actives et du thème, écritures SQL groupées ; collecte PHP pendant le rendu.
- Application PHP et WordPress JavaScript des seules traductions publiées ; conservation des catalogues natifs.
- Traduction automatique, protection des variables et balises, cache partagé et compteurs réels avec plafonds.
- Worker cron effectif, borné, verrouillé, reprises différées et protection des modifications humaines.
- Éditeur secondaire autonome, sélection sans fausse alerte de modification, erreurs visibles au lieu d’un écran blanc.
- Bascule source/cible préservée et identification correcte des lignes dans l’éditeur visuel.
- Activation corrigée : contrat cron, contraintes SQL nommées par préfixe, schéma auxiliaire additif.
- Réglages automatiques unifiés, intervalle réconcilié, événements supprimés à la désactivation.
- Suppression des chargements forcés d’un autre plugin et du dépannage de thème spécifiques à une installation.
