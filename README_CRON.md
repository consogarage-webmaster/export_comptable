# Tâche cron : Export comptable automatique

Ce module propose un script pour exporter chaque jour les écritures de la veille au format XLSX.

## Installation

1. Vérifiez que le dossier `exports/` existe à la racine du module (créé automatiquement).
2. Le script à appeler est : `cron_export_comptable.php`

## Exemple de ligne crontab

```
0 2 * * * php /var/www/html/pts/modules/export_comptable/cron_export_comptable.php
```

Cela lancera l’export chaque jour à 2h du matin.

Le fichier sera généré dans le dossier `exports/` sous le nom :
```
export_comptable_YYYY-MM-DD.xlsx
```

## Notes
- Le script exporte les écritures de la veille (date du jour - 1).
- Les droits d’écriture doivent être accordés au dossier `exports/`.
- Le script utilise la logique d’export du backoffice (mêmes colonnes et format).
- Le format généré est XLSX (Excel, Office Open XML).
