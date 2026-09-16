ARG BASE_IMAGE
FROM ${BASE_IMAGE}

# The published simplycodedsoftware/php:8.5.3 image is missing ext-sockets, which
# php-amqplib/php-amqplib (pulled in via enqueue/amqp-lib) hard-requires. It is built
# outside this repo, so we extend it locally instead of patching it here.
RUN docker-php-ext-install sockets
