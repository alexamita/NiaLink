/**
 * Vuetify Configuration File (src/plugins/vuetify.js)
 *
 * This file acts as the central "control panel" or "theme engine" for the UI.
 * It initializes the Vuetify framework and prepares it to be injected into the main Vue app.
 *
 * Key responsibilities of this file include:
 * 1. Global Theming: Defining custom light/dark modes and mapping brand colors
 * (e.g., setting your specific hex code for 'primary', 'secondary', 'error', etc.).
 * 2. Global Defaults: Setting default behaviors for components to avoid repetition (e.g., telling all <v-btn> tags to be rounded and flat by default).
 * 3. Asset Loading: Importing the foundational CSS and Icon fonts required by the components.
 * * This logic isolation helps make the main.js file stay clean, and be
 * a single source of truth for the application's design system.
 */


// Import Vuetify's core CSS styles
import 'vuetify/styles'

// Import the Material Design Icons CSS
import '@mdi/font/css/materialdesignicons.css'

// Import the function that creates the Vuetify instance
import { createVuetify } from 'vuetify'

// Export the configured Vuetify instance so your Vue app can use it
export default createVuetify({
  // You will eventually add custom colors and themes here
})
