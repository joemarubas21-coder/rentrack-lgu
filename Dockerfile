FROM php:8.1-apache

# 1. I-install ang mga system library na kailangan ng PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. I-install at i-enable ang PHP PostgreSQL driver
RUN docker-php-ext-install pdo_pgsql pgsql

# 3. Kopyahin ang lahat ng files ng project
COPY . /var/www/html/

# 4. Kopyahin ang entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

# 5. FIX PARA SA CRLF: Alisin ang Windows carriage returns (\r)
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh

# 6. Gawin itong executable at gamitin bilang entrypoint
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]