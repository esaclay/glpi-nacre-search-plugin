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

# Remove existing plugin directory to ensure clean copy
if [ -d "${TARGET_DIR}" ]; then
  rm -rf "${TARGET_DIR}"
fi

mkdir -p "${TARGET_DIR}"

# Copy plugin files
cp -a "${REPO_ROOT}/." "${TARGET_DIR}/"

# Clean up unnecessary files
rm -rf "${TARGET_DIR}/.git" "${TARGET_DIR}/.github" "${TARGET_DIR}/node_modules" "${TARGET_DIR}/vendor"

# Set permissions
chmod +x "${TARGET_DIR}/install.sh" "${TARGET_DIR}/bin/configure.php" "${TARGET_DIR}/bin/update_nacre_data.php"
chown -R "${WEB_USER}:${WEB_USER}" "${TARGET_DIR}"
chmod -R 755 "${TARGET_DIR}"

echo "Plugin déployé dans ${TARGET_DIR}"
echo "Permissions définies pour ${WEB_USER}:${WEB_USER}"
echo "Activez ensuite le plugin depuis Configuration > Plugins dans GLPI 11."
