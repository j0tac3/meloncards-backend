#!/usr/bin/env node

/**
 * auto-recortar.js
 * Recorta el espacio en blanco de una imagen usando sharp.
 * Uso: node auto-recortar.js <ruta-absoluta-imagen>
 */

import sharp from 'sharp';
import fs    from 'fs';

const rutaImagen = process.argv[2];

if (!rutaImagen) {
    console.error('❌ Error: Debes proporcionar la ruta de la imagen.');
    console.error('   Uso: node auto-recortar.js /ruta/a/imagen.webp');
    process.exit(1);
}

if (!fs.existsSync(rutaImagen)) {
    console.error(`❌ Error: El archivo no existe: ${rutaImagen}`);
    process.exit(1);
}

// ── Recortar usando un archivo temporal para evitar corrupción ────────────────
// Si escribimos directamente sobre el archivo de origen mientras sharp lo lee,
// podemos corromperlo. La solución es escribir a un .tmp y luego renombrar.
const rutaTmp = rutaImagen + '.tmp';

try {
    await sharp(rutaImagen)
        .trim()          // Elimina bordes en blanco/transparentes
        .toFile(rutaTmp); // Escribe en temporal primero

    // Reemplazar el original de forma atómica
    fs.renameSync(rutaTmp, rutaImagen);

    console.log(`✅ Recortada: ${rutaImagen}`);
    process.exit(0);

} catch (err) {
    // Limpiar el temporal si quedó a medias
    if (fs.existsSync(rutaTmp)) fs.unlinkSync(rutaTmp);

    console.error(`❌ Error recortando ${rutaImagen}: ${err.message}`);
    process.exit(1);
}
