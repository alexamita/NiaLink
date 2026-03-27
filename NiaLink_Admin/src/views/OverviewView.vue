<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'
import StatCard from '../components/StatCard.vue'
import LineChart from '../components/LineChart.vue'

// ── State ──────────────────────────────────────────────────────────────────
const stats = ref({ total_liquidity: 0, total_revenue: 0, volume_24h: 0, pending_merchants: 0 })
const merchants = ref([])
const transactions = ref([])
const loading = ref(true)

// ── Fetch ──────────────────────────────────────────────────────────────────
onMounted(async () => {
    try {
        const [s, m, t] = await Promise.all([
            api.stats(),
            api.merchants(),
            api.transactions(),
        ])
        stats.value = s ?? stats.value
        merchants.value = Array.isArray(m) ? m : []
        transactions.value = Array.isArray(t) ? t : []
    } catch (e) {
        console.error('Dashboard load failed', e)
    } finally {
        loading.value = false
    }
})

// ── Formatters ─────────────────────────────────────────────────────────────
const fmt = (n) =>
    Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 })

const fmtDate = (d) =>
    d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '-'

// ── Stat cards ─────────────────────────────────────────────────────────────
const statCards = computed(() => [
    {
        label: 'Total Liquidity',
        value: `KES ${fmt(stats.value.total_liquidity)}`,
        icon: 'mdi-wallet-outline',
        trend: null,
        trendUp: true,
    },
    {
        label: 'Total Revenue',
        value: `KES ${fmt(stats.value.total_revenue)}`,
        icon: 'mdi-trending-up',
        trend: null,
        trendUp: true,
    },
    {
        label: 'Volume (24h)',
        value: `KES ${fmt(stats.value.volume_24h)}`,
        icon: 'mdi-swap-horizontal',
        trend: null,
        trendUp: true,
    },
    {
        label: 'Pending KYC',
        value: String(stats.value.pending_merchants ?? 0),
        icon: 'mdi-store-clock-outline',
        trend: null,
        trendUp: false,
    },
])

// ── Top merchants by wallet balance ────────────────────────────────────────
const topMerchants = computed(() =>
    [...merchants.value]
        .filter(m => m.wallet)
        .sort((a, b) => (b.wallet?.balance ?? 0) - (a.wallet?.balance ?? 0))
        .slice(0, 5)
)

const merchantInitials = (name) => {
    if (!name) return '?'
    return name.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('')
}

const merchantColors = ['#2B9D8F', '#4DB6AC', '#22C55E', '#3B82F6', '#F59E0B']

// ── Transaction table ──────────────────────────────────────────────────────
const txSearch = ref('')
const txStatus = ref('all')
const txPage = ref(1)
const txPerPage = 8

