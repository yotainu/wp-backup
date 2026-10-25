#!/usr/bin/env bash
#
# Build and publish a release of WP pCloud Backup.
#
# Usage: bin/release.sh [--dry-run] [--allow-branch] X.Y.Z [notes-file]
#
#   --dry-run       Rewrite version strings and build build/wp-pcloud-backup.zip, but do not
#                   commit, tag, push or create the GitHub release. Revert with:
#                     git checkout -- . && rm -r build
#   --allow-branch  Allow releasing from a branch other than main.
#
# Keep notes-file outside the repository (or gitignored): an untracked file makes the tree "dirty".
# Without notes-file the release notes are generated from the commit subjects since the previous tag.
#
# Publish order: commit, tag, push the tag, create the GitHub release, push the branch (update.json
# goes live last, so no site is pointed at a release asset that does not exist yet).
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN="wp-pcloud-backup"
DRY_RUN=0
ALLOW_BRANCH=0
POSITIONAL=()

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --allow-branch) ALLOW_BRANCH=1 ;;
    -h|--help)
      sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    -*)
      echo "Unknown option: $arg" >&2
      exit 1
      ;;
    *) POSITIONAL+=("$arg") ;;
  esac
done

if [ "${#POSITIONAL[@]}" -lt 1 ] || [ "${#POSITIONAL[@]}" -gt 2 ]; then
  echo "Usage: bin/release.sh [--dry-run] [--allow-branch] X.Y.Z [notes-file]" >&2
  exit 1
fi

VERSION="${POSITIONAL[0]}"
NOTES_FILE="${POSITIONAL[1]:-}"
TAG="v${VERSION}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  fail "Version must look like X.Y.Z (got '$VERSION')."
fi

if [ -n "$NOTES_FILE" ] && [ ! -f "$NOTES_FILE" ]; then
  fail "Notes file not found: $NOTES_FILE"
fi

# --- Preconditions (nothing has been changed yet) ---------------------------------------------

if [ -n "$(git status --porcelain)" ]; then
  fail "Working tree is not clean. Commit or stash your changes first."
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" != "main" ] && [ "$ALLOW_BRANCH" -eq 0 ]; then
  fail "Releases are made from main (current branch: ${BRANCH}). Use --allow-branch to override."
fi

if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
  fail "Tag ${TAG} already exists."
fi

if ! gh auth status >/dev/null 2>&1; then
  fail "gh is not authenticated. Run 'gh auth login' first."
fi

OWNER="OWNER"
REMOTE_URL="$(git remote get-url origin 2>/dev/null || true)"
if [ -z "$REMOTE_URL" ]; then
  [ "$DRY_RUN" -eq 1 ] || fail "No 'origin' remote. Add the GitHub repository first."
  echo "WARNING: no 'origin' remote; the dry run keeps the OWNER placeholder." >&2
else
  # git@github.com:owner/repo(.git) or https://github.com/owner/repo(.git)
  PARSED="$(printf '%s' "$REMOTE_URL" | sed -E 's#^.*github\.com[:/]+([^/]+)/([^/]+)$#\1/\2#; s#\.git$##')"
  OWNER="${PARSED%%/*}"
  REPO="${PARSED#*/}"
  if [ "$PARSED" = "$REMOTE_URL" ] || [ -z "$OWNER" ] || [ "$REPO" != "$PLUGIN" ]; then
    fail "origin ($REMOTE_URL) must be a GitHub URL of the repository '${PLUGIN}'."
  fi
  if [[ "$OWNER" == *OWNER* ]]; then
    fail "origin still contains the OWNER placeholder."
  fi
  if [ -n "$(git ls-remote --tags origin "refs/tags/${TAG}")" ]; then
    fail "Tag ${TAG} already exists on origin."
  fi
fi

if [ "$DRY_RUN" -eq 0 ] && grep -q "/OWNER/" "${PLUGIN}.php"; then
  fail "${PLUGIN}.php still contains the OWNER placeholder (WP_PCLOUD_BACKUP_UPDATE_URL). Replace it and commit before releasing."
fi

# --- Release notes -----------------------------------------------------------------------------

mkdir -p build
if [ -n "$NOTES_FILE" ]; then
  NOTES="$NOTES_FILE"
else
  NOTES="build/release-notes.md"
  PREVIOUS_TAG="$(git describe --tags --abbrev=0 2>/dev/null || true)"
  if [ -n "$PREVIOUS_TAG" ]; then
    RANGE="${PREVIOUS_TAG}..HEAD"
  else
    RANGE="HEAD"
  fi
  git log --no-merges --pretty=format:'- %s' "$RANGE" > "$NOTES"
  printf '\n' >> "$NOTES"
fi

# --- Rewrite version strings (before the zip is built, so the zip carries them) --------------

export VERSION OWNER TAG NOTES PLUGIN

perl -pi -e 's/^(\s*\*\s*Version:\s*)\S+/${1}$ENV{VERSION}/' "${PLUGIN}.php"
perl -pi -e "s/(define\\(\\s*'WP_PCLOUD_BACKUP_VERSION',\\s*')[^']*(')/\${1}\$ENV{VERSION}\${2}/" "${PLUGIN}.php"
perl -pi -e 's/^(Stable tag:\s*).*/${1}$ENV{VERSION}/' readme.txt

