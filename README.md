# Onsite Test — Magento 2

A pre-built Magento 2.4.8-p4 environment with Hyvä theme.

## What's inside

- **Magento 2.4.8-p4** Open Source + sample data
- **Hyvä Default theme** (Magento's default Luma checkout — Hyvä Checkout is a paid add-on, not included)
- **Stack**: Nginx + PHP 8.3 (rootless), MariaDB 11.4, Valkey 8 (cache + sessions), OpenSearch 2.19, Varnish 7.7 (FPC), Mailpit, Traefik (HTTPS via mkcert, auto-redirects HTTP→HTTPS)
- **One-command bootstrap** — `./dev.sh up && ./dev.sh bootstrap`

## Quick start

### 1. Add a hosts entry (one time per machine)

```bash
grep -q onsite-test.docker /etc/hosts || \
    sudo sh -c 'echo "127.0.0.1 onsite-test.docker mail.onsite-test.docker" >> /etc/hosts'
```

Traefik publishes `:80` and `:443` on the host, so pointing the domain at `127.0.0.1` is all that's needed. Safe to re-run — it skips if the entry already exists.

### 2. Start the stack

```bash
./dev.sh up
```

First run pulls images and builds the app container — about 5 minutes. It also downloads `mkcert` and asks once for your password to install a local root CA in your system trust store (so browsers don't warn about the self-signed cert).

### 3. Bootstrap Magento (only needed once per fresh checkout)

```bash
./dev.sh bootstrap
```

This runs `composer install`, `setup:install`, deploys sample data, installs Hyvä, builds Tailwind, reindexes. **15–25 minutes** the first time.

### 4. Open the site

- **Storefront**: <https://onsite-test.docker>
- **Admin**: <https://onsite-test.docker/admin>
- **Mailpit**: <https://mail.onsite-test.docker>
- **Traefik dashboard**: <http://localhost:8090>

HTTP traffic auto-redirects to HTTPS via Traefik.

## Daily commands

```bash
./dev.sh up           # Start (idempotent)
./dev.sh down         # Stop
./dev.sh shell        # Bash inside the app container (as ubuntu user)
./dev.sh logs app     # Tail logs for one service
./dev.sh status       # Show URLs + service health
./dev.sh cert         # Regenerate TLS certs (e.g. after changing DOMAIN)
./dev.sh reset        # Wipe DB + indexes + caches, re-run bootstrap (keeps vendor/)
./dev.sh nuke         # Wipe everything including images and volumes (asks first)
./dev.sh stan         # PHPStan static analysis (level 9 + strict + deprecation rules)
./dev.sh sniff        # PHPCS code style check (Magento2 + PSR-12 + Slevomat)
./dev.sh fix          # phpcbf auto-fix style violations
./dev.sh check        # PHPStan + PHPCS together (what CI will eventually gate on)
```

Inside the container:

```bash
bin/magento cache:flush
bin/magento setup:upgrade
bin/magento indexer:reindex
composer require some/package
```

## Claude Code skills

This repo ships with [Hyvä's official AI skills](https://github.com/hyva-themes/hyva-ai-tools) in `.claude/skills/`. If you're using Claude Code, you can ask things like:

- "Create a Hyvä child theme"
- "Scaffold a new Magento module"
- "Add an Alpine component"
- "Compile Tailwind CSS"
- "Add a CMS custom field"

Claude will pick up the relevant skill automatically. To refresh from upstream:

```bash
curl -fsSL https://raw.githubusercontent.com/hyva-themes/hyva-ai-tools/refs/heads/main/install.sh | sh -s claude
```

## Tailwind / Hyvä

The default theme's Tailwind sources live in `vendor/hyva-themes/magento2-default-theme/web/tailwind`. Bootstrap builds them once. To watch during development:

```bash
./dev.sh shell
npm --prefix vendor/hyva-themes/magento2-default-theme/web/tailwind run watch
```

When you create your own theme under `app/design/frontend/`, point Tailwind at that path instead.

## auth.json

`auth.json` is **committed to this repo** for onboarding convenience — it ships with Magebit's shared test Magento Marketplace key and a Hyvä Private Packagist token. The repo is private; the keys are scoped to this onboarding context only. Treat them accordingly: don't push the repo to public mirrors, don't paste them into Slack screenshots, don't reuse them for client work.

## Cron

Cron is **disabled by default** (`ENABLE_CRON=false` in `compose.yaml`). Enable when you need queue consumers or scheduled jobs:

```yaml
# compose.yaml
ENABLE_CRON: "true"
```

Then `./dev.sh restart`.

## SSH into the container (optional, for IDE remote dev)

The container has an SSH server that's off by default. To turn it on:

1. Set `ENABLE_SSHD: "true"` in `compose.yaml`
2. Add a port mapping:
   ```yaml
   ports:
     - "2222:2222"
   ```
3. Mount your public key:
   ```yaml
   volumes:
     - ~/.ssh/id_ed25519.pub:/home/ubuntu/.ssh/authorized_keys:ro
   ```
4. `./dev.sh restart`, then `ssh -p 2222 ubuntu@localhost`

For most onsite tasks `./dev.sh shell` is enough.

## Troubleshooting

**"Could not find a matching version of magento/...":** auth.json has placeholder keys. Replace them with real Marketplace keys.

**"Connection refused" on the storefront URL:** `/etc/hosts` entry missing, or the stack isn't running. Check with `./dev.sh status`.

**"Permission denied" on `var/`/`generated/`:** the host user's UID isn't 1000 (Linux only — macOS handles this transparently). Run `sudo chown -R 1000:1000 var generated pub/static`.

**OpenSearch keeps OOM-killing on Linux:** `sudo sysctl -w vm.max_map_count=262144`.

## Verifying Varnish is working

The full-page cache is Varnish (caching_application=2). Look at response headers — Varnish injects `X-Magento-Cache-Debug: HIT` (or `MISS`) on cacheable pages.

```bash
curl -sI http://onsite-test.docker/ | grep -i cache-debug
# X-Magento-Cache-Debug: HIT
```

Customer/checkout pages bypass Varnish entirely (no `X-Magento-Cache-Debug` header). To purge from inside the app container:

```bash
./dev.sh shell
bin/magento cache:clean full_page    # Magento sends PURGE to varnish:80
```

Watch live cache activity:

```bash
docker compose exec varnish varnishlog -g request
```