const statusTabs = [
    { value: 'all', label: 'All' },
    { value: 'completed', label: 'Completed' },
    { value: 'pending', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
]

const filteredTx = computed(() => {
    let list = transactions.value
    if (txStatus.value !== 'all') list = list.filter(t => t.status === txStatus.value)
    if (txSearch.value) {
        const q = txSearch.value.toLowerCase()
        list = list.filter(t =>
            t.user?.name?.toLowerCase().includes(q) ||
            t.merchant?.business_name?.toLowerCase().includes(q) ||
            t.reference?.toLowerCase().includes(q)
        )
    }
    return list
})

const paginatedTx = computed(() => {
    const start = (txPage.value - 1) * txPerPage
    return filteredTx.value.slice(start, start + txPerPage)
})

const totalPages = computed(() => Math.ceil(filteredTx.value.length / txPerPage))

const statusColor = (s) => ({ completed: 'success', pending: 'warning', failed: 'error' }[s] ?? 'default')
const typeColor = (t) => ({ p2m: 'primary', p2p: 'info' }[t] ?? 'default')

// Reset to page 1 when filter changes
const setStatus = (val) => {
    txStatus.value = val
    txPage.value = 1
}
</script>

<template>
    <div>

        <!-- ── Page Header ── -->
        <div class="d-flex align-center justify-space-between mb-5">
            <div>
                <h1 class="text-h5 font-weight-bold" style="letter-spacing:-0.02em">Dashboard</h1>
                <div class="text-caption text-medium-emphasis mt-0.5">
                    Welcome back, Super Admin — here's your system overview
                </div>
            </div>
            <v-btn
                prepend-icon="mdi-refresh"
                variant="tonal"
                color="primary"
                size="small"
                rounded="lg"
                :loading="loading"
            >
                Refresh
            </v-btn>
        </div>

        <!-- ── Stat Cards ── -->
        <v-row class="mb-5" dense>
            <v-col
                v-for="card in statCards"
                :key="card.label"
                cols="12"
                sm="6"
                lg="3"
            >
                <template v-if="loading">
                    <v-card variant="outlined" rounded="lg" class="pa-4">
                        <v-skeleton-loader type="article" />
                    </v-card>
                </template>
                <StatCard
                    v-else
                    :label="card.label"
                    :value="card.value"
                    :icon="card.icon"
                    :trend="card.trend"
                    :trend-up="card.trendUp"
                />
            </v-col>
        </v-row>

        <!-- ── Charts + Top Merchants Row ── -->
        <v-row class="mb-5" dense align="stretch">

            <!-- Cash Flow Chart ~60% -->
            <v-col cols="12" md="7" class="d-flex flex-column">
                <template v-if="loading">
                    <v-card variant="outlined" rounded="lg" class="pa-4" style="height:320px">
                        <v-skeleton-loader type="image" />
                    </v-card>
                </template>
                <LineChart
                    v-else
                    :transactions="transactions"
                    style="flex:1;min-height:320px"
                />
            </v-col>

            <!-- Top Merchants ~40% -->
            <v-col cols="12" md="5" class="d-flex flex-column">
                <v-card variant="outlined" rounded="lg" class="pa-4 merchants-card" style="flex:1">
                    <div class="d-flex align-center justify-space-between mb-4">
                        <div>
                            <div class="text-body-2 font-weight-bold">Top Merchants by Payouts</div>
                            <div class="text-caption text-medium-emphasis">Ranked by wallet balance</div>
                        </div>
                        <v-btn icon="mdi-dots-horizontal" variant="text" size="x-small" class="text-medium-emphasis" />
                    </div>

                    <!-- Loading skeleton -->
                    <template v-if="loading">
                        <div v-for="i in 5" :key="i" class="d-flex align-center gap-3 mb-3">
                            <v-skeleton-loader type="avatar" width="36" height="36" />
                            <div style="flex:1">
                                <v-skeleton-loader type="text" width="120" class="mb-1" />
                                <v-skeleton-loader type="text" width="80" />
                            </div>
                            <v-skeleton-loader type="text" width="70" />
                        </div>
                    </template>

                    <!-- Empty state -->
                    <template v-else-if="topMerchants.length === 0">
                        <div class="d-flex flex-column align-center justify-center py-6 text-center">
                            <v-icon size="36" color="grey-lighten-2" class="mb-2">mdi-store-off-outline</v-icon>
                            <div class="text-body-2 text-medium-emphasis">No merchant data yet</div>
                        </div>
                    </template>

                    <!-- Merchant list -->
                    <template v-else>
                        <div
                            v-for="(m, i) in topMerchants"
                            :key="m.id"
                            class="d-flex align-center gap-3 merchant-row"
                            :class="i < topMerchants.length - 1 ? 'border-b pb-3 mb-3' : ''"
                        >
                            <!-- Rank + Avatar -->
                            <div class="d-flex align-center gap-2">
                                <span class="rank-num">{{ i + 1 }}</span>
                                <v-avatar
                                    :color="merchantColors[i % merchantColors.length]"
                                    size="34"
                                    style="font-size:11px;font-weight:700;color:#fff"
                                >
                                    {{ merchantInitials(m.business_name) }}
                                </v-avatar>
                            </div>

                            <!-- Info -->
                            <div style="flex:1;min-width:0">
                                <div
                                    class="text-body-2 font-weight-medium"
                                    style="line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                >
                                    {{ m.business_name }}
                                </div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ m.terminals?.length ?? 0 }} terminal{{ (m.terminals?.length ?? 0) !== 1 ? 's' : '' }}
                                </div>
                            </div>

                            <!-- Balance -->
                            <div class="text-right">
                                <div class="text-body-2 font-weight-bold" style="white-space:nowrap">
                                    KES {{ fmt(m.wallet?.balance) }}
                                </div>
                                <v-chip
                                    size="x-small"
                                    :color="m.status === 'active' ? 'success' : m.status === 'pending' ? 'warning' : 'error'"
                                    variant="tonal"
                                    class="mt-0.5"
                                >
                                    {{ m.status }}
                                </v-chip>
                            </div>
                        </div>
                    </template>

                </v-card>
            </v-col>
        </v-row>

        <!-- ── Recent Transactions ── -->
        <v-card variant="outlined" rounded="lg" class="transactions-card">

            <!-- Card header -->
            <div class="d-flex align-center justify-space-between px-4 pt-4 pb-2">
                <div>
                    <div class="text-body-1 font-weight-bold">Recent Transactions</div>
                    <div class="text-caption text-medium-emphasis">All platform transactions</div>
                </div>
                <v-text-field
                    v-model="txSearch"
                    prepend-inner-icon="mdi-magnify"
                    placeholder="Search by name, reference..."
                    variant="solo-filled"
                    flat
                    density="compact"
                    hide-details
                    rounded="lg"
                    style="max-width:260px"
                    bg-color="rgba(0,0,0,0.04)"
                    @update:model-value="txPage = 1"
                />
            </div>

            <!-- Status filter tabs -->
            <div class="px-4">
                <div class="d-flex gap-1 mb-2">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.value"
                        class="status-tab"
                        :class="txStatus === tab.value ? 'status-tab--active' : ''"
                        @click="setStatus(tab.value)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
                <v-divider />
            </div>

            <!-- Loading skeleton -->
            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 5" :key="i" type="list-item-avatar" class="mb-1" />
                </div>
            </template>

            <!-- Table -->
            <template v-else>
                <v-table density="compact" class="tx-table">
                    <thead>
                        <tr>
                            <th class="text-caption font-weight-bold">Name / Merchant</th>
                            <th class="text-caption font-weight-bold">Date</th>
                            <th class="text-caption font-weight-bold">Reference</th>
                            <th class="text-caption font-weight-bold">Type</th>
                            <th class="text-caption font-weight-bold text-right">Amount</th>
                            <th class="text-caption font-weight-bold text-right">Fee</th>
                            <th class="text-caption font-weight-bold text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>

                        <!-- Empty state -->
                        <tr v-if="paginatedTx.length === 0">
                            <td colspan="7" class="text-center py-10">
                                <v-icon size="40" color="grey-lighten-2" class="mb-2 d-block mx-auto">
                                    mdi-database-off-outline
                                </v-icon>
                                <div class="text-body-2 text-medium-emphasis">No transactions found</div>
                                <div v-if="txSearch || txStatus !== 'all'" class="text-caption text-medium-emphasis mt-1">
                                    Try clearing your search or filter
                                </div>
                            </td>
                        </tr>

                        <!-- Data rows -->
                        <tr v-for="tx in paginatedTx" :key="tx.id" class="tx-row">
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar
                                        :color="tx.type === 'p2m' ? 'primary' : 'info'"
                                        size="28"
                                        style="font-size:9px;font-weight:700;color:#fff;flex-shrink:0"
                                        variant="tonal"
                                    >
                                        {{ tx.type?.toUpperCase() ?? '??' }}
                                    </v-avatar>
                                    <div>
                                        <div class="text-body-2 font-weight-medium" style="line-height:1.3">
                                            {{ tx.user?.name ?? '—' }}
                                        </div>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ tx.merchant?.business_name ?? '—' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(tx.created_at) }}</td>
                            <td>
                                <code class="reference-code">{{ tx.reference ?? '—' }}</code>
                            </td>
                            <td>
                                <v-chip
                                    size="x-small"
                                    :color="typeColor(tx.type)"
                                    variant="tonal"
                                >
                                    {{ tx.type?.toUpperCase() ?? '—' }}
                                </v-chip>
                            </td>
                            <td class="text-right font-weight-bold text-body-2">
                                KES {{ fmt(tx.amount) }}
                            </td>
                            <td class="text-right text-caption text-medium-emphasis">
                                KES {{ fmt(tx.fee) }}
                            </td>
                            <td class="text-center">
                                <v-chip
                                    size="x-small"
                                    :color="statusColor(tx.status)"
                                    variant="tonal"
                                    class="font-weight-bold"
                                >
                                    {{ tx.status }}
                                </v-chip>
                            </td>
                        </tr>

                    </tbody>
                </v-table>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="d-flex align-center justify-space-between px-4 py-3 border-t">
                    <div class="text-caption text-medium-emphasis">
                        Showing {{ ((txPage - 1) * txPerPage) + 1 }}–{{ Math.min(txPage * txPerPage, filteredTx.length) }}
                        of {{ filteredTx.length }} transactions
                    </div>
                    <v-pagination
                        v-model="txPage"
                        :length="totalPages"
                        density="compact"
                        color="primary"
                        :total-visible="5"
                        rounded="circle"
                    />
                </div>
                <div v-else-if="filteredTx.length > 0" class="px-4 py-2 border-t">
                    <div class="text-caption text-medium-emphasis">
                        {{ filteredTx.length }} transaction{{ filteredTx.length !== 1 ? 's' : '' }}
                    </div>
                </div>

            </template>

        </v-card>

    </div>
