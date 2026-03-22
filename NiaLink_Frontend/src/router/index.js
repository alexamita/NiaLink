/**
 * Vue Router Configuration File (src/router/index.js)
 *
 * This file is the "Traffic Controller" or "Navigator" for my Single Page Application (SPA).
 * It dictates which Vue component should be displayed on the screen based on the current URL.
 *
 * Key responsibilities of this file include:
 * 1. Route Mapping: Defining the connections between URL paths (like '/about')
 * and actual Vue View components (like AboutView.vue).
 * 2. History Management: Using `createWebHistory` to utilize the browser's native API.
 * This gives clean URLs without the old-school '#' symbols and makes the
 * browser's Back and Forward buttons work perfectly.
 * 3. Lazy Loading (Performance): Configuring routes so that the browser only downloads
 * the code for a specific page when the user actually navigates to it.
 * 4. Navigation Guards (Security): This is where I will eventually add logic to
 * protect certain pages (e.g., checking if a user is logged in before letting
 * them see the '/dashboard' route).
 */


import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
    // Uses the base URL from Vite config (usually '/') for all routing
    history: createWebHistory(import.meta.env.BASE_URL),

    // This array lists out all the pages in the app
    routes: [],
})

export default router
