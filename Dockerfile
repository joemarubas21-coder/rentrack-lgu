FROM php:8.1-apache

# I-install muna ang lahat ng system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Pagkatapos ay i-compile ang PHP extensions (huwag isama ang mbstring, built-in na ito)
RUN docker-php-ext-install pdo_pgsql pgsql zip

# Kopyahin ang Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Kopyahin ang project files
COPY . /var/www/html/
WORKDIR /var/www/html

# Kung may composer.json, i-install ang dependencies; kung wala, laktawan
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader --no-interaction; else echo "No composer.json, skipping"; fi

# Entrypoint
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]