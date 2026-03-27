<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

const transactions = ref([])
const loading = ref(true)
const search = ref('')
const statusFilter = ref('all')
const typeFilter = ref('all')
const page = ref(1)
const perPage = 12

const statusTabs = [
    { value: 'all', label: 'All' },
    { value: 'completed', label: 'Completed' },
    { value: 'pending', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
]

const load = async () => {
    loading.value = true
    try {
        const params = {}
        if (statusFilter.value !== 'all') params.status = statusFilter.value
        if (typeFilter.value !== 'all') params.type = typeFilter.value
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

const filtered = computed(() => {
    if (!search.value) return transactions.value
    const q = search.value.toLowerCase()
    return transactions.value.filter(t =>
        t.user?.name?.toLowerCase().includes(q) ||
        t.merchant?.business_name?.toLowerCase().includes(q) ||
        t.reference?.toLowerCase().includes(q)
    )
})

const paginated = computed(() => {
    const start = (page.value - 1) * perPage
    return filtered.value.slice(start, start + perPage)
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage))

// Summary totals
const totals = computed(() => ({
    volume: transactions.value.filter(t => t.status === 'completed').reduce((s, t) => s + Number(t.amount || 0), 0),
    fees: transactions.value.filter(t => t.status === 'completed').reduce((s, t) => s + Number(t.fee || 0), 0),
    completed: transactions.value.filter(t => t.status === 'completed').length,
    failed: transactions.value.filter(t => t.status === 'failed').length,
}))

const fmt = (n) => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const statusColor = (s) => ({ completed: 'success', pending: 'warning', failed: 'error' }[s] ?? 'default')
const typeColor = (t) => ({ p2m: 'primary', p2p: 'info' }[t] ?? 'default')
</script>

<template>
    <div>
        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-5">
            <div>
                <h1 class="text-h5 font-weight-bold" style="letter-spacing:-0.02em">Transactions</h1>
                <div class="text-caption text-medium-emphasis">Full platform transaction ledger</div>
            </div>
            <div class="d-flex gap-2">
                <v-select v-model="typeFilter" :items="[{title:'All Types',value:'all'},{title:'P2M',value:'p2m'},{title:'P2P',value:'p2p'}]"
                    variant="outlined" density="compact" hide-details rounded="lg" style="min-width:130px"
                    @update:model-value="load" />
                <v-btn prepend-icon="mdi-refresh" variant="tonal" color="primary" size="small" rounded="lg"
                    :loading="loading" @click="load">Refresh</v-btn>
            </div>
        </div>

        <!-- Summary chips -->
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
                    <div class="text-h6 font-weight-bold text-primary">KES {{ fmt(totals.fees) }}</div>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="outlined" rounded="lg" class="pa-3 summary-card">
                    <div class="text-caption text-medium-emphasis mb-1">Completed</div>
                    <div class="text-h6 font-weight-bold text-success">{{ totals.completed }}</div>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="outlined" rounded="lg" class="pa-3 summary-card">
                    <div class="text-caption text-medium-emphasis mb-1">Failed</div>
                    <div class="text-h6 font-weight-bold text-error">{{ totals.failed }}</div>
                </v-card>
            </v-col>
        </v-row>

        <!-- Table card -->
        <v-card variant="outlined" rounded="lg" class="page-card">

            <div class="d-flex align-center justify-space-between px-4 pt-4 pb-2 flex-wrap gap-3">
                <div class="d-flex gap-1">
                    <button v-for="tab in statusTabs" :key="tab.value" class="status-tab"
                        :class="statusFilter === tab.value ? 'status-tab--active' : ''" @click="setStatus(tab.value)">
                        {{ tab.label }}
                    </button>
                </div>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Search..."
                    variant="solo-filled" flat density="compact" hide-details rounded="lg" style="max-width:240px"
                    bg-color="rgba(0,0,0,0.04)" @update:model-value="page = 1" />
            </div>
            <v-divider />

            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 8" :key="i" type="list-item-avatar" class="mb-1" />
                </div>
            </template>

            <template v-else>
                <v-table density="compact" class="data-table">
                    <thead>
                        <tr>
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
                            <td colspan="7" class="text-center py-10">
                                <v-icon size="40" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-database-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No transactions found</div>
                            </td>
                        </tr>
                        <tr v-for="tx in paginated" :key="tx.id" class="data-row">
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar :color="tx.type === 'p2m' ? 'primary' : 'info'" size="28"
                                        style="font-size:9px;font-weight:700;color:#fff" variant="tonal">
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
                                <v-chip size="x-small" :color="typeColor(tx.type)" variant="tonal">
                                    {{ tx.type?.toUpperCase() ?? '—' }}
                                </v-chip>
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(tx.created_at) }}</td>
                            <td class="text-right font-weight-bold">KES {{ fmt(tx.amount) }}</td>
                            <td class="text-right text-caption text-medium-emphasis">KES {{ fmt(tx.fee) }}</td>
                            <td class="text-center">
                                <v-chip size="x-small" :color="statusColor(tx.status)" variant="tonal">
                                    {{ tx.status }}
                                </v-chip>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <div v-if="totalPages > 1" class="d-flex align-center justify-space-between px-4 py-3 border-t">
                    <div class="text-caption text-medium-emphasis">
                        Showing {{ ((page - 1) * perPage) + 1 }}–{{ Math.min(page * perPage, filtered.length) }}
                        of {{ filtered.length }}
                    </div>
                    <v-pagination v-model="page" :length="totalPages" density="compact" color="primary"
                        :total-visible="5" rounded="circle" />
                </div>
                <div v-else class="px-4 py-2 border-t">
                    <div class="text-caption text-medium-emphasis">{{ filtered.length }} transaction{{ filtered.length !== 1 ? 's' : '' }}</div>
                </div>
            </template>

        </v-card>
    </div>
</template>

<style scoped>
.page-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.summary-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.border-t { border-top: 1px solid rgba(0,0,0,0.06); }
.status-tab { padding: 5px 14px; font-size: 12px; font-weight: 500; border: none; background: none; border-radius: 20px; cursor: pointer; color: rgba(0,0,0,0.5); font-family: inherit; transition: background 0.15s, color 0.15s; }
.status-tab:hover { background: rgba(0,0,0,0.05); color: rgba(0,0,0,0.75); }
.status-tab--active { background: rgba(43,157,143,0.12) !important; color: #2B9D8F !important; font-weight: 600; }
.data-table :deep(thead tr th) { color: rgba(0,0,0,0.45) !important; font-weight: 600 !important; font-size: 11px !important; letter-spacing: 0.03em !important; text-transform: uppercase; padding: 10px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.07) !important; background: rgba(0,0,0,0.015); }
.data-table :deep(tbody tr td) { font-size: 13px !important; padding: 9px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.045) !important; vertical-align: middle; }
.data-row { transition: background 0.1s; }
.data-row:hover { background: rgba(43,157,143,0.04) !important; }
.ref-code { font-family: 'Courier New', monospace; font-size: 11px; background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; color: rgba(0,0,0,0.65); }
</style>
