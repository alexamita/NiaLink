<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'
import StatCard from '../components/StatCard.vue'
import LineChart from '../components/LineChart.vue'

const stats        = ref({ total_liquidity: 0, total_revenue: 0, volume_24h: 0, pending_merchants: 0 })
const merchants    = ref([])
const transactions = ref([])
const loading      = ref(true)

async function load() {
    loading.value = true
    try {
        const [s, m, t] = await Promise.all([
            api.stats(),
            api.merchants(),
            api.transactions(),
        ])
        stats.value        = s ?? stats.value
        merchants.value    = Array.isArray(m) ? m : []
        transactions.value = Array.isArray(t) ? t : []
    } catch (e) {
        console.error('Dashboard load failed', e)
    } finally {
        loading.value = false
    }
}

onMounted(load)

const fmt = (n) =>
    Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 })

const fmtDate = (d) =>
    d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const statCards = computed(() => [
    { label: 'Total Liquidity', value: `KES ${fmt(stats.value.total_liquidity)}`, icon: 'mdi-wallet-outline' },
    { label: 'Total Revenue',   value: `KES ${fmt(stats.value.total_revenue)}`,   icon: 'mdi-trending-up' },
    { label: 'Volume (24h)',    value: `KES ${fmt(stats.value.volume_24h)}`,       icon: 'mdi-swap-horizontal' },
    { label: 'Pending KYC',    value: String(stats.value.pending_merchants ?? 0),  icon: 'mdi-store-clock-outline' },
])

const topMerchants = computed(() =>
    [...merchants.value]
        .filter(m => m.wallet)
        .sort((a, b) => (b.wallet?.balance ?? 0) - (a.wallet?.balance ?? 0))
        .slice(0, 5)
)

const merchantInitials = (name) =>
    name?.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('') ?? '?'

const merchantColors = ['#2B9D8F', '#4DB6AC', '#22C55E', '#3B82F6', '#F59E0B']

// ── Recent Transactions ─────────────────────────────────────────────────────
const txSearch = ref('')
const txStatus = ref('all')
const txPage   = ref(1)
const txPerPage = 8

