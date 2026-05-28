#!/usr/bin/env bash
# bootstrap.sh — Install Magento + Hyvä on a fresh container.
# Runs INSIDE the app container. Use ./dev.sh bootstrap from the host.
#
# Idempotent: safe to re-run. Steps that are already done are skipped.
set -euo pipefail

cd /app

DOMAIN="${DOMAIN:-onsite-test.docker}"
DB_HOST="${DB_HOST:-mariadb}"
DB_NAME="${DB_NAME:-magento}"
DB_USER="${DB_USER:-magento}"
DB_PASSWORD="${DB_PASSWORD:-magento}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin123}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_FRONTNAME="${ADMIN_FRONTNAME:-admin}"

GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
CYAN=$'\033[0;36m'
NC=$'\033[0m'

step() { echo "${CYAN}==>${NC} $*"; }
warn() { echo "${YELLOW}!${NC}  $*"; }
done_msg() { echo "${GREEN}✓${NC} $*"; }

# --------------------------------------------------------------------------
# 1. Composer install
# --------------------------------------------------------------------------
if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    step "Installing Composer dependencies (this takes a few minutes)..."
    composer install --no-interaction --prefer-dist
    done_msg "Composer dependencies installed."
else
    done_msg "Composer dependencies already installed (skipping)."
fi

# --------------------------------------------------------------------------
# 2. Wait for OpenSearch
# --------------------------------------------------------------------------
step "Waiting for OpenSearch..."
for i in {1..60}; do
    if curl -sf "http://opensearch:9200/_cluster/health" \
         | grep -q '"status":"\(green\|yellow\)"'; then
        done_msg "OpenSearch is ready."
        break
    fi
    [ "$i" = "60" ] && { echo "OpenSearch did not become ready in 5 minutes."; exit 1; }
    sleep 5
done

# --------------------------------------------------------------------------
# 3. Wait for MariaDB
# --------------------------------------------------------------------------
step "Waiting for MariaDB..."
for i in {1..30}; do
    if mariadb -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1; then
        done_msg "MariaDB is ready."
        break
    fi
    [ "$i" = "30" ] && { echo "MariaDB did not become ready in 5 minutes."; exit 1; }
    sleep 10
done

# --------------------------------------------------------------------------
# 4. setup:install (skip if app/etc/env.php already exists)
# --------------------------------------------------------------------------
if [ ! -f app/etc/env.php ]; then
    step "Running bin/magento setup:install..."
    bin/magento setup:install \
        --base-url="https://${DOMAIN}/" \
        --base-url-secure="https://${DOMAIN}/" \
        --use-secure=1 \
        --use-secure-admin=1 \
        --backend-frontname="${ADMIN_FRONTNAME}" \
        --db-host="${DB_HOST}" \
        --db-name="${DB_NAME}" \
        --db-user="${DB_USER}" \
        --db-password="${DB_PASSWORD}" \
        --admin-firstname=Admin \
        --admin-lastname=User \
        --admin-email="${ADMIN_EMAIL}" \
        --admin-user="${ADMIN_USER}" \
        --admin-password="${ADMIN_PASSWORD}" \
        --language=en_US \
        --currency=USD \
        --timezone=UTC \
        --use-rewrites=1 \
        --search-engine=opensearch \
        --opensearch-host=opensearch \
        --opensearch-port=9200 \
        --session-save=redis \
        --session-save-redis-host=valkey-sessions \
        --session-save-redis-port=6379 \
        --session-save-redis-db=0 \
        --session-save-redis-disable-locking=1 \
        --cache-backend=redis \
        --cache-backend-redis-server=valkey-cache \
        --cache-backend-redis-port=6379 \
        --cache-backend-redis-db=0 \
        --page-cache=redis \
        --page-cache-redis-server=valkey-cache \
        --page-cache-redis-port=6379 \
        --page-cache-redis-db=1
    done_msg "Magento installed."

    # 2FA off in dev
    bin/magento module:disable Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth || true

    # Wire Magento to Varnish: tells Magento where to send PURGE requests
    bin/magento setup:config:set --http-cache-hosts=varnish:80 -n

    # Switch full page cache backend from built-in (1) to Varnish (2)
    bin/magento config:set system/full_page_cache/caching_application 2

    # Route all outbound mail to Mailpit (no auth, no TLS — local dev only)
    bin/magento config:set system/smtp/transport smtp
    bin/magento config:set system/smtp/host mailpit
    bin/magento config:set system/smtp/port 1025
    bin/magento config:set system/smtp/ssl none
    bin/magento config:set system/smtp/username ""
    bin/magento config:set system/smtp/password ""
    bin/magento config:set system/smtp/auth none
    bin/magento config:set system/smtp/return_path_email admin@onsite-test.docker
    bin/magento config:set trans_email/ident_general/email admin@onsite-test.docker
    bin/magento config:set trans_email/ident_sales/email admin@onsite-test.docker
    bin/magento config:set trans_email/ident_support/email admin@onsite-test.docker
