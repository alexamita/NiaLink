<script setup>
import { computed, ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth.js'
import { api } from '../services/api.js'

const auth = useAuthStore()

const displayName = computed(() => auth.user?.name ?? 'Super Admin')
const displayEmail = computed(() => auth.user?.email ?? 'admin@nialink.co.ke')
const displayRole = computed(() => {
    const r = auth.user?.user_role ?? 'admin'
    return r === 'merchant_admin' ? 'Merchant Admin' : 'Super Admin'
})
const initials = computed(() =>
    displayName.value.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('')
)

const pendingCount = ref(0)
onMounted(async () => {
    try {
        const s = await api.stats()
        pendingCount.value = s?.pending_merchants ?? 0
    } catch { /* silent */ }
})

const navGroups = [
    {
        label: 'GENERAL',
        items: [
            { label: 'Overview',  icon: 'mdi-view-dashboard-outline', to: '/' },
            { label: 'Notifications', icon: 'mdi-bell-outline', to: '/merchants', badge: true },
        ],
    },
    {
        label: 'NIALINK',
        items: [
            { label: 'Merchants',    icon: 'mdi-store-outline',           to: '/merchants' },
            { label: 'Transactions', icon: 'mdi-swap-horizontal',         to: '/transactions' },
            { label: 'Terminals',    icon: 'mdi-monitor-dashboard',       to: '/terminals' },
            { label: 'Audit Log',    icon: 'mdi-shield-check-outline',    to: '/audit' },
            { label: 'Liquidity',    icon: 'mdi-water-outline',           to: '/liquidity' },
            { label: 'Payments',     icon: 'mdi-credit-card-outline',     to: '/payments' },
        ],
    },
    {
        label: 'ACCOUNT',
        items: [
            { label: 'Settings',     icon: 'mdi-cog-outline',             to: '/settings' },
            { label: 'Import data',  icon: 'mdi-database-import-outline', to: '/billing' },
            { label: 'Export data',  icon: 'mdi-database-export-outline', to: '/billing' },
        ],
    },
]
</script>

<template>
    <v-navigation-drawer permanent width="230" class="sidebar">

        <!-- ── Brand ── -->
        <div class="brand-row">
            <div class="brand-icon">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="white" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="white" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="brand-lockup">
                <span class="brand-nia">Nia</span><span class="brand-link">Link</span>
            </div>
            <v-icon size="13" class="brand-chevron">mdi-chevron-down</v-icon>
        </div>

        <v-divider class="sidebar-divider" />

        <!-- ── Navigation ── -->
        <nav class="nav-list">
            <template v-for="(group, gi) in navGroups" :key="group.label">

                <div class="nav-group-label" :class="gi > 0 ? 'mt-4' : 'mt-2'">
                    {{ group.label }}
                </div>

                <v-list-item
                    v-for="item in group.items"
                    :key="item.to + item.label"
                    :to="item.to"
                    :prepend-icon="item.icon"
                    :title="item.label"
                    rounded="lg"
                    active-color="primary"
                    color="primary"
                    density="compact"
                    class="nav-item"
                >
                    <template v-if="item.badge && pendingCount > 0" v-slot:append>
                        <span class="nav-badge">{{ pendingCount }}</span>
                    </template>
                </v-list-item>

            </template>
        </nav>

        <!-- ── Footer ── -->
        <template v-slot:append>
            <v-divider class="sidebar-divider" />
            <div class="footer-area">
                <v-avatar color="primary" size="26" class="footer-avatar">
                    <span class="footer-initials">{{ initials }}</span>
                </v-avatar>
                <div class="footer-text">
                    <div class="footer-name">{{ displayName }}</div>
                    <div class="footer-email">{{ displayEmail }}</div>
                </div>
                <div class="footer-right">
                    <span class="footer-plan">{{ displayRole }}</span>
                </div>
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
    opacity: 0.35;
}

/* ── Brand row ── */
.brand-row {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 14px 14px 12px;
    cursor: pointer;
    user-select: none;
}

.brand-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: linear-gradient(145deg, #34b5a5 0%, #1e7a6e 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow:
        0 3px 10px rgba(43, 157, 143, 0.42),
        inset 0 1px 0 rgba(255, 255, 255, 0.22),
        inset 0 -1px 0 rgba(0, 0, 0, 0.08);
}

.brand-lockup {
    flex: 1;
    font-size: 16.5px;
    font-weight: 900;
    letter-spacing: -0.05em;
    line-height: 1;
}

.brand-nia  { color: rgba(0, 0, 0, 0.88); }
.brand-link { color: #2B9D8F; }

.brand-chevron {
    color: rgba(0, 0, 0, 0.25);
    font-size: 13px !important;
}

/* ── Shortcuts ── */
.shortcuts-area {
    padding: 6px 8px;
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.shortcut-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.12s ease;
}

.shortcut-item:hover {
    background: rgba(0, 0, 0, 0.04);
}

.shortcut-icon {
    color: rgba(0, 0, 0, 0.38) !important;
    flex-shrink: 0;
}

.shortcut-label {
    flex: 1;
    font-size: 12.5px;
    font-weight: 500;
    color: rgba(0, 0, 0, 0.55);
}

.shortcut-badge {
    font-size: 10px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.3);
    background: rgba(0, 0, 0, 0.06);
    border-radius: 4px;
    padding: 1px 5px;
    letter-spacing: 0.02em;
}

/* ── Navigation ── */
.nav-list {
    padding: 4px 8px 8px;
    overflow-y: auto;
    flex: 1;
}

.nav-group-label {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.10em;
    color: rgba(0, 0, 0, 0.28);
    padding: 0 8px;
    margin-bottom: 2px;
    text-transform: uppercase;
}

.nav-item {
    margin-bottom: 1px;
}

.nav-item :deep(.v-list-item__prepend) {
    width: 28px;
}

.nav-item :deep(.v-list-item__prepend .v-icon) {
    font-size: 15px;
    opacity: 0.5;
    margin-inline-end: 0;
}

.nav-item :deep(.v-list-item-title) {
    font-size: 12.5px;
    font-weight: 500;
    letter-spacing: 0;
}

.nav-item.v-list-item--active :deep(.v-list-item__prepend .v-icon) { opacity: 1; }
.nav-item.v-list-item--active :deep(.v-list-item-title) { font-weight: 600; }

/* Notification badge */
.nav-badge {
    font-size: 10px;
    font-weight: 700;
    background: #F59E0B;
    color: #fff;
    border-radius: 10px;
    padding: 1px 6px;
    line-height: 1.5;
}

/* ── Footer ── */
.footer-area {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
}

.footer-avatar {
    flex-shrink: 0;
}

.footer-initials {
    font-size: 9px;
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
    font-size: 11.5px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.76);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1;
}

.footer-email {
    font-size: 9.5px;
    color: rgba(0, 0, 0, 0.35);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1;
}

.footer-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    flex-shrink: 0;
}

.footer-plan {
    font-size: 8.5px;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: rgba(0, 0, 0, 0.3);
    background: rgba(0, 0, 0, 0.06);
    border-radius: 4px;
    padding: 1px 5px;
    text-transform: uppercase;
    white-space: nowrap;
}

.footer-logout {
    color: rgba(0, 0, 0, 0.28) !important;
    width: 20px !important;
    height: 20px !important;
}
</style>
