ARG BASE_IMAGE
FROM ${BASE_IMAGE}

# The bind-mounted repo is usually owned by a different uid than the one git expects,
# which makes git refuse to touch it ("dubious ownership") the first time any
# composer/git command walks a path-repo package. Baked in for every user, container-wide.
RUN git config --system --add safe.directory /data/app

# The published simplycodedsoftware/php:8.5.3 image is missing ext-sockets, which
# php-amqplib/php-amqplib (pulled in via enqueue/amqp-lib) hard-requires. It is built
# outside this repo, so we extend it locally instead of patching it here.
# simplycodedsoftware/php:8.2.31 already has it, so INSTALL_SOCKETS stays 0 there.
ARG INSTALL_SOCKETS=0
RUN if [ "$INSTALL_SOCKETS" = "1" ]; then docker-php-ext-install sockets; fi

# The openswoole and swoole services run the applications on a long-lived HTTP server and the coroutine tests.
# Xdebug is removed there: it is loaded in the base image even when XDEBUG_ENABLED=0,
# and neither extension runs coroutines with it loaded.
ARG INSTALL_OPENSWOOLE=0
ARG OPENSWOOLE_VERSION=26.2.0
RUN if [ "$INSTALL_OPENSWOOLE" = "1" ]; then \
        install-php-extensions openswoole-${OPENSWOOLE_VERSION} \
        && rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    fi

# Swoole is compiled from source: the image's install-php-extensions cannot configure this version, and
# --enable-swoole-pgsql and --enable-swoole-sqlite are what let a coroutine wait on a PostgreSQL or SQLite query.
ARG INSTALL_SWOOLE=0
ARG SWOOLE_VERSION=6.2.6
RUN if [ "$INSTALL_SWOOLE" = "1" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends libpq-dev libsqlite3-dev libssl-dev libbrotli-dev libc-ares-dev libcurl4-openssl-dev \
        && curl -fsSL https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz | tar xz -C /tmp \
        && cd /tmp/swoole-${SWOOLE_VERSION} \
        && phpize \
        && ./configure --enable-sockets --enable-openssl --enable-mysqlnd --enable-swoole-curl --enable-cares --enable-brotli --enable-swoole-pgsql --enable-swoole-sqlite \
        && make -j"$(nproc)" \
        && make install \
        && docker-php-ext-enable swoole \
        && rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
        && rm -rf /tmp/swoole-${SWOOLE_VERSION} /var/lib/apt/lists/*; \
    fi
