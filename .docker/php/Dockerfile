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
