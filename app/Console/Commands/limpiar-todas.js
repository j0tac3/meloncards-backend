#!/usr/bin/env node

/**
 * limpiar-todas.js
 * Recorta el espacio en blanco de todas las imágenes de una carpeta.
 *
 * Uso:  node limpiar-todas.js [ruta-carpeta]
 * Por defecto apunta a: ./storage/app/public/sets
 *
 * CORRECCIÓN CRÍTICA respecto al original:
 * El forEach con async/await no esperaba las promesas → condición de carrera
 * que podía corromper archivos. Ahora usa Promise.allSettled con concurrencia
 * controlada (5 archivos en paralelo máximo).
 */

import sharp from 'sharp';
import fs    from 'fs';
import path  from 'path';

// ── Configuración ─────────────────────────────────────────────────────────────
const dir         = process.argv[2] ?? './storage/app/public/sets';
const CONCURRENCY = 5; // Procesar N imágenes en paralelo como máximo

// ── Validar carpeta ───────────────────────────────────────────────────────────
if (!fs.existsSync(dir)) {
    console.error(`❌ La carpeta no existe: ${dir}`);
    process.exit(1);
}

const archivos = fs.readdirSync(dir)
    .filter(f => /\.(png|jpg|jpeg|webp)$/i.test(f));

if (archivos.length === 0) {
    console.log(`⚠️  No se encontraron imágenes en: ${dir}`);
    process.exit(0);
}

console.log(`🚀 Iniciando recorte de ${archivos.length} imágenes (concurrencia: ${CONCURRENCY})...`);

// ── Función de recorte individual (con archivo temporal para evitar corrupción) ─
async function recortarImagen(archivo) {
    const ruta    = path.join(dir, archivo);
    const rutaTmp = ruta + '.tmp';

    try {
        await sharp(ruta).trim().toFile(rutaTmp);
        fs.renameSync(rutaTmp, ruta); // Reemplazo atómico
        return { archivo, ok: true };
    } catch (err) {
        if (fs.existsSync(rutaTmp)) fs.unlinkSync(rutaTmp);
        return { archivo, ok: false, error: err.message };
    }
}

// ── Procesar con concurrencia controlada ──────────────────────────────────────
// Divide el array en chunks de CONCURRENCY y espera cada chunk antes del siguiente.
// Así evitamos abrir 1000 handles de archivo a la vez en colecciones grandes.
async function procesarEnLotes(archivos, concurrencia) {
    let completadas = 0;
    let errores     = 0;

    for (let i = 0; i < archivos.length; i += concurrencia) {
        const lote      = archivos.slice(i, i + concurrencia);
        const promesas  = lote.map(recortarImagen);
        const resultados = await Promise.allSettled(promesas);

        for (const resultado of resultados) {
            // Promise.allSettled nunca rechaza, pero por si acaso:
            if (resultado.status === 'rejected') {
                console.error(`❌ Error inesperado: ${resultado.reason}`);
                errores++;
                continue;
            }

            const { archivo, ok, error } = resultado.value;
            if (ok) {
                console.log(`✅ ${archivo}`);
                completadas++;
            } else {
                console.error(`❌ ${archivo}: ${error}`);
                errores++;
            }
        }
    }

    return { completadas, errores };
}

// ── Punto de entrada ──────────────────────────────────────────────────────────
const { completadas, errores } = await procesarEnLotes(archivos, CONCURRENCY);

console.log('');
console.log(`🎉 Completado: ${completadas} recortadas | ${errores} errores`);

if (errores > 0) process.exit(1);
