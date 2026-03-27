<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

// ── State ──────────────────────────────────────────────────────────────────
const transactions = ref([])
const loading      = ref(true)
const search       = ref('')
const statusFilter = ref('all')
const typeFilter   = ref('all')
const page         = ref(1)
const perPage      = ref(15)
const selectedIds  = ref([])
const activeView   = ref('table')

const statusTabs = [
    { value: 'all',       label: 'All' },
    { value: 'completed', label: 'Completed' },
    { value: 'pending',   label: 'Pending' },
    { value: 'failed',    label: 'Failed' },
]
const perPageOptions = [10, 15, 25, 50]

// ── Load ────────────────────────────────────────────────────────────────────
const load = async () => {
    loading.value = true
    selectedIds.value = []
    try {
        const params = {}
        if (statusFilter.value !== 'all') params.status = statusFilter.value
        if (typeFilter.value !== 'all')   params.type   = typeFilter.value
        const data = await api.transactions(params)
        transactions.value = Array.isArray(data) ? data : []
    } catch (e) {
        console.error('Transactions load failed', e)
    } finally {
        loading.value = false
    }
}

onMounted(load)

const setStatus = (val) => { statusFilter.value = val; page.value = 1; load() }
const onTypeChange = () => { page.value = 1; load() }

// ── Filter + Paginate ───────────────────────────────────────────────────────
const filtered = computed(() => {
    if (!search.value) return transactions.value
    const q = search.value.toLowerCase()
    return transactions.value.filter(t =>
        t.user?.name?.toLowerCase().includes(q) ||
        t.merchant?.business_name?.toLowerCase().includes(q) ||
        t.reference?.toLowerCase().includes(q)
    )
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage.value))
const paginated  = computed(() => {
    const start = (page.value - 1) * perPage.value
    return filtered.value.slice(start, start + perPage.value)
})
const startRow = computed(() => filtered.value.length === 0 ? 0 : (page.value - 1) * perPage.value + 1)
const endRow   = computed(() => Math.min(page.value * perPage.value, filtered.value.length))

// ── Totals ──────────────────────────────────────────────────────────────────
const totals = computed(() => ({
    volume:    transactions.value.filter(t => t.status === 'completed').reduce((s, t) => s + Number(t.amount || 0), 0),
    fees:      transactions.value.filter(t => t.status === 'completed').reduce((s, t) => s + Number(t.fee || 0), 0),
    completed: transactions.value.filter(t => t.status === 'completed').length,
    failed:    transactions.value.filter(t => t.status === 'failed').length,
}))

// ── Select-all ──────────────────────────────────────────────────────────────
const allSelected = computed(() =>
    paginated.value.length > 0 && paginated.value.every(t => selectedIds.value.includes(t.id))
)
function toggleAll() {
    if (allSelected.value) {
        selectedIds.value = selectedIds.value.filter(id => !paginated.value.find(t => t.id === id))
    } else {
        paginated.value.forEach(t => { if (!selectedIds.value.includes(t.id)) selectedIds.value.push(t.id) })
    }
}
function toggleRow(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx === -1) selectedIds.value.push(id)
    else selectedIds.value.splice(idx, 1)
}

// ── Formatters ──────────────────────────────────────────────────────────────
const fmt     = (n) => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const typeColors = { p2m: '#3B82F6', p2p: '#8B5CF6' }
</script>

