# Export Comptable - Module PrestaShop

Module PrestaShop pour l'export comptable des factures et avoirs au format XLSM standardisé.

## Fonctionnalités

- ✅ Export des **factures** (OrderInvoice)
- ✅ Export des **avoirs** (OrderSlip) avec écritures inversées
- ✅ Support des **consignes** par produit
- ✅ Distinction France / Export (codes comptables différents)
- ✅ Filtre par période (date de début / date de fin)
- ✅ Affiche les 100 derniers documents par défaut
- ✅ Format d'export : **XLSM** (Excel avec macros)
- ✅ Prévisualisation en tableau avec en-têtes sur deux lignes

## Accès

**Menu** : Vendre > Commandes > **Export comptable**

## Structure de l'export

### Factures (2 à 5 lignes selon le contenu)

1. **Total TTC** - Débit (D) - Compte `41100000`
2. **Articles HT** - Crédit (C) - Compte `70700300` (France) / `70792300` (Export)
3. **Frais de port HT** - Crédit (C) - Compte `70850300` (France) / `70852300` (Export) - *si > 0*
4. **TVA** - Crédit (C) - Compte `44570000` - *si > 0*
5. **Consigne HT** - Crédit (C) - Compte `70710300` (France) / `70712300` (Export) - *si > 0*

### Avoirs (2 à 5 lignes avec CODC inversé)

1. **Total TTC** - Crédit (C) - Compte `41100000`
2. **Articles HT** - Débit (D) - Compte `70700300` (France) / `70792300` (Export)
3. **Frais de port HT** - Débit (D) - Compte `70850300` (France) / `70852300` (Export) - *si > 0*
4. **TVA** - Débit (D) - Compte `44570000` - *si > 0*
5. **Consigne HT** - Débit (D) - Compte `70710300` (France) / `70712300` (Export) - *si > 0*

### Numérotation
- **Factures** : Numéro PrestaShop natif
- **Avoirs** : Format `AV` + 6 chiffres (ex: `AV000123`)

## Comptes généraux utilisés

| Type             | France   | Export   |
| ---------------- | -------- | -------- |
| Articles HT      | 70700300 | 70792300 |
| Frais de port HT | 70850300 | 70852300 |
| Consigne HT      | 70710300 | 70712300 |
| TVA collectée    | 44570000 | 44570000 |
| Compte client    | 41100000 | 41100000 |

## Gestion des consignes

### Base de données
Le module utilise une colonne `consigne` dans la table `ps_product` :
- Type : `DECIMAL(20,6) UNSIGNED`
- Valeur par défaut : `0.000000`

### Calcul
Le total des consignes par commande est calculé automatiquement :
```sql
SUM(quantité × consigne) pour tous les produits de la commande
```

### Configuration
Voir `sql/README.md` pour :
- Script d'ajout de la colonne (`add_consigne_column.sql`)
- Script de seeding pour les tests (`seed_consignes.sql`)

## Installation

1. Copier le dossier `export_comptable` dans le dossier `modules` de votre boutique PrestaShop
2. Installer le module depuis le back-office
3. **Important** : Exécuter le script SQL pour ajouter la colonne consigne :
   ```bash
   cd modules/export_comptable
   mysql -u [user] -p[pass] [database] < sql/add_consigne_column.sql
   ```
4. L'écran d'export est accessible dans **Vendre > Commandes > Export comptable**

## Utilisation

1. Accéder à **Vendre > Commandes > Export comptable**
2. (Optionnel) Sélectionner une période avec les filtres date
3. Cliquer sur **Filtrer** pour prévisualiser les données
4. Cliquer sur **Exporter en XLSM** pour télécharger le fichier

### Sans filtre
- Affiche les 100 derniers documents (factures + avoirs)
- Export rapide des données récentes

### Avec filtre de dates
- Export de tous les documents de la période
- Pas de limite de résultats

## Format du fichier exporté

- **Type** : XLSM (Office Open XML - Excel avec macros)
- **Nom** : `export_comptable_AAAAMMJJ_HHMMSS.xlsm`
- **Structure** : 38 colonnes par ligne
- **En-têtes** : 2 lignes (descriptions + codes courts)
- **Compatibilité** : Excel, LibreOffice, logiciels comptables

## Compatibilité

- PrestaShop 8.0.0 et supérieur
- PHP 7.4+
- MySQL 5.6+

## Documentation technique

- `INTEGRATION_CONSIGNES.md` : Documentation détaillée de l'intégration des consignes
- `sql/README.md` : Documentation des scripts SQL et migrations
- `controllers/admin/AdminExportComptableController.php` : Logique principale

## Version

**1.0.1** - Avec support complet des consignes

## Auteur

Romain / Consogarage
