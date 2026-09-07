#!/usr/bin/env bash
set -euo pipefail

PLUGIN_KEY="nacresearch"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_ROOT="${GLPI_PLUGIN_DIR:-/var/www/html/glpi/plugins}"
TARGET_DIR="${TARGET_ROOT}/${PLUGIN_KEY}"
WEB_USER="${WEB_USER:-www-data}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP est requis pour installer le plugin." >&2
  exit 1
fi

echo "Source: ${REPO_ROOT}"
echo "Destination: ${TARGET_DIR}"

# Remove existing plugin directory to ensure clean copy
if [ -d "${TARGET_DIR}" ]; then
  echo "Suppression du répertoire existant..."
  rm -rf "${TARGET_DIR}" || { echo "Erreur: impossible de supprimer ${TARGET_DIR}"; exit 1; }
fi

# Create target directory
mkdir -p "${TARGET_DIR}"

# Copy all plugin files - use find + cp for reliability
echo "Copie des fichiers du plugin..."
find "${REPO_ROOT}" -mindepth 1 -maxdepth 1 ! -name '.git' ! -name '.github' ! -name 'node_modules' ! -name 'vendor' -exec cp -a {} "${TARGET_DIR}/" \;

# Verify key files were copied
if [ ! -f "${TARGET_DIR}/setup.php" ]; then
  echo "Erreur: setup.php n'a pas été copié!"
  exit 1
fi

if [ ! -f "${TARGET_DIR}/inc/Profile.php" ]; then
  echo "Erreur: inc/Profile.php n'a pas été copié!"
  exit 1
fi

# Clean up unnecessary files (if they exist)
[ -d "${TARGET_DIR}/.git" ] && rm -rf "${TARGET_DIR}/.git"
[ -d "${TARGET_DIR}/.github" ] && rm -rf "${TARGET_DIR}/.github"
[ -d "${TARGET_DIR}/node_modules" ] && rm -rf "${TARGET_DIR}/node_modules"
[ -d "${TARGET_DIR}/vendor" ] && rm -rf "${TARGET_DIR}/vendor"

# Set permissions
chmod +x "${TARGET_DIR}/install.sh" "${TARGET_DIR}/bin/configure.php" "${TARGET_DIR}/bin/update_nacre_data.php" 2>/dev/null || true
chown -R "${WEB_USER}:${WEB_USER}" "${TARGET_DIR}"
chmod -R 755 "${TARGET_DIR}"

# Verify deployment
echo ""
echo "Vérification du déploiement:"
echo "- setup.php: $([ -f "${TARGET_DIR}/setup.php" ] && echo '✓' || echo '✗')"
echo "- inc/Profile.php: $([ -f "${TARGET_DIR}/inc/Profile.php" ] && echo '✓' || echo '✗')"
echo "- public/js/nacre-search.js: $([ -f "${TARGET_DIR}/public/js/nacre-search.js" ] && echo '✓' || echo '✗')"
echo ""
echo "Plugin déployé dans ${TARGET_DIR}"
echo "Propriétaire: ${WEB_USER}:${WEB_USER}"
echo "Permissions: 755"
echo "Activez ensuite le plugin depuis Configuration > Plugins dans GLPI 11."
