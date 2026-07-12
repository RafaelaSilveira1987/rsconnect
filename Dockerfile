FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y libzip-dev libcurl4-openssl-dev libonig-dev unzip git \
    && docker-php-ext-install pdo_mysql curl mbstring zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html

RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    ServerName localhost' \
    '    DocumentRoot /var/www/html/public' \
    '' \
    '    <Directory /var/www/html/public>' \
    '        Options -Indexes +FollowSymLinks' \
    '        AllowOverride All' \
    '        Require all granted' \
    '        FallbackResource /index.php' \
    '    </Directory>' \
    '' \
    '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
    '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf \
    && mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/public/uploads/white-label \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads

EXPOSE 80
