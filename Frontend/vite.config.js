import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import imagePresets from "vite-plugin-image-presets";
import legacy from "@vitejs/plugin-legacy";

export default defineConfig({
  base: "/",
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
    legacy({
      targets: ["defaults", "not IE 11"],
      additionalLegacyPolyfills: ["regenerator-runtime/runtime"],
    })
  ],

  build: {
    polyfillDynamicImport: false,
    chunkSizeWarningLimit: 800,
    minify: "esbuild",
    sourcemap: false,
    cssCodeSplit: true,
    outDir: "dist",
    assetsDir: "assets",
    rollupOptions: {
      output: {
        format: "es",
        entryFileNames: "assets/[name].[hash].js",
        chunkFileNames: "assets/[name].[hash].js",
        assetFileNames: "assets/[name].[hash][extname]",
        manualChunks: {
          react: ["react", "react-dom", "react-router-dom"],
          mui: ["@mui/material", "@mui/icons-material", "@emotion/react", "@emotion/styled"],
          utils: ["dompurify"],
          reactSlick: ["react-slick", "slick-carousel"],
          thirdParty: ["aos", "axios", "react-icons"],
        },
      },
    },
  },

  resolve: {
    alias: {
      react: "react",
      "react-dom": "react-dom",
    },
  },

  esbuild: {
    minify: true,
    treeShaking: true,
    legalComments: "none",
  },
});
