FROM php:8.3-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip curl libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
	&& docker-php-ext-configure gd --with-jpeg --with-freetype \
	&& docker-php-ext-install -j"$(nproc)" pdo_mysql intl gd \
	&& pecl install pcov \
	&& docker-php-ext-enable pcov \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/cms.ini
COPY apache.conf /etc/apache2/conf-available/zz-cms.conf

# AllowEncodedSlashes (in the vhost): "%2F" in a path reaches the application instead of Apache's own 404 page.
# The container runs as the host user (see docker-compose.yml), so Apache must
# not need root: runtime and log dirs are world-writable.
RUN a2enmod rewrite \
	&& a2enconf zz-cms \
	&& sed -ri 's!/var/www/html!/var/www/html/www!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
	&& sed -ri 's!^(<VirtualHost \*:80>)$!\1\n\tAllowEncodedSlashes NoDecode!' /etc/apache2/sites-available/000-default.conf \
	&& grep -q 'AllowEncodedSlashes NoDecode' /etc/apache2/sites-available/000-default.conf \
	&& mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
	&& chmod -R 777 /var/run/apache2 /var/lock/apache2 /var/log/apache2

ENV COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer/cache HOME=/tmp
