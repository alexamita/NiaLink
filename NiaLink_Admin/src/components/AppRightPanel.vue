<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

const stats = ref({ total_liquidity: 0, total_revenue: 0, volume_24h: 0, pending_merchants: 0 })
const wallets = ref({ total_liquidity: 0, active_liquidity: 0, frozen_liquidity: 0, wallets: [] })
const auditLog = ref([])
const loading = ref(true)

onMounted(async () => {
    try {
        const [s, w, a] = await Promise.all([
            api.stats(),
            api.wallets(),
            api.audit(),
        ])
        stats.value = s ?? stats.value
        wallets.value = w ?? wallets.value
        auditLog.value = Array.isArray(a) ? a : []
    } catch (e) {
        console.error('Right panel load failed', e)
    } finally {
        loading.value = false
    }
})

// Finance score: proxy for system health
const financeScore = computed(() => {
    const pending = stats.value.pending_merchants ?? 0
    const raw = 100 - pending * 2
    return Math.min(99, Math.max(60, raw))
})

const scoreLabel = computed(() => {
    const s = financeScore.value
    if (s >= 90) return 'Excellent'
    if (s >= 80) return 'Very Good'
    if (s >= 70) return 'Good'
    return 'Fair'
})

// Format large numbers
const fmt = (n) => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 })

// Recent activity — last 6 entries
const recentActivity = computed(() => auditLog.value.slice(0, 6))

// Group audit entries by TODAY / YESTERDAY
const today = new Date()
today.setHours(0, 0, 0, 0)
const yesterday = new Date(today)
yesterday.setDate(yesterday.getDate() - 1)

const isToday = (dateStr) => {
    const d = new Date(dateStr)
    d.setHours(0, 0, 0, 0)
    return d.getTime() === today.getTime()
}

const isYesterday = (dateStr) => {
    const d = new Date(dateStr)
    d.setHours(0, 0, 0, 0)
    return d.getTime() === yesterday.getTime()
}

const getDateLabel = (dateStr) => {
    if (isToday(dateStr)) return 'TODAY'
    if (isYesterday(dateStr)) return 'YESTERDAY'
    return new Date(dateStr).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' }).toUpperCase()
}

const fmtTime = (d) => d ? new Date(d).toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit' }) : ''

// Initials from action string
const actionInitials = (action) => {
    if (!action) return '??'
    const parts = action.replace(/_/g, ' ').split(' ')
    return parts.slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('')
}

const actionColor = (action) => {
    if (!action) return 'grey'
    if (action.includes('approve')) return 'success'
    if (action.includes('suspend') || action.includes('reject')) return 'error'
    if (action.includes('create') || action.includes('add')) return 'primary'
    return 'secondary'
}

const quickActions = [
    { label: 'Transfer', icon: 'mdi-bank-transfer' },
    { label: 'Request', icon: 'mdi-hand-coin-outline' },
    { label: 'History', icon: 'mdi-history' },
    { label: 'Top Up', icon: 'mdi-plus-circle-outline' },
]

// Group recent activity by date label
const groupedActivity = computed(() => {
    const groups = []
    let lastLabel = null
    for (const entry of recentActivity.value) {
        const label = getDateLabel(entry.created_at)
        if (label !== lastLabel) {
            groups.push({ type: 'header', label })
            lastLabel = label
        }
        groups.push({ type: 'entry', ...entry })
    }
    return groups
})
</script>

