FROM php:8.1-cli-alpine

RUN apk add --no-cache unzip

RUN mkdir /usr/src/prometheus-solaxmodbus-exporter-php
COPY src/* /usr/src/prometheus-solaxmodbus-exporter-php

WORKDIR /usr/src/prometheus-solaxmodbus-exporter-php

# Install composer from the official image
COPY --from=composer /usr/bin/composer /usr/bin/composer
# Run composer install to install the dependencies
RUN composer install --optimize-autoloader --no-interaction --no-progress

CMD php metrics.php