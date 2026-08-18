FROM php:8.2-apache

# Инсталиране на системни пакети
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libsqlite3-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql zip intl ctype iconv gd

# Активиране на mod_rewrite за Symfony
RUN a2enmod rewrite

# Настройка на Apache DocumentRoot към public/ и разрешаване на .htaccess (AllowOverride All)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN echo '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>' >> /etc/apache2/apache2.conf

# Инсталиране на Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- ДОБАВЕНИТЕ РЕДОВЕ ЗА RENDER ---

# 1. Копираме целия код от проекта вътре в сървъра
COPY . /var/www/html/

# 2. Инсталираме зависимостите на Symfony (без това няма vendor папка и сайтът е празен)
RUN composer install --no-interaction --optimize-autoloader --no-scripts

# 3. Даваме нужните права на Apache да чете файловете и да пише в кеша
RUN chown -R www-data:www-data /var/www/html/var /var/www/html/public
