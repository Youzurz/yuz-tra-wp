# YUZ-TRA 1.5.6

[Télécharger le ZIP](https://github.com/Youzurz/yuz-tra-wp/releases) · [Guide de démarrage](GETTING-STARTED.md) · [Support](https://github.com/Youzurz/yuz-tra-wp/issues/new/choose) · [Confidentialité](PRIVACY.md) · [Coûts](PRICING.md) · [Limites connues](KNOWN-LIMITS.md)

Distribution indépendante à évaluer sur staging : **pas d’approbation WordPress.org**.
Les [informations juridiques et responsabilités du propriétaire](LEGAL.md) distinguent
la licence du code, les conditions des fournisseurs et les mentions légales à compléter.
Les mises à jour se font par téléversement du ZIP après sauvegarde ; aucun serveur
de mise à jour automatique externe n’est activé. Voir les limites et la recette du dépôt.

## Atelier ajustable

Dans l’éditeur visuel, ouvrir Strings : le visuel se place à gauche, les chaînes à
droite, alignés en haut. Glisser la séparation pour ajuster la largeur, ou le coin
inférieur droit pour ajuster l’ensemble. Les poignées fonctionnent aussi avec les
flèches du clavier (Maj accélère, Début réinitialise). Sur écran étroit, les panneaux
s’empilent. La disposition est mémorisée par site/utilisateur dans le navigateur.

Échap ou × dans le catalogue revient au visuel sans effacer les saisies du catalogue.
Les saisies visuelles peuvent s’enregistrer automatiquement ; les boutons de sauvegarde
et publication du catalogue restent indépendants. Un panneau n’est pas une copie de l’autre.

Extension WordPress : éditeur visuel et catalogue des chaînes Gettext du cœur, des extensions et des thèmes, en PHP et dans les scripts utilisant le système de traduction WordPress.

## Installation

1. Sauvegarder la base et l’ancienne extension. Dans WordPress : Extensions → Ajouter → Téléverser, sélectionner `yuz-tra-1.5.6.zip`, installer puis activer. Pour une mise à jour, confirmer le remplacement de YUZ-TRA uniquement.
2. Configurer les langues dans YUZ-TRA. Pour l’administration, la langue affichée suit celle du profil utilisateur WordPress ; pour les pages WordPress, elle suit la langue active du routeur YUZ.
3. Ouvrir YUZ-TRA → onglet Strings/Chaînes, ou `/wp-admin/admin.php?page=yuz-string-translation-editor`.
4. Lancer « Scanner WordPress, plugins et thèmes ». Le scan fonctionne par étapes et déduplique les chaînes. Relancer après une mise à jour d’extension. Les appels PHP rencontrés lors des visites sont aussi collectés, sans traduction distante pendant le rendu.
5. Choisir langue, domaine et filtre ; saisir puis publier, ou sélectionner jusqu’à cinq chaînes et demander une traduction automatique. Les chaînes déjà renseignées ne sont pas écrasées par ce bouton.

Prérequis déclarés : WordPress 6.5+, PHP 8.1+, extensions DOM et mbstring recommandées, base MySQL/MariaDB avec verrous nommés. Recette réalisée sur WordPress 7.1, PHP 8.3 et WooCommerce 10.8.1 ; les autres versions ne sont pas toutes certifiées.

## Relecture et publication

États : 1 brouillon, 2 à relire, 3 révisé, 4 publié, 5 archivé. Seul l’état 4 remplace les chaînes affichées. Contextes Gettext et formes plurielles sont distincts. Les variables printf, marqueurs entre accolades et balises HTML sont contrôlés avant publication et protégés lors des appels automatiques. Les règles plurielles couvrent 346 codes/alias de locales WordPress.

La traduction automatique manuelle produit un état 2. Le mode silencieux explicitement activé publie les chaînes encore absentes avec les moteurs classiques : l’activer seulement si cette politique convient. Les réponses générées par Ollama restent toujours en état 2, même en mode silencieux. Une traduction automatique n’est pas une garantie de justesse linguistique ; pluriels, négations et chaînes ambiguës nécessitent une relecture humaine.

## Mémoire approuvée, glossaire et Ollama

Dans le catalogue, « Approuver pour la mémoire » enregistre une validation humaine explicite (utilisateur et date), distincte de la publication. Aucune ancienne traduction publiée ou générée ne devient automatiquement une référence. Une modification, un archivage ou un changement de langue source invalide la référence correspondante. La réutilisation exacte est gratuite en appels fournisseur et cloisonnée par site, langue source, locale cible, domaine et contexte. Les pluriels ne sont pas réutilisés par la mémoire de phrases singulières.

Le panneau « Langue source et glossaire approuvé » permet de déclarer la langue d’un domaine et d’importer des termes validés. Format CSV UTF-8, séparateur virgule, en-tête exact :

```csv
source_lang,target_lang,domain,context,source,target
```

Maximum 500 lignes par import, 200 Ko. Les six colonnes sont requises, le contexte peut être vide. Toutes les lignes sont validées avant écriture ; une erreur rejette l’import entier. Réimporter la même clé remplace son terme approuvé.

Dans les réglages automatiques, choisir Ollama puis renseigner l’URL de base privée et le nom exact d’un modèle déjà installé. Aucun modèle ni adresse n’est imposé, aucune installation de modèle n’est déclenchée. « Tester » vérifie que le serveur répond et expose ce modèle ; ce test ne certifie pas sa qualité de traduction. Après changement du modèle derrière un même nom, changer aussi sa révision dans les réglages pour invalider le cache.

Lors d’une génération, le code récupère réellement jusqu’à 3 exemples approuvés parmi les 200 références récentes du même domaine/contexte et jusqu’à 12 termes applicables parmi les 1 000 derniers termes de ce périmètre. Ces données sont insérées dans la requête Ollama. C’est une première récupération lexicale sur SQL, pas une recherche vectorielle ni une garantie de pertinence. L’adaptateur exige une réponse JSON complète, protège les marqueurs et conserve la relecture humaine.

Les tokens d’entrée/sortie annoncés par Ollama et le temps HTTP sont mesurés ; ni prix en euros, ni carbone, ni progression ne sont inventés. Les plafonds de tokens utilisent une marge conservatrice avant chaque génération. La concurrence Ollama est limitée à une génération à la fois pour un endpoint partagé dans la même base. Les limites CPU, contexte, sortie et délai sont configurables. Aucun moteur secondaire n’est appelé en cas d’échec.

## Traduction silencieuse et consommation

Dans les réglages automatiques, choisir le fournisseur et son adresse, le mode silencieux/automatique, cocher l’activation, fixer l’intervalle et les quotas. Les réglages canoniques sont `yuz_tra_at_settings` ; l’ancien emplacement reste un recours s’ils sont absents.

- WP-Cron doit être exécuté. Sur un backend interne peu visité ou avec `DISABLE_WP_CRON`, prévoir un ordonnanceur système qui exécute les événements WordPress dus.
- Le worker traite par défaut 3 identités par passage, uniquement pour les langues marquées traduisibles. Les options canoniques `worker_batch_size` (1–10) et `worker_time_budget` (5–120 secondes) permettent de borner le travail. Un pluriel peut nécessiter plusieurs requêtes ; une requête déjà lancée peut prolonger le passage.
- Les traductions renseignées, brouillons compris, sont préservées. Verrou global du worker, verrou par texte et contrôle de concurrence avant enregistrement.
- Un échec est visible et réessayé après 5 minutes, puis 30 minutes, avec au plus 3 tentatives de traitement. Quota épuisé : arrêt sans boucle de requêtes. Les réponses partielles réutilisables restent dans le cache.
- Plafonds partagés : caractères envoyés par jour UTC et requêtes par minute calendaire UTC. Valeurs de repli : 50 000 caractères et 100 requêtes/minute. Les appels échoués comptent aussi. Ce ne sont pas des compteurs de facturation en euros.
- Cache des traductions 24 h ; catalogues natifs `.mo` réutilisés en priorité lorsqu’ils contiennent toutes les formes attendues. Aucun basculement implicite vers un autre fournisseur.
- Les tâches explicites des anciennes routes IA ont un état persistant : en attente, traitement, terminé ou échoué. Lire leur progression ne les exécute pas. Un échec conserve les sorties déjà reçues et n’entraîne pas de nouvelle tentative payante automatique. Les tâches et sorties restent conservées dans les options WordPress, sans suppression automatique.
- Les erreurs fournisseur et d’écriture ne deviennent plus un texte source déclaré traduit. Les réponses partielles indiquent explicitement leurs erreurs et leurs identifiants. Les plafonds de caractères/requêtes sont des réservations conservatrices de tentatives : une validation locale tardive peut aussi consommer une réservation ; ce ne sont pas des relevés de facturation fournisseur.
- Les compteurs et le dernier bilan du worker sont visibles dans le suivi. Les requêtes de découverte des langues du fournisseur, mises en cache séparément, ne sont pas des requêtes de traduction comptabilisées.

Un gros catalogue se traite progressivement : par exemple 18 000 identités × une langue, sans cache, à 3 par passage de 5 minutes représente environ 21 jours. Adapter l’intervalle et le périmètre ; ne pas confondre fonctionnement silencieux et traduction instantanée de tout un site.

## Périmètre et intégration

Le catalogue couvre les appels Gettext PHP littéraux et ceux rencontrés à l’exécution, ainsi que les appels JavaScript littéraux reconnus, y compris certains formats compilés. Pour appliquer les traductions JavaScript, l’extension tierce doit enregistrer son domaine avec le mécanisme WordPress (`wp_set_script_translations` / `wp.i18n`). Les catalogues JSON natifs sont conservés et complétés ; leur absence ne bloque pas YUZ.

Le scan est limité à 30 000 fichiers PHP/JS de moins de 2 Mo ; il ignore les symlinks et répertoires vendor/node_modules/tests. Les chaînes générées dynamiquement dans un JavaScript non enregistré, textes d’API externes, applications headless, images, PDF et textes arbitraires hors Gettext ne deviennent pas automatiquement des chaînes WordPress. Le contenu éditorial WordPress reste géré par l’éditeur visuel existant. Les anciens écrans spécialisés slugs/emails ne font pas partie du nouveau catalogue ; les appels Gettext de modèles d’emails, eux, sont couverts.

Les fronts non WordPress nécessitent leur propre consommation de traductions : installer ce ZIP sur leur backend ne traduit pas à lui seul une application front indépendante.

Les originaux Gettext sont supposés anglais par défaut. Pour un domaine dont les originaux sont dans une autre langue, utiliser le filtre `yuz_tra_gettext_source_language` (arguments : langue, domaine). Les filtres `yuz_tra_string_language`, `yuz_tra_catalog_languages` et `yuz_tra_plural_rule` permettent les intégrations particulières.

## Données et confidentialité

Schéma additif : sources, traductions par langue et journal de consommation dans des tables préfixées WordPress. Pas de suppression des anciennes traductions à la mise à jour ou désactivation. La désactivation retire les événements cron YUZ. Sauvegarder avant tout retour à une version antérieure.

Les textes sélectionnés ou mis en file sont envoyés au fournisseur configuré quand une traduction est demandée. Ne pas activer la traduction silencieuse pour des chaînes sensibles sans examiner ce périmètre. Garder les traces de debug désactivées en production : certaines traces historiques peuvent contenir du texte traduit.

Le paquet ne contient ni configuration de site, ni clé, ni compte de test, ni sauvegarde, ni capture réseau. Il n’active pas d’autres plugins et ne modifie pas TranslatePress.

## Version, provenance et intégrité

Le bandeau WordPress affiche la version installée et les liens de téléchargement et d’aide.
[RELEASE-INTEGRITY.md](RELEASE-INTEGRITY.md) décrit les contrôles automatiques, le manifeste
SHA-256 et le raccordement GitLab restant à effectuer. Aucun miroir non vérifié n’est annoncé.
