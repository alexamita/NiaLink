<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

const router = useRouter()
const auth = useAuthStore()

const displayName = computed(() => auth.user?.name ?? 'Super Admin')
const displayEmail = computed(() => auth.user?.email ?? '')
const initials = computed(() =>
    displayName.value.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('')
)

function logout() {
    auth.logout()
    router.replace('/login')
}

const navGroups = [
    {
        label: 'MAIN',
        items: [
            { label: 'Overview',     icon: 'mdi-view-dashboard-outline', to: '/' },
            { label: 'Merchants',    icon: 'mdi-store-outline',           to: '/merchants' },
            { label: 'Transactions', icon: 'mdi-swap-horizontal',         to: '/transactions' },
            { label: 'Payments',     icon: 'mdi-credit-card-outline',     to: '/payments' },
            { label: 'Audit Log',    icon: 'mdi-shield-check-outline',    to: '/audit' },
        ],
    },
    {
        label: 'FINANCE',
        items: [
            { label: 'Liquidity', icon: 'mdi-water-outline',       to: '/liquidity' },
            { label: 'Billing',   icon: 'mdi-receipt-text-outline', to: '/billing' },
        ],
    },
    {
        label: 'MANAGEMENT',
        items: [
            { label: 'Terminals', icon: 'mdi-monitor-dashboard', to: '/terminals' },
            { label: 'Settings',  icon: 'mdi-cog-outline',        to: '/settings' },
        ],
    },
]
</script>

<template>
    <v-navigation-drawer permanent width="220" class="sidebar">

        <!-- Logo -->
        <div class="logo-area">
            <div class="logo-mark">
                <v-icon size="15" color="white">mdi-link-variant</v-icon>
            </div>
            <div class="logo-lockup">
                <div class="logo-wordmark">
                    <span class="logo-nia">Nia</span><span class="logo-link">Link</span>
                </div>
                <div class="logo-sub">Admin Console</div>
            </div>
        </div>

        <v-divider class="sidebar-divider" />

        <!-- Navigation -->
        <nav class="nav-list">
            <template v-for="(group, gi) in navGroups" :key="group.label">

                <!-- Group label -->
                <div class="nav-group-label" :class="gi > 0 ? 'mt-4' : 'mt-2'">
                    {{ group.label }}
                </div>

                <!-- Items -->
                <v-list-item
                    v-for="item in group.items"
                    :key="item.to"
                    :to="item.to"
                    :prepend-icon="item.icon"
                    :title="item.label"
                    rounded="lg"
                    active-color="primary"
                    color="primary"
                    density="compact"
                    class="nav-item"
                />

            </template>
        </nav>

        <!-- Footer -->
        <template v-slot:append>
            <v-divider class="sidebar-divider" />
            <div class="footer-area">
                <v-avatar color="primary" size="28" class="footer-avatar">
                    <span class="footer-initials">{{ initials }}</span>
                </v-avatar>
                <div class="footer-text">
                    <div class="footer-name">{{ displayName }}</div>
                    <div class="footer-email">{{ displayEmail }}</div>
                </div>
                <v-btn
                    icon="mdi-logout"
                    variant="text"
                    size="x-small"
                    class="footer-menu-btn"
                    title="Sign out"
                    @click="logout"
                />
            </div>
        </template>

    </v-navigation-drawer>
</template>

<style scoped>
/* ── Shell ── */
.sidebar {
    border-right: 1px solid rgba(0, 0, 0, 0.07) !important;
    background: #fff !important;
}

.sidebar-divider {
    opacity: 0.4;
}

/* ── Logo ── */
.logo-area {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 18px 16px 16px;
}

.logo-mark {
    width: 32px;
    height: 32px;
    border-radius: 9px;
    background: #2B9D8F;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(43, 157, 143, 0.35), inset 0 1px 0 rgba(255,255,255,0.15);
}

.logo-lockup {
    display: flex;
    flex-direction: column;
    gap: 1px;
    line-height: 1;
}

.logo-wordmark {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1;
}

.logo-nia {
    color: rgba(0, 0, 0, 0.82);
}

.logo-link {
    color: #2B9D8F;
}

.logo-sub {
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.01em;
    color: rgba(0, 0, 0, 0.35);
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

/* ── Navigation ── */
.nav-list {
    padding: 4px 8px 8px;
    overflow-y: auto;
    flex: 1;
}

.nav-group-label {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.09em;
    color: rgba(0, 0, 0, 0.32);
    padding: 0 8px;
    margin-bottom: 3px;
    text-transform: uppercase;
}

/* Nav items — pin icon size and spacing precisely */
.nav-item {
    margin-bottom: 1px;
}

.nav-item :deep(.v-list-item__prepend) {
    width: 32px;
}

.nav-item :deep(.v-list-item__prepend .v-icon) {
    font-size: 16px;
    opacity: 0.55;
    margin-inline-end: 0;
}

.nav-item :deep(.v-list-item-title) {
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 0;
}

/* Active state */
.nav-item.v-list-item--active :deep(.v-list-item__prepend .v-icon) {
    opacity: 1;
}

.nav-item.v-list-item--active :deep(.v-list-item-title) {
    font-weight: 600;
}

/* ── Footer ── */
.footer-area {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
}

.footer-avatar {
    flex-shrink: 0;
}

.footer-initials {
    font-size: 10px;
    font-weight: 700;
    color: #fff;
}

.footer-text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.footer-name {
    font-size: 12px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.76);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1;
}

.footer-email {
    font-size: 10px;
    color: rgba(0, 0, 0, 0.38);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1;
}

.footer-menu-btn {
    flex-shrink: 0;
    color: rgba(0, 0, 0, 0.35) !important;
}
</style>
