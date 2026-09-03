# glpi-nacre-search-plugin

Plugin GLPI 11 pour intégrer une recherche de codes NACRE directement dans les formulaires.

## Fonctionnalités

- injection automatique d’un bouton de recherche près des champs dont le nom, l’identifiant, le placeholder ou le libellé contient `nacre`
- ouverture d’une modale de recherche avec filtrage temps réel sur les codes, libellés et mots-clés
- injection du code sélectionné dans le champ cible avec déclenchement des événements `input` et `change`
- chargement des données depuis un JSON statique optimisé avec champ `search` pré-calculé
- support des formulaires dynamiques via `MutationObserver`
- installation automatisée avec `install.sh`
- scripts CLI pour initialiser puis mettre à jour les données NACRE
- import administrateur d’un classeur NACRES Excel avec sauvegardes restaurables

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

Le script copie le plugin dans `GLPI_PLUGIN_DIR/nacresearch`.

Les données versionnées dans `public/data/nacre.json` sont conservées. Ensuite, activez le plugin depuis **Configuration > Plugins** dans GLPI.

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

## Initialisation du workflow de tickets LPS

Après avoir déployé le plugin dans GLPI, exécutez le script avec le compte du serveur web :

```bash
runuser -u www-data -- php /var/www/commandes/plugins/nacresearch/bin/bootstrap_lps_ticket_workflow.php
```

Si le plugin n’est pas dans le répertoire standard des plugins, précisez GLPI :

```bash
runuser -u www-data -- php /chemin/vers/nacresearch/bin/bootstrap_lps_ticket_workflow.php \
  --glpi-root=/var/www/commandes
```

Le script GLPI 11 est idempotent et utilise les modèles GLPI. Il exige une unique entité exactement nommée **LPS**, puis crée ou met en conformité le groupe technique **Gestionnaires financiers** dans cette entité et les profils centraux **Gestionnaire financier** et **Administratrice financière**. Il ne crée ni comptes, ni règles d’autorisation, ni associations utilisateurs.

Le gestionnaire financier ne peut traiter que les tickets qui lui sont affectés ou affectés à son groupe et ne peut pas affecter d’autres techniciens. L’administratrice peut voir et affecter tous les tickets, et gérer les formulaires natifs. Le plugin Fields ne propose pas de droit d’administration dédié : son éditeur exige le droit global **Configurer**, qui autorise également la gestion des plugins. Afin de préserver l’interdiction de gérer les plugins, le script ne l’accorde pas ; configurez Fields avec un compte distinct autorisé si nécessaire. Aucun droit utilisateurs, groupes, profils ou plugins n’est attribué.

## Import Excel administrateur

Le droit de profil **plugin_nacresearch_data_management** (Gestion des données NACRES) est désactivé par défaut. Accordez-le explicitement en mise à jour à l’administrateur chargé de l’import. Le script `bootstrap_lps_ticket_workflow.php` l’accorde au profil **Administratrice financière**.

Avec ce droit, ouvrez **Configuration > Plugins > NACRE Search > Configuration**, puis sélectionnez le classeur `.xlsx`. Le fichier est limité à 10 Mo et doit contenir une seule feuille nommée **N** :

- la ligne 4 contient les en-têtes des colonnes B à N ;
- le code est en B, le code auxiliaire en C et le libellé en D ;
- les comptes sont en E à L, la nature en M et le marqueur d’inactivité en N.

Les espaces des codes sont normalisés (`XB. 09` devient `XB.09`). Les lignes vides, mal formées ou marquées `X` en colonne N sont ignorées. L’import est refusé si la structure attendue manque, si un code valide apparaît deux fois ou si aucune ligne exploitable ne reste. Les données remplacées sont archivées avant l’écriture atomique ; les cinq versions les plus récentes apparaissent sur la même page et peuvent être restaurées après confirmation.

### Après la première connexion CAS

Pour chaque personne, après sa première connexion CAS :

1. dans **Administration > Utilisateurs**, ajoutez-la manuellement au groupe **Gestionnaires financiers** ;
2. dans l’onglet **Autorisations**, associez manuellement le profil **Gestionnaire financier** ou **Administratrice financière** à l’entité **LPS**, sans récursivité ;
3. pour les demandeurs, conservez le profil GLPI existant **Self-Service**.

Les changements d’appartenance et de profil restent volontaires et manuels afin de ne pas interférer avec CAS.

## Utilisation dans GLPI

1. ouvrez un formulaire GLPI 11 contenant un champ texte lié au code NACRE
2. assurez-vous que le champ cible contient `nacre` dans son nom technique, son identifiant, son placeholder ou son libellé
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

- vérifiez que le champ du formulaire contient bien `nacre` dans son nom technique, son identifiant, son placeholder ou son libellé
- confirmez que le plugin est activé dans GLPI
- ouvrez les outils de développement du navigateur pour vérifier le chargement de `public/js/nacre-search.js`

### La modale s’ouvre mais aucun code n’est trouvé

- vérifiez la présence du fichier `public/data/nacre.json` dans le plugin déployé
- relancez `php bin/configure.php` ou `php bin/update_nacre_data.php --source=...`
- contrôlez la validité du JSON source

### Les formulaires sont chargés dynamiquement

Le plugin observe les mutations du DOM. Si un formulaire est injecté après chargement de la page, le bouton NACRE est ajouté automatiquement.

## Données fournies

Le dépôt contient un exemple riche dans `resources/nacre.example.json` et un JSON prêt à servir dans `public/data/nacre.json`.
