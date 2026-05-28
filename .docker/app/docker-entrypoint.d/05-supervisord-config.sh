#!/bin/bash
export ENABLE_PHP_FPM=${ENABLE_PHP_FPM:-true}
export ENABLE_NGINX=${ENABLE_NGINX:-true}
export ENABLE_CRON=${ENABLE_CRON:-false}
export ENABLE_SSHD=${ENABLE_SSHD:-false}

for conf_file in /etc/supervisor/conf.d/*.conf; do
    if [ -f "$conf_file" ]; then
        temp_file=$(mktemp)
        envsubst < "$conf_file" > "$temp_file"
        mv "$temp_file" "$conf_file"
    fi
done
