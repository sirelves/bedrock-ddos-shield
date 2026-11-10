#!/usr/bin/env bash
# Quick adoption stats for bedrock-ddos-shield. Needs `gh` logged in.
# Usage: scripts/stats.sh [owner/repo]
set -euo pipefail
R="${1:-sirelves/bedrock-ddos-shield}"

echo "📦 Downloads (.phar):"
gh api "repos/$R/releases" \
  --jq '.[].assets[] | select(.name|endswith(".phar")) | "  \(.name): \(.download_count)"'
echo "📥 Clones (14d):"
gh api "repos/$R/traffic/clones" --jq '"  total \(.count) / únicos \(.uniques)"' \
  || echo "  (sem acesso ao traffic — precisa de push no repo)"
echo "👀 Views (14d):"
gh api "repos/$R/traffic/views" --jq '"  total \(.count) / únicos \(.uniques)"' \
  || echo "  (sem acesso ao traffic)"
echo "⭐ Repo:"
gh api "repos/$R" --jq '"  stars \(.stargazers_count) / forks \(.forks_count) / watchers \(.subscribers_count)"'
