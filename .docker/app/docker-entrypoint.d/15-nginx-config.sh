#!/bin/bash
export NGINX_WORKER_PROCESSES=${NGINX_WORKER_PROCESSES:-auto}
export NGINX_WORKER_CONNECTIONS=${NGINX_WORKER_CONNECTIONS:-1024}
export NGINX_KEEPALIVE_TIMEOUT=${NGINX_KEEPALIVE_TIMEOUT:-65}
export NGINX_SENDFILE=${NGINX_SENDFILE:-on}
export NGINX_TCP_NOPUSH=${NGINX_TCP_NOPUSH:-on}
export NGINX_TCP_NODELAY=${NGINX_TCP_NODELAY:-on}
export NGINX_CLIENT_MAX_BODY_SIZE=${NGINX_CLIENT_MAX_BODY_SIZE:-64M}
export NGINX_SERVER_NAME=${NGINX_SERVER_NAME:-_}
export NGINX_SERVER_ROOT=${NGINX_SERVER_ROOT:-/app/pub}
export NGINX_SERVER_INDEX=${NGINX_SERVER_INDEX:-"index.php index.html"}

# Only substitute variables we explicitly own — leaves nginx's own $variables untouched.
NGINX_VARS='${NGINX_WORKER_PROCESSES} ${NGINX_WORKER_CONNECTIONS} ${NGINX_KEEPALIVE_TIMEOUT}
${NGINX_SENDFILE} ${NGINX_TCP_NOPUSH} ${NGINX_TCP_NODELAY} ${NGINX_CLIENT_MAX_BODY_SIZE}
${NGINX_SERVER_NAME} ${NGINX_SERVER_ROOT} ${NGINX_SERVER_INDEX}'

for config_file in /etc/nginx/nginx.conf /etc/nginx/http.conf /etc/nginx/server.conf; do
    if [ -f "$config_file" ]; then
        temp_file=$(mktemp)
        envsubst "$NGINX_VARS" < "$config_file" > "$temp_file"
        mv "$temp_file" "$config_file"
    fi
done
