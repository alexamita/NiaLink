<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const routeMap = {
    '/':             { section: 'NiaLink', page: 'Overview' },
    '/merchants':    { section: 'NiaLink', page: 'Merchants' },
    '/transactions': { section: 'NiaLink', page: 'Transactions' },
    '/terminals':    { section: 'NiaLink', page: 'Terminals' },
    '/audit':        { section: 'NiaLink', page: 'Audit Log' },
    '/liquidity':    { section: 'Finance',  page: 'Liquidity' },
    '/payments':     { section: 'Finance',  page: 'Payments' },
    '/billing':      { section: 'Account', page: 'Billing' },
    '/settings':     { section: 'Account', page: 'Settings' },
}

const crumb = computed(() => routeMap[route.path] ?? { section: 'NiaLink', page: 'Dashboard' })

const displayName = computed(() => auth.user?.name ?? 'Super Admin')
const displayEmail = computed(() => auth.user?.email ?? '')
const displayRole = computed(() => {
    const r = auth.user?.user_role ?? 'admin'
    return r === 'merchant_admin' ? 'Merchant Admin' : 'Administrator'
})
const initials = computed(() =>
    displayName.value.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('')
)

const menuOpen = ref(false)
const loggingOut = ref(false)

async function logout() {
    loggingOut.value = true
    menuOpen.value = false
    await auth.logout()
    router.replace('/login')
}
</script>

<template>
    <v-app-bar flat height="48" class="topbar">

        <!-- Breadcrumb -->
        <div class="breadcrumb-area ml-4">
            <span class="crumb-section">{{ crumb.section }}</span>
            <v-icon size="13" class="crumb-sep">mdi-chevron-right</v-icon>
            <span class="crumb-page">{{ crumb.page }}</span>
        </div>

        <v-spacer />

        <!-- Right actions -->
        <div class="right-actions">

            <!-- Notifications -->
            <v-btn icon variant="text" size="small" class="icon-btn" title="Notifications">
                <v-icon size="16">mdi-bell-outline</v-icon>
            </v-btn>

            <v-divider vertical class="divider" />

            <!-- User menu -->
            <v-menu
                v-model="menuOpen"
                location="bottom end"
                :offset="8"
                transition="fade-transition"
            >
                <template v-slot:activator="{ props }">
                    <div class="avatar-group" v-bind="props">
                        <v-avatar size="26" color="primary" class="avatar-circle">
                            <span class="avatar-initials">{{ initials }}</span>
                        </v-avatar>
                        <div class="avatar-text">
                            <div class="avatar-name">{{ displayName }}</div>
                            <div class="avatar-role">{{ displayRole }}</div>
                        </div>
                        <v-icon size="12" class="avatar-chevron" :class="{ 'chevron-open': menuOpen }">
                            mdi-chevron-down
                        </v-icon>
                    </div>
                </template>

                <v-card class="user-menu" rounded="xl" elevation="3" width="220">
                    <!-- User identity -->
                    <div class="menu-identity">
                        <v-avatar size="32" color="primary" class="menu-avatar">
                            <span class="menu-initials">{{ initials }}</span>
                        </v-avatar>
                        <div class="menu-id-text">
                            <div class="menu-name">{{ displayName }}</div>
                            <div class="menu-email">{{ displayEmail }}</div>
                        </div>
                    </div>

                    <v-divider class="menu-divider" />

                    <!-- Role badge -->
                    <div class="menu-meta">
                        <span class="menu-role-badge">{{ displayRole }}</span>
                    </div>

                    <v-divider class="menu-divider" />

                    <!-- Sign out -->
                    <div class="menu-actions">
                        <button class="menu-signout" :disabled="loggingOut" @click="logout">
                            <v-icon size="14" class="signout-icon">mdi-logout</v-icon>
                            <span>{{ loggingOut ? 'Signing out…' : 'Sign out' }}</span>
                        </button>
                    </div>
                </v-card>
            </v-menu>

        </div>
    </v-app-bar>
</template>

<style scoped>
.topbar {
    border-bottom: 1px solid rgba(0, 0, 0, 0.07) !important;
    background: #fff !important;
}

.breadcrumb-area {
    display: flex;
    align-items: center;
    gap: 2px;
}

.crumb-section {
    font-size: 12.5px;
    font-weight: 500;
    color: rgba(0, 0, 0, 0.38);
}

.crumb-sep {
    color: rgba(0, 0, 0, 0.22);
}

.crumb-page {
    font-size: 12.5px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.72);
}

.right-actions {
    display: flex;
    align-items: center;
    gap: 2px;
    padding-right: 14px;
}

.icon-btn {
    color: rgba(0, 0, 0, 0.4) !important;
    border-radius: 8px !important;
    width: 30px !important;
    height: 30px !important;
    flex-shrink: 0;
}

.icon-btn:hover {
    color: rgba(0, 0, 0, 0.7) !important;
    background: rgba(0, 0, 0, 0.05) !important;
}

.divider {
    height: 16px !important;
    margin: 0 8px;
    opacity: 0.12;
    align-self: center;
}

/* Avatar trigger */
.avatar-group {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 3px 7px 3px 5px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.13s;
    margin-left: 2px;
    user-select: none;
}

.avatar-group:hover {
    background: rgba(0, 0, 0, 0.04);
}

.avatar-circle {
    box-shadow: 0 0 0 2px rgba(43, 157, 143, 0.15);
    flex-shrink: 0;
}

.avatar-initials {
    font-size: 9.5px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.02em;
}

.avatar-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.avatar-name {
    font-size: 11.5px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.76);
    line-height: 1;
    white-space: nowrap;
}

.avatar-role {
    font-size: 9.5px;
    color: rgba(0, 0, 0, 0.34);
    line-height: 1;
    white-space: nowrap;
}

.avatar-chevron {
    color: rgba(0, 0, 0, 0.26);
    flex-shrink: 0;
    transition: transform 0.15s ease;
}

.chevron-open {
    transform: rotate(180deg);
}

/* Dropdown card */
.user-menu {
    border: 1px solid rgba(0, 0, 0, 0.07) !important;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.10) !important;
    overflow: hidden;
}

.menu-identity {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 14px 12px;
}

.menu-avatar {
    flex-shrink: 0;
}

.menu-initials {
    font-size: 11px;
    font-weight: 700;
    color: #fff;
}

.menu-id-text {
    min-width: 0;
    flex: 1;
}

.menu-name {
    font-size: 12.5px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.82);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.menu-email {
    font-size: 10.5px;
    color: rgba(0, 0, 0, 0.38);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}

.menu-divider {
    opacity: 0.08;
}

.menu-meta {
    padding: 8px 14px;
}

.menu-role-badge {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: rgba(0, 0, 0, 0.32);
    background: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
    padding: 2px 7px;
}

.menu-actions {
    padding: 6px 8px 8px;
}

.menu-signout {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 8px;
    border: none;
    background: none;
    border-radius: 7px;
    cursor: pointer;
    font-size: 12.5px;
    font-weight: 500;
    color: #dc2626;
    transition: background 0.12s;
    text-align: left;
}

.menu-signout:hover:not(:disabled) {
    background: rgba(220, 38, 38, 0.06);
}

.menu-signout:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.signout-icon {
    color: #dc2626 !important;
    flex-shrink: 0;
}
</style>
