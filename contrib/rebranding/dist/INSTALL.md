# Rebranding « Commandes LPS » — méthode B (Alias Apache)

Remplace les logos GLPI (en-tête, page de connexion, menu replié) + le favicon,
sans toucher au code GLPI. Réversible en 30 s.

## Contenu du bundle

| Fichier | Dim. | Emplacement GLPI ciblé |
|---|---|---|
| `logo-GLPI-100-white.png` | 100×55 | En-tête, barre sombre *(actif en prod)* |
| `logo-GLPI-100-black.png` | 100×55 | En-tête, barre claire |
| `logo-GLPI-250-black.png` | 250×137 | Page de connexion *(actif en prod)* |
| `logo-GLPI-250-white.png` | 250×137 | Page de connexion, palette claire |
| `logo-G-100-white.png` | 53×53 | Menu replié *(actif en prod)* |
| `logo-G-100-black.png` | 53×53 | Menu replié, palette claire |
| `favicon.ico` | 16/32/48 | Onglet navigateur |
| `apache-rebranding.conf` | — | Snippet à coller dans le vhost |

## Déploiement (compte root sur le serveur de prod)

```bash
# 1. Déposer le dossier
mkdir -p /var/www/commandes-branding
#    (copier les 7 fichiers image + éventuellement ce dossier via scp)
#    scp -r commandes-branding/* root@<serveur>:/var/www/commandes-branding/

# 2. Droits
chown -R root:www-data /var/www/commandes-branding
chmod 755 /var/www/commandes-branding
chmod 644 /var/www/commandes-branding/*

# 3. Trouver le vhost HTTPS
apache2ctl -S 2>/dev/null | grep -i commandes

# 4. Éditer ce vhost : coller le contenu de apache-rebranding.conf
#    À L'INTÉRIEUR du bloc <VirtualHost *:443> ... </VirtualHost>

# 5. (si besoin) activer mod_headers pour le no-cache
a2enmod headers

# 6. Tester puis recharger
apache2ctl configtest
systemctl reload apache2

# 7. Vérifier
curl -sI https://commandes.lps.u-psud.fr/pics/logos/logo-GLPI-100-white.png
#    → Content-Length ~1842 (l'ancien logo GLPI fait ~1475). 200 OK.
```

Puis dans le navigateur (Ctrl+F5) : en-tête, `/index.php?noAUTO=1` (page login), menu replié, onglet.

## Revert

Commenter le bloc dans le vhost → `systemctl reload apache2`. Les logos GLPI d'origine reviennent (fichiers jamais modifiés).

## Notes

- Les URLs de logo n'ont pas de cache-buster GLPI : un Ctrl+F5 peut être nécessaire côté postes déjà ouverts. Le `Header set Cache-Control` du snippet limite le problème pour les visites suivantes.
- Une mise à jour GLPI ne casse rien : les Alias continuent de pointer vers `/var/www/commandes-branding/`.
- Si GLPI ajoute un jour d'autres variantes de logo (SVG, tailles), elles afficheront encore le logo GLPI jusqu'à ajout d'un Alias.
