FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN sed -ri "s#/var/www/html#${APACHE_DOCUMENT_ROOT}#g" /etc/apache2/sites-available/000-default.conf \
    && sed -ri "s#/var/www/html#${APACHE_DOCUMENT_ROOT}#g" /etc/apache2/sites-available/default-ssl.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/storage

EXPOSE 80

CMD ["apache2-foreground"]