<template>
    <div>

        <!-- ── Page Header + Summary Cards ── -->
        <div class="page-header mb-4">
            <div>
                <div class="d-flex align-center gap-2">
                    <h1 class="page-title">Transactions</h1>
                    <span class="count-badge">{{ filtered.length }}</span>
                </div>
                <div class="page-subtitle">Full platform transaction ledger.</div>
            </div>
        </div>

        <!-- Summary stat cards -->
        <v-row class="mb-4" dense>
            <v-col cols="6" sm="3">
                <v-card variant="outlined" rounded="lg" class="pa-3 summary-card">
                    <div class="text-caption text-medium-emphasis mb-1">Completed Volume</div>
                    <div class="text-h6 font-weight-bold">KES {{ fmt(totals.volume) }}</div>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="outlined" rounded="lg" class="pa-3 summary-card">
                    <div class="text-caption text-medium-emphasis mb-1">Fees Collected</div>
                    <div class="text-h6 font-weight-bold" style="color:#2B9D8F">KES {{ fmt(totals.fees) }}</div>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="outlined" rounded="lg" class="pa-3 summary-card">
                    <div class="text-caption text-medium-emphasis mb-1">Completed</div>
                    <div class="text-h6 font-weight-bold" style="color:#22C55E">{{ totals.completed }}</div>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="outlined" rounded="lg" class="pa-3 summary-card">
                    <div class="text-caption text-medium-emphasis mb-1">Failed</div>
                    <div class="text-h6 font-weight-bold" style="color:#EF4444">{{ totals.failed }}</div>
                </v-card>
            </v-col>
        </v-row>

        <!-- ── Main Card ── -->
        <v-card variant="outlined" rounded="lg" class="page-card">

            <!-- Controls row -->
            <div class="controls-row">
                <div class="view-toggle">
                    <button class="view-btn" :class="activeView === 'table' ? 'view-btn--active' : ''" @click="activeView = 'table'">
                        <v-icon size="13">mdi-table</v-icon> Table
                    </button>
                    <button class="view-btn" :class="activeView === 'list' ? 'view-btn--active' : ''" @click="activeView = 'list'">
                        <v-icon size="13">mdi-format-list-bulleted</v-icon> List
                    </button>
                </div>
                <div class="controls-right">
                    <v-text-field
                        v-model="search"
                        prepend-inner-icon="mdi-magnify"
                        placeholder="Search transactions..."
                        variant="solo-filled" flat density="compact" hide-details rounded="lg"
                        style="max-width:200px" bg-color="rgba(0,0,0,0.04)"
                        @update:model-value="page = 1"
                    />
                    <button class="ctrl-btn" @click="load"><v-icon size="14">mdi-refresh</v-icon></button>
                    <button class="ctrl-btn"><v-icon size="14">mdi-export-variant</v-icon> Export</button>
                </div>
            </div>

            <!-- Filter pills -->
            <div class="filter-row">
                <button
                    v-for="tab in statusTabs" :key="tab.value"
                    class="filter-pill" :class="statusFilter === tab.value ? 'filter-pill--active' : ''"
                    @click="setStatus(tab.value)"
                >
                    {{ tab.label }} <v-icon size="12">mdi-chevron-down</v-icon>
                </button>
                <!-- Type filter -->
                <button
                    class="filter-pill" :class="typeFilter !== 'all' ? 'filter-pill--active' : ''"
                    @click="typeFilter = typeFilter === 'p2m' ? 'p2p' : typeFilter === 'p2p' ? 'all' : 'p2m'; onTypeChange()"
                >
                    Type: {{ typeFilter === 'all' ? 'All' : typeFilter.toUpperCase() }}
                    <v-icon size="12">mdi-chevron-down</v-icon>
                </button>
                <button class="filter-pill filter-pill--add">
                    <v-icon size="12">mdi-plus</v-icon> Add filter
                </button>
            </div>

            <v-divider style="opacity:0.4" />

            <!-- Loading -->
            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 8" :key="i" type="list-item-avatar" class="mb-1" />
                </div>
            </template>

            <!-- Table -->
            <template v-else>
                <v-table density="compact" class="data-table">
                    <thead>
                        <tr>
                            <th style="width:40px;padding-left:16px">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" class="row-check" />
                            </th>
                            <th>User / Merchant</th>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Fee</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="paginated.length === 0">
                            <td colspan="8" class="text-center py-12">
                                <v-icon size="38" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-database-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No transactions found</div>
                            </td>
                        </tr>
                        <tr v-for="tx in paginated" :key="tx.id" class="data-row" :class="selectedIds.includes(tx.id) ? 'data-row--selected' : ''">
                            <td style="padding-left:16px">
                                <input type="checkbox" :checked="selectedIds.includes(tx.id)" @change="toggleRow(tx.id)" class="row-check" />
                            </td>
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar
                                        :color="typeColors[tx.type] ?? '#6B7280'"
                                        size="28"
                                        style="font-size:9px;font-weight:700;color:#fff;flex-shrink:0"
                                        variant="tonal"
                                    >
                                        {{ tx.type?.toUpperCase() ?? '??' }}
                                    </v-avatar>
                                    <div>
                                        <div class="text-body-2 font-weight-medium" style="line-height:1.3">{{ tx.user?.name ?? '—' }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ tx.merchant?.business_name ?? '—' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td><code class="ref-code">{{ tx.reference ?? '—' }}</code></td>
                            <td>
                                <span class="type-badge" :style="`background:${(typeColors[tx.type] ?? '#6B7280')}18;color:${typeColors[tx.type] ?? '#6B7280'}`">
                                    {{ tx.type?.toUpperCase() ?? '—' }}
                                </span>
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(tx.created_at) }}</td>
                            <td class="text-right font-weight-bold text-body-2">KES {{ fmt(tx.amount) }}</td>
                            <td class="text-right text-caption text-medium-emphasis">KES {{ fmt(tx.fee) }}</td>
                            <td class="text-center">
                                <div class="status-cell" style="justify-content:center">
                                    <span class="status-dot" :class="tx.status"></span>
                                    <span class="status-text">{{ tx.status }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Pagination -->
                <div class="pagination-row">
                    <div class="pagination-left">
                        <span class="pg-label">Rows per page</span>
                        <select v-model="perPage" class="pg-select" @change="page = 1">
                            <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                        </select>
                    </div>
                    <div class="pagination-center text-caption text-medium-emphasis">
                        {{ startRow }}–{{ endRow }} of {{ filtered.length }} transactions
                    </div>
                    <div class="pagination-right">
                        <button class="pg-btn" :disabled="page === 1" @click="page = 1">«</button>
                        <button class="pg-btn" :disabled="page === 1" @click="page--">‹</button>
                        <template v-for="p in totalPages" :key="p">
                            <button v-if="p === 1 || p === totalPages || Math.abs(p - page) <= 1"
                                class="pg-btn" :class="p === page ? 'pg-btn--active' : ''" @click="page = p">{{ p }}</button>
                            <span v-else-if="p === 2 && page > 3" class="pg-ellipsis">…</span>
                            <span v-else-if="p === totalPages - 1 && page < totalPages - 2" class="pg-ellipsis">…</span>
                        </template>
                        <button class="pg-btn" :disabled="page === totalPages || totalPages === 0" @click="page++">›</button>
                        <button class="pg-btn" :disabled="page === totalPages || totalPages === 0" @click="page = totalPages">»</button>
                    </div>
                </div>
            </template>

        </v-card>
    </div>
</template>

<style scoped>
.page-header { display: flex; align-items: flex-start; justify-content: space-between; }
.page-title { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; }
.count-badge { font-size: 12px; font-weight: 600; color: rgba(0,0,0,0.45); background: rgba(0,0,0,0.06); border-radius: 20px; padding: 2px 8px; }
.page-subtitle { font-size: 12.5px; color: rgba(0,0,0,0.45); margin-top: 3px; }
.page-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.summary-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }

