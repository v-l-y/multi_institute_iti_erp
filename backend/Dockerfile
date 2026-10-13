FROM php:8.5-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/erp.ini

WORKDIR /var/www/html
RUN mkdir -p /var/lib/iti-private/documents /var/lib/iti-private/outbox \
    && chown -R www-data:www-data /var/lib/iti-private \
    && chmod 0700 /var/lib/iti-private /var/lib/iti-private/documents /var/lib/iti-private/outbox
COPY . .

EXPOSE 80