const statusTabs = [
    { value: 'all',       label: 'All' },
    { value: 'completed', label: 'Completed' },
    { value: 'pending',   label: 'Pending' },
    { value: 'failed',    label: 'Failed' },
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

const paginatedTx  = computed(() => filteredTx.value.slice((txPage.value - 1) * txPerPage, txPage.value * txPerPage))
const totalTxPages = computed(() => Math.ceil(filteredTx.value.length / txPerPage))

const setStatus = (val) => { txStatus.value = val; txPage.value = 1 }

const typeColors = { p2m: '#3B82F6', p2p: '#8B5CF6' }
</script>

<template>
    <div>

        <!-- ── Page Header ── -->
        <div class="page-header mb-5">
            <div>
                <h1 class="page-title">Overview</h1>
                <p class="page-subtitle">Welcome back — here's your platform snapshot.</p>
            </div>
            <button class="refresh-btn" :disabled="loading" @click="load">
                <v-icon size="14" :class="loading ? 'spin' : ''">mdi-refresh</v-icon>
                Refresh
            </button>
        </div>

        <!-- ── Stat Cards ── -->
        <v-row class="mb-4" dense>
            <v-col v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3">
                <v-card variant="outlined" rounded="lg" class="pa-4 stat-card" v-if="loading">
                    <v-skeleton-loader type="heading" class="mb-2" />
                    <v-skeleton-loader type="text" width="60%" />
                </v-card>
                <StatCard
                    v-else
                    :label="card.label"
                    :value="card.value"
                    :icon="card.icon"
                    :trend="null"
                    :trend-up="true"
                />
            </v-col>
        </v-row>

        <!-- ── Chart + Top Merchants ── -->
        <v-row class="mb-4" dense align="stretch">

            <!-- Line chart -->
            <v-col cols="12" md="7" class="d-flex flex-column">
                <v-card variant="outlined" rounded="lg" class="pa-4 section-card" style="height:300px" v-if="loading">
                    <v-skeleton-loader type="image" height="268" />
                </v-card>
                <LineChart v-else :transactions="transactions" style="flex:1;min-height:300px" />
            </v-col>

            <!-- Top Merchants -->
            <v-col cols="12" md="5" class="d-flex flex-column">
                <v-card variant="outlined" rounded="lg" class="pa-4 section-card" style="flex:1">

                    <div class="card-header mb-3">
                        <div>
                            <div class="card-title">Top Merchants</div>
                            <div class="card-subtitle">Ranked by wallet balance</div>
                        </div>
                    </div>

                    <!-- Skeleton -->
                    <template v-if="loading">
                        <div v-for="i in 5" :key="i" class="d-flex align-center gap-3 mb-3">
                            <v-skeleton-loader type="avatar" width="32" height="32" />
                            <div style="flex:1">
                                <v-skeleton-loader type="text" width="120" class="mb-1" />
                                <v-skeleton-loader type="text" width="70" />
                            </div>
                            <v-skeleton-loader type="text" width="60" />
                        </div>
                    </template>

                    <!-- Empty -->
                    <template v-else-if="topMerchants.length === 0">
                        <div class="empty-state">
                            <v-icon size="32" color="grey-lighten-2" class="mb-2">mdi-store-off-outline</v-icon>
                            <div class="text-body-2 text-medium-emphasis">No merchant data yet</div>
                        </div>
                    </template>

                    <!-- List -->
                    <template v-else>
                        <div
                            v-for="(m, i) in topMerchants"
                            :key="m.id"
                            class="merchant-row"
                            :class="i < topMerchants.length - 1 ? 'merchant-row--divider' : ''"
                        >
                            <span class="rank-num">{{ i + 1 }}</span>

                            <v-avatar
                                :color="merchantColors[i % merchantColors.length]"
                                size="30"
                                style="font-size:10px;font-weight:700;color:#fff;flex-shrink:0"
                            >
                                {{ merchantInitials(m.business_name) }}
                            </v-avatar>

                            <div class="merchant-info">
                                <div class="merchant-name">{{ m.business_name }}</div>
                                <div class="merchant-meta">
                                    {{ m.terminals?.length ?? 0 }} terminal{{ (m.terminals?.length ?? 0) !== 1 ? 's' : '' }}
                                </div>
                            </div>

                            <div class="merchant-right">
                                <div class="merchant-balance">KES {{ fmt(m.wallet?.balance) }}</div>
                                <div class="d-flex align-center gap-1 justify-end mt-1">
                                    <span class="status-dot" :class="m.status"></span>
                                    <span class="status-label">{{ m.status }}</span>
                                </div>
                            </div>
                        </div>
                    </template>

                </v-card>
            </v-col>
        </v-row>

        <!-- ── Recent Transactions ── -->
        <v-card variant="outlined" rounded="lg" class="section-card">

            <!-- Header -->
            <div class="tx-card-header">
                <div>
                    <div class="card-title">Recent Transactions</div>
                    <div class="card-subtitle">All platform transactions</div>
                </div>
                <v-text-field
                    v-model="txSearch"
                    prepend-inner-icon="mdi-magnify"
                    placeholder="Search name, reference…"
                    variant="solo-filled"
                    flat
                    density="compact"
                    hide-details
                    rounded="lg"
                    style="max-width:240px"
                    bg-color="rgba(0,0,0,0.04)"
                    @update:model-value="txPage = 1"
                />
            </div>

            <!-- Filter pills -->
            <div class="tx-filter-row">
                <button
                    v-for="tab in statusTabs"
                    :key="tab.value"
                    class="filter-pill"
                    :class="txStatus === tab.value ? 'filter-pill--active' : ''"
                    @click="setStatus(tab.value)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <v-divider style="opacity:0.4" />

            <!-- Skeleton -->
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
                            <th>User / Merchant</th>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Fee</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="paginatedTx.length === 0">
                            <td colspan="7" class="empty-cell">
                                <v-icon size="36" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-database-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No transactions found</div>
                                <div v-if="txSearch || txStatus !== 'all'" class="text-caption text-medium-emphasis mt-1">
                                    Try clearing your search or filter
                                </div>
                            </td>
                        </tr>

                        <tr v-for="tx in paginatedTx" :key="tx.id" class="tx-row">
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar
                                        :color="typeColors[tx.type] ?? '#6B7280'"
                                        size="26"
                                        style="font-size:8px;font-weight:700;color:#fff;flex-shrink:0"
                                        variant="tonal"
                                    >
                                        {{ tx.type?.toUpperCase() ?? '??' }}
                                    </v-avatar>
                                    <div>
                                        <div class="row-name">{{ tx.user?.name ?? '—' }}</div>
                                        <div class="row-sub">{{ tx.merchant?.business_name ?? '—' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="row-sub">{{ fmtDate(tx.created_at) }}</td>
                            <td>
                                <code class="ref-code">{{ tx.reference ?? '—' }}</code>
                            </td>
                            <td>
                                <span
                                    class="type-badge"
                                    :style="`background:${(typeColors[tx.type] ?? '#6B7280')}18;color:${typeColors[tx.type] ?? '#6B7280'}`"
                                >
                                    {{ tx.type?.toUpperCase() ?? '—' }}
                                </span>
                            </td>
                            <td class="text-right font-weight-bold" style="font-size:13px">
                                KES {{ fmt(tx.amount) }}
                            </td>
                            <td class="text-right row-sub">KES {{ fmt(tx.fee) }}</td>
                            <td>
                                <div class="status-cell">
                                    <span class="status-dot" :class="tx.status"></span>
                                    <span class="status-label">{{ tx.status }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Pagination -->
                <div class="tx-pagination">
                    <div class="text-caption text-medium-emphasis">
                        <template v-if="filteredTx.length > 0">
                            {{ ((txPage - 1) * txPerPage) + 1 }}–{{ Math.min(txPage * txPerPage, filteredTx.length) }}
                            of {{ filteredTx.length }} transactions
                        </template>
                        <template v-else>0 transactions</template>
                    </div>
                    <div class="d-flex gap-1" v-if="totalTxPages > 1">
                        <button class="pg-btn" :disabled="txPage === 1" @click="txPage--">‹</button>
                        <button
                            v-for="p in totalTxPages"
                            :key="p"
                            class="pg-btn"
                            :class="p === txPage ? 'pg-btn--active' : ''"
                            @click="txPage = p"
                        >{{ p }}</button>
                        <button class="pg-btn" :disabled="txPage === totalTxPages" @click="txPage++">›</button>
                    </div>
                </div>
            </template>

        </v-card>

    </div>
</template>

<style scoped>
/* ── Page Header ── */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.page-title {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.25;
    color: rgba(0, 0, 0, 0.85);
}

.page-subtitle {
    font-size: 12.5px;
    color: rgba(0, 0, 0, 0.42);
    margin-top: 2px;
}

.refresh-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 7px;
    background: #fff;
    cursor: pointer;
    color: rgba(0, 0, 0, 0.6);
    font-family: inherit;
    transition: background 0.12s;
    flex-shrink: 0;
}

.refresh-btn:hover:not(:disabled) { background: rgba(0, 0, 0, 0.04); }
.refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@keyframes spin { to { transform: rotate(360deg); } }
.spin { animation: spin 0.7s linear infinite; }

/* ── Stat Cards ── */
.stat-card {
    background: #fff;
    border-color: rgba(0, 0, 0, 0.08) !important;
}

/* ── Shared card styles ── */
.section-card {
    background: #fff;
    border-color: rgba(0, 0, 0, 0.08) !important;
}

.card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
}

.card-title {
    font-size: 13px;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.82);
    line-height: 1.3;
}

.card-subtitle {
    font-size: 11.5px;
    color: rgba(0, 0, 0, 0.4);
    margin-top: 1px;
}

/* ── Merchant list ── */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 0;
    text-align: center;
}