.controls-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px 8px; gap: 8px; flex-wrap: wrap; }
.controls-right { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.view-toggle { display: flex; border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
.view-btn { display: flex; align-items: center; gap: 4px; padding: 5px 10px; font-size: 12px; font-weight: 500; border: none; background: none; cursor: pointer; color: rgba(0,0,0,0.5); font-family: inherit; transition: background 0.12s, color 0.12s; }
.view-btn:not(:last-child) { border-right: 1px solid rgba(0,0,0,0.1); }
.view-btn:hover { background: rgba(0,0,0,0.04); color: rgba(0,0,0,0.75); }
.view-btn--active { background: rgba(43,157,143,0.1); color: #2B9D8F; font-weight: 600; }
.ctrl-btn { display: flex; align-items: center; gap: 4px; padding: 5px 10px; font-size: 12px; font-weight: 500; border: 1px solid rgba(0,0,0,0.1); border-radius: 7px; background: #fff; cursor: pointer; color: rgba(0,0,0,0.6); font-family: inherit; white-space: nowrap; transition: background 0.12s; }
.ctrl-btn:hover { background: rgba(0,0,0,0.04); }

.filter-row { display: flex; align-items: center; gap: 6px; padding: 8px 14px; flex-wrap: wrap; }
.filter-pill { display: flex; align-items: center; gap: 4px; padding: 4px 10px; font-size: 12px; font-weight: 500; border: 1px solid rgba(0,0,0,0.12); border-radius: 20px; background: #fff; cursor: pointer; color: rgba(0,0,0,0.55); font-family: inherit; transition: background 0.12s; }
.filter-pill:hover { background: rgba(0,0,0,0.03); }
.filter-pill--active { background: rgba(43,157,143,0.08); border-color: rgba(43,157,143,0.4); color: #2B9D8F; font-weight: 600; }
.filter-pill--add { border-style: dashed; color: rgba(0,0,0,0.38); }

.data-table :deep(thead tr th) { color: rgba(0,0,0,0.42) !important; font-weight: 600 !important; font-size: 10.5px !important; letter-spacing: 0.04em !important; text-transform: uppercase; padding: 9px 12px !important; border-bottom: 1px solid rgba(0,0,0,0.07) !important; background: rgba(0,0,0,0.015); white-space: nowrap; }
.data-table :deep(tbody tr td) { font-size: 13px !important; padding: 9px 12px !important; border-bottom: 1px solid rgba(0,0,0,0.04) !important; vertical-align: middle; }
.data-row { transition: background 0.1s; }
.data-row:hover { background: rgba(43,157,143,0.03) !important; }
.data-row--selected { background: rgba(43,157,143,0.06) !important; }

.row-check { width: 14px; height: 14px; cursor: pointer; accent-color: #2B9D8F; }
.status-cell { display: flex; align-items: center; gap: 6px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.status-dot.completed { background: #22C55E; }
.status-dot.pending   { background: #F59E0B; }
.status-dot.failed    { background: #EF4444; }
.status-text { font-size: 12.5px; font-weight: 500; color: rgba(0,0,0,0.7); }
.type-badge { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; display: inline-block; letter-spacing: 0.03em; }
.ref-code { font-family: 'Courier New', monospace; font-size: 11px; background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; color: rgba(0,0,0,0.6); }

.pagination-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-top: 1px solid rgba(0,0,0,0.06); flex-wrap: wrap; gap: 8px; }
.pagination-left { display: flex; align-items: center; gap: 8px; }
.pagination-center { flex: 1; text-align: center; }
.pagination-right { display: flex; align-items: center; gap: 2px; }
.pg-label { font-size: 12px; color: rgba(0,0,0,0.5); }
.pg-select { font-size: 12px; font-weight: 500; color: rgba(0,0,0,0.7); border: 1px solid rgba(0,0,0,0.12); border-radius: 6px; padding: 2px 6px; background: #fff; cursor: pointer; font-family: inherit; }
.pg-btn { min-width: 28px; height: 28px; padding: 0 6px; font-size: 12px; font-weight: 500; border: 1px solid rgba(0,0,0,0.1); border-radius: 6px; background: #fff; cursor: pointer; color: rgba(0,0,0,0.6); font-family: inherit; display: inline-flex; align-items: center; justify-content: center; transition: background 0.1s; }
.pg-btn:hover:not(:disabled) { background: rgba(0,0,0,0.05); }
.pg-btn--active { background: #2B9D8F !important; color: #fff !important; border-color: #2B9D8F !important; }
.pg-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.pg-ellipsis { font-size: 12px; color: rgba(0,0,0,0.35); padding: 0 4px; align-self: center; }
</style>
