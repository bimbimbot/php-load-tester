FROM php:8.2-apache

# Install cURL extension
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Matikan MPM lain agar tidak bentrok dengan prefork
RUN a2dismod mpm_event || true \
    && a2dismod mpm_worker || true \
    && a2enmod mpm_prefork

# Copy aplikasi ke direktori Apache
COPY . /var/www/html/

EXPOSE 80
