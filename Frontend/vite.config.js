import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import imagePresets from 'vite-plugin-image-presets'
import viteCompression from 'vite-plugin-compression'

export default defineConfig({
  base: '/',
  plugins: [
    react(),
    imagePresets({
      optimized: {
        quality: 75,
        formats: {
          avif: { quality: 50 },
          webp: { quality: 75 },
        },
      },
    }),
    // Emit precompressed assets alongside the originals (server picks .br/.gz).
    viteCompression({ algorithm: 'brotliCompress', ext: '.br' }),
    viteCompression({ algorithm: 'gzip', ext: '.gz' }),
  ],

  // DEV-ONLY: don't pre-bundle react-icons as whole packs. Named subpath imports
  // (react-icons/fi, etc.) are already tree-shaken in the production build, but
  // Vite's dev pre-bundle otherwise pulls entire packs — which is what inflates
  // the dev treemap to ~17 MB. Excluding it makes the dev view match reality.
  // No effect on the production bundle (react-icons ships as ~tens of KB).
  optimizeDeps: {
    exclude: ['react-icons'],
  },

  build: {
    polyfillDynamicImport: false,
    chunkSizeWarningLimit: 800,
    minify: 'esbuild',
    sourcemap: false,
    cssCodeSplit: true,
    outDir: 'dist',
    assetsDir: 'assets',
    rollupOptions: {
      output: {
        format: 'es',
        entryFileNames: 'assets/[name].[hash].js',
        chunkFileNames: 'assets/[name].[hash].js',
        assetFileNames: 'assets/[name].[hash][extname]',
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          mui: ['@mui/material', '@mui/icons-material', '@emotion/react', '@emotion/styled'],
          utils: ['dompurify'],
          thirdParty: ['axios', 'react-icons'],
          three: ['three'],
          'react-three': ['@react-three/fiber', '@react-three/drei'],
        },
      },
    },
  },

  resolve: {
    alias: {
      react: 'react',
      'react-dom': 'react-dom',
    },
  },

  esbuild: {
    minify: true,
    treeShaking: true,
    legalComments: 'none',
  },
})
