#!/usr/bin/env bash
# redis-cpanel-plugin installer
# Usage: bash install.sh
# Must be run as root on a cPanel server.

set -euo pipefail

PLUGIN_DIR="/usr/local/cpanel/base/frontend/jupiter/redis_plugin"
REPO_URL="https://github.com/windsofchange/redis-cpanel-plugin/archive/main.zip"
ARCHIVE="Redis_Plugin_Package.zip"
EXTRACT_DIR="redis-cpanel-plugin-main"

# ---- Sanity checks -------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    echo "ERROR: This script must be run as root." >&2
    exit 1
fi

if [[ ! -f /usr/local/cpanel/scripts/install_plugin ]]; then
    echo "ERROR: cPanel install_plugin not found. Is this a cPanel server?" >&2
    exit 1
fi

# Check for a Redis-compatible binary (valkey-server preferred)
if command -v valkey-server &>/dev/null; then
    echo "INFO: valkey-server found at $(command -v valkey-server)"
elif command -v redis-server &>/dev/null; then
    echo "INFO: redis-server found at $(command -v redis-server)"
else
    echo "ERROR: Neither valkey-server nor redis-server is installed." >&2
    echo "       Install Valkey first:  dnf install valkey" >&2
    exit 1
fi

# ---- Clean previous install ----------------------------------------------
echo "Removing any previous installation..."
rm -rf "${PLUGIN_DIR}"
mkdir -p "${PLUGIN_DIR}"

# ---- Download ------------------------------------------------------------
echo "Downloading Redis cPanel Plugin from GitHub..."
cd "${PLUGIN_DIR}"
if ! wget -q "${REPO_URL}" -O "${ARCHIVE}"; then
    echo "ERROR: Download failed. Check network/DNS and try again." >&2
    exit 1
fi

# ---- Extract -------------------------------------------------------------
echo "Extracting plugin..."
if ! unzip -q "${ARCHIVE}"; then
    echo "ERROR: Extraction failed." >&2
    exit 1
fi

# ---- Deploy --------------------------------------------------------------
echo "Installing plugin files..."
mv "${EXTRACT_DIR}/plugin/"* ./

# ---- Register with cPanel ------------------------------------------------
echo "Registering plugin with cPanel..."
/usr/local/cpanel/scripts/install_plugin "${PLUGIN_DIR}" --theme jupiter

# ---- Permissions ---------------------------------------------------------
echo "Setting permissions..."
find "${PLUGIN_DIR}" -type f -name "*.php" -exec chmod 644 {} \;
find "${PLUGIN_DIR}" -type f -name "*.sh"  -exec chmod 755 {} \;
chmod 644 "${PLUGIN_DIR}"/*.json "${PLUGIN_DIR}"/*.css \
          "${PLUGIN_DIR}"/*.png  "${PLUGIN_DIR}"/*.svg  "${PLUGIN_DIR}"/*.webp 2>/dev/null || true

# ---- Cleanup -------------------------------------------------------------
echo "Cleaning up..."
rm -f "${ARCHIVE}"
rm -rf "${EXTRACT_DIR}"
cd - > /dev/null

echo ""
echo "=========================================================="
echo " Redis / Valkey cPanel Plugin installed successfully."
echo " Users can find it under: cPanel -> Software -> Valkey / Redis"
echo "=========================================================="
