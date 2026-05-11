import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                path.resolve(__dirname, 'poss.css'), // File chính cần build
            ],
            refresh: true,
        }),
    ],
    build: {
        outDir: path.resolve(__dirname, '../../public/build-payment') // Thư mục build sẽ là public/build ở ngoài cùng
    }
});
