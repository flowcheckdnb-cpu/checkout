#!/bin/bash
PHP_VERSION="8.3"

PHP_MEMORY_LIMIT=${PHP_MEMORY_LIMIT:-"2G"}
PHP_MAX_EXECUTION_TIME=${PHP_MAX_EXECUTION_TIME:-"3600"}
PHP_MAX_INPUT_VARS=${PHP_MAX_INPUT_VARS:-"10000"}
PHP_POST_MAX_SIZE=${PHP_POST_MAX_SIZE:-"100M"}
PHP_UPLOAD_MAX_FILESIZE=${PHP_UPLOAD_MAX_FILESIZE:-"100M"}
PHP_REALPATH_CACHE_SIZE=${PHP_REALPATH_CACHE_SIZE:-"10M"}
PHP_REALPATH_CACHE_TTL=${PHP_REALPATH_CACHE_TTL:-"7200"}
PHP_OPCACHE_MEMORY_CONSUMPTION=${PHP_OPCACHE_MEMORY_CONSUMPTION:-"256"}
PHP_OPCACHE_MAX_ACCELERATED_FILES=${PHP_OPCACHE_MAX_ACCELERATED_FILES:-"20000"}
PHP_OPCACHE_VALIDATE_TIMESTAMPS=${PHP_OPCACHE_VALIDATE_TIMESTAMPS:-"1"}
PHP_OPCACHE_REVALIDATE_FREQ=${PHP_OPCACHE_REVALIDATE_FREQ:-"0"}
PHP_DISPLAY_ERRORS=${PHP_DISPLAY_ERRORS:-"On"}
PHP_LOG_ERRORS=${PHP_LOG_ERRORS:-"On"}
PHP_ERROR_REPORTING=${PHP_ERROR_REPORTING:-"E_ALL & ~E_DEPRECATED & ~E_STRICT"}
PHP_EXPOSE_PHP=${PHP_EXPOSE_PHP:-"Off"}
PHP_SESSION_GC_MAXLIFETIME=${PHP_SESSION_GC_MAXLIFETIME:-"86400"}
PHP_DATE_TIMEZONE=${PHP_DATE_TIMEZONE:-"UTC"}

cat > /etc/php/${PHP_VERSION}/conf.d/99-docker-env.ini << EOF
memory_limit = ${PHP_MEMORY_LIMIT}
max_execution_time = ${PHP_MAX_EXECUTION_TIME}
max_input_vars = ${PHP_MAX_INPUT_VARS}
post_max_size = ${PHP_POST_MAX_SIZE}
upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE}
realpath_cache_size = ${PHP_REALPATH_CACHE_SIZE}
realpath_cache_ttl = ${PHP_REALPATH_CACHE_TTL}
date.timezone = ${PHP_DATE_TIMEZONE}

display_errors = ${PHP_DISPLAY_ERRORS}
log_errors = ${PHP_LOG_ERRORS}
error_reporting = ${PHP_ERROR_REPORTING}
expose_php = ${PHP_EXPOSE_PHP}

session.gc_maxlifetime = ${PHP_SESSION_GC_MAXLIFETIME}
session.gc_probability = 1
session.gc_divisor = 1000

opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = ${PHP_OPCACHE_MEMORY_CONSUMPTION}
opcache.max_accelerated_files = ${PHP_OPCACHE_MAX_ACCELERATED_FILES}
opcache.validate_timestamps = ${PHP_OPCACHE_VALIDATE_TIMESTAMPS}
opcache.revalidate_freq = ${PHP_OPCACHE_REVALIDATE_FREQ}
opcache.save_comments = 1
opcache.consistency_checks = 0
opcache.interned_strings_buffer = 16
EOF

cp /etc/php/${PHP_VERSION}/conf.d/99-docker-env.ini /etc/php/${PHP_VERSION}/cli/conf.d/

php_fpm_pool_conf="/etc/php/${PHP_VERSION}/fpm/pool.d/99-docker-pool.conf"
echo "[www]" > "${php_fpm_pool_conf}"
while IFS='=' read -r name value; do
    if [[ $name == FPM_POOL_* ]]; then
        setting_key=$(echo "${name#FPM_POOL_}" | tr '[:upper:]' '[:lower:]' | sed 's/_/./')
        echo "${setting_key} = ${value}" >> "${php_fpm_pool_conf}"
    fi
done < <(env)

if [ -n "$XDEBUG_MODE" ] && [ "$XDEBUG_MODE" != "off" ]; then
    XDEBUG_CLIENT_HOST=${XDEBUG_CLIENT_HOST:-"host.docker.internal"}
    XDEBUG_CLIENT_PORT=${XDEBUG_CLIENT_PORT:-"9003"}
    XDEBUG_START_WITH_REQUEST=${XDEBUG_START_WITH_REQUEST:-"trigger"}

    cat > /etc/php/${PHP_VERSION}/cli/conf.d/20-xdebug.ini << EOF
zend_extension=xdebug.so
xdebug.mode=${XDEBUG_MODE}
xdebug.client_host=${XDEBUG_CLIENT_HOST}
xdebug.client_port=${XDEBUG_CLIENT_PORT}
xdebug.start_with_request=${XDEBUG_START_WITH_REQUEST}
xdebug.log=/var/log/supervisor/xdebug.log
EOF
    cp /etc/php/${PHP_VERSION}/cli/conf.d/20-xdebug.ini /etc/php/${PHP_VERSION}/fpm/conf.d/20-xdebug.ini
fi
