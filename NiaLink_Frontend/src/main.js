/**
 * Main Application Entry Point (src/main.js)
 *
 * This file is the "Boot Sequence" or "Mixing Board" for your entire Vue application.
 * It is the very first JavaScript file that runs when your browser loads the app.
 *
 * Key responsibilities of this file include:
 * 1. Creating the Instance: It imports your root component (`App.vue`) and uses
 * `createApp()` to initialize the core Vue engine.
 * 2. Registering Plugins (`app.use`): This is where you connect all your independent
 * systems together. You are taking your Database (Pinia), your Navigator (Router),
 * and your UI Framework (Vuetify) and plugging them into the core engine.
 * 3. Mounting to the DOM: The absolute final step (`app.mount('#app')`) takes your
 * fully configured, plugin-loaded Vue application and physically injects it into
 * the plain `<div id="app"></div>` tag sitting inside your `index.html` file.
 *
 * Note: Order matters! You must always register your plugins with `app.use()`
 * BEFORE you tell the app to `mount()`.
 */

import { createApp } from 'vue'
import { createPinia } from 'pinia' // <-- Pinia - State Management Library
import router from './router' // <-- The Router - Routing Library
import App from './App.vue'

// -> Imports the Vuetify 'control panel' in './plugins/vuetify'
// -> Makes all the <v-...> tags available globally across the entire project.
import vuetify from './plugins/vuetify'

const app = createApp(App)

app.use(createPinia()) // Turn on the database
app.use(router) // Turn on the navigation
app.use(vuetify) // Turn on the UI styles -> Tell Vue to use Vuetify BEFORE mounting the app

app.mount('#app')
