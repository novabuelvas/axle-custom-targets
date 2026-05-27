#!/usr/bin/env bash
set -euo pipefail

HOST="${HOST:-159.65.189.43}"
SSH_USER="${SSH_USER:-root}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/do_nova}"
REMOTE_ROOT="${REMOTE_ROOT:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$(dirname "$0")/.."

if [[ ! -f "$SSH_KEY" ]]; then
  echo "Missing SSH key: $SSH_KEY" >&2
  exit 1
fi

if [[ ! -f custom-branded-shooting-targets/index.html ]]; then
  echo "Missing custom-branded-shooting-targets/index.html" >&2
  exit 1
fi

if [[ ! -f api/axle-proof-request.php ]]; then
  echo "Missing api/axle-proof-request.php" >&2
  exit 1
fi

echo "Local source checks..."
grep -q "/api/axle-proof-request.php" custom-branded-shooting-targets/index.html
if grep -q "submit.php" custom-branded-shooting-targets/index.html; then
  echo "Refusing deploy: frontend still references submit.php" >&2
  exit 1
fi

echo "Creating remote folders..."
ssh -i "$SSH_KEY" "$SSH_USER@$HOST" "mkdir -p '$REMOTE_ROOT/custom-branded-shooting-targets' '$REMOTE_ROOT/api'"

echo "Backing up current live files..."
ssh -i "$SSH_KEY" "$SSH_USER@$HOST" "\
  test ! -f '$REMOTE_ROOT/custom-branded-shooting-targets/index.html' || cp '$REMOTE_ROOT/custom-branded-shooting-targets/index.html' '$REMOTE_ROOT/custom-branded-shooting-targets/index.html.bak.$STAMP'; \
  test ! -f '$REMOTE_ROOT/api/axle-proof-request.php' || cp '$REMOTE_ROOT/api/axle-proof-request.php' '$REMOTE_ROOT/api/axle-proof-request.php.bak.$STAMP'"

echo "Uploading stable embedded frontend and backend..."
scp -i "$SSH_KEY" custom-branded-shooting-targets/index.html "$SSH_USER@$HOST:$REMOTE_ROOT/custom-branded-shooting-targets/index.html"
scp -i "$SSH_KEY" api/axle-proof-request.php "$SSH_USER@$HOST:$REMOTE_ROOT/api/axle-proof-request.php"

echo "Checking live frontend source..."
curl -fsSL "https://go.axletargets.com/custom-branded-shooting-targets/" | grep -q "/api/axle-proof-request.php"
if curl -fsSL "https://go.axletargets.com/custom-branded-shooting-targets/" | grep -q "submit.php"; then
  echo "Deploy failed verification: live page still references submit.php" >&2
  exit 1
fi
echo "Frontend source checks passed."

echo "Checking backend availability..."
curl -sS -o /dev/null -w "Backend HTTP %{http_code}\n" "https://go.axletargets.com/api/axle-proof-request.php"

if [[ "${RUN_EMAIL_TEST:-0}" == "1" ]]; then
  echo "Sending test proof email..."
  curl -fsS -X POST "https://go.axletargets.com/api/axle-proof-request.php" \
    -H "Content-Type: application/json" \
    -d '{"serviceType":"full-service","fullService":{"fullName":"Deploy Test","business":"AXLE Deploy Test","email":"info@axletargets.com","quantity":"1,000 Targets - deploy test","notes":"Stable embedded deploy smoke test"},"uploads":[],"sourcePath":"/deploy-test","submittedAt":"manual-deploy-test"}'
  echo
fi

echo "Done. Open https://go.axletargets.com/custom-branded-shooting-targets/ and hard-refresh."
