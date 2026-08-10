import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Dua pasang entry: sisi web/mini app dan panel admin. Dipisah
            // supaya pengunjung mini app tidak ikut mengunduh CSS + JS panel
            // admin yang tidak pernah dipakai di halaman mereka.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/admin.css',
                'resources/js/admin-entry.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
