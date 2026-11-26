import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

const dotenvExpand = require('dotenv-expand');
dotenvExpand(require('dotenv').config({ path: '../../.env'/*, debug: true*/ }));

export default defineConfig({
    build: {
        outDir: '../../public/build-bladethemev1',
        emptyOutDir: true,
        manifest: true,
    },
    plugins: [
        laravel({
            publicDirectory: '../../public',
            buildDirectory: 'build-bladethemev1',
            // Hot file riêng (khớp Modules\BladeThemeV1\Support\ThemeVite::HOT_FILE) — không dùng chung
            // public/hot với dev server Vite gốc, nếu không asset theme bị trỏ nhầm sang server đó.
            hotFile: '../../public/hot-bladethemev1',
            input: [
                __dirname + '/Resources/assets/sass/app.scss',
                __dirname + '/Resources/assets/sass/home.scss',
                __dirname + '/Resources/assets/js/app.js',
                __dirname + '/Resources/assets/js/home.js',
            ],
            refresh: false,
        }),
    ],
    css: {
        postcss: {
            plugins: [
                tailwindcss,
                autoprefixer,
            ],
        },
    },
});
