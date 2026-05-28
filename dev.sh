#!/usr/bin/env bash
# dev.sh — Manage the local Magento environment.
#
# Usage:
#   ./dev.sh up           Start the stack (default)
#   ./dev.sh down         Stop the stack
#   ./dev.sh restart      Restart all services
#   ./dev.sh build        Rebuild the app image (no cache)
#   ./dev.sh status       Show URLs + service status
#   ./dev.sh shell        Open a bash shell in the app container
#   ./dev.sh logs [svc]   Tail logs (all services or one)
#   ./dev.sh bootstrap    Run /app/bootstrap.sh inside the app container
#   ./dev.sh nuke         Stop + remove containers, volumes, images (asks first)
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . .env
    set +a
fi
DOMAIN=${DOMAIN:-onsite-test.docker}

SSL_DIR="$SCRIPT_DIR/.docker/traefik/ssl"
MKCERT_BIN="$SCRIPT_DIR/.docker/bin/mkcert"
MKCERT_VERSION="v1.4.4"

GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
CYAN=$'\033[0;36m'
RED=$'\033[0;31m'
NC=$'\033[0m'

bootstrap_certs() {
    # Already have certs? Skip.
    if [ -f "$SSL_DIR/cert.pem" ] && [ -f "$SSL_DIR/key.pem" ]; then
        return 0
    fi

    echo "${CYAN}==>${NC} Bootstrapping local TLS certificates..."
    mkdir -p "$SSL_DIR" "$(dirname "$MKCERT_BIN")"

    if [ ! -x "$MKCERT_BIN" ]; then
        local os arch
        os="$(uname -s | tr '[:upper:]' '[:lower:]')"
        case "$(uname -m)" in
            x86_64|amd64) arch=amd64 ;;
            arm64|aarch64) arch=arm64 ;;
            *) echo "${RED}Unsupported architecture: $(uname -m)${NC}"; exit 1 ;;
        esac
        echo "    Downloading mkcert ${MKCERT_VERSION} (${os}-${arch})..."
        curl -fsSL -o "$MKCERT_BIN" \
            "https://github.com/FiloSottile/mkcert/releases/download/${MKCERT_VERSION}/mkcert-${MKCERT_VERSION}-${os}-${arch}"
        chmod +x "$MKCERT_BIN"
    fi

    echo "    Installing mkcert root CA into system trust store..."
    echo "    (You may be prompted for your password — this is a one-time setup.)"
    if ! "$MKCERT_BIN" -install; then
        echo "${YELLOW}!${NC} mkcert -install failed. Continuing — browsers will warn about the cert."
        echo "  On macOS with Firefox, run: brew install nss && $MKCERT_BIN -install"
    fi

    echo "    Generating cert for ${DOMAIN}, mail.${DOMAIN}..."
    "$MKCERT_BIN" \
        -cert-file "$SSL_DIR/cert.pem" \
        -key-file "$SSL_DIR/key.pem" \
        "$DOMAIN" "mail.$DOMAIN" "localhost" "127.0.0.1"

    echo "${GREEN}✓${NC} Certificates ready: $SSL_DIR/"
}

show_status() {
    echo ""
    echo "${GREEN}=== onsite-test ===${NC}"
    echo ""
    echo "  Storefront:  ${CYAN}https://${DOMAIN}${NC}"
    echo "  Admin:       ${CYAN}https://${DOMAIN}/admin${NC}"
    echo "  Mailpit:     ${CYAN}https://mail.${DOMAIN}${NC}"
    echo "  Traefik UI:  ${CYAN}http://localhost:8090${NC}"
    echo ""
    docker compose ps --format "table {{.Service}}\t{{.Status}}" 2>/dev/null || true
    echo ""

    if ! grep -q "$DOMAIN" /etc/hosts 2>/dev/null; then
        echo "${YELLOW}NOTE:${NC} ${DOMAIN} is not in /etc/hosts."
        echo "      Add this line (sudo required):"
        echo ""
        echo "      127.0.0.1 ${DOMAIN} mail.${DOMAIN}"
        echo ""
    fi
}

