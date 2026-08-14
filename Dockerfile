FROM php:8.2-apache

# Install ekstensi cURL yang dibutuhkan
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Copy semua file ke direktori web Apache
COPY . /var/www/html/

EXPOSE 80
