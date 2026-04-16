import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/site.css', 'resources/js/site.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    // Alpine.js in eigen chunk
                    if (id.includes('alpinejs') || id.includes('@alpinejs')) {
                        return 'alpine';
                    }
                    // GSAP + Lenis in eigen chunk
                    if (id.includes('gsap') || id.includes('lenis')) {
                        return 'animation';
                    }
                },
                // Betere hashing voor lange cache-TTL
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash].[ext]',
            },
        },
        // Verhoog de chunk-size warning naar 500KB
        chunkSizeWarningLimit: 500,
    },
});
