FROM php:8.2-apache

# Инсталиране на нужните пакети за Symfony и MySQL
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip

# Активиране на Apache mod_rewrite за Symfony
RUN a2enmod rewrite

# Настройка на DocumentRoot да сочи към public папката на Symfony
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Инсталиране на Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer