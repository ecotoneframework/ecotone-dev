FROM php:8.1-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    openssh-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

# Install required PHP extensions
RUN install-php-extensions \
    zip \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    amqp \
    grpc \
    rdkafka \
    redis \
    opcache

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /data/app

# Configure PHP
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/99-memory-limit.ini

# Command to keep container running
CMD ["sleep", "99999"]
