# Recette 1.5.0 — 31 août 2026

## Atelier et transport

Tests locaux avec les véritables JS/CSS du paquet :

- Deux éditeurs alignés en haut, sans recouvrement ; saisies dans les deux panneaux.
- Séparation à la souris et au clavier ; dimensions mémorisées et réinitialisables.
- Petit écran : empilement et absence de débordement horizontal du conteneur.
- Fermeture/réouverture : conservation des saisies, focus et raccourcis isolés.
- HTML 200/403/404, JSON en erreur/incomplet et endpoint d’une autre origine : pas de faux succès.
- Réponse automatique ou sauvegarde tardive : ne remplace pas une nouvelle saisie/sélection/langue.

Recette navigateur **authentifiée sur un vrai backend WordPress** : alignement des deux
éditeurs, champ du catalogue cliquable, redimensionnement clavier, Échap et réouverture
avec conservation. Requêtes AJAX observées HTTP 200 JSON, aucune erreur JavaScript.
Compte temporaire à droits limités supprimé après le test. Aucun texte de test publié.
La traduction WooCommerce affichée est un enregistrement réel du catalogue, pas une fixture.

L’éditeur visuel historique charge sa traduction après le bootstrap : le contrôle de
conservation attend la fin de ce chargement pour ne pas le confondre avec une perte de saisie.

## Limites de cette preuve

La disposition n’établit pas la justesse de toutes les traductions. Les tests unitaires
avec fixtures ne constituent pas des appels réels à LibreTranslate/Ollama. Les contrôles
fournisseur des versions précédentes ne certifient pas Google/DeepL ni chaque modèle.
Voir KNOWN-LIMITS.md pour les alertes Plugin Check et les travaux avant WordPress.org.

## Construction et suivi

Installation effective du ZIP dans WordPress de test : activation, catalogue,
enregistrement/publication puis rechargement vérifiés. Les régressions PHP couvrent
les faux succès fournisseur, variantes régionales, HTML, mémoire/glossaire et tâches
persistantes ; leur fournisseur contrôlé est une fixture, pas une démo commerciale.

Un passage Plugin Check 2.1.0 sur le premier paquet 1.5.0 a compté **392 erreurs et
2 025 avertissements**, contre 431/2 022 sur la base précédente. Les erreurs CDN,
en-tête de licence et de nombreux gardes ont été corrigées ; 368 erreurs de domaine
de traduction restent, ainsi que des alertes nécessitant qualification. Ce résultat
ne vaut pas une admission WordPress.org. La version déclarée « Tested up to 7.0 »
correspond à la machine de test ; le vérificateur la signale comme à actualiser.

Un test HTTP sur le WordPress isolé avec Twenty Twenty-Five et le bootstrap stock
du ZIP a également ouvert les deux éditeurs : Vue local HTTP 200, aucun appel à
un CDN de scripts dans ce parcours, aucune erreur JavaScript. Un premier essai
avait détecté l’absence du thème dans l’environnement de test ; installer le thème
officiel a permis la recette, sans ajouter de secours spécifique au site dans le ZIP.

Les résultats CI, le commit source, le ZIP et sa somme SHA-256 sont consultables dans
Actions et Releases. Vérifier l’état effectif des exécutions : ce document n’anticipe pas
leur réussite. Le statut d’un ticket prouve son routage dans GitHub, pas la réception
d’un email ni une prise en charge humaine.
