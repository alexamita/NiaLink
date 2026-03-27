<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'
import { useNotify } from '../composables/useNotify.js'

const { notify } = useNotify()

// ── State ──────────────────────────────────────────────────────────────────
const merchants     = ref([])
const loading       = ref(true)
const statusFilter  = ref('all')
const search        = ref('')
const page          = ref(1)
const perPage       = ref(15)
const selectedIds   = ref([])
const actionLoading = ref(null)
const activeView    = ref('table') // table | list

// ── Tabs / options ──────────────────────────────────────────────────────────
const statusTabs = [
    { value: 'all',       label: 'All' },
    { value: 'pending',   label: 'Pending KYC' },
    { value: 'active',    label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
]
const perPageOptions = [10, 15, 25, 50]

// ── Load ────────────────────────────────────────────────────────────────────
const load = async () => {
    loading.value = true
    selectedIds.value = []
    try {
        const params = statusFilter.value !== 'all' ? { status: statusFilter.value } : {}
        const data = await api.merchants(params)
        merchants.value = Array.isArray(data) ? data : []
    } catch (e) {
        console.error('Merchants load failed', e)
    } finally {
        loading.value = false
    }
}

onMounted(load)

const setStatus = (val) => { statusFilter.value = val; page.value = 1; load() }

// ── Filter + Paginate ───────────────────────────────────────────────────────
const filtered = computed(() => {
    if (!search.value) return merchants.value
    const q = search.value.toLowerCase()
    return merchants.value.filter(m =>
        m.business_name?.toLowerCase().includes(q) ||
        m.merchant_code?.toLowerCase().includes(q)
    )
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage.value))

const paginated = computed(() => {
    const start = (page.value - 1) * perPage.value
    return filtered.value.slice(start, start + perPage.value)
})

const startRow = computed(() => filtered.value.length === 0 ? 0 : (page.value - 1) * perPage.value + 1)
const endRow   = computed(() => Math.min(page.value * perPage.value, filtered.value.length))

// ── Select-all ──────────────────────────────────────────────────────────────
const allSelected = computed(() =>
    paginated.value.length > 0 && paginated.value.every(m => selectedIds.value.includes(m.id))
)
function toggleAll() {
    if (allSelected.value) {
        selectedIds.value = selectedIds.value.filter(id => !paginated.value.find(m => m.id === id))
    } else {
        paginated.value.forEach(m => { if (!selectedIds.value.includes(m.id)) selectedIds.value.push(m.id) })
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
const initials = (name) => name?.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('') ?? '??'
const avatarColors = ['#2B9D8F', '#4DB6AC', '#22C55E', '#3B82F6', '#F59E0B', '#EF4444', '#8B5CF6']

// ── Actions ─────────────────────────────────────────────────────────────────
const approve = async (merchant) => {
    actionLoading.value = merchant.id
    try {
        await api.approveMerchant(merchant.id)
        merchant.status = 'active'
        merchant.verified_at = new Date().toISOString()
        notify(`"${merchant.business_name}" approved`, { subtitle: 'Merchant is now live and wallet provisioned.' })
    } catch (e) {
        notify('Approval failed', { color: 'error', subtitle: 'Please try again.' })
    } finally {
        actionLoading.value = null
    }
}

const suspend = async (merchant) => {
    actionLoading.value = merchant.id
    try {
        await api.suspendMerchant(merchant.id)
        merchant.status = 'suspended'
        notify(`"${merchant.business_name}" suspended`, { subtitle: 'All terminals are now inactive.', color: 'warning' })
    } catch (e) {
        notify('Suspension failed', { color: 'error', subtitle: 'Please try again.' })
    } finally {
        actionLoading.value = null
    }
}

// Reset page when perPage changes
const onPerPageChange = () => { page.value = 1 }
const onSearch = () => { page.value = 1 }
</script>

<template>
    <div>

        <!-- ── Page Header ── -->
        <div class="page-header mb-5">
            <div>
                <div class="d-flex align-center gap-2">
                    <h1 class="page-title">Merchants</h1>
                    <span class="count-badge">{{ filtered.length }}</span>
                </div>
                <div class="page-subtitle">Manage merchant accounts and KYC approvals here.</div>
            </div>
        </div>

        <!-- ── Main Card ── -->
        <v-card variant="outlined" rounded="lg" class="page-card">

            <!-- Top controls row -->
            <div class="controls-row">
                <!-- View toggle -->
                <div class="view-toggle">
                    <button
                        class="view-btn"
                        :class="activeView === 'table' ? 'view-btn--active' : ''"
                        @click="activeView = 'table'"
                    >
                        <v-icon size="13">mdi-table</v-icon> Table
                    </button>
                    <button
                        class="view-btn"
                        :class="activeView === 'list' ? 'view-btn--active' : ''"
                        @click="activeView = 'list'"
                    >
                        <v-icon size="13">mdi-format-list-bulleted</v-icon> List
                    </button>
                </div>

                <div class="controls-right">
                    <!-- Search -->
                    <v-text-field
                        v-model="search"
                        prepend-inner-icon="mdi-magnify"
                        placeholder="Search merchants..."
                        variant="solo-filled"
                        flat
                        density="compact"
                        hide-details
                        rounded="lg"
                        style="max-width:200px"
                        bg-color="rgba(0,0,0,0.04)"
                        @update:model-value="onSearch"
                    />
                    <button class="ctrl-btn">
                        <v-icon size="14">mdi-eye-outline</v-icon> Hide
                    </button>
                    <button class="ctrl-btn">
                        <v-icon size="14">mdi-tune</v-icon> Customize
                    </button>
                    <button class="ctrl-btn" @click="load">
                        <v-icon size="14">mdi-refresh</v-icon>
                    </button>
                    <button class="ctrl-btn">
                        <v-icon size="14">mdi-export-variant</v-icon> Export
                    </button>
                    <button class="ctrl-btn ctrl-btn--primary">
                        <v-icon size="14">mdi-plus</v-icon> Add Merchant
                        <v-icon size="13">mdi-chevron-down</v-icon>
                    </button>
                </div>
            </div>

            <!-- Filter pills row -->
            <div class="filter-row">
                <button
                    v-for="tab in statusTabs"
                    :key="tab.value"
                    class="filter-pill"
                    :class="statusFilter === tab.value ? 'filter-pill--active' : ''"
                    @click="setStatus(tab.value)"
                >
                    <v-icon v-if="tab.value !== 'all'" size="12" class="mr-1">mdi-circle-outline</v-icon>
                    {{ tab.label }}
                    <v-icon size="12">mdi-chevron-down</v-icon>
                </button>
                <button class="filter-pill filter-pill--add">
                    <v-icon size="12">mdi-plus</v-icon> Add filter
                </button>
            </div>

            <v-divider style="opacity:0.4" />

            <!-- Loading skeleton -->
            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 8" :key="i" type="list-item-avatar-two-line" class="mb-1" />
                </div>
            </template>

            <!-- Table view -->
            <template v-else-if="activeView === 'table'">
                <v-table density="compact" class="data-table">
                    <thead>
                        <tr>
                            <th style="width:40px;padding-left:16px">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" class="row-check" />
                            </th>
                            <th>Business Name</th>
                            <th>Merchant Code</th>
                            <th>Status</th>
                            <th class="text-right">Wallet Balance</th>
                            <th>Terminals</th>
                            <th>Joined</th>
                            <th class="text-center">KYC</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="paginated.length === 0">
                            <td colspan="9" class="text-center py-12">
                                <v-icon size="38" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-store-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No merchants found</div>
                                <div v-if="search || statusFilter !== 'all'" class="text-caption text-medium-emphasis mt-1">
                                    Try clearing your search or filter
                                </div>
                            </td>
                        </tr>
                        <tr v-for="(m, i) in paginated" :key="m.id" class="data-row" :class="selectedIds.includes(m.id) ? 'data-row--selected' : ''">
                            <td style="padding-left:16px">
                                <input type="checkbox" :checked="selectedIds.includes(m.id)" @change="toggleRow(m.id)" class="row-check" />
                            </td>
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar
                                        :color="avatarColors[i % avatarColors.length]"
                                        size="28"
                                        style="font-size:10px;font-weight:700;color:#fff;flex-shrink:0"
                                    >
                                        {{ initials(m.business_name) }}
                                    </v-avatar>
                                    <span class="text-body-2 font-weight-medium">{{ m.business_name ?? '—' }}</span>
                                </div>
                            </td>
                            <td>
                                <code class="ref-code">{{ m.merchant_code ?? '—' }}</code>
                            </td>
                            <td>
                                <div class="status-cell">
                                    <span class="status-dot" :class="m.status"></span>
                                    <span class="status-text">
                                        {{ m.status === 'pending' ? 'Pending' : m.status === 'active' ? 'Active' : 'Suspended' }}
                                    </span>
                                </div>
                            </td>
                            <td class="text-right font-weight-bold text-body-2">
                                KES {{ fmt(m.wallet?.balance) }}
                            </td>
                            <td class="text-caption text-medium-emphasis">
                                {{ m.terminals?.length ?? 0 }} terminal{{ (m.terminals?.length ?? 0) !== 1 ? 's' : '' }}
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(m.created_at) }}</td>
                            <td class="text-center">
                                <span class="kyc-badge" :class="m.status === 'active' ? 'kyc-badge--verified' : m.status === 'pending' ? 'kyc-badge--pending' : 'kyc-badge--suspended'">
                                    {{ m.status === 'active' ? 'Verified' : m.status === 'pending' ? 'Pending' : 'Suspended' }}
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-center gap-1">
                                    <button
                                        v-if="m.status === 'pending'"
                                        class="action-btn action-btn--approve"
                                        :disabled="actionLoading === m.id"
                                        @click="approve(m)"
                                    >
                                        <v-icon size="13">mdi-check</v-icon> Approve
                                    </button>
                                    <button
                                        v-if="m.status === 'active'"
                                        class="action-btn action-btn--suspend"
                                        :disabled="actionLoading === m.id"
                                        @click="suspend(m)"
                                    >
                                        <v-icon size="13">mdi-pause</v-icon> Suspend
                                    </button>
                                    <button
                                        v-if="m.status === 'suspended'"
                                        class="action-btn action-btn--approve"
                                        :disabled="actionLoading === m.id"
                                        @click="approve(m)"
                                    >
                                        <v-icon size="13">mdi-check</v-icon> Reactivate
                                    </button>
                                    <button class="action-btn action-btn--edit">
                                        <v-icon size="13">mdi-pencil-outline</v-icon> Edit
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </template>

            <!-- List view -->
            <template v-else>
                <div v-if="paginated.length === 0" class="text-center py-12">
                    <v-icon size="38" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-store-off-outline</v-icon>
                    <div class="text-body-2 text-medium-emphasis">No merchants found</div>
                </div>
                <div v-for="(m, i) in paginated" :key="m.id" class="list-row">
                    <input type="checkbox" :checked="selectedIds.includes(m.id)" @change="toggleRow(m.id)" class="row-check" />
                    <v-avatar :color="avatarColors[i % avatarColors.length]" size="32" style="font-size:11px;font-weight:700;color:#fff;flex-shrink:0">
                        {{ initials(m.business_name) }}
                    </v-avatar>
                    <div style="flex:1;min-width:0">
                        <div class="text-body-2 font-weight-semibold">{{ m.business_name }}</div>
                        <div class="text-caption text-medium-emphasis">{{ m.merchant_code }} · {{ m.terminals?.length ?? 0 }} terminals</div>
                    </div>
                    <div class="status-cell">
                        <span class="status-dot" :class="m.status"></span>
                        <span class="status-text">{{ m.status }}</span>
                    </div>
                    <div class="text-body-2 font-weight-bold" style="min-width:90px;text-align:right">KES {{ fmt(m.wallet?.balance) }}</div>
                    <button v-if="m.status === 'pending'" class="action-btn action-btn--approve" @click="approve(m)">Approve</button>
                    <button v-if="m.status === 'active'" class="action-btn action-btn--suspend" @click="suspend(m)">Suspend</button>
                    <button v-if="m.status === 'suspended'" class="action-btn action-btn--approve" @click="approve(m)">Reactivate</button>
                </div>
            </template>

            <!-- ── Pagination ── -->
            <div class="pagination-row">
                <div class="pagination-left">
                    <span class="pg-label">Rows per page</span>
                    <select v-model="perPage" class="pg-select" @change="onPerPageChange">
                        <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>
                <div class="pagination-center text-caption text-medium-emphasis">
                    {{ startRow }}–{{ endRow }} of {{ filtered.length }} merchants
                </div>
                <div class="pagination-right">
                    <button class="pg-btn" :disabled="page === 1" @click="page = 1">«</button>
                    <button class="pg-btn" :disabled="page === 1" @click="page--">‹</button>
                    <template v-for="p in totalPages" :key="p">
                        <button
                            v-if="p === 1 || p === totalPages || Math.abs(p - page) <= 1"
                            class="pg-btn"
                            :class="p === page ? 'pg-btn--active' : ''"
                            @click="page = p"
                        >{{ p }}</button>
                        <span v-else-if="p === 2 && page > 3" class="pg-ellipsis">…</span>
                        <span v-else-if="p === totalPages - 1 && page < totalPages - 2" class="pg-ellipsis">…</span>
                    </template>
                    <button class="pg-btn" :disabled="page === totalPages || totalPages === 0" @click="page++">›</button>
                    <button class="pg-btn" :disabled="page === totalPages || totalPages === 0" @click="page = totalPages">»</button>
                </div>
            </div>

        </v-card>
    </div>
</template>

<style scoped>
/* ── Page header ── */
.page-header { display: flex; align-items: flex-start; justify-content: space-between; }
.page-title { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; }
.count-badge { font-size: 12px; font-weight: 600; color: rgba(0,0,0,0.45); background: rgba(0,0,0,0.06); border-radius: 20px; padding: 2px 8px; }
.page-subtitle { font-size: 12.5px; color: rgba(0,0,0,0.45); margin-top: 3px; }

/* ── Card ── */
.page-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }

