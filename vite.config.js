import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            // laravel-vite-plugin injects this implicitly, but 100+ files depend on it —
            // declaring it here means the build does not rely on that side effect.
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    build: {
        // Never ship source maps: they expose the unminified frontend, including business
        // logic and commented-out endpoints. CI also fails the build if any .map appears.
        sourcemap: false,
        // Prevent race where `npm run build` deletes public/build/manifest.json before
        // the new one is written, causing concurrent Inertia requests to 500 with
        // ViteManifestNotFoundException (seen 18:06:24 during POST /notifications/disconnect).
        // With `false` the old manifest stays until the new one overwrites it atomically
        // at the end of the build. Old hashed assets will accumulate and can be pruned
        // by `npm run build:prune` if needed.
        emptyOutDir: false,
        // NOTE: deliberately NO `manualChunks`.
        //
        // Grouping node_modules into named vendor chunks was tried and made things worse:
        // forcing modules into a named chunk means Rollup must load that whole chunk if any
        // single module in it is reachable from the entry, which fused otherwise-lazy
        // libraries (react-big-calendar/moment) into the eager path. Rollup's default
        // per-import-graph splitting already produces one chunk per page here, which is what
        // we want. Keep the wins in the source instead: no eager layout import in app.jsx,
        // and a lazily-constructed Echo client.
    },
});
