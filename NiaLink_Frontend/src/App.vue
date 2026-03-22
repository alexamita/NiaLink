/**
* Root Component (src/App.vue)
* * This is the outer shell of your entire application. Every other page or
* component you build will eventually be nested inside this file.
*/

<script setup>
import { RouterLink, RouterView } from 'vue-router'
</script>


<template>
    <v-app>

        <v-app-bar color="primary" elevation="3">
            <v-app-bar-title>NiaLink</v-app-bar-title>

            <v-spacer></v-spacer>

            <v-btn to="/" variant="text">Home</v-btn>
            <v-btn to="/about" variant="text">About</v-btn>
        </v-app-bar>

        <v-main>

            <v-container>
                <RouterView />
            </v-container>

        </v-main>

    </v-app>
</template>


/* The 'scoped' keyword means any standard CSS you write here will ONLY apply
to this App.vue file and won't leak into other components.
Because Vuetify provides so many utility classes (like 'd-flex', 'mt-4', 'text-center'),
you will rarely need to write custom CSS here!
<style scoped></style>