case "${1:-up}" in
    up)
        bootstrap_certs
        echo "Starting onsite-test..."
        docker compose up -d --build
        show_status
        ;;

    cert)
        # Force-regenerate certs (e.g. domain changed)
        rm -f "$SSL_DIR/cert.pem" "$SSL_DIR/key.pem"
        bootstrap_certs
        ;;

    down)
        echo "Stopping onsite-test..."
        docker compose down
        echo "Done."
        ;;

    restart)
        docker compose restart
        show_status
        ;;

    build)
        echo "Rebuilding app image (no cache)..."
        docker compose build --no-cache app
        echo "Done. Run './dev.sh up' to start."
        ;;

    status)
        show_status
        ;;

    shell)
        exec docker compose exec -u ubuntu app bash
        ;;

    logs)
        if [ -n "${2:-}" ]; then
            docker compose logs -f "$2"
        else
            docker compose logs -f
        fi
        ;;

    bootstrap)
        echo "Running bootstrap inside app container..."
        docker compose exec -u ubuntu -T app bash /app/bootstrap.sh
        ;;

    stan)
        # PHPStan (logic + types + bugs). Strict ruleset, scope app/code/.
        docker compose exec -u ubuntu -T app composer stan -- "${@:2}"
        ;;

    sniff)
        # PHPCS (style + conventions). Magento2 + PSR-12 + Slevomat.
        # Filter out PHPCS 4.0 forward-deprecation noise from Magento2's
        # Less/GraphQL/CSS sniffs (irrelevant — we don't lint those file types).
        docker compose exec -u ubuntu -T app composer sniff -- "${@:2}" 2>&1 \
            | sed 's/\x1b\[[0-9;]*m//g' \
            | grep -vE '^DEPRECATED: |^The Magento2\.|^Deprecated sniffs|^Support for|^   This sniff|^   Support for|^future\.|^WARNING: The OnsiteTest|^-  Squiz\.|^----+$'
        ;;

    fix)
        # phpcbf — auto-fix what PHPCS can fix on its own.
        docker compose exec -u ubuntu -T app composer fix -- "${@:2}" 2>&1 \
            | sed 's/\x1b\[[0-9;]*m//g' \
            | grep -vE '^DEPRECATED: |^The Magento2\.|^Deprecated sniffs|^Support for|^   This sniff|^   Support for|^future\.|^WARNING: The OnsiteTest|^-  Squiz\.|^----+$'
        ;;

    check)
        # Run both static analyzers — equivalent to what CI would gate on.
        docker compose exec -u ubuntu -T app composer check 2>&1 \
            | sed 's/\x1b\[[0-9;]*m//g' \
            | grep -vE '^DEPRECATED: |^The Magento2\.|^Deprecated sniffs|^Support for|^   This sniff|^   Support for|^future\.|^WARNING: The OnsiteTest|^-  Squiz\.|^----+$'
        ;;

    reset)
        # Reset Magento back to a fresh-installed state without rebuilding
        # vendor/ or re-downloading anything.
        echo "${YELLOW}This will:${NC}"
        echo "  - Drop the Magento database"
        echo "  - Wipe OpenSearch indexes"
        echo "  - Clear var/, generated/, pub/static/"
        echo "  - Remove app/etc/env.php and app/etc/config.php"
        echo "  - Re-run ./dev.sh bootstrap (15-25 min)"
        echo ""
        echo "${GREEN}Kept:${NC} vendor/, composer state, Docker images"
        echo ""
        read -p "Type 'reset' to confirm: " confirm
        if [ "$confirm" != "reset" ]; then
            echo "Aborted."
            exit 0
        fi

        echo ""
        echo "${CYAN}==>${NC} Dropping database..."
        docker compose exec -T mariadb mariadb -uroot -p"${DB_ROOT_PASSWORD:-root}" -e \
            "DROP DATABASE IF EXISTS magento; CREATE DATABASE magento; GRANT ALL ON magento.* TO 'magento'@'%';"

        echo "${CYAN}==>${NC} Wiping OpenSearch indexes..."
        docker compose exec -T app curl -sf -X DELETE 'http://opensearch:9200/magento2*' >/dev/null || true

        echo "${CYAN}==>${NC} Clearing local Magento state..."
        docker compose exec -T -u ubuntu app bash -c '
            rm -rf var/cache/* var/page_cache/* var/view_preprocessed/* var/log/* var/session/* generated/* pub/static/frontend pub/static/adminhtml pub/static/_cache 2>/dev/null
            rm -f app/etc/env.php app/etc/config.php
        '

        echo "${CYAN}==>${NC} Flushing Valkey..."
        docker compose exec -T valkey-cache valkey-cli FLUSHALL >/dev/null || true
        docker compose exec -T valkey-sessions valkey-cli FLUSHALL >/dev/null || true

        echo "${GREEN}✓${NC} Reset complete. Re-running bootstrap..."
        echo ""
        docker compose exec -u ubuntu -T app bash /app/bootstrap.sh
        ;;

    nuke)
        echo "${RED}This will delete all containers, volumes, and the app image for onsite-test.${NC}"
        read -p "Type 'nuke' to confirm: " confirm
        if [ "$confirm" = "nuke" ]; then
            docker compose down -v --rmi local
            echo "Nuked."
        else
            echo "Aborted."
        fi
        ;;

    *)
        echo "Usage: ./dev.sh [up|down|restart|build|status|shell|logs|bootstrap|stan|sniff|fix|check|cert|reset|nuke]"
        exit 1
        ;;
esac
