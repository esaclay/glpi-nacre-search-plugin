# Rebranding « Commandes LPS » — logos + favicon GLPI 11

Hors périmètre du plugin `nacresearch` : remplace les logos GLPI de l'instance de prod
(`commandes.lps.u-psud.fr`) sans modifier le cœur GLPI. Déployé le 2026-09-10.

## Pourquoi c'est ici et pas dans le plugin

GLPI 11 sert ses logos comme des PNG dans `pics/logos/` via des variables CSS
(`--glpi-logo*`). **Aucune UI d'admin**, et le CSS/JS des plugins **ne se charge pas
sur la page de connexion** → un plugin ne peut pas rebrander le login. La seule voie
propre est le remplacement de fichiers, fait ici via des `Alias` Apache.

## Contenu

- `src/` — sources SVG éditables + scripts de génération
  - `build_glpi_logos.py` : lockup « commandes / .lps » → SVG (texte vectorisé via fontTools)
  - `build_logo.py` : ancien wordmark large (variante B d'origine)
  - `lk-*.svg` : lockups en-tête/login (blanc/bleu, avec/sans panier — **sans panier retenu**)
  - `sq-*.svg`, `favicon-commandes.svg` : icône panier (menu replié + favicon)
- `dist/` — livrables
  - 6 PNG aux dimensions exactes GLPI + `favicon.ico`
  - `apache-rebranding.conf` : bloc à coller dans le `<VirtualHost *:443>`
  - `deploy-branding-files.sh` : recrée les fichiers sur le serveur sans scp
  - `INSTALL.md` : procédure complète

## Régénérer

```bash
cd src
python build_glpi_logos.py          # -> lk-*.svg, sq-*.svg
# puis rasteriser (ImageMagick) :
magick -background none -density 500 lk-white-nocart.svg -resize 100x55 ../dist/logo-GLPI-100-white.png
magick -background none -density 500 lk-dark-nocart.svg  -resize 100x55 ../dist/logo-GLPI-100-black.png
magick -background none -density 500 lk-dark-nocart.svg  -resize 250x138 ../dist/logo-GLPI-250-black.png
magick -background none -density 500 lk-white-nocart.svg -resize 250x138 ../dist/logo-GLPI-250-white.png
magick -background none -density 500 sq-white.svg -resize 53x53 ../dist/logo-G-100-white.png
magick -background none -density 500 sq-dark.svg  -resize 53x53 ../dist/logo-G-100-black.png
magick -background none -density 400 favicon-commandes.svg -define icon:auto-resize=16,32,48 ../dist/favicon.ico
```

## État prod (2026-09-10)

Fichiers dans `/var/www/commandes/commandes-branding/`, 7 `Alias` dans le vhost `:443`,
`mod_headers` actif, `Cache-Control: no-cache`. Revert = commenter le bloc + reload Apache.
Détails : mémoire `glpi-prod-rebranding-logos`.

Couleurs : bleu `#1f3a56`, accent orange `#e8833a`, gris `#5b7285`. Police : Arial (vectorisée).