.merchant-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    transition: background 0.1s;
}

.merchant-row--divider {
    border-bottom: 1px solid rgba(0, 0, 0, 0.055);
}

.rank-num {
    font-size: 10.5px;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.22);
    width: 12px;
    text-align: right;
    flex-shrink: 0;
}

.merchant-info {
    flex: 1;
    min-width: 0;
}

.merchant-name {
    font-size: 12.5px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.78);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
}

.merchant-meta {
    font-size: 11px;
    color: rgba(0, 0, 0, 0.4);
    line-height: 1.3;
}

.merchant-right {
    text-align: right;
    flex-shrink: 0;
}

.merchant-balance {
    font-size: 12.5px;
    font-weight: 700;
    color: rgba(0, 0, 0, 0.8);
    white-space: nowrap;
}

/* ── Status dot ── */
.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-block;
}

.status-dot.active, .status-dot.completed { background: #22C55E; }
.status-dot.pending                        { background: #F59E0B; }
.status-dot.suspended, .status-dot.failed, .status-dot.inactive { background: #EF4444; }

.status-label {
    font-size: 11px;
    font-weight: 500;
    color: rgba(0, 0, 0, 0.55);
    text-transform: capitalize;
}

.status-cell {
    display: flex;
    align-items: center;
    gap: 5px;
    justify-content: center;
}

/* ── Transaction card header ── */
.tx-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 10px;
    gap: 12px;
}

/* ── Filter pills row ── */
.tx-filter-row {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 16px 10px;
}

.filter-pill {
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 500;
    border: none;
    background: none;
    border-radius: 20px;
    cursor: pointer;
    color: rgba(0, 0, 0, 0.48);
    font-family: inherit;
    transition: background 0.13s, color 0.13s;
    white-space: nowrap;
}

.filter-pill:hover {
    background: rgba(0, 0, 0, 0.05);
    color: rgba(0, 0, 0, 0.72);
}

.filter-pill--active {
    background: rgba(43, 157, 143, 0.11);
    color: #2B9D8F;
    font-weight: 600;
}

/* ── Transaction table ── */
.tx-table :deep(thead tr th) {
    font-size: 10.5px !important;
    font-weight: 600 !important;
    letter-spacing: 0.04em !important;
    text-transform: uppercase;
    color: rgba(0, 0, 0, 0.4) !important;
    padding: 9px 14px !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.07) !important;
    background: rgba(0, 0, 0, 0.013);
    white-space: nowrap;
}

.tx-table :deep(tbody tr td) {
    font-size: 13px !important;
    padding: 8px 14px !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.042) !important;
    vertical-align: middle;
}