</template>

<style scoped>
.border-b {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.border-t {
    border-top: 1px solid rgba(0, 0, 0, 0.06);
}

.merchants-card {
    background: #fff;
    border-color: rgba(0, 0, 0, 0.08) !important;
}

.rank-num {
    font-size: 11px;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.25);
    width: 14px;
    text-align: right;
    flex-shrink: 0;
}

.merchant-row {
    transition: background 0.1s ease;
}

.merchant-row:hover {
    background: rgba(43, 157, 143, 0.03);
    border-radius: 8px;
}

.transactions-card {
    background: #fff;
    border-color: rgba(0, 0, 0, 0.08) !important;
}

/* Status filter tabs */
.status-tab {
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 500;
    border: none;
    background: none;
    border-radius: 20px;
    cursor: pointer;
    color: rgba(0, 0, 0, 0.5);
    font-family: inherit;
    transition: background 0.15s ease, color 0.15s ease;
    white-space: nowrap;
}

.status-tab:hover {
    background: rgba(0, 0, 0, 0.05);
    color: rgba(0, 0, 0, 0.75);
}

.status-tab--active {
    background: rgba(43, 157, 143, 0.12) !important;
    color: #2B9D8F !important;
    font-weight: 600;
}

/* Transaction table */
.tx-table :deep(thead tr th) {
    color: rgba(0, 0, 0, 0.45) !important;
    font-weight: 600 !important;
    font-size: 11px !important;
    letter-spacing: 0.03em !important;
    text-transform: uppercase;
    padding-top: 10px !important;
    padding-bottom: 10px !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.07) !important;
    background: rgba(0, 0, 0, 0.015);
}

.tx-table :deep(tbody tr td) {
    font-size: 13px !important;
    padding: 8px 16px !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.045) !important;
    vertical-align: middle;
}

.tx-row {
    transition: background 0.1s ease;
}

.tx-row:hover {
    background: rgba(43, 157, 143, 0.04) !important;
}

.reference-code {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    background: rgba(0, 0, 0, 0.05);
    padding: 2px 6px;
    border-radius: 4px;
    color: rgba(0, 0, 0, 0.65);
    letter-spacing: 0.02em;
}
</style>
