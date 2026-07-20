#!/bin/bash
# Commits changes to git and builds a distributable zip in dist/.
#
# Usage: ./build.sh "Your commit message" [patch|minor|major]
#
# Bump type defaults to "patch" if omitted. See VERSION_MANAGEMENT.md
# section 1 for which one a given change actually calls for — a new,
# backwards-compatible feature is a minor bump, not a patch, even though
# patch is the default here.
#
# Both steps always run:
#  - If there is nothing new to commit, the commit step is skipped gracefully
#    and the zip is still built.
#  - If any build step fails (rsync, zip, mv) the script aborts immediately
#    and the dist/ directory is left unchanged.

set -euo pipefail

if [ -z "${1:-}" ]; then
  echo "Error: commit message required."
  echo "Usage: ./build.sh \"Your commit message\" [patch|minor|major]"
  exit 1
fi

COMMIT_MSG="$1"
BUMP_TYPE="${2:-patch}"
case "$BUMP_TYPE" in
  patch|minor|major) ;;
  *)
    echo "Error: bump type must be patch, minor, or major (got '${BUMP_TYPE}')."
    echo "Usage: ./build.sh \"Your commit message\" [patch|minor|major]"
    exit 1
    ;;
esac

PLUGIN_SLUG="turbo-search-for-woocommerce"
# Resolve the repo root from the script's own location so the script can be
# called from any working directory.
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_DIR="/tmp/${PLUGIN_SLUG}"

# Remove stale temp dir from any previous failed run before we start.
trap 'rm -rf "$TMP_DIR"' EXIT

cd "$REPO_DIR"

# Extract current version, bump per $BUMP_TYPE, and rewrite all version strings.
OLD_VERSION=$(grep -m1 "define( 'WCS_VERSION'" "$REPO_DIR/turbo-search-for-woocommerce.php" \
              | sed "s/.*'\([^']*\)'.*/\1/")
if [ -z "$OLD_VERSION" ]; then
  echo "Error: could not parse WCS_VERSION from turbo-search-for-woocommerce.php."
  exit 1
fi

MAJOR=$(echo "$OLD_VERSION" | cut -d. -f1)
MINOR=$(echo "$OLD_VERSION" | cut -d. -f2)
PATCH=$(echo "$OLD_VERSION" | cut -d. -f3)
case "$BUMP_TYPE" in
  patch) VERSION="${MAJOR}.${MINOR}.$((PATCH + 1))" ;;
  minor) VERSION="${MAJOR}.$((MINOR + 1)).0" ;;
  major) VERSION="$((MAJOR + 1)).0.0" ;;
esac

echo "==> Bumping version (${BUMP_TYPE}): ${OLD_VERSION} → ${VERSION}"

# Update every version occurrence in the plugin files.
sed -i "s/define( 'WCS_VERSION', '${OLD_VERSION}' )/define( 'WCS_VERSION', '${VERSION}' )/" \
    "$REPO_DIR/turbo-search-for-woocommerce.php"
sed -i "s/^\( \* Version:\s*\)${OLD_VERSION}/\1${VERSION}/" \
    "$REPO_DIR/turbo-search-for-woocommerce.php"
sed -i "s/^Stable tag:\s*${OLD_VERSION}/Stable tag:        ${VERSION}/" \
    "$REPO_DIR/readme.txt"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

# ── Step 0: Test gate ──────────────────────────────────────────────────────────
# Run the PHPUnit suite when it is installed (composer install). A failing
# test aborts the build before anything is committed or zipped. When a
# coverage driver (pcov/xdebug) is loaded, the coverage ratchet is enforced
# too (see composer.json "coverage" script for the threshold).
if [ -x "$REPO_DIR/vendor/bin/phpunit" ]; then
  if php -m | grep -qiE '^(pcov|xdebug)$'; then
    echo "==> Step 0: Running test suite with coverage ratchet..."
    ( cd "$REPO_DIR" && composer coverage --no-interaction ) || {
      echo "Error: test suite or coverage ratchet failed — aborting build." >&2
      exit 1
    }
  else
    echo "==> Step 0: Running test suite (no coverage driver — install php-pcov to enforce the ratchet)..."
    ( cd "$REPO_DIR" && ./vendor/bin/phpunit --colors=never ) || {
      echo "Error: test suite failed — aborting build." >&2
      exit 1
    }
  fi
else
  echo "==> Step 0: PHPUnit not installed (run 'composer install') — skipping tests."
fi

# ── Step 1: Commit ─────────────────────────────────────────────────────────────
echo "==> Step 1: Committing changes..."
git add .
if git diff --cached --quiet; then
  echo "    Nothing new to commit — working tree is already clean."
else
  git commit -m "$COMMIT_MSG"
  echo "    Committed."
fi

# ── Step 2: Build zip ─────────────────────────────────────────────────────────
echo "==> Step 2: Building zip..."

mkdir -p "$REPO_DIR/dist/archive"

# Move any existing zips out of dist/ root into archive/ before writing the
# new one. Skips the zip we are about to create (same version rebuild).
for old_zip in "$REPO_DIR"/dist/*.zip; do
  [ -f "$old_zip" ] || continue                          # glob found nothing
  [ "$old_zip" = "$REPO_DIR/dist/${ZIP_NAME}" ] && continue  # same version
  mv "$old_zip" "$REPO_DIR/dist/archive/"
  echo "    Archived $(basename "$old_zip")"
done

rm -f "$REPO_DIR/dist/${ZIP_NAME}"

rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"

# Copy plugin files into the temp staging directory, excluding dev-only content.
rsync -a \
  --exclude='.git'             \
  --exclude='build.sh'         \
  --exclude='*.md'             \
  --exclude='dist'             \
  --exclude='tests'            \
  --exclude='.gitignore'       \
  --exclude='.claude'          \
  --exclude='vendor'           \
  --exclude='composer.json'    \
  --exclude='composer.lock'    \
  --exclude='phpunit.xml.dist' \
  --exclude='.phpunit.cache'   \
  --exclude='.playwright-mcp'  \
  --exclude='/icon-*'          \
  --exclude='/banner-*'        \
  --exclude='/*.png'           \
  "$REPO_DIR/" "$TMP_DIR/"

# Build the zip in /tmp, then move to dist/.
# The subshell keeps the main shell's working directory unchanged.
(cd /tmp && zip -r "$ZIP_NAME" "$PLUGIN_SLUG" > /dev/null)
mv "/tmp/${ZIP_NAME}" "$REPO_DIR/dist/"

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo "Done!  dist/${ZIP_NAME} ready."
echo ""
echo "Next steps (from VERSION_MANAGEMENT.md):"
echo "  git tag v${VERSION}"
echo "  git push origin v${VERSION}"
