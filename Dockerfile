# 1. Usamos una imagen oficial de PHP con Apache
FROM php:8.2-apache

# 2. Instalamos las dependencias del sistema que necesita Laravel
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# 3. Limpiamos la caché para que el servidor sea más ligero
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 4. Instalamos las extensiones de PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 5. Habilitamos el mod_rewrite de Apache (Crucial para que funcionen las rutas de Laravel)
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

# 9. Instalamos las dependencias de Laravel (optimizado para producción)
RUN composer install --optimize-autoloader --no-dev

# 10. Damos permisos a las carpetas que Laravel necesita para escribir archivos y caché
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 11. Exponemos el puerto 80 para que Render pueda enviar tráfico
EXPOSE 80