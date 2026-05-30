#!/bin/bash

# 1. Ejecutar las migraciones siempre de forma segura
echo "Ejecutando migraciones..."
php artisan migrate --force

# 2. Limpiar la caché por si has subido cambios de código
php artisan optimize:clear

# ==========================================
# ⚠️ SOBRE LOS COMANDOS DE IMPORTACIÓN ⚠️
# Descomenta las siguientes líneas SOLO si quieres que se 
# ejecuten CADA VEZ que subas código a GitHub. 
# (Recomendación: Déjalos comentados y lánzalos a mano 
# o con una tarea programada para no ralentizar tus despliegues).
# ==========================================

# echo "Importando One Piece..."
# php artisan tcg:import-one-piece
# php artisan tcg:update-prices

# 3. Finalmente, encender el servidor web de Apache
echo "Iniciando Apache..."
apache2-foreground