# ChangeLog

## Unreleased

- Création du module autonome `lmdbrexelpunchout`.
- Renommage complet du module en `lmdbrexelpunchout` / `LmdbRexelPunchout`.
- Conservation du seul flux Punchout cXML : `PunchOutSetupRequest`, `PunchOutSetupResponse` et `PunchOutOrderMessage`.
- Acceptation des lignes cXML `ItemOut` et des références alternatives lorsque `SupplierPartID` est vide afin d’éviter les paniers Rexel rejetés comme sans ligne.
- Conservation d'un retour cXML public limité au stockage du panier, avec import confirmé depuis une page authentifiée et protégée par token CSRF.
- Suppression des anciens protocoles non cXML, pages de retour associées, parsers et tests dédiés.
- Suppression des anciennes logiques fournisseur spécifiques : création automatique de fournisseur, barèmes dédiés, pages associées et fallbacks fiscaux dédiés.
- Ajout de la sélection explicite du fournisseur Rexel dans les réglages du module.
- Renommage des constantes en préfixe `LMDBREXELPUNCHOUT_`.
- Renommage des tables SQL en `lmdbrexelpunchout_session`, `lmdbrexelpunchout_session_line` et `lmdbrexelpunchout_unitmap`.
- Conservation du cron natif de nettoyage des sessions et payloads sous la classe `LmdbRexelPunchoutCron`.
- Ajout d'un bouton de réglage pour créer ou associer automatiquement le tiers fournisseur REXEL France.
- Ajout d'une stratégie configurable de référence produit pour les produits REXEL absents : préfixe configuré, numérotation native Produits/Services, référence REXEL ou choix manuel à l'import.
- Mise à jour des traductions, de la documentation et des tests légers pour le périmètre cXML uniquement.

## 1.0.0 - 2026-07-01

- Première version autonome du module Rexel Punchout cXML.