/* ── Controls row ── */
.controls-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px 8px;
    gap: 8px;
    flex-wrap: wrap;
}
.controls-right { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* View toggle */
.view-toggle { display: flex; border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
.view-btn {
    display: flex; align-items: center; gap: 4px;
    padding: 5px 10px; font-size: 12px; font-weight: 500;
    border: none; background: none; cursor: pointer;
    color: rgba(0,0,0,0.5); font-family: inherit;
    transition: background 0.12s, color 0.12s;
}
.view-btn:not(:last-child) { border-right: 1px solid rgba(0,0,0,0.1); }
.view-btn:hover { background: rgba(0,0,0,0.04); color: rgba(0,0,0,0.75); }
.view-btn--active { background: rgba(43,157,143,0.1); color: #2B9D8F; font-weight: 600; }

/* Control buttons */
.ctrl-btn {
    display: flex; align-items: center; gap: 4px;
    padding: 5px 10px; font-size: 12px; font-weight: 500;
    border: 1px solid rgba(0,0,0,0.1); border-radius: 7px;
    background: #fff; cursor: pointer; color: rgba(0,0,0,0.6);
    font-family: inherit; white-space: nowrap;
    transition: background 0.12s, border-color 0.12s;
}
.ctrl-btn:hover { background: rgba(0,0,0,0.04); border-color: rgba(0,0,0,0.18); }
.ctrl-btn--primary {
    background: #2B9D8F; color: #fff; border-color: #2B9D8F;
    font-weight: 600;
}
.ctrl-btn--primary:hover { background: #24867a; border-color: #24867a; }

/* ── Filter pills ── */
.filter-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    flex-wrap: wrap;
}
.filter-pill {
    display: flex; align-items: center; gap: 4px;
    padding: 4px 10px; font-size: 12px; font-weight: 500;
    border: 1px solid rgba(0,0,0,0.12); border-radius: 20px;
    background: #fff; cursor: pointer; color: rgba(0,0,0,0.55);
    font-family: inherit; transition: background 0.12s, border-color 0.12s;
}
.filter-pill:hover { background: rgba(0,0,0,0.03); border-color: rgba(0,0,0,0.2); }
.filter-pill--active {
    background: rgba(43,157,143,0.08);
    border-color: rgba(43,157,143,0.4);
    color: #2B9D8F;
    font-weight: 600;
}
.filter-pill--add {
    border-style: dashed;
    color: rgba(0,0,0,0.38);
}
.filter-pill--add:hover { color: rgba(0,0,0,0.6); }

/* ── Table ── */
.data-table :deep(thead tr th) {
    color: rgba(0,0,0,0.42) !important; font-weight: 600 !important;
    font-size: 10.5px !important; letter-spacing: 0.04em !important;
    text-transform: uppercase; padding: 9px 12px !important;
    border-bottom: 1px solid rgba(0,0,0,0.07) !important;
    background: rgba(0,0,0,0.015); white-space: nowrap;
}
.data-table :deep(tbody tr td) {
    font-size: 13px !important; padding: 9px 12px !important;
    border-bottom: 1px solid rgba(0,0,0,0.04) !important;
    vertical-align: middle;
}
.data-row { transition: background 0.1s; cursor: default; }
.data-row:hover { background: rgba(43,157,143,0.03) !important; }
.data-row--selected { background: rgba(43,157,143,0.06) !important; }

/* ── Checkbox ── */
.row-check {
    width: 14px; height: 14px; cursor: pointer;
    accent-color: #2B9D8F;
}

/* ── Status dot ── */
.status-cell { display: flex; align-items: center; gap: 6px; }
.status-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}
.status-dot.active    { background: #22C55E; }
.status-dot.pending   { background: #F59E0B; }
.status-dot.suspended { background: #EF4444; }
.status-dot.inactive  { background: #EF4444; }
.status-text { font-size: 12.5px; font-weight: 500; color: rgba(0,0,0,0.7); }

/* ── KYC badge ── */
.kyc-badge {
    font-size: 10.5px; font-weight: 600;
    padding: 2px 8px; border-radius: 20px;
    display: inline-block;
}
.kyc-badge--verified  { background: rgba(34,197,94,0.12);  color: #16a34a; }
.kyc-badge--pending   { background: rgba(245,158,11,0.14); color: #d97706; }
.kyc-badge--suspended { background: rgba(239,68,68,0.12);  color: #dc2626; }

/* ── Action buttons ── */
.action-btn {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 3px 9px; font-size: 11.5px; font-weight: 500;
    border: 1px solid rgba(0,0,0,0.12); border-radius: 6px;
    background: #fff; cursor: pointer; font-family: inherit;
    transition: background 0.12s;
}
.action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.action-btn--approve { color: #16a34a; border-color: rgba(34,197,94,0.3); }
.action-btn--approve:hover:not(:disabled) { background: rgba(34,197,94,0.07); }
.action-btn--suspend { color: #dc2626; border-color: rgba(239,68,68,0.3); }
.action-btn--suspend:hover:not(:disabled) { background: rgba(239,68,68,0.07); }
.action-btn--edit { color: rgba(0,0,0,0.5); }
.action-btn--edit:hover { background: rgba(0,0,0,0.05); }

/* ── Ref code ── */
.ref-code {
    font-family: 'Courier New', monospace; font-size: 11px;
    background: rgba(0,0,0,0.05); padding: 2px 6px;
    border-radius: 4px; color: rgba(0,0,0,0.6);
}

/* ── List view ── */
.list-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    transition: background 0.1s;
}
.list-row:hover { background: rgba(43,157,143,0.03); }

/* ── Pagination ── */
.pagination-row {
    display: flex; align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-top: 1px solid rgba(0,0,0,0.06);
    flex-wrap: wrap;
    gap: 8px;
}
.pagination-left  { display: flex; align-items: center; gap: 8px; }
.pagination-center { flex: 1; text-align: center; }
.pagination-right { display: flex; align-items: center; gap: 2px; }

.pg-label { font-size: 12px; color: rgba(0,0,0,0.5); }
.pg-select {
    font-size: 12px; font-weight: 500; color: rgba(0,0,0,0.7);
    border: 1px solid rgba(0,0,0,0.12); border-radius: 6px;
    padding: 2px 6px; background: #fff; cursor: pointer;
    font-family: inherit;
}

.pg-btn {
    min-width: 28px; height: 28px; padding: 0 6px;
    font-size: 12px; font-weight: 500;
    border: 1px solid rgba(0,0,0,0.1); border-radius: 6px;
    background: #fff; cursor: pointer; color: rgba(0,0,0,0.6);
    font-family: inherit; display: inline-flex; align-items: center; justify-content: center;
    transition: background 0.1s, color 0.1s;
}
.pg-btn:hover:not(:disabled) { background: rgba(0,0,0,0.05); color: rgba(0,0,0,0.85); }
.pg-btn--active { background: #2B9D8F !important; color: #fff !important; border-color: #2B9D8F !important; }
.pg-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.pg-ellipsis { font-size: 12px; color: rgba(0,0,0,0.35); padding: 0 4px; align-self: center; }
</style>
