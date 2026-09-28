FROM php:8.3-fpm-alpine

# --- system packages -------------------------------------------------------
# gd + freetype are only needed by the artwork generator's optional metrics;
# intl and pdo_mysql are hard requirements of the API.
RUN apk add --no-cache \
        bash \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        linux-headers \
        $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        gd \
        opcache \
        pcntl \
        zip \
        bcmath

# Raise the stock 2M upload ceiling so admin image uploads are not rejected by
# PHP before they ever reach the validator.
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# --- runtime libraries ------------------------------------------------------
RUN apk add --no-cache libzip icu-libs libjpeg-turbo libpng freetype

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
# Regenerate the autoloader and the package manifest against the --no-dev
# vendor tree, so dev-only providers are not registered.
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan package:discover --ansi --no-interaction

# Generated artwork is written straight into the document root; the volume
# mount in compose overrides this directory at runtime.
RUN mkdir -p public/images storage/framework/{cache,sessions,views} storage/logs \
    && chown -R www-data:www-data storage public/images

COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=0755 artisan-healthcheck.php /var/www/html/artisan-healthcheck.php

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
