FROM php:apache

RUN a2enmod rewrite
COPY apache_conf/v1_rewrite.conf /etc/apache2/conf-enabled/
COPY src/ /var/www/html/
