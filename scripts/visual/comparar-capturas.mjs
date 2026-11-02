#!/usr/bin/env node
/*
 * Compara dos juegos de capturas de scripts/visual/capturar-oficina.mjs píxel a píxel.
 *
 * Uso: node scripts/visual/comparar-capturas.mjs <antes> <despues> [carpeta-diferencias]
 *
 * Termina con código 1 si alguna captura cambió, falta o cambió de tamaño. Con una tercera
 * carpeta guarda una imagen por diferencia (los píxeles distintos en rojo).
 * Un cambio sin efecto visual debe terminar en «0 capturas con diferencias».
 */
import fs from 'node:fs';
import path from 'node:path';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const [antes, despues, carpetaDiferencias] = process.argv.slice(2);
if (!antes || !despues) {
    console.error('Uso: node scripts/visual/comparar-capturas.mjs <antes> <despues> [carpeta-diferencias]');
    process.exit(2);
}

const hallazgos = [];
let total = 0;
for (const tema of fs.readdirSync(antes).filter((nombre) => nombre !== 'html' && fs.statSync(path.join(antes, nombre)).isDirectory())) {
    for (const archivo of fs.readdirSync(path.join(antes, tema)).filter((nombre) => nombre.endsWith('.png'))) {
        total += 1;
        const rutaDespues = path.join(despues, tema, archivo);
        if (!fs.existsSync(rutaDespues)) {
            hallazgos.push(`falta       ${tema}/${archivo}`);
            continue;
        }
        const a = PNG.sync.read(fs.readFileSync(path.join(antes, tema, archivo)));
        const b = PNG.sync.read(fs.readFileSync(rutaDespues));
        if (a.width !== b.width || a.height !== b.height) {
            hallazgos.push(`tamaño      ${tema}/${archivo}  ${a.width}×${a.height} → ${b.width}×${b.height}`);
            continue;
        }
        const diferencia = new PNG({ width: a.width, height: a.height });
        const pixeles = pixelmatch(a.data, b.data, diferencia.data, a.width, a.height, { threshold: 0 });
        if (!pixeles) continue;
        hallazgos.push(`diferencia  ${tema}/${archivo}  ${pixeles} px`);
        if (carpetaDiferencias) {
            fs.mkdirSync(path.join(carpetaDiferencias, tema), { recursive: true });
            fs.writeFileSync(path.join(carpetaDiferencias, tema, archivo), PNG.sync.write(diferencia));
        }
    }
}

hallazgos.forEach((linea) => console.log(linea));
console.log(`${hallazgos.length} de ${total} capturas con diferencias`);
process.exit(hallazgos.length ? 1 : 0);