.tx-row { transition: background 0.1s; }
.tx-row:hover { background: rgba(43, 157, 143, 0.03) !important; }

.row-name {
    font-size: 12.5px;
    font-weight: 500;
    color: rgba(0, 0, 0, 0.78);
    line-height: 1.3;
}

.row-sub {
    font-size: 11.5px;
    color: rgba(0, 0, 0, 0.4);
    line-height: 1.3;
}

.ref-code {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    background: rgba(0, 0, 0, 0.05);
    padding: 2px 6px;
    border-radius: 4px;
    color: rgba(0, 0, 0, 0.6);
    letter-spacing: 0.02em;
}

.type-badge {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    display: inline-block;
    letter-spacing: 0.03em;
}

.empty-cell {
    text-align: center;
    padding: 40px 16px !important;
}

/* ── Pagination ── */
.tx-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    border-top: 1px solid rgba(0, 0, 0, 0.06);
}

.pg-btn {
    min-width: 28px;
    height: 28px;
    padding: 0 7px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    color: rgba(0, 0, 0, 0.6);
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.1s;
}

.pg-btn:hover:not(:disabled) { background: rgba(0, 0, 0, 0.05); }
.pg-btn--active { background: #2B9D8F !important; color: #fff !important; border-color: #2B9D8F !important; }
.pg-btn:disabled { opacity: 0.35; cursor: not-allowed; }
</style>
