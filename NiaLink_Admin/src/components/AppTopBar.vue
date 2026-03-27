<script setup>
import { ref } from 'vue'
import { useTheme } from 'vuetify'

const theme = useTheme()
const isDark = ref(false)
const search = ref('')

const toggleTheme = () => {
    isDark.value = !isDark.value
    theme.global.name.value = isDark.value ? 'dark' : 'light'
}
</script>

<template>
    <v-app-bar flat height="52" class="topbar">

        <!-- Search -->
        <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Search..."
            variant="solo-filled"
            flat
            density="compact"
            hide-details
            rounded="lg"
            class="ml-4 search-field"
            style="max-width:220px;align-self:center"
            bg-color="rgba(0,0,0,0.045)"
        />

        <v-spacer />

        <!-- Right actions -->
        <div class="right-actions">

            <!-- Theme toggle -->
            <v-btn
                icon
                variant="text"
                size="small"
                class="icon-btn"
                :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                @click="toggleTheme"
            >
                <v-icon size="17">{{ isDark ? 'mdi-weather-night' : 'mdi-weather-sunny' }}</v-icon>
            </v-btn>

            <v-divider vertical class="divider" />

            <!-- Notifications -->
            <v-btn icon variant="text" size="small" class="icon-btn" title="Notifications">
                <v-badge color="error" content="3" floating offset-x="-1" offset-y="-1">
                    <v-icon size="17">mdi-bell-outline</v-icon>
                </v-badge>
            </v-btn>

            <!-- Messages -->
            <v-btn icon variant="text" size="small" class="icon-btn" title="Messages">
                <v-badge color="primary" content="5" floating offset-x="-1" offset-y="-1">
                    <v-icon size="17">mdi-email-outline</v-icon>
                </v-badge>
            </v-btn>

            <v-divider vertical class="divider" />

            <!-- Avatar + name -->
            <div class="avatar-group">
                <v-avatar size="28" color="primary" class="avatar-circle">
                    <span class="avatar-initials">SA</span>
                </v-avatar>
                <div class="avatar-text">
                    <div class="avatar-name">Super Admin</div>
                    <div class="avatar-role">Administrator</div>
                </div>
                <v-icon size="13" class="avatar-chevron">mdi-chevron-down</v-icon>
            </div>

        </div>
    </v-app-bar>
</template>

<style scoped>
.topbar {
    border-bottom: 1px solid rgba(0, 0, 0, 0.07) !important;
    background: #fff !important;
}

/* ── Search ── */
.search-field :deep(.v-field) {
    border-radius: 8px;
    height: 34px;
}

.search-field :deep(.v-field__input) {
    font-size: 13px;
    padding-top: 0;
    padding-bottom: 0;
}

.search-field :deep(.v-field__prepend-inner) {
    padding-top: 0;
    align-items: center;
}

.search-field :deep(.v-field__prepend-inner .v-icon) {
    font-size: 15px;
    opacity: 0.38;
}

/* ── Right actions group ── */
.right-actions {
    display: flex;
    align-items: center;
    gap: 2px;
    padding-right: 16px;
}

/* ── Icon buttons ── */
.icon-btn {
    color: rgba(0, 0, 0, 0.42) !important;
    border-radius: 8px !important;
    width: 32px !important;
    height: 32px !important;
    flex-shrink: 0;
}

.icon-btn:hover {
    color: rgba(0, 0, 0, 0.72) !important;
    background: rgba(0, 0, 0, 0.05) !important;
}

/* ── Dividers ── */
.divider {
    height: 18px !important;
    margin: 0 8px;
    opacity: 0.15;
    align-self: center;
}

/* ── Avatar group ── */
.avatar-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px 4px 6px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s;
    margin-left: 4px;
}

.avatar-group:hover {
    background: rgba(0, 0, 0, 0.04);
}

.avatar-circle {
    box-shadow: 0 0 0 2px rgba(43, 157, 143, 0.18);
    flex-shrink: 0;
}

.avatar-initials {
    font-size: 10px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.03em;
}

.avatar-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.avatar-name {
    font-size: 12px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.76);
    line-height: 1;
    white-space: nowrap;
}

.avatar-role {
    font-size: 10px;
    color: rgba(0, 0, 0, 0.36);
    line-height: 1;
    white-space: nowrap;
}

.avatar-chevron {
    color: rgba(0, 0, 0, 0.28);
    flex-shrink: 0;
}
</style>
