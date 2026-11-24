import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

// The unit tests use their own config, not vite.config.mjs. The Laravel and
// Tailwind plugins are not necessary to compile a component. The `vue` alias of
// vite.config.mjs is also not used here: @vue/test-utils imports the default
// `vue` build, and two copies of Vue break the store injection.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        // The components import each other without the `.vue` extension.
        extensions: ['.mjs', '.js', '.json', '.vue'],
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        // Playwright runs the specs in tests/e2e. Vitest must not collect them.
        exclude: ['node_modules/**', 'vendor/**', 'tests/e2e/**'],
        restoreMocks: true,
        unstubGlobals: true,
    },
});
