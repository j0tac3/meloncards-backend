import sharp from 'sharp';
import fs from 'fs';
import path from 'path';

// Apuntamos directamente a la carpeta pública de Laravel donde ya tienes las fotos
const dir = './storage/app/public/sets';

if (!fs.existsSync(dir)) {
    console.error(`❌ La carpeta ${dir} no existe.`);
    process.exit(1);
}

// Leemos todos los archivos de imagen
const archivos = fs.readdirSync(dir).filter(f => f.match(/\.(png|jpg|jpeg|webp)$/i));

if (archivos.length === 0) {
    console.log(`⚠️ No se encontraron imágenes en ${dir}`);
    process.exit(0);
}

console.log(`🚀 Iniciando recorte masivo de ${archivos.length} imágenes...`);

// Procesamos cada imagen
archivos.forEach(async (archivo) => {
    const ruta = path.join(dir, archivo);
    
    try {
        // Sharp lee, recorta y guarda en memoria
        const buffer = await sharp(ruta).trim().toBuffer();
        
        // Sobrescribimos el archivo original con la versión ya recortada
        fs.writeFileSync(ruta, buffer);
        console.log(`✅ Recortada: ${archivo}`);
    } catch (err) {
        console.error(`❌ Error procesando ${archivo}:`, err.message);
    }
});