BASE="https://github.com/${OWNER}/${PLUGIN}"
export BASE
perl -MJSON::PP -0pi -e '
  my $base = "$ENV{BASE}/releases/download/$ENV{TAG}/$ENV{PLUGIN}.zip";
  s/("version"\s*:\s*)"[^"]*"/$1"$ENV{VERSION}"/;
  s/("download_url"\s*:\s*)"[^"]*"/$1"$base"/;
  s/("sha256_url"\s*:\s*)"[^"]*"/$1"$base.sha256"/;
  s/("homepage"\s*:\s*)"[^"]*"/$1"$ENV{BASE}"/;
  open(my $fh, "<", $ENV{NOTES}) or die "cannot read notes: $!";
  local $/; my $notes = <$fh>; close $fh;
  $notes =~ s/\s+$//;
  my $json = JSON::PP->new->allow_nonref->ascii->encode($notes);
  s/("changelog"\s*:\s*)"(?:[^"\\]|\\.)*"/$1$json/;
' update.json

# Sanity: every rewritten field must have taken effect.
grep -q "Version:[[:space:]]*${VERSION}\$" "${PLUGIN}.php" || fail "Version header was not updated."
grep -q "define( 'WP_PCLOUD_BACKUP_VERSION', '${VERSION}' )" "${PLUGIN}.php" || fail "WP_PCLOUD_BACKUP_VERSION was not updated."
grep -q "^Stable tag: ${VERSION}\$" readme.txt || fail "Stable tag was not updated."
grep -q "\"version\": \"${VERSION}\"" update.json || fail "update.json version was not updated."
grep -q "/releases/download/${TAG}/${PLUGIN}.zip\"" update.json || fail "update.json download_url was not updated."
grep -q "/releases/download/${TAG}/${PLUGIN}.zip.sha256\"" update.json || fail "update.json sha256_url was not updated."

# --- Build -------------------------------------------------------------------------------------

rm -rf "build/${PLUGIN}" "build/${PLUGIN}.zip" "build/${PLUGIN}.zip.sha256"
rsync -a --delete --exclude-from=.distignore ./ "build/${PLUGIN}/"
(cd build && zip -qr "${PLUGIN}.zip" "${PLUGIN}")
shasum -a 256 "build/${PLUGIN}.zip" | awk '{print $1}' > "build/${PLUGIN}.zip.sha256"

# The zip must have a single top-level folder named after the plugin.
# Capture the listing first: piping unzip into grep -q can end in SIGPIPE, which pipefail turns into a failure.
ENTRIES="$(unzip -Z1 "build/${PLUGIN}.zip")"
OUTSIDE="$(grep -v "^${PLUGIN}/" <<< "$ENTRIES" || true)"
if [ -n "$OUTSIDE" ]; then
  fail "The zip contains entries outside ${PLUGIN}/."
fi
if ! grep -qx "${PLUGIN}/${PLUGIN}.php" <<< "$ENTRIES"; then
  fail "The zip does not contain ${PLUGIN}/${PLUGIN}.php."
fi

echo "Built build/${PLUGIN}.zip ($(wc -c < "build/${PLUGIN}.zip" | tr -d ' ') bytes)"
echo "sha256: $(cat "build/${PLUGIN}.zip.sha256")"

if [ "$DRY_RUN" -eq 1 ]; then
  echo
  echo "Dry run: nothing was committed, tagged, pushed or released."
  echo "Revert the rewritten files with: git checkout -- . && rm -r build"
  exit 0
fi

# --- Publish -----------------------------------------------------------------------------------

git commit -am "chore: release ${TAG}"

# From here on a failure leaves a local commit (and maybe a tag or a pushed tag): print how to recover.
STEP="tag"
on_error() {
  local code=$?
  trap - ERR
  echo >&2
  echo "ERROR: the release stopped at step '${STEP}' (exit ${code}). Recovery:" >&2
  case "$STEP" in
    tag|push-tag)
      echo "  Nothing was published. To undo and start over:" >&2
      echo "    git tag -d ${TAG}; git reset --soft HEAD~1" >&2
      echo "  (if the tag was already pushed: git push origin :refs/tags/${TAG})" >&2
      ;;
    release)
      echo "  The tag ${TAG} is on origin, but the GitHub release was not created. Retry:" >&2
      echo "    gh release create ${TAG} build/${PLUGIN}.zip build/${PLUGIN}.zip.sha256 --title ${TAG} --notes-file ${NOTES}" >&2
      echo "  then push the branch:  git push origin HEAD" >&2
      echo "  To abandon instead:" >&2
      echo "    git push origin :refs/tags/${TAG}; git tag -d ${TAG}; git reset --soft HEAD~1" >&2
      ;;
    push-branch)
      echo "  The GitHub release exists, but the branch was not pushed (update.json is not live yet). Retry:" >&2
      echo "    git push origin HEAD" >&2
      ;;
  esac
  exit "$code"
}
trap on_error ERR

git tag -a "${TAG}" -m "${TAG}"

STEP="push-tag"
git push origin "refs/tags/${TAG}"

STEP="release"
gh release create "${TAG}" \
  "build/${PLUGIN}.zip" "build/${PLUGIN}.zip.sha256" \
  --title "${TAG}" \
  --notes-file "${NOTES}"

# update.json (on the branch) is what the sites poll, so it goes live only once the release exists.
STEP="push-branch"
git push origin HEAD

trap - ERR
echo "Released ${TAG}."
