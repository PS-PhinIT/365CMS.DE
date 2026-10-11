FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    default-mysql-client

RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    intl \
    zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

RUN php -l index.php

CMD ["/bin/bash"]
