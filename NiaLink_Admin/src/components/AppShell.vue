<script setup>
import AppSidebar from './AppSidebar.vue'
import AppTopBar from './AppTopBar.vue'
import AppRightPanel from './AppRightPanel.vue'
</script>

<template>
    <!-- Left sidebar -->
    <AppSidebar />

    <!-- Top bar -->
    <AppTopBar />

    <!-- Right panel -->
    <AppRightPanel />

    <!-- Main content area -->
    <v-main>
        <v-container fluid class="pa-5" style="max-width:none">
            <router-view />
        </v-container>
    </v-main>
</template>
