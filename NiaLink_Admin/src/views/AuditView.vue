<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

// ── State ──────────────────────────────────────────────────────────────────
const logs        = ref([])
const loading     = ref(true)
const search      = ref('')
const actionFilter = ref('all')
const page        = ref(1)
const perPage     = ref(15)
const selectedIds = ref([])

const perPageOptions = [10, 15, 25, 50]

const actionGroups = [
    { value: 'all',      label: 'All Actions' },
    { value: 'approve',  label: 'Approvals' },
    { value: 'suspend',  label: 'Suspensions' },
    { value: 'lock',     label: 'Terminal Locks' },
    { value: 'unlock',   label: 'Unlocks' },
]

// ── Load ────────────────────────────────────────────────────────────────────
onMounted(async () => {
    try {
        const data = await api.audit()
        logs.value = Array.isArray(data) ? data : []
    } catch (e) {
        console.error('Audit load failed', e)
    } finally {
        loading.value = false
    }
})

// ── Filter + Paginate ───────────────────────────────────────────────────────
const filtered = computed(() => {
    let list = logs.value
    if (actionFilter.value !== 'all') {
        list = list.filter(l => l.action?.includes(actionFilter.value))
    }
    if (search.value) {
        const q = search.value.toLowerCase()
        list = list.filter(l =>
            l.action?.toLowerCase().includes(q) ||
            l.resource_type?.toLowerCase().includes(q) ||
            l.ip_address?.includes(q)
        )
    }
    return list
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage.value))
const paginated  = computed(() => {
    const start = (page.value - 1) * perPage.value
    return filtered.value.slice(start, start + perPage.value)
})
const startRow = computed(() => filtered.value.length === 0 ? 0 : (page.value - 1) * perPage.value + 1)
const endRow   = computed(() => Math.min(page.value * perPage.value, filtered.value.length))

// ── Select-all ──────────────────────────────────────────────────────────────
const allSelected = computed(() =>
    paginated.value.length > 0 && paginated.value.every(l => selectedIds.value.includes(l.id))
)
function toggleAll() {
    if (allSelected.value) {
        selectedIds.value = selectedIds.value.filter(id => !paginated.value.find(l => l.id === id))
    } else {
        paginated.value.forEach(l => { if (!selectedIds.value.includes(l.id)) selectedIds.value.push(l.id) })
    }
}
function toggleRow(id) {
    const idx = selectedIds.value.indexOf(id)
    if (idx === -1) selectedIds.value.push(id)
    else selectedIds.value.splice(idx, 1)
}

// ── Formatters ──────────────────────────────────────────────────────────────
const fmtDate = (d) => d ? new Date(d).toLocaleString('en-KE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'

const actionColor = (action) => {
    if (!action) return 'grey'
    if (action.includes('approve') || action.includes('unlock')) return '#22C55E'
    if (action.includes('suspend') || action.includes('lock'))   return '#EF4444'
    if (action.includes('create') || action.includes('add'))     return '#3B82F6'
    return '#6B7280'
}

const actionBg = (action) => {
    const c = actionColor(action)
    return `${c}18`
}

const actionLabel = (action) => action?.replace(/_/g, ' ') ?? '—'
const resourceLabel = (type) => type?.split('\\').pop() ?? type ?? '—'
</script>

<template>
    <div>

        <!-- ── Page Header ── -->
        <div class="page-header mb-5">
            <div>
                <div class="d-flex align-center gap-2">
                    <h1 class="page-title">Audit Log</h1>
                    <span class="count-badge">{{ filtered.length }}</span>
                </div>
                <div class="page-subtitle">Immutable record of all admin actions on the platform.</div>
            </div>
        </div>

        <!-- ── Main Card ── -->
        <v-card variant="outlined" rounded="lg" class="page-card">

            <!-- Controls row -->
            <div class="controls-row">
                <div class="view-toggle">
                    <button class="view-btn view-btn--active">
                        <v-icon size="13">mdi-table</v-icon> Table
                    </button>
                    <button class="view-btn">
                        <v-icon size="13">mdi-format-list-bulleted</v-icon> List
                    </button>
                </div>
                <div class="controls-right">
                    <v-text-field
                        v-model="search"
                        prepend-inner-icon="mdi-magnify"
                        placeholder="Search actions..."
                        variant="solo-filled" flat density="compact" hide-details rounded="lg"
                        style="max-width:200px" bg-color="rgba(0,0,0,0.04)"
                        @update:model-value="page = 1"
                    />
                    <button class="ctrl-btn"><v-icon size="14">mdi-export-variant</v-icon> Export</button>
                </div>
            </div>

            <!-- Filter pills -->
            <div class="filter-row">
                <button
                    v-for="grp in actionGroups" :key="grp.value"
                    class="filter-pill" :class="actionFilter === grp.value ? 'filter-pill--active' : ''"
                    @click="actionFilter = grp.value; page = 1"
                >
                    {{ grp.label }} <v-icon size="12">mdi-chevron-down</v-icon>
                </button>
                <button class="filter-pill filter-pill--add">
                    <v-icon size="12">mdi-plus</v-icon> Add filter
                </button>
            </div>

            <v-divider style="opacity:0.4" />

            <!-- Loading -->
            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 8" :key="i" type="list-item-avatar-two-line" class="mb-1" />
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
                            <th>Action</th>
                            <th>Resource</th>
                            <th>Resource ID</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="paginated.length === 0">
                            <td colspan="6" class="text-center py-12">
                                <v-icon size="38" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-shield-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No audit logs found</div>
                            </td>
                        </tr>
                        <tr v-for="log in paginated" :key="log.id" class="data-row" :class="selectedIds.includes(log.id) ? 'data-row--selected' : ''">
                            <td style="padding-left:16px">
                                <input type="checkbox" :checked="selectedIds.includes(log.id)" @change="toggleRow(log.id)" class="row-check" />
                            </td>
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <span
                                        class="action-badge"
                                        :style="`background:${actionBg(log.action)};color:${actionColor(log.action)}`"
                                    >
                                        {{ actionLabel(log.action) }}
                                    </span>
                                </div>
                            </td>
                            <td class="text-body-2 text-medium-emphasis">{{ resourceLabel(log.resource_type) }}</td>
                            <td>
                                <code class="ref-code">{{ log.resource_id ?? '—' }}</code>
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ log.ip_address ?? '—' }}</td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(log.created_at) }}</td>
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
                        {{ startRow }}–{{ endRow }} of {{ filtered.length }} entries
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

.controls-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px 8px; gap: 8px; flex-wrap: wrap; }
.controls-right { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.view-toggle { display: flex; border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
.view-btn { display: flex; align-items: center; gap: 4px; padding: 5px 10px; font-size: 12px; font-weight: 500; border: none; background: none; cursor: pointer; color: rgba(0,0,0,0.5); font-family: inherit; }
.view-btn:not(:last-child) { border-right: 1px solid rgba(0,0,0,0.1); }
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

.action-badge { font-size: 11.5px; font-weight: 600; padding: 3px 10px; border-radius: 20px; display: inline-block; text-transform: capitalize; white-space: nowrap; }
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
