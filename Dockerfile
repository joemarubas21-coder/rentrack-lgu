FROM php:8.1-apache

# 1. I-install ang mga system library (idinagdag ang git, zip, at libzip-dev)
RUN apt-get update && apt-get install -y libpq-dev unzip git zip libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. I-install at i-enable ang PHP extensions (idinagdag ang zip at mbstring)
RUN docker-php-ext-install pdo_pgsql pgsql zip mbstring

# 3. Kopyahin ang Composer mula sa official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Kopyahin ang lahat ng files ng project
COPY . /var/www/html/
WORKDIR /var/www/html

# 5. I-install ang PHP dependencies
# May "if" statement dito para hindi mag-crash kung walang composer.json
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader --no-interaction; else echo "WALANG composer.json - nilalaktawan ang composer install"; fi

# 6. Kopyahin ang entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

# 7. FIX PARA SA CRLF: Alisin ang Windows carriage returns (\r)
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh

# 8. Gawin itong executable at gamitin bilang entrypoint
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]