else
    done_msg "Magento already installed (app/etc/env.php exists, skipping setup:install)."
fi

# --------------------------------------------------------------------------
# 5. Sample data
# --------------------------------------------------------------------------
if ! bin/magento module:status Magento_CatalogSampleData 2>/dev/null | grep -q "Module is enabled"; then
    step "Deploying sample data..."
    bin/magento sampledata:deploy
    bin/magento setup:upgrade
    done_msg "Sample data deployed."
else
    done_msg "Sample data already deployed (skipping)."
fi

# --------------------------------------------------------------------------
# 6. Hyvä themes
# --------------------------------------------------------------------------
if [ ! -d vendor/hyva-themes/magento2-default-theme ]; then
    step "Installing Hyvä theme + dependencies..."
    composer require --no-interaction \
        hyva-themes/magento2-default-theme \
        hyva-themes/magento2-theme-module \
        hyva-themes/magento2-email-module \
        hyva-themes/magento2-graphql-tokens \
        hyva-themes/magento2-luma-checkout
    bin/magento setup:upgrade
    done_msg "Hyvä installed."
else
    done_msg "Hyvä already installed (skipping)."
fi

# --------------------------------------------------------------------------
# 7. Switch frontend theme to Hyva/default
# --------------------------------------------------------------------------
current_theme=$(bin/magento config:show design/theme/theme_id 2>/dev/null || echo "")
if [ "$current_theme" != "Hyva/default" ]; then
    step "Switching frontend theme to Hyva/default..."
    bin/magento config:set design/theme/theme_id Hyva/default

    # Hyvä-required config flags (per https://docs.hyva.io/hyva-themes/getting-started/).
    # Hyvä bypasses Magento's RequireJS/jQuery/UI components stack, so the legacy
    # JS/CSS merge/minify/bundle pipeline must stay off — otherwise it mangles
    # Hyvä's pre-compiled Tailwind output. Default captcha relies on those same
    # legacy modules and breaks the login form when enabled.
    bin/magento config:set customer/captcha/enable 0
    bin/magento config:set dev/template/minify_html 0
    bin/magento config:set dev/js/merge_files 0
    bin/magento config:set dev/js/enable_js_bundling 0
    bin/magento config:set dev/js/minify_files 0
    bin/magento config:set dev/js/move_script_to_bottom 0
    bin/magento config:set dev/css/merge_css_files 0
    bin/magento config:set dev/css/minify_files 0
    done_msg "Theme switched + Hyvä compat flags applied."
fi

# --------------------------------------------------------------------------
# 8. Tailwind build
# --------------------------------------------------------------------------
TW_DIR="vendor/hyva-themes/magento2-default-theme/web/tailwind"
if [ -d "$TW_DIR" ] && [ ! -d "$TW_DIR/node_modules" ]; then
    step "Building Tailwind CSS..."
    npm --prefix "$TW_DIR" ci
    npm --prefix "$TW_DIR" run build
    done_msg "Tailwind built."
elif [ -d "$TW_DIR/node_modules" ]; then
    done_msg "Tailwind dependencies already installed (skipping)."
fi

# --------------------------------------------------------------------------
# 9. Final reindex + cache flush
# --------------------------------------------------------------------------
step "Reindexing & flushing cache..."
bin/magento indexer:reindex
bin/magento cache:flush
done_msg "Reindex + cache flush done."

# --------------------------------------------------------------------------
# Done
# --------------------------------------------------------------------------
echo ""
echo "${GREEN}════════════════════════════════════════════════════════${NC}"
echo "${GREEN}  Magento is ready.${NC}"
echo "${GREEN}════════════════════════════════════════════════════════${NC}"
echo ""
echo "  Storefront:  ${CYAN}https://${DOMAIN}${NC}"
echo "  Admin:       ${CYAN}https://${DOMAIN}/${ADMIN_FRONTNAME}${NC}"
echo "  Mailpit UI:  ${CYAN}https://mail.${DOMAIN}${NC}"
echo ""
echo "  Admin user:     ${ADMIN_USER}"
echo "  Admin password: ${ADMIN_PASSWORD}"
echo ""
