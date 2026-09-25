# Évaluation linguistique et budget séparé

Le corpus tests/corpus.json contient 12 cas synthétiques, pas 12 traductions validées.
Les locales ne constituent pas un échantillon représentatif de toutes les langues.
Aucune note humaine, traduction fournisseur ou dépense n'est préremplie.

## Protocole

1. Le responsable autorise un essai identifié : fournisseur, modèle exact, devise, plafond total et plafond par requête, tarif daté, corpus SHA-256. Aucun solde existant n'est une autorisation de dépense.
2. Exécuter uniquement ces cas, enregistrer réponse brute privée, traduction, modèle/révision, paramètres, date, tokens réellement retournés et erreurs. Pas de reprise payante automatique.
3. Réserver le coût maximal avant chaque appel; refuser si tarif inconnu ou plafond insuffisant. Distinguer coût estimé et coût réellement facturé. Ce banc ne possède pas encore d'exécuteur fournisseur payant.
4. Faire relire par une personne compétente dans chaque paire de langues, sans lui présenter une référence IA comme vérité.
5. Pour chaque sortie : identifiant du cas, empreinte de la sortie évaluée, identifiant du relecteur, date et notes 0–4 sur sens, terminologie, fluidité; défauts de négation, variables, balises et pluriels notés séparément.
6. Aucun passage automatique : erreur critique = rejet; modification de la sortie = nouvelle relecture. Une moyenne ne compense pas une négation inversée ou une variable perdue.

À fournir pour terminer ce volet : budget d'essai explicite et relecteur(s) qualifié(s).
Les sorties attendues sont un registre de consommation et des évaluations humaines traçables; en leur absence le rapport reste NOT_TESTED.
