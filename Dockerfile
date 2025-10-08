FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/web

RUN sed -ri "s#/var/www/html#${APACHE_DOCUMENT_ROOT}#g" /etc/apache2/sites-available/000-default.conf \
    && sed -ri "s#/var/www/html#${APACHE_DOCUMENT_ROOT}#g" /etc/apache2/sites-available/default-ssl.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY web/ /var/www/html/web/
COPY src/ /var/www/html/src/
COPY storage/ /var/www/html/storage/
COPY index.php /var/www/html/index.php
COPY README.md /var/www/html/README.md
COPY docs/ /var/www/html/docs/

RUN chown -R www-data:www-data /var/www/html/storage

EXPOSE 80

CMD ["apache2-foreground"]
