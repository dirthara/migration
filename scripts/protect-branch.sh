#!/bin/sh

# Applies this repository's branch protection to a release branch.
#
# Usage: scripts/protect-branch.sh 0.2
#
# Requires the gh CLI, authenticated with admin rights on the repository.
# Protection is per branch, so every new release branch needs this once.

set -e

branch="$1"

if [ -z "$branch" ]; then
    echo "Usage: $0 <branch>" >&2

    exit 1
fi

repo=$(gh repo view --json nameWithOwner --jq .nameWithOwner)

echo "Protecting $branch on $repo ..."

gh api --method PUT "repos/$repo/branches/$branch/protection" --input - <<JSON
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["CI"]
  },
  "required_pull_request_reviews": null,
  "enforce_admins": false,
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_linear_history": false,
  "required_conversation_resolution": true,
  "block_creations": false
}
JSON

echo "Done. Pull requests into $branch now require the CI check to pass."
