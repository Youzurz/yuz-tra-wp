# Contrôle obligatoire, publication proportionnée au risque

Décision du 16 septembre 2026. Le parseur `tools/check-plugin-report.php` refusait
les ERROR mais laissait passer tous les WARNING. Un scan « vert » n'établissait donc
pas que les alertes SQL, nonces, préfixes ou validation avaient été traitées.

Décision ajustée : publier après contrôle, sans exiger zéro défaut.
WARNING/ERROR n'est pas une criticité de vulnérabilité.

| Observation | Décision |
| --- | --- |
| Contrôle obligatoire absent, invalide ou sur un autre artefact | Refaire le contrôle |
| Vulnérabilité critique/élevée confirmée, secret actif exposé | Corriger/neutraliser avant publication du périmètre affecté |
| Installation impossible, perte/corruption de données, faux succès d'une fonction essentielle | Corriger et prouver l'effet métier avant publication |
| Risque moyen sans impact essentiel | Publication possible avec acceptation, compensation, responsable et échéance |
| Défaut faible, esthétique ou avis de performance | Publication possible avec suivi |
| Suspicion SQL/XSS/permission non évaluée | Examen ciblé; pas une vulnérabilité confirmée |
| Exigence WordPress.org | Correction ou faux positif étayé pour ce canal, indépendamment du risque sécurité |

Le faux succès « publié » est essentiel : HTTP 200 ne prouve pas l'enregistrement
de la traduction. Un défaut esthétique n'a pas la même conséquence.

## Sous-contrôle Plugin Check

`php tools/check-plugin-report.php REPORT.csv [TRIAGE.json]`

- DirectDatabaseQuery.DirectQuery et DirectDatabaseQuery.NoCaching seuls sont
  informatifs/performance : TRACKED_ADVISORY, conservés mais non bloquants.
  Ils ne prouvent ni SQL sûr ni charge acceptable : PreparedSQL et tests métier
  restent indépendants. Les autres alertes sont REVIEW_REQUIRED, pas des failles avérées.
- Une décision optionnelle est liée au SHA-256 du rapport exact et au fingerprint
  d'une ligne. Jokers, doublons, entrées absentes et décisions expirées sont refusés.
- LOW/MEDIUM accepté peut laisser passer un WARNING. HIGH/CRITICAL ne passe pas
  par acceptation. ERROR ne passe que par faux positif démontré, pas risque accepté.
- Verdicts : PASS, PASS_WITH_FINDINGS, FAIL. Le deuxième conserve le défaut;
  ce n'est ni un scan vide ni une approbation WordPress.org.

Manifeste : report_sha256, findings[]. Chaque entrée : fingerprint, severity
(NONE/LOW/MEDIUM/HIGH/CRITICAL), disposition (FALSE_POSITIVE/ACCEPTED_RISK/CONFIRMED),
owner, approved_by, approval_evidence, rationale, evidence, issue, reviewed_at,
expires_at (ISO-8601). Inclure compensation et sa preuve dans rationale/evidence.
Expiration au plus tard 30 jours après examen; rapport changé = nouvel examen.
FALSE_POSITIVE exige NONE; un vrai défaut exige une gravité.

Le parseur vérifie structure/portée, pas l'identité de l'approbateur ni le contenu
des preuves : l'acceptation doit être vérifiable dans une revue protégée. Un nom
dans JSON n'est pas une autorisation. Aucun manifeste d'acceptation réel n'a été
créé; les fixtures de tests ne sont pas des dérogations.

Ce contrôle est appelé par la recette WordPress du workflow existant. Le job
`release-gate` dépend des jobs verify, wordpress et security; le job publish dépend
de release-gate. La modification est locale tant qu'aucun commit/run distant exact
ne la prouve. Ne pas présenter cette implémentation comme déjà déployée sur GitHub/GitLab.

## Preuves minimales

- Tests dans les deux sens : avis mineur autorisé, risque majeur refusé;
  acceptation valide autorisée, expiration/incohérence refusée; rapport invalide refusé.
- Rapport Plugin Check complet de l'artefact exact; fingerprints pour le triage.
- Contrôles d'effet métier : refus sans écriture, publication effectivement persistée,
  HTML exécutable supprimé, balisage légitime conservé, accès public sans fournisseur.
- Provenance commit/ZIP, scans secrets/dépendances, build reproductible, licences/SBOM.
- Publication manuelle autorisée seulement après réussite; pas de réemploi du résultat
  d'une candidate sur une autre et aucun écrasement de ZIP versionné.

## Wazuh : détection et réaction distinctes

Les contrôles applicatifs produisent les résultats; Wazuh collecte/corrèle les événements
et surveille la dérive. Relier commit, version, SHA-256, run et identifiant d'événement,
sans jetons, textes clients ou payloads sensibles. Tester la réception et la notification,
pas seulement l'émission. Le transport CI→Wazuh n'est pas déclaré validé par ce fichier.

Une alerte bloque si le seuil de risque ou une exigence plateforme le justifie,
sinon elle reste suivie sans empêcher la release. Le parseur n'annule pas les tests
métier essentiels ni les contrôles HIGH/CRITICAL/secrets séparés. Notifier seulement
un changement actionnable, une panne ou une échéance. Toute réponse de production
destructive exige une autorisation. Voir CONTROL-MAINTENANCE.md pour la fraîcheur,
la compatibilité et la mise à jour progressive des contrôles.
