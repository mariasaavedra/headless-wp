#!/usr/bin/env bash
#
# Syncs the code between this repo (a snapshot) and the live WordPress
# install in Local by Flywheel.
#
#   bin/sync.sh pull   # live site -> repo   (before committing)
#   bin/sync.sh push   # repo      -> live site (after cloning/editing)
#
# Paths are configurable via the environment variables:
#   HCLE_PLUGIN_LIVE, HCLE_THEME_LIVE
#
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PLUGIN_LIVE="${HCLE_PLUGIN_LIVE:-$HOME/Local Sites/tps/app/public/wp-content/plugins/habeas-cle}"
THEME_LIVE="${HCLE_THEME_LIVE:-$HOME/Local Sites/tps/app/public/wp-content/themes/habeas-cle-theme}"

DIRECTION="${1:-}"

case "$DIRECTION" in
  pull)
    echo "Syncing: live site -> repo"
    rsync -a --delete --exclude '.git' "$PLUGIN_LIVE/" "$REPO_DIR/plugin/"
    rsync -a --delete --exclude '.git' "$THEME_LIVE/"  "$REPO_DIR/theme/"
    ;;
  push)
    echo "Syncing: repo -> live site"
    rsync -a --delete "$REPO_DIR/plugin/" "$PLUGIN_LIVE/"
    rsync -a --delete "$REPO_DIR/theme/"  "$THEME_LIVE/"
    ;;
  *)
    echo "Usage: bin/sync.sh [pull|push]"
    echo "  pull  live site -> repo"
    echo "  push  repo -> live site"
    exit 1
    ;;
esac

echo "Done."
