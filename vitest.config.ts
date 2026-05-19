import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';

/**
 * Vitest configuration — admin React + RecEngineClient stub tests.
 *
 * Separate from vite.config.ts so the build pipeline and the test
 * pipeline have orthogonal options (Vitest pulls jsdom; production
 * Vite build doesn't). They share the @admin / @client aliases so
 * tests import the same paths the components use.
 */
export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: [resolve(__dirname, 'admin/src/test-setup.ts')],
    include: ['admin/src/**/*.test.{ts,tsx}', 'public/js/lib/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['admin/src/**/*.{ts,tsx}', 'public/js/lib/**/*.ts'],
      exclude: [
        'admin/src/test-setup.ts',
        'admin/src/**/*.test.{ts,tsx}',
        'admin/src/index.tsx', // mount-only, covered by manual sanity test
      ],
      reporter: ['text', 'html'],
    },
  },

  resolve: {
    alias: {
      '@admin': resolve(__dirname, 'admin/src'),
      '@client': resolve(__dirname, 'public/js/lib'),
    },
  },
});
