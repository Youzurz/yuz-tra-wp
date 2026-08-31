# Commencer sans se perdre

## 1. Télécharger le bon fichier

Sur la [page des versions](https://github.com/Youzurz/yuz-tra-wp/releases), choisir
**yuz-tra-1.5.0.zip** dans « Assets ». Ne pas choisir « Source code.zip » : c’est
l’archive du dépôt, pas l’extension prête à téléverser.
La version est une distribution indépendante d’évaluation, pas une validation WordPress.org.

Le fichier `.sha256` permet de vérifier l’intégrité, sans garantir à lui seul la sécurité :

```sh
sha256sum -c yuz-tra-1.5.0.zip.sha256
```

Sur macOS : `shasum -a 256 yuz-tra-1.5.0.zip` et comparer avec le fichier de contrôle.

## 2. Installer sur staging

Sauvegarder la base **et** les fichiers de l’ancienne extension. Conserver le précédent ZIP.
Dans WordPress : **Extensions → Ajouter une extension → Téléverser une extension**.
Sélectionner le ZIP sans le décompresser, installer puis activer. Pour mettre à jour,
confirmer le remplacement de **YUZ-TRA uniquement**. Ne pas supprimer TranslatePress
ou d’autres extensions pour installer ce paquet.

Prérequis déclarés : WordPress 6.5+, PHP 8.1+. Les tests de cette livraison utilisent
WordPress 7.0 et PHP 8.3. Vérifier la recette et les limites avant un site de production.

## 3. Traduire une première chaîne, sans service payant

1. Dans YUZ-TRA, configurer les langues source et cible.
2. Ouvrir l’onglet Strings/Chaînes. Un administrateur peut lancer le scan du code installé.
3. Choisir la langue cible et le domaine du plugin concerné, puis rechercher un texte précis.
4. Lire l’original, son contexte et ses éventuelles formes plurielles.
5. Saisir la traduction. Garder les variables comme `%s`, `%1$d` ou `{name}` intactes.
6. Enregistrer un brouillon. Après relecture, cliquer **Publier**.
7. Visiter la page concernée avec la bonne langue. Seules les traductions publiées
   du catalogue sont appliquées. La langue d’administration suit le profil WordPress.

Si le texte ne s’affiche pas : vérifier domaine, contexte, langue du profil, publication,
cache et si l’extension utilise réellement Gettext. Ne pas relancer une traduction payante
pour résoudre un simple problème d’affichage.

## 4. Utiliser les deux éditeurs

Ouvrir une page WordPress dans l’éditeur visuel puis **Strings**. Le visuel est à gauche,
les chaînes à droite. La séparation change leur largeur ; le coin inférieur droit change
la taille de l’ensemble. Au clavier : Tab jusqu’à la poignée, puis flèches.

**Échap** ou le × du catalogue revient au visuel. Le catalogue conserve ses saisies
tant que la page reste ouverte. L’éditeur visuel peut autosauvegarder : ne pas y saisir
de texte de démonstration sur un site de production. Le bouton Close du visuel ferme
le visuel ; le catalogue reste indépendant.

## 5. Ajouter un fournisseur, seulement si nécessaire

Lire PRIVACY.md et les conditions du fournisseur. Renseigner son adresse, sa clé si
nécessaire et les quotas. Le bouton Tester vérifie la connexion, pas la qualité linguistique.
Dans le catalogue, sélectionner une à cinq chaînes non renseignées et demander la
traduction. Relire le résultat avant publication. Un échec doit afficher une erreur,
pas un succès avec l’original inchangé.

Ollama nécessite un serveur et un modèle déjà disponibles. Le glossaire et la mémoire
approuvée peuvent contextualiser les demandes ; ils ne garantissent pas la justesse.
Le worker silencieux est facultatif, consomme des ressources et nécessite WP-Cron.
Ne pas l’activer sur tout le catalogue avant d’avoir testé un petit périmètre.

## 6. Mettre à jour ou revenir en arrière

Lire CHANGELOG.md et KNOWN-LIMITS.md, sauvegarder, essayer la nouvelle version sur staging,
puis téléverser le ZIP. Pas de mise à jour automatique dans cette distribution.
Un retour à l’ancien ZIP ne restaure pas la base : en cas de problème de migration,
utiliser la sauvegarde correspondante avec l’administrateur. La désactivation conserve
les données et arrête les événements cron YUZ.

## 7. Besoin d’aide ?

[Choisir une demande](https://github.com/Youzurz/yuz-tra-wp/issues/new/choose) :
bug, évolution ou aide. Joindre les versions et les étapes, jamais une clé, un cookie,
une commande client, un export de base ni un HAR brut. Une difficulté d’usage est aussi
un retour utile sur la clarté du produit.
