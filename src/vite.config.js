import { defineConfig } from "vite";
import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
  plugins: [
    tailwindcss(),
    laravel({
      input: [
        "resources/css/app.css",
        "resources/css/storefront.css",
        "resources/js/app.js",
        "resources/js/storefront-entry.js",
        "resources/images/lhie-store-logo.webp",
        "resources/images/lhie-store-logo-512.png",
        "resources/images/chibi-favicon-256.png",
        "resources/images/chibi-favicon-64.png",
      ],
      refresh: true,
    }),
  ],
  server: {
    watch: {
      ignored: ["**/storage/framework/views/**"],
    },
  },
});
