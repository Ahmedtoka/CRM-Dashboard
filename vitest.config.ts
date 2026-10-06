import vue from '@vitejs/plugin-vue';
import path from 'path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            'ziggy-js': path.resolve(__dirname, './vendor/tightenco/ziggy'),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        setupFiles: ['resources/js/test/setup.ts'],
        restoreMocks: true,
    },
});
