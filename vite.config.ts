import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Single Vite config with two named build entries:
 *
 *   admin/admin              → dist/admin/admin.js + dist/admin/admin.css
 *   client/rec-engine-client → dist/client/rec-engine-client.js
 *
 * Erkki ratified the single-config approach for sub-PR 2.A. We split into
 * separate `vite.*.config.ts` files only if the build-time options start
 * to diverge in ways named modes can't express (no projection of that yet).
 *
 * The admin bundle is consumed by the WordPress admin (loaded with
 * wp_enqueue_script in admin/settings.php and admin/wizard.php in
 * sub-PR 2.H). It imports React, ReactDOM, the admin/src/* TypeScript
 * tree, and Tailwind via the index.css side-effect import.
 *
 * The client bundle is the WordPress-free RecEngineClient — Phase 3
 * sub-PR 6 fills in the class body; Phase 2 ships a stub with the public
 * API surface fully signed (each method throws "Not implemented") so the
 * API contour is locked at the start of Phase 2 rather than redesigned
 * later. Mailstone 2 extracts this file unchanged into
 * @smaily/recengine-client; the WP-wrapper (public/js/beacon.ts, shipped as
 * dist/public/js/sc-runtime.js) stays in the plugin.
 */
export default defineConfig({
  plugins: [react()],

  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: true,

    rollupOptions: {
      // Both entries are side-effect apps (admin renders React; beacon boots
      // the storefront tracker). Neither is consumed as a library, so let
      // Rollup drop unused exports — the built bundles then carry no top-level
      // `export`, which is what lets sc-runtime.js load as a classic <script>
      // (StorefrontBeacon enqueues it without type="module").
      preserveEntrySignatures: false,

      input: {
        'admin/admin': resolve(__dirname, 'admin/src/index.tsx'),
        // beacon.ts inlines RecEngineClient (rec-engine-client.ts is NOT a
        // separate entry, so there is no shared chunk and the bundle has no
        // top-level `import`). The lib stays as source for the Milestone-2
        // npm extraction. The OUTPUT is deliberately named `sc-runtime.js` (not
        // `beacon.js`): the source name `beacon` matches EasyPrivacy ad-block
        // filter lists, which blocked the storefront request for real users
        // (the route is renamed off `/beacon` → `/relay` for the same reason).
        // The entry-key IS the output basename (`[name].js`), so the source file
        // keeps its name and only the shipped filename changes (F3-41).
        'public/js/sc-runtime': resolve(__dirname, 'public/js/beacon.ts'),
      },
      output: {
        entryFileNames: '[name].js',
        // Chunks shared between the two entries land in a neutral folder
        // so neither bundle's directory carries hash-named artifacts.
        chunkFileNames: 'shared/[name]-[hash].js',
        // CSS produced by the admin entry needs to land next to admin.js.
        // The asset-info name carries the originating source filename.
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'admin/admin.css';
          }
          return 'shared/[name]-[hash][extname]';
        },
      },
    },
  },

  resolve: {
    alias: {
      '@admin': resolve(__dirname, 'admin/src'),
      '@client': resolve(__dirname, 'public/js/lib'),
    },
  },

  server: {
    port: 5173,
    strictPort: false,
  },
});
