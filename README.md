# glpi-nacre-search-plugin

Plugin GLPI 11 pour intégrer une recherche de codes NACRE directement dans les formulaires.

## Fonctionnalités

- injection automatique d’un bouton de recherche près des champs dont le nom, l’identifiant ou le placeholder contient `nacre`
- ouverture d’une modale de recherche avec filtrage temps réel sur les codes, libellés et mots-clés
- injection du code sélectionné dans le champ cible avec déclenchement des événements `input` et `change`
- chargement des données depuis un JSON statique optimisé avec champ `search` pré-calculé
- support des formulaires dynamiques via `MutationObserver`
- installation automatisée avec `install.sh`
- scripts CLI pour initialiser puis mettre à jour les données NACRE

## Structure du plugin

```text
bin/
  configure.php
  update_nacre_data.php
config/
  defaults.php
inc/
  NacreData.php
public/
  css/nacre-search.css
  data/nacre.json
  js/nacre-search.js
resources/
  nacre.example.json
hook.php
install.sh
setup.php
```

## Installation rapide

### Pré-requis

- GLPI 11
- PHP 8.1+
- `rsync` recommandé sur le serveur cible

### Déploiement automatique

Depuis le dépôt du plugin :

```bash
chmod +x /home/runner/work/glpi-nacre-search-plugin/glpi-nacre-search-plugin/install.sh
GLPI_PLUGIN_DIR=/var/www/html/glpi/plugins /home/runner/work/glpi-nacre-search-plugin/glpi-nacre-search-plugin/install.sh
```

Le script :

1. génère la configuration locale `config/local.php`
2. initialise `public/data/nacre.json` à partir de `resources/nacre.example.json`
3. copie le plugin dans `GLPI_PLUGIN_DIR/nacresearch`
4. rend les scripts exécutables

Ensuite, activez le plugin depuis **Configuration > Plugins** dans GLPI.

## Initialisation / mise à jour des données NACRE

### Initialisation locale

```bash
php /home/runner/work/glpi-nacre-search-plugin/glpi-nacre-search-plugin/bin/configure.php
```

### Mise à jour depuis un nouveau JSON

Le fichier source doit être un tableau JSON d’objets contenant au minimum `code` et `label`.

```bash
php /home/runner/work/glpi-nacre-search-plugin/glpi-nacre-search-plugin/bin/update_nacre_data.php \
  --source=/chemin/vers/nacre.json \
  --target=/home/runner/work/glpi-nacre-search-plugin/glpi-nacre-search-plugin/public/data/nacre.json
```

Exemple de structure attendue :

```json
[
  {
    "code": "62.01Z",
    "label": "Programmation informatique",
    "section": "J",
    "division": "62",
    "group": "62.0",
    "class": "62.01",
    "keywords": ["développement", "logiciel", "code"]
  }
]
```

Le script normalise et trie les entrées, puis ajoute un champ `search` pour accélérer les filtrages volumineux.

## Utilisation dans GLPI

1. ouvrez un formulaire GLPI 11 contenant un champ texte lié au code NACRE
2. assurez-vous que le champ cible contient `nacre` dans son `name`, son `id` ou son `placeholder`
3. cliquez sur le bouton **Chercher un code NACRE** injecté par le plugin
4. recherchez un code ou un mot-clé
5. cliquez sur un résultat pour injecter automatiquement le code dans le champ

## Personnalisation

Le fichier `config/defaults.php` définit :

- la limite de résultats affichés
- le délai de debounce de recherche
- les libellés d’interface

Après installation, `config/local.php` peut surcharger ces valeurs sans modifier les fichiers versionnés.

## Dépannage

### Le bouton n’apparaît pas

- vérifiez que le champ du formulaire contient bien `nacre` dans son `name`, `id` ou `placeholder`
- confirmez que le plugin est activé dans GLPI
- ouvrez les outils de développement du navigateur pour vérifier le chargement de `js/nacre-search.js`

### La modale s’ouvre mais aucun code n’est trouvé

- vérifiez la présence du fichier `public/data/nacre.json` dans le plugin déployé
- relancez `php bin/configure.php` ou `php bin/update_nacre_data.php --source=...`
- contrôlez la validité du JSON source

### Les formulaires sont chargés dynamiquement

Le plugin observe les mutations du DOM. Si un formulaire est injecté après chargement de la page, le bouton NACRE est ajouté automatiquement.

## Données fournies

Le dépôt contient un exemple riche dans `resources/nacre.example.json` et un JSON prêt à servir dans `public/data/nacre.json`.
