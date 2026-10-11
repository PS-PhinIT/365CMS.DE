FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    default-mysql-client

RUN docker-php-ext-install \
    pdo \
    pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --prefer-dist

RUN find CMS -type f -name "*.php" -print0 | \
    xargs -0 -n1 php -l

CMD ["/bin/bash"]
