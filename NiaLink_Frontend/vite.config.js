/**
 * Vite Configuration File (vite.config.js)
 *
 * This file is the "Engine Room" or "Build Master" for your entire project.
 * It controls how Vite (your development server and bundler) processes your code
 * before serving it to the browser or packaging it for production.
 *
 * Key responsibilities of this file include:
 * 1. Plugin Management: Registering essential tools like the Vue compiler (`vue()`),
 * debugging tools (`vueDevTools()`), and the Vuetify treeshaking plugin.
 * 2. Auto-Importing (Performance): The `vuetify({ autoImport: true })` setting watches
 * your templates. When you type `<v-btn>`, it automatically imports only the CSS and JS
 * needed for that specific component, keeping your application incredibly fast.
 * 3. Path Aliasing: Setting up the `@` symbol as a shortcut for your `src/` directory.
 * This prevents messy relative imports (like `import '../../components/Button.vue'`)
 * and allows you to write clean, absolute imports (like `import '@/components/Button.vue'`).
 */

import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import vuetify from 'vite-plugin-vuetify' // <-- Imports the Vuetify plugin

// https://vite.dev/config/
export default defineConfig({
    plugins: [vue(), vueDevTools(), vuetify({ autoImport: true })],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
})
