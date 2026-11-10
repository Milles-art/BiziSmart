import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { VitePWA } from "vite-plugin-pwa";
import { fileURLToPath, URL } from "node:url";

// https://vitejs.dev/config/
export default defineConfig({
  server: {
    host: '127.0.0.1', port: 15173, strictPort: true,
    proxy: { '/api': { target: 'http://127.0.0.1:18080', changeOrigin: false } },
  },
  plugins: [
    react(),
    VitePWA({
      registerType: "prompt",
      injectRegister: "auto",
      manifest: {
        name: "BiziSmart Supermarket",
        short_name: "BiziSmart",
        start_url: "/",
        display: "standalone",
        theme_color: "#10b981",
        background_color: "#f1f5f9",
      },
      workbox: {
        globPatterns: ["**/*.{js,css,html,svg,woff2}"],
        navigateFallback: "index.html",
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [],
        cleanupOutdatedCaches: true,
      },
    }),
  ],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes("node_modules")) return;
          if (/\/dexie\//.test(id)) return "offline-storage";
          if (/\/(recharts|d3-[^/]+|victory-vendor)\//.test(id))
            return "charts";
          if (/\/(@mui|@emotion)\//.test(id)) return "mui";
          if (
            /\/(react|react-dom|scheduler|use-sync-external-store)\//.test(id)
          )
            return "runtime";
          if (/\/(@reduxjs|redux|react-redux|reselect|immer)\//.test(id))
            return "runtime";
        },
      },
    },
  },
});
