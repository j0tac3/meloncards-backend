# 1. CAMBIO CRÍTICO: Usamos php:8.3 en lugar de 8.2
FROM php:8.3-apache

# Instalar dependencias del sistema indispensables (AHORA INCLUYE LAS GRÁFICAS PARA GD)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl zip gd

# 2. Configurar Apache
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite

# 3. Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Preparar el directorio
WORKDIR /var/www/html

# 5. Copiamos el código
COPY . .

# 6. Ejecutar la instalación (con escudos de memoria y plataforma)
ENV COMPOSER_MEMORY_LIMIT=-1
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

# 7. Permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

# Al final de tu Dockerfile, después del EXPOSE 80
CMD php artisan migrate --force && apache2-foreground