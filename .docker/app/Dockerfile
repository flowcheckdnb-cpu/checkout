FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

# ============================================================================
# System packages + PHP PPA
# ============================================================================
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    software-properties-common \
    gnupg2 \
    ca-certificates \
    curl \
    wget \
    unzip \
    zip \
    gzip \
    git \
    patch \
    supervisor \
    nginx \
    openssh-server \
    mariadb-client \
    gettext-base \
    jq \
    rsync \
    htop \
    nano \
    less \
    lsof \
    inetutils-ping \
    iproute2 \
    && add-apt-repository ppa:ondrej/php -y \
    && apt-get update

# ============================================================================
# PHP 8.3 + extensions (Magento 2.4.8 default)
# ============================================================================
RUN apt-get install -y \
    php8.3-fpm \
    php8.3-cli \
    php8.3-bcmath \
    php8.3-curl \
    php8.3-dom \
    php8.3-gd \
    php8.3-intl \
    php8.3-mbstring \
    php8.3-mysql \
    php8.3-redis \
    php8.3-soap \
    php8.3-xml \
    php8.3-xsl \
    php8.3-zip \
    php8.3-opcache \
    php8.3-common \
    php8.3-exif \
    php8.3-imagick \
    php8.3-xdebug \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/php/8.3/cli/conf.d/20-xdebug.ini /etc/php/8.3/fpm/conf.d/20-xdebug.ini

# ============================================================================
# Node.js 22 LTS (Hyvä Tailwind)
# ============================================================================
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ============================================================================
# CLI tools: Composer, magerun2, supercronic
# ============================================================================
ARG COMPOSER_VERSION=2.9.7
ARG MAGERUN_VERSION=9.4.0
ARG SUPERCRONIC_VERSION=0.2.45

RUN curl -sS https://getcomposer.org/installer | php -- \
        --version=${COMPOSER_VERSION} \
        --install-dir=/usr/local/bin \
        --filename=composer \
    && composer --version

RUN curl -sSL -o /usr/local/bin/magerun2 \
        https://github.com/netz98/n98-magerun2/releases/download/${MAGERUN_VERSION}/n98-magerun2.phar \
    && chmod +x /usr/local/bin/magerun2

RUN curl -fsSL -o /usr/local/bin/supercronic \
        https://github.com/aptible/supercronic/releases/download/v${SUPERCRONIC_VERSION}/supercronic-linux-amd64 \
    && chmod +x /usr/local/bin/supercronic

# ============================================================================
# SSH server (rootless, key-based) — port 2222, off-by-default in compose
# ============================================================================
RUN mkdir -p /run/sshd /home/ubuntu/.ssh/host_keys \
    && ssh-keygen -A \
    && cp /etc/ssh/ssh_host_*_key /home/ubuntu/.ssh/host_keys/ \
    && chown -R ubuntu:ubuntu /home/ubuntu/.ssh/host_keys /run/sshd \
    && { \
        echo "Port 2222"; \
        echo "HostKey /home/ubuntu/.ssh/host_keys/ssh_host_rsa_key"; \
        echo "HostKey /home/ubuntu/.ssh/host_keys/ssh_host_ecdsa_key"; \
        echo "HostKey /home/ubuntu/.ssh/host_keys/ssh_host_ed25519_key"; \
        echo "PasswordAuthentication no"; \
        echo "PubkeyAuthentication yes"; \
        echo "AllowAgentForwarding yes"; \
        echo "AcceptEnv TERM"; \
        echo "PidFile /tmp/sshd.pid"; \
    } > /etc/ssh/sshd_config.d/local-dev.conf

# ============================================================================
# Composer global config (as ubuntu user)
# ============================================================================
USER ubuntu
RUN composer global config process-timeout 0 \
    && composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer global config allow-plugins.laminas/laminas-dependency-plugin true \
    && composer global config allow-plugins.magento/composer-dependency-version-audit-plugin true \
    && composer global config allow-plugins.magento/composer-root-update-plugin true \
    && composer global config allow-plugins.magento/inventory-composer-installer true \
    && composer global config allow-plugins.magento/magento-composer-installer true \
    && composer global config allow-plugins.php-http/discovery true
USER root

# ============================================================================
# PHP-FPM: run as ubuntu, listen on unix socket
# ============================================================================
RUN sed -i 's/^user = .*/user = ubuntu/' /etc/php/8.3/fpm/pool.d/www.conf \
    && sed -i 's/^group = .*/group = ubuntu/' /etc/php/8.3/fpm/pool.d/www.conf \
    && sed -i 's/^listen.owner = .*/listen.owner = ubuntu/' /etc/php/8.3/fpm/pool.d/www.conf \
    && sed -i 's/^listen.group = .*/listen.group = ubuntu/' /etc/php/8.3/fpm/pool.d/www.conf \
    && sed -i 's|^listen = .*|listen = /run/php/php-fpm.sock|' /etc/php/8.3/fpm/pool.d/www.conf \
    && sed -i 's|^error_log = .*|error_log = /var/log/supervisor/php-fpm-error.log|' /etc/php/8.3/fpm/php-fpm.conf \
    && sed -i 's|^pid = .*|pid = /tmp/php-fpm.pid|' /etc/php/8.3/fpm/php-fpm.conf

# ============================================================================
# Directories + permissions
# ============================================================================
RUN mkdir -p /run/php /var/log/php-fpm /var/log/supervisor /etc/crontabs /app \
             /docker-entrypoint.d /etc/nginx/conf.d/http /etc/nginx/conf.d/server \
    && chown -R ubuntu:ubuntu /run/php /var/log/php-fpm /var/log/supervisor \
             /etc/php/8.3/fpm /etc/php/8.3/cli/conf.d \
             /etc/php/8.3/mods-available /etc/crontabs /app \
             /etc/supervisor/conf.d /etc/nginx /run/sshd \
    && mkdir -p /home/ubuntu/.ssh \
    && chmod 700 /home/ubuntu/.ssh \
    && chown ubuntu:ubuntu /home/ubuntu/.ssh

# ============================================================================
# Configuration files
# ============================================================================
COPY etc/ /etc/
COPY docker-entrypoint.d/ /docker-entrypoint.d/
COPY entrypoint.sh /entrypoint.sh
COPY crontabs/ /etc/crontabs/

RUN chmod +x /entrypoint.sh /docker-entrypoint.d/*.sh \
    && chown -R ubuntu:ubuntu /entrypoint.sh /docker-entrypoint.d/ \
             /etc/supervisor/conf.d/ /etc/nginx/ /etc/crontabs/

# ============================================================================
# Runtime
# ============================================================================
USER ubuntu
WORKDIR /app

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
