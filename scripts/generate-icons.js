#!/usr/bin/env node

import sharp from 'sharp';
import toIco from 'to-ico';
import { readFileSync, writeFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const publicDir = resolve(__dirname, '..', 'public');
const svgPath = resolve(publicDir, 'favicon.svg');

const svg = readFileSync(svgPath);

async function generatePng(size, filename) {
    await sharp(svg, { density: 300 })
        .resize(size, size)
        .png()
        .toFile(resolve(publicDir, filename));
    console.log(`Generated ${filename} (${size}x${size})`);
}

async function generateIco() {
    const sizes = [16, 32, 48];
    const buffers = await Promise.all(
        sizes.map((size) => sharp(svg, { density: 300 }).resize(size, size).png().toBuffer()),
    );
    const ico = await toIco(buffers);
    writeFileSync(resolve(publicDir, 'favicon.ico'), ico);
    console.log(`Generated favicon.ico (${sizes.join(', ')})`);
}

function generateManifest() {
    const manifest = {
        name: 'Partner365',
        short_name: 'P365',
        icons: [
            { src: '/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
            { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
        ],
        theme_color: '#4F46E5',
        background_color: '#4F46E5',
        display: 'standalone',
    };
    writeFileSync(resolve(publicDir, 'site.webmanifest'), JSON.stringify(manifest, null, 2) + '\n');
    console.log('Generated site.webmanifest');
}

async function main() {
    await Promise.all([
        generatePng(180, 'apple-touch-icon.png'),
        generatePng(192, 'pwa-192x192.png'),
        generatePng(512, 'pwa-512x512.png'),
    ]);
    await generateIco();
    generateManifest();
    console.log('Done!');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