<template>
    <v-navigation-drawer
        permanent
        location="right"
        width="280"
        class="right-panel"
    >
        <div class="right-panel-inner">

            <!-- === A. Finance Score Card === -->
            <div class="finance-score-card pa-4 mb-3">
                <div class="d-flex align-center justify-space-between mb-1">
                    <span class="score-small-label">Finance Quality</span>
                    <v-icon size="16" color="rgba(255,255,255,0.6)">mdi-information-outline</v-icon>
                </div>
                <div class="score-title mb-3">Finance Score</div>

                <div class="d-flex align-center justify-space-between mb-2">
                    <span class="score-label-text">
                        <template v-if="loading">
                            <v-skeleton-loader type="text" width="80" class="skeleton-light" />
                        </template>
                        <template v-else>{{ scoreLabel }}</template>
                    </span>
                    <span class="score-pct">
                        <template v-if="loading">—</template>
                        <template v-else>{{ financeScore }}%</template>
                    </span>
                </div>

                <v-progress-linear
                    :model-value="loading ? 0 : financeScore"
                    color="rgba(255,255,255,0.9)"
                    bg-color="rgba(255,255,255,0.25)"
                    rounded
                    height="6"
                    class="mb-4"
                />

                <!-- Quick Actions -->
                <div class="d-flex justify-space-around">
                    <div
                        v-for="action in quickActions"
                        :key="action.label"
                        class="quick-action text-center"
                    >
                        <div class="quick-action-circle mb-1">
                            <v-icon size="16" color="primary">{{ action.icon }}</v-icon>
                        </div>
                        <div class="quick-action-label">{{ action.label }}</div>
                    </div>
                </div>
            </div>

            <!-- === B. Balance Details Card === -->
            <div class="balance-card pa-4 mb-3 mx-3" style="border-radius:12px">
                <div class="d-flex align-center justify-space-between mb-3">
                    <span class="balance-title">Balance Details</span>
                    <v-btn icon="mdi-dots-horizontal" variant="text" size="x-small" color="rgba(255,255,255,0.5)" />
                </div>

                <div class="balance-amount mb-1">
                    <template v-if="loading">
                        <v-skeleton-loader type="text" width="140" class="skeleton-dark" />
                    </template>
                    <template v-else>KES {{ fmt(wallets.total_liquidity) }}</template>
                </div>

                <div class="balance-trend mb-3">
                    Increase last Month
                    <v-icon size="12" class="ml-1">mdi-arrow-right</v-icon>
                    <span class="ml-1" style="color:#4DB6AC;font-weight:600">+3.5%</span>
                </div>

                <div class="balance-account">Account No: NIALINK-ADMIN</div>
            </div>

            <!-- === C. Recent Activity === -->
            <div class="px-3 pb-3">
                <div class="d-flex align-center justify-space-between mb-2 mt-1">
                    <span class="section-title">Recent Activity</span>
                    <v-btn icon="mdi-dots-horizontal" variant="text" size="x-small" class="text-medium-emphasis" />
                </div>

                <!-- Loading skeleton -->
                <template v-if="loading">
                    <div v-for="i in 4" :key="i" class="d-flex align-center gap-2 mb-3">
                        <v-skeleton-loader type="avatar" width="30" height="30" />
                        <div style="flex:1">
                            <v-skeleton-loader type="text" width="120" class="mb-1" />
                            <v-skeleton-loader type="text" width="70" />
                        </div>
                    </div>
                </template>

                <!-- Empty state -->
                <template v-else-if="recentActivity.length === 0">
                    <div class="text-center py-4 text-caption text-medium-emphasis">
                        <v-icon size="28" class="mb-1 d-block" color="grey-lighten-1">mdi-history</v-icon>
                        No recent activity
                    </div>
                </template>

                <!-- Activity list -->
                <template v-else>
                    <template v-for="item in groupedActivity" :key="item.type === 'header' ? item.label : item.id">
                        <!-- Date group header -->
                        <div v-if="item.type === 'header'" class="activity-date-label mb-1 mt-2">
                            {{ item.label }}
                        </div>

                        <!-- Activity entry -->
                        <div v-else class="d-flex align-start gap-2 mb-2 activity-entry">
                            <v-avatar
                                :color="actionColor(item.action)"
                                size="28"
                                style="flex-shrink:0;font-size:9px;font-weight:700;color:#fff;margin-top:1px"
                            >
                                {{ actionInitials(item.action) }}
                            </v-avatar>
                            <div style="flex:1;min-width:0">
                                <div class="activity-action">
                                    {{ item.action?.replace(/_/g, ' ') }}
                                    <span v-if="item.resource_type" class="activity-resource">
                                        {{ item.resource_type }} #{{ item.resource_id }}
                                    </span>
                                </div>
                                <div class="activity-time">{{ fmtTime(item.created_at) }}</div>
                            </div>
                        </div>
                    </template>
                </template>
            </div>

        </div>
    </v-navigation-drawer>
</template>

<style scoped>
.right-panel {
    border-left: 1px solid rgba(0, 0, 0, 0.07) !important;
    background: #FFFFFF !important;
}

.right-panel-inner {
    padding-top: 8px;
    height: 100%;
    overflow-y: auto;
}

/* ── Finance Score Card ── */
.finance-score-card {
    background: linear-gradient(135deg, #2B9D8F 0%, #1E7A6E 100%);
    color: #fff;
    margin: 12px;
    border-radius: 14px;
}

.score-small-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    color: rgba(255, 255, 255, 0.65);
    text-transform: uppercase;
}

.score-title {
    font-size: 17px;
    font-weight: 700;
    color: #fff;
}

.score-label-text {
    font-size: 20px;
    font-weight: 700;
    color: #fff;
}

.score-pct {
    font-size: 20px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.85);
}

.quick-action-circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.quick-action-circle:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.quick-action-label {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.85);
    font-weight: 500;
    white-space: nowrap;
}

/* ── Balance Card ── */
.balance-card {
    background: #1C3D5A;
    color: #fff;
}

.balance-title {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
}

.balance-amount {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.02em;
    line-height: 1.2;
}

.balance-trend {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.5);
    display: flex;
    align-items: center;
}

.balance-account {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.35);
    letter-spacing: 0.04em;
}

/* ── Recent Activity ── */
.section-title {
    font-size: 13px;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.75);
}

.activity-date-label {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: rgba(0, 0, 0, 0.3);
    text-transform: uppercase;
}

.activity-entry {
    cursor: default;
}

.activity-action {
    font-size: 11px;
    font-weight: 500;
    color: rgba(0, 0, 0, 0.75);
    line-height: 1.4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.activity-resource {
    color: rgba(0, 0, 0, 0.4);
    font-weight: 400;
}

.activity-time {
    font-size: 10px;
    color: rgba(0, 0, 0, 0.38);
    margin-top: 1px;
}

/* skeleton loaders light bg */
.skeleton-light :deep(.v-skeleton-loader__bone) {
    background: rgba(255, 255, 255, 0.3) !important;
}

.skeleton-dark :deep(.v-skeleton-loader__bone) {
    background: rgba(255, 255, 255, 0.15) !important;
}
</style>
