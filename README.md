# export_comptable

Module PrestaShop pour l’export comptable des factures.

## Fonctionnalités

- Ajoute un écran dans **Vendre > Commandes** pour exporter les écritures comptables des factures.
- Filtre par date de facture.
- Affiche les 100 dernières factures par défaut.
- Export CSV des écritures (format compatible comptabilité).
- Tableau avec en-têtes sur deux lignes.

## Installation

1. Copier le dossier `export_comptable` dans le dossier `modules` de votre boutique PrestaShop.
2. Installer le module depuis le back-office.
3. L’écran d’export est accessible dans **Vendre > Commandes > Export comptable**.

## Utilisation

- Sélectionner une période ou laisser vide pour les 100 dernières factures.
- Cliquer sur **Filtrer** pour afficher le tableau.
- Cliquer sur **Exporter en CSV** pour télécharger le fichier.

## Compatibilité

- PrestaShop 1.7 et 8.x

## Export automatique (cron)

Ce module propose un script pour exporter chaque jour les écritures de la veille au format XLSX.

### Installation du cron

1. Vérifiez que le dossier `exports/` existe à la racine du module (créé automatiquement).
2. Le script à appeler est : `cron_export_comptable.php`

### Exemple de ligne crontab

```
0 2 * * * php /var/www/html/pts/modules/export_comptable/cron_export_comptable.php
```

Cela lancera l’export chaque jour à 2h du matin.

Le fichier sera généré dans le dossier `exports/` sous le nom :
```
export_comptable_YYYY-MM-DD.xlsx
```

#### Notes
- Le script exporte les écritures de la veille (date du jour - 1).
- Les droits d’écriture doivent être accordés au dossier `exports/`.
- Le script utilise la logique d’export du backoffice (mêmes colonnes et format).
- Le format généré est XLSX (Excel, Office Open XML).

## Auteur

Romain / Consogarage

---