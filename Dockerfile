# Dockerfile
#
# Builds the PHP app (owner/, cashier/, config/, etc.) for Render's Docker
# runtime. Composer dependencies are installed during the build, NOT copied
# from a local vendor/ -- vendor/ is gitignored, so this is the real source
# of truth for it now, unlike the InfinityFree path which needed a manual
# FTP upload of a pre-built vendor/.
#
# forecast_service/ is intentionally NOT part of this image -- it deploys
# as its own separate Render service from render.yaml.

FROM php:8.2-apache

# gd (Dompdf logos, endroid/qr-code), zip (.xlsx import), pdo_mysql (the
# database), mbstring/intl (Dompdf + date formatting) -- see
# reference_xampp_zip_gd_extensions_enabled in project notes for why gd/zip
# specifically are load-bearing, not optional.
RUN apt-get update && apt-get install -y \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pdo_mysql mbstring intl \
    && rm -rf /var/lib/apt/lists/*

# Composer, for the build step only
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency layer first, so a code-only change doesn't reinstall vendor/
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Then the app itself
COPY . .
# The Python source lives in the repo for the SEPARATE Render service that
# deploys it -- this image never needs it.
RUN rm -rf forecast_service

# Apache serves the app from the repo root (index.php lives there), matching
# how it's served locally under XAMPP -- no DocumentRoot change needed.
RUN a2enmod rewrite

# Render only sets $PORT when the CONTAINER STARTS, not during this build --
# baking a port into the config here (RUN, build time) would bake in an
# empty/wrong value. Substituted instead by start.sh, at CMD time, when
# $PORT is actually available.
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 10000
CMD ["/usr/local/bin/start.sh"]
