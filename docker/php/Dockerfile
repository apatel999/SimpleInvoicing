FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

COPY apache.conf /etc/apache2/conf-available/simpleinvoicing.conf
COPY php.ini /usr/local/etc/php/conf.d/simpleinvoicing.ini
RUN a2enconf simpleinvoicing

WORKDIR /var/www/html
