<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from './components/AppShell.vue'

const route = useRoute()
const isPublic = computed(() => route.meta.public)
</script>

<template>
    <v-app>
        <template v-if="isPublic">
            <router-view />
        </template>
        <template v-else>
            <AppShell />
        </template>
    </v-app>
</template>

<style scoped>
/*
    Vuetify provides hundreds of utility classes (d-flex, mt-4, text-center, pa-6…)
    so you will rarely need custom CSS here.
    Add only truly global overrides that cannot be expressed with Vuetify utilities.
*/
</style>
