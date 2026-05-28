#!/usr/bin/env bash
#
# PostToolUse hook: lint PHP files written/edited by Claude.
#
# Reads the tool-call JSON from stdin and only runs when the touched file is
# .php or .phtml under app/code/ or app/design/frontend/. Anything else
# (markdown, configs, JSON, .docker/, vendor, etc.) is silently skipped.
#
# When checks fail, the hook returns non-zero and prints the violations to
# stderr — Claude Code surfaces them back into the conversation so they can
# be fixed before the next step.
set -euo pipefail

# stdin = JSON envelope from Claude Code
PAYLOAD="$(cat)"
FILE_PATH="$(echo "$PAYLOAD" | jq -r '.tool_input.file_path // empty')"

# No file path → nothing to lint
[ -z "$FILE_PATH" ] && exit 0

# We only care about PHP/.phtml files in our own codebase
case "$FILE_PATH" in
    */app/code/*.php|*/app/code/*.phtml) ;;
    */app/design/frontend/*.php|*/app/design/frontend/*.phtml) ;;
    *) exit 0 ;;
esac

# Skip if container isn't running — don't block edits when stack is down
if ! docker compose ps --status running --services 2>/dev/null | grep -q '^app$'; then
    exit 0
fi

# Project root (script lives in .claude/hooks/, so go up two levels)
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Path inside the container (absolute path on host → /app-relative)
REL_PATH="${FILE_PATH#$PROJECT_ROOT/}"
CONTAINER_PATH="/app/$REL_PATH"

cd "$PROJECT_ROOT"

FAILED=0
OUTPUT=""

# PHPCS — fast (~1s), runs first
# Strip Magento2 ruleset's deprecation warnings — they're irrelevant noise
# from sniffs that announce themselves for CSS/Less/GraphQL files we don't have.
# Pattern set mirrors `./dev.sh sniff` so hook + manual runs stay consistent.
if ! PHPCS_OUT="$(docker compose exec -T -u ubuntu app vendor/bin/phpcs --no-cache "$CONTAINER_PATH" 2>&1 \
        | sed 's/\x1b\[[0-9;]*m//g' \
        | grep -vE '^DEPRECATED: |^The Magento2\.|^Deprecated sniffs|^Support for|^   This sniff|^   Support for|^future\.|^WARNING: The OnsiteTest|^-  Squiz\.|^----+$')"; then
    OUTPUT+="${PHPCS_OUT}"$'\n'
    FAILED=1
fi

# PHPStan — slower (~5-10s), runs second
if ! PHPSTAN_OUT="$(docker compose exec -T -u ubuntu app vendor/bin/phpstan analyse --no-progress --memory-limit=2G "$CONTAINER_PATH" 2>&1)"; then
    OUTPUT+="${PHPSTAN_OUT}"$'\n'
    FAILED=1
fi

if [ "$FAILED" -ne 0 ]; then
    echo "Static analysis failed for $REL_PATH:" >&2
    echo "$OUTPUT" >&2
    exit 2  # exit 2 = block + feed stderr back to Claude
fi

exit 0
