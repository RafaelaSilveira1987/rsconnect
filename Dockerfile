FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git unzip zip libzip-dev libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install pdo_mysql curl mbstring zip opcache \
    && printf 'upload_max_filesize=25M\npost_max_size=26M\nmax_file_uploads=5\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/rs-connect.ini \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
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
    && mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/conversation-attachments /var/www/html/storage/generated-reports /var/www/html/storage/app/white-label \
    && chown -R www-data:www-data /var/www/html/storage \
    && php -r "require '/var/www/html/app/Core/Autoloader.php'; App\\Core\\Autoloader::register('/var/www/html/app'); if (!class_exists('App\\Core\\Router')) { fwrite(STDERR, 'Router autoload validation failed.\n'); exit(1); }" \
    && php /var/www/html/bin/migrate.php verify

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health/live >/dev/null || exit 1
