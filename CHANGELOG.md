# 1.5.45 — 2026-10-01 (candidate de portabilité, non soumise)

- Lecture REQUEST_URI validée, dé-slashée et assainie directement ; deux signalements supplémentaires de Plugin Check traités sans suppression globale des avertissements.
- Correction de la variable d'origine du constructeur d'URL courante et consolidation des exclusions locales.
- Versions intermédiaires conservées comme artefacts non soumis ; seule une recette exacte réussie rend cette candidate utilisable pour une soumission.

# 1.5.44 — 2026-10-01 (candidate de portabilité, non soumise)

- Correction du cas WordPress avec site public HTTP et administration HTTPS ; les URL admin/login exclues sont conservées intégralement.
- Les chemins WordPress sur le même hôte restent exclus même si leur schéma/port diffère ; un domaine CDN distinct ne réserve pas une page locale.
- La 1.5.43 et ses preuves d'échec restent conservées ; nouvelle recette requise.

# 1.5.43 — 2026-10-01 (candidate de portabilité, non soumise)

- Revue indépendante : REST par query distingué des pages normales, exclusions limitées à leur origine et détection des langues respectant la frontière du sous-répertoire.
- La première candidate 1.5.42 reste conservée avec ses cas limites connus ; elle n'est pas destinée à la soumission.
- Recette étendue à la traduction réelle fr/en, aux permaliens simples et aux chemins CDN ; validations exactes requises avant soumission.

# 1.5.42 — 2026-10-01 (candidate de portabilité, non soumise)

- Suppression de neuf chemins d'actifs fixes dans le ZIP : imports ESM relatifs, service résolu depuis le script, base d'actifs WordPress explicite et chargeur inspecteur partagé.
- Les scripts des intégrations de thème sont chargés uniquement via des URL explicitement configurées sur la même origine ; les API déjà chargées restent utilisables.
- L'origine du site provient de WP_HOME/home/siteurl ; aucun Host de requête ne remplace la configuration et aucun localhost n'est inventé.
- Contrôle de dépendances d'environnement à la frontière du build, inventaire des URL et tests de chemins personnalisés.
- Les ZIP déjà reçus restent intacts ; cette version exige sa propre recette et ne vaut pas approbation.

# 1.5.41 — 2026-09-29 (candidate T5, validation en cours)

- Dé-slashage des deux tableaux de réglages placé explicitement dans le routeur authentifié ; validation métier inchangée et dé-slashage unique.
- La candidate locale 1.5.40 reste archivée : sa recette passe, mais son scan exigeait ces deux corrections. Elle n'a pas été soumise.

# 1.5.40 — 2026-09-29 (candidate T5 non soumise)

- Refus des nonces AJAX avant toute journalisation ; suppression des traces de publication avant contrôle et des diagnostics de refus contenant la requête.
- La découverte automatique Gettext ne vide plus de collecte différée pendant une requête AJAX. Les écritures explicites autorisées du catalogue sont conservées.
- Réglages et callback de lecture : entrées validées passées explicitement, sans relecture implicite des superglobales.
- Préfixes JavaScript producteurs/consommateurs et groupe de cache rendus spécifiques à YUZ-TRA.
- Tests avec logger actif, vrais arrêts HTTP 403, observation SQL jusqu'au shutdown et contrôle positif de l'observateur.
- Aucun remplacement de la 1.5.39 reçue par WordPress.org ; approbation et publication non acquises.

# 1.5.39 — 2026-09-28 (candidate locale, validation en cours)

- Revue T4 : validation contextuelle JSON/CSV et listes de langues, permissions et nonces des réglages, diagnostics minimisés.
- Migration des symboles PHP, hooks, actions AJAX et espace REST vers YUZTRA_/yuztra_. Rupture explicite pour les intégrations des versions d’évaluation antérieures.
- Migration des réglages, capacités et cron par manifeste explicite, sous arrêt des anciens writers ; conservation des options historiques et des tables de traduction. Aucun remplacement de texte libre.
- Contrôle séparé du chargement de dbDelta et propagation des échecs de schéma.
- Aucun téléversement ou email effectué par ce lot ; les preuves du ZIP final doivent être établies avant soumission.

# 1.5.6 — 2026-09-09 (candidate de revue)

- Refus du code exécutable dans les traductions ; contrôle des anciennes valeurs à la lecture PHP et JavaScript.
- Publication globale réservée aux administrateurs, consentement administrateur obligatoire pour les liens de crédit.
- Route de santé privée, assainissement des entrées et des callbacks de réglages, suppression des journaux de sonde dans wp-content.
- Scripts enregistrés via WordPress et remplacement du buffer global par un traitement encadré du template.
- Correction des noms génériques NullEnvironment, NullCsvImporter et tables_ok.
- Select2 4.1.0, documentation OpenAI et correction des liens de services externes.
- Les endpoints AJAX utilisent la configuration WordPress, sans chemin racine supposé.
- Candidat non soumis automatiquement : l’approbation WordPress.org n’est pas acquise.

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
