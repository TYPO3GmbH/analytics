#!/usr/bin/env bash
#
# Interactive release preparation for t3g/analytics.
#
# What it does, in order:
#   1. Verifies a clean working tree on the main branch.
#   2. Shows every commit since the last tag.
#   3. Proposes the next version (SemVer, derived from commit-message prefixes)
#      and lets you override it.
#   4. Rolls the "Unreleased" changelog section into the new version, then opens
#      the changelog in $EDITOR so you can curate it (git log is shown as a hint).
#   5. Syncs the version into composer.json, ext_emconf.php and guides.xml.
#   6. Runs cgl, phpstan and the test suite — aborts on any failure.
#   7. After a final confirmation: commits, tags (vX.Y.Z) and pushes.
#
# The actual TER upload is NOT done here — pushing the tag triggers
# .github/workflows/publish.yml, which publishes to the TER and creates the
# GitHub release. That keeps the TER token off developer machines.
#
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

CHANGELOG="Documentation/Changelog/Index.rst"
MAIN_BRANCH="main"

bold()  { printf '\033[1m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }
warn()  { printf '\033[33m%s\033[0m\n' "$1"; }
die()   { printf '\033[31mError: %s\033[0m\n' "$1" >&2; exit 1; }

confirm() {
    local prompt="$1" reply
    read -r -p "$prompt [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]]
}

# ---------------------------------------------------------------------------
# 1. Preconditions
# ---------------------------------------------------------------------------
[[ -z "$(git status --porcelain)" ]] || die "Working tree is not clean. Commit or stash first."

current_branch="$(git rev-parse --abbrev-ref HEAD)"
[[ "$current_branch" == "develop" ]] || warn "You are on '$current_branch', not 'develop'."

git fetch --tags --quiet

last_tag="$(git tag --sort=-v:refname | head -1 || true)"
[[ -n "$last_tag" ]] || die "No existing tag found to compare against."
bold "Last release: $last_tag"

# ---------------------------------------------------------------------------
# 1b. Merge develop → main
# ---------------------------------------------------------------------------
info "Merging develop into $MAIN_BRANCH..."
git checkout "$MAIN_BRANCH"
git merge --no-ff develop -m "Merge branch 'develop' into $MAIN_BRANCH"

# ---------------------------------------------------------------------------
# 2. Show commits since the last tag
# ---------------------------------------------------------------------------
commits="$(git log --format='%s' "$last_tag"..HEAD)"
[[ -n "$commits" ]] || die "No commits since $last_tag — nothing to release."

info "Commits since $last_tag:"
git log --oneline "$last_tag"..HEAD
echo

# ---------------------------------------------------------------------------
# 3. Propose the next version (SemVer, honouring 0.x semantics)
# ---------------------------------------------------------------------------
last_num="${last_tag#v}"
IFS=. read -r major minor patch <<< "$last_num"

if grep -qiE '\[!!!\]|breaking' <<< "$commits"; then
    level="breaking"
elif grep -qiE '\[FEATURE\]' <<< "$commits"; then
    level="feature"
else
    level="patch"
fi

if [[ "$major" -eq 0 ]]; then
    case "$level" in
        breaking|feature) minor=$((minor + 1)); patch=0 ;;
        patch)            patch=$((patch + 1)) ;;
    esac
else
    case "$level" in
        breaking) major=$((major + 1)); minor=0; patch=0 ;;
        feature)  minor=$((minor + 1)); patch=0 ;;
        patch)    patch=$((patch + 1)) ;;
    esac
fi
suggested="$major.$minor.$patch"

bold "Detected change level: $level  ->  suggested version: $suggested"
read -r -p "Version to release [$suggested]: " version
version="${version:-$suggested}"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Invalid version '$version' (expected X.Y.Z)."
git rev-parse "v$version" >/dev/null 2>&1 && die "Tag v$version already exists."

# ---------------------------------------------------------------------------
# 4. Changelog: close "Unreleased" into the new version, then let the user edit
# ---------------------------------------------------------------------------
[[ -f "$CHANGELOG" ]] || die "Changelog not found at $CHANGELOG."

header="$version - $(date +%Y-%m-%d)"
underline="$(printf '=%.0s' $(seq 1 ${#header}))"

NH="$header" NU="$underline" perl -0pi -e \
  's/^Unreleased\n=+\n/Unreleased\n==========\n\n*   *(nothing yet)*\n\n$ENV{NH}\n$ENV{NU}\n/m' \
  "$CHANGELOG" || die "Failed to update $CHANGELOG (no 'Unreleased' section?)."

info "Reference — commits since $last_tag:"
git log --oneline "$last_tag"..HEAD | sed 's/^/    /'
echo
warn "Opening $CHANGELOG so you can curate the '$version' section..."
"${EDITOR:-vi}" "$CHANGELOG"

# ---------------------------------------------------------------------------
# 5. Sync version into composer.json, ext_emconf.php, guides.xml
# ---------------------------------------------------------------------------
minor_version="${major}.${minor}"

V="$version" perl -0pi -e 's/("version":\s*")[^"]*(")/${1}$ENV{V}${2}/' composer.json
V="$version" perl -0pi -e "s/('version'\s*=>\s*')[^']*(')/\${1}\$ENV{V}\${2}/" ext_emconf.php
V="$version" perl -0pi -e 's/(release=")[^"]*(")/${1}$ENV{V}${2}/' Documentation/guides.xml
MM="$minor_version" perl -0pi -e 's/(<project\b[^>]*?\bversion=")[^"]*(")/${1}$ENV{MM}${2}/' Documentation/guides.xml

info "Version set to $version in:"
git --no-pager diff --stat composer.json ext_emconf.php Documentation/guides.xml "$CHANGELOG"
echo

# ---------------------------------------------------------------------------
# 6. Quality gates (mirrors CI)
# ---------------------------------------------------------------------------
bold "Running quality gates (cgl, phpstan, tests)..."
ddev composer t3g:cgl
ddev composer t3g:phpstan
ddev composer t3g:test:unit
ddev composer t3g:test:functional
info "All checks passed."
echo

# ---------------------------------------------------------------------------
# 7. Commit, tag, push
# ---------------------------------------------------------------------------
git --no-pager diff --stat
echo
confirm "Commit, tag v$version and push to origin/$MAIN_BRANCH?" || die "Aborted before commit."

git add composer.json ext_emconf.php Documentation/guides.xml "$CHANGELOG"
git commit -m "[RELEASE] $version"
git tag "v$version"
git push origin "$MAIN_BRANCH"
git push origin "v$version"

# ---------------------------------------------------------------------------
# 8. Back-merge main → develop
# ---------------------------------------------------------------------------
info "Merging $MAIN_BRANCH back into develop..."
git checkout develop
git merge --no-ff "$MAIN_BRANCH" -m "Merge branch '$MAIN_BRANCH' into develop"
git push origin develop

bold "Pushed v$version."
info "TER upload and GitHub release run automatically via .github/workflows/publish.yml."
