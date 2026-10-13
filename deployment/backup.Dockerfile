FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client gnupg \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY backend/bootstrap bootstrap
COPY backend/config config
COPY backend/app app
COPY backend/bin/paired-backup.php bin/paired-backup.php
COPY backend/bin/verify-restored-documents.php bin/verify-restored-documents.php
ENV GNUPGHOME=/tmp/gnupg
ENTRYPOINT ["php", "bin/paired-backup.php"]
