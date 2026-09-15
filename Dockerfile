FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libzip-dev libxml2-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" curl dom mbstring xml zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .

RUN mkdir -p data/cache data/logs \
    && chown -R www-data:www-data data \
    && find data -type d -exec chmod 775 {} \; \
    && find data -type f -exec chmod 664 {} \;

RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/pentest.conf \
    && a2enconf pentest

EXPOSE 80
CMD ["apache2-foreground"]
