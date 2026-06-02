import sharp from 'sharp';
import fs from 'fs';

const rutaImagen = process.argv[2];

if (!rutaImagen || !fs.existsSync(rutaImagen)) {
    console.error('Error: Ruta de imagen no válida o no proporcionada.');
    process.exit(1);
}

sharp(rutaImagen)
    .trim()
    .toBuffer()
    .then(buffer => {
        fs.writeFileSync(rutaImagen, buffer);
        console.log(`Imagen recortada con éxito: ${rutaImagen}`);
    })
    .catch(err => {
        console.error(`Error recortando la imagen: ${err.message}`);
        process.exit(1);
    });