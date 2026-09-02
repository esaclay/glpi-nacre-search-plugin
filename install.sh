#!/usr/bin/env bash
set -euo pipefail

PLUGIN_KEY="nacresearch"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_ROOT="${GLPI_PLUGIN_DIR:-/var/www/html/glpi/plugins}"
TARGET_DIR="${TARGET_ROOT}/${PLUGIN_KEY}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP est requis pour installer le plugin." >&2
  exit 1
fi

php "${REPO_ROOT}/bin/configure.php"

mkdir -p "${TARGET_DIR}"
if command -v rsync >/dev/null 2>&1; then
  rsync -a     --exclude '.git'     --exclude '.github'     --exclude 'node_modules'     --exclude 'vendor'     --delete     "${REPO_ROOT}/" "${TARGET_DIR}/"
else
  find "${TARGET_DIR}" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' -exec rm -rf {} +
  cp -a "${REPO_ROOT}/." "${TARGET_DIR}/"
  rm -rf "${TARGET_DIR}/.git" "${TARGET_DIR}/.github" "${TARGET_DIR}/node_modules" "${TARGET_DIR}/vendor"
fi

chmod +x "${TARGET_DIR}/install.sh" "${TARGET_DIR}/bin/configure.php" "${TARGET_DIR}/bin/update_nacre_data.php"

echo "Plugin déployé dans ${TARGET_DIR}"
echo "Activez ensuite le plugin depuis Configuration > Plugins dans GLPI 11."
