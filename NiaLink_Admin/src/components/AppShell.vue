<script setup>
import { useNotify } from '../composables/useNotify.js'
import AppSidebar from './AppSidebar.vue'
import AppTopBar from './AppTopBar.vue'
import AppRightPanel from './AppRightPanel.vue'

const notify = useNotify()
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

    <!-- Global toast notification (matches image top-right success toast) -->
    <v-snackbar
        v-model="notify.visible.value"
        location="top right"
        :timeout="3500"
        rounded="xl"
        elevation="4"
        min-width="260"
        max-width="360"
        style="top: 16px; right: 16px"
    >
        <div class="d-flex align-start gap-3">
            <v-icon
                :color="notify.color.value === 'error' ? 'error' : 'success'"
                size="18"
                style="margin-top:1px;flex-shrink:0"
            >
                {{ notify.color.value === 'error' ? 'mdi-alert-circle' : 'mdi-check-circle' }}
            </v-icon>
            <div>
                <div class="text-body-2 font-weight-semibold" style="font-weight:600;line-height:1.3">
                    {{ notify.text.value }}
                </div>
                <div v-if="notify.subtext.value" class="text-caption text-medium-emphasis mt-0.5">
                    {{ notify.subtext.value }}
                </div>
            </div>
        </div>
        <template v-slot:actions>
            <v-btn
                icon="mdi-close"
                size="x-small"
                variant="text"
                class="text-medium-emphasis"
                @click="notify.dismiss()"
            />
        </template>
    </v-snackbar>
</template>
