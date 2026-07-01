# lmdbrexelpunchout

Module externe Dolibarr pour lancer un Punchout Rexel cXML et importer le panier retourné dans une commande fournisseur brouillon.

## Compatibilité

- Dolibarr : v20+
- PHP : 8.0+
- Base : MySQL/MariaDB via l'abstraction Dolibarr
- Emplacement d'installation : `htdocs/custom/lmdbrexelpunchout`

Le dépôt contient directement la racine du module. Pour l'installer, placer ce répertoire dans `htdocs/custom/lmdbrexelpunchout`, puis activer le module depuis la liste des modules Dolibarr.

## Dépendances

- Module Fournisseurs / commandes fournisseurs
- Module Produits / services

Le module ne dépend pas de `rexelsync`. Le tiers Rexel et les identifiants cXML sont configurés dans les réglages du module.

## Fonctionnalités

- Bouton `Punchout Rexel` ajouté par hook sur les commandes fournisseur brouillon.
- Affichage du bouton uniquement si la commande est brouillon, liée au tiers Rexel configuré, dans l'entité active propriétaire et si l'utilisateur dispose du droit `punchout/use`.
- Flux cXML standard avec `PunchOutSetupRequest`, `PunchOutSetupResponse` et retour `PunchOutOrderMessage`.
- Retour panier public en `cXML-urlencoded` ou `cXML-base64`.
- Conservation des métadonnées cXML : `BuyerCookie`, `BrowserFormPost`, `StartPage`, frais de port, écocontribution DEEE, total, taxes, adresse `ShipTo`, références produit (`SupplierPartID` ou référence alternative cXML), `SupplierPartAuxiliaryID`, `UnitOfMeasure` et `Classification`.
- Stockage du panier au retour cXML, puis import immédiat avec l’utilisateur Dolibarr qui a lancé la session Punchout.
- Recherche produit par prix fournisseur Rexel, puis par référence générée, puis création optionnelle.
- Mise à jour du prix fournisseur via `ProductFournisseur`.
- Ajout des lignes via `CommandeFournisseur::addline()`.
- Import optionnel des frais de port cXML positifs comme ligne de commande fournisseur.
- Import optionnel de l’écocontribution DEEE cXML positive comme ligne de commande fournisseur.
- Mapping générique des unités fournisseur vers les unités Dolibarr.
- Sessions Punchout temporaires avec jeton aléatoire à usage unique.
- Cron natif, désactivé par défaut, pour expirer les sessions et purger les anciens payloads.
- Bouton de réglage pour créer ou associer automatiquement le tiers fournisseur REXEL France.
- Stratégie configurable pour les références produit créées lors de l'import : préfixe actuel, numérotation native Produits/Services, référence REXEL ou choix manuel par ligne.

## Configuration

Le seul point d'entrée déclaré est :

```text
setup.php@lmdbrexelpunchout
```

Onglets internes disponibles :

- Réglages
- Compatibilité
- Sessions Punchout
- À propos

Paramètres principaux :

- Tiers fournisseur Rexel
- URL cXML PunchOutSetup
- `SharedSecret` cXML
- Domaines et identités `From`, `To` et `Sender`
- Mode cXML test ou production
- Mode d'ouverture : modale intégrée, fenêtre popup ou nouvel onglet
- Devise attendue
- TVA par défaut
- Création des produits absents
- Stratégie de référence pour les produits absents
- Autorisation des prix à zéro
- Préfixe de référence produit, par défaut `REXEL-`
- Durée de validité du jeton
- Durée de conservation des payloads
- Import des frais de port cXML, produit/service de frais de port optionnel et TVA dédiée optionnelle
- Import de l’écocontribution DEEE cXML, produit/service DEEE optionnel et TVA dédiée optionnelle
- Correspondances d'unités fournisseur vers unités Dolibarr

Les réglages sont enregistrés par entité. Les secrets sont stockés via `dolEncrypt()` lorsque cette fonction native Dolibarr est disponible.

## Flux Utilisateur

1. L'utilisateur crée une commande fournisseur brouillon pour le tiers Rexel configuré.
2. Le bouton `Punchout Rexel` apparaît sur la fiche si les droits et la configuration sont valides.
3. Le module crée une session Punchout temporaire et envoie une requête `PunchOutSetupRequest` vers Rexel.
4. Rexel renvoie une `StartPage` vers laquelle l'utilisateur est redirigé.
5. Rexel retourne le panier sur l'URL publique `public/return_cxml.php`.
6. Le module stocke le payload brut, normalise les lignes et importe directement la commande avec l’utilisateur qui a lancé le Punchout.
7. Si la stratégie de référence produit est le choix manuel et qu’une ligne absente nécessite une référence, l’utilisateur arrive directement sur `public/import.php` pour saisir uniquement les références manquantes.
8. Le module crée ou retrouve les produits, met à jour les prix fournisseur et ajoute les lignes, frais de port et DEEE dans la commande.

## Multicompany

La version actuelle refuse le lancement et l'import lorsque la commande fournisseur appartient à une autre entité que l'entité active. Cette règle évite d'écrire des produits, prix fournisseur ou lignes de commande dans une mauvaise entité lors de la consultation d'une commande partagée.

## Pages Publiques

- `public/start.php`
- `public/return_cxml.php`
- `public/return_common.php`
- `public/import.php`

Les pages publiques vérifient le jeton Punchout et l'entité. La saisie manuelle des références produit reste protégée par authentification Dolibarr et token CSRF.

## Hors Périmètre V1

- Autres protocoles Punchout que cXML.
- Barèmes fiscaux spécifiques fournisseur.
- Couplage obligatoire avec un module de synchronisation Rexel.
- Envoi final de la commande à Rexel par EDI ORDER.
- Support complet d'import depuis une entité différente de l'entité propriétaire.

## Tests Recommandés

- Activation, désactivation et réactivation du module sans perte des réglages.
- Page de compatibilité.
- Configuration cXML complète et incomplète.
- Bouton visible/invisible selon tiers, statut, droits et entité.
- Retour cXML avec `PunchOutOrderMessage` en `cXML-urlencoded`.
- Retour cXML avec `PunchOutOrderMessage` en `cXML-base64`.
- Retour cXML avec lignes `ItemIn` ou `ItemOut` et référence produit alternative lorsque `SupplierPartID` est vide.
- Parsing `StartPage` depuis `PunchOutSetupResponse`.
- Retour cXML avec frais de port absent, nul et positif.
- Retour cXML avec écocontribution DEEE absente, nulle et positive.
- Parsing des taxes, de `ShipTo` et des métadonnées de lignes.
- Saisie manuelle sans token CSRF refusée.
- Saisie manuelle avec token CSRF acceptée.
- Double retour ou double import refusé.
- Prix à zéro refusé par défaut, puis accepté si l'option est activée.
- Devise différente de la devise attendue refusée.
- Produit existant par référence fournisseur.
- Produit créé avec préfixe configuré.
- Produit créé avec la numérotation native Produits/Services lorsque le modèle produit fournit une référence.
- Produit créé ou réutilisé avec la référence REXEL.
- Produit créé ou réutilisé avec une référence saisie par l'utilisateur lors de l'import.
- Création ou association automatique du tiers REXEL depuis les réglages.
- Unité fournisseur non mappée importée avec avertissement.
- Deux entités Multicompany avec configurations distinctes.
- Cron natif visible dans les travaux planifiés et exécutable manuellement.
