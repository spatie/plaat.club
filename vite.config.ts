import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    resolve: {
        alias: {
            '@laravel/converse-react': `${import.meta.dirname}/vendor/laravel/converse/packages/react/src/index.ts`,
            '@laravel/converse': `${import.meta.dirname}/vendor/laravel/converse/packages/converse/src/index.ts`,
        },
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/social.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.tsx',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'packages/converse/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'packages/converse/**',
            'composer.json',
            // Keep Google's ownership-verification file byte-for-byte intact.
            'public/googlec13fe7c31e9be465.html',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            stylesheet: 'resources/css/social.css',
        },
    },
});
