# 1. Usamos una imagen oficial de PHP con Apache
FROM php:8.2-apache

# 2. Instalamos dependencias del sistema (¡Añadimos libpq-dev para Neon y libzip-dev!)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip

# 3. Limpiamos la caché para que el servidor sea más ligero
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 4. Instalamos las extensiones de PHP (¡Añadimos pdo_pgsql y zip!)
RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip

# 5. Habilitamos el mod_rewrite de Apache
RUN a2enmod rewrite

# 6. Apuntamos el servidor web a la carpeta "public" de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 7. Instalamos Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 8. Copiamos todo tu código de Laravel al servidor
WORKDIR /var/www/html
COPY . .

# 9. Damos memoria ilimitada a Composer y ejecutamos la instalación
ENV COMPOSER_MEMORY_LIMIT=-1
RUN composer install --optimize-autoloader --no-dev

# 10. Damos permisos a las carpetas que Laravel necesita
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 11. Exponemos el puerto 80
EXPOSE 80

# 12. Copiamos nuestro script de arranque y le damos permisos
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# 13. Le decimos a Docker que arranque ejecutando nuestro script
CMD ["/usr/local/bin/start.sh"]