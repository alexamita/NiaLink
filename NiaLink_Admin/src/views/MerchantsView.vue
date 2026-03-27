<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

const merchants = ref([])
const loading = ref(true)
const statusFilter = ref('all')
const search = ref('')
const page = ref(1)
const perPage = 10
const actionLoading = ref(null) // merchant id being actioned

const statusTabs = [
    { value: 'all', label: 'All' },
    { value: 'pending', label: 'Pending KYC' },
    { value: 'active', label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
]

const load = async () => {
    loading.value = true
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

const setStatus = (val) => {
    statusFilter.value = val
    page.value = 1
    load()
}

const filtered = computed(() => {
    if (!search.value) return merchants.value
    const q = search.value.toLowerCase()
    return merchants.value.filter(m =>
        m.business_name?.toLowerCase().includes(q) ||
        m.merchant_code?.toLowerCase().includes(q)
    )
})

const paginated = computed(() => {
    const start = (page.value - 1) * perPage
    return filtered.value.slice(start, start + perPage)
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage))

const fmt = (n) => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const statusColor = (s) => ({ active: 'success', pending: 'warning', suspended: 'error' }[s] ?? 'default')
const initials = (name) => name?.split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('') ?? '??'
const avatarColors = ['#2B9D8F', '#4DB6AC', '#22C55E', '#3B82F6', '#F59E0B', '#EF4444']

const approve = async (merchant) => {
    actionLoading.value = merchant.id
    try {
        await api.approveMerchant(merchant.id)
        merchant.status = 'active'
    } catch (e) {
        console.error('Approve failed', e)
    } finally {
        actionLoading.value = null
    }
}

const suspend = async (merchant) => {
    actionLoading.value = merchant.id
    try {
        await api.suspendMerchant(merchant.id)
        merchant.status = 'suspended'
    } catch (e) {
        console.error('Suspend failed', e)
    } finally {
        actionLoading.value = null
    }
}
</script>

<template>
    <div>
        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-5">
            <div>
                <h1 class="text-h5 font-weight-bold" style="letter-spacing:-0.02em">Merchants</h1>
                <div class="text-caption text-medium-emphasis">Manage merchant accounts and KYC approvals</div>
            </div>
            <v-btn prepend-icon="mdi-refresh" variant="tonal" color="primary" size="small" rounded="lg"
                :loading="loading" @click="load">
                Refresh
            </v-btn>
        </div>

        <!-- Main card -->
        <v-card variant="outlined" rounded="lg" class="page-card">

            <!-- Filters row -->
            <div class="d-flex align-center justify-space-between px-4 pt-4 pb-2 flex-wrap gap-3">
                <div class="d-flex gap-1">
                    <button v-for="tab in statusTabs" :key="tab.value" class="status-tab"
                        :class="statusFilter === tab.value ? 'status-tab--active' : ''" @click="setStatus(tab.value)">
                        {{ tab.label }}
                    </button>
                </div>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Search merchants..."
                    variant="solo-filled" flat density="compact" hide-details rounded="lg" style="max-width:240px"
                    bg-color="rgba(0,0,0,0.04)" @update:model-value="page = 1" />
            </div>
            <v-divider />

            <!-- Loading -->
            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 6" :key="i" type="list-item-avatar-two-line" class="mb-1" />
                </div>
            </template>

            <!-- Table -->
            <template v-else>
                <v-table density="compact" class="data-table">
                    <thead>
                        <tr>
                            <th>Merchant</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th class="text-right">Wallet Balance</th>
                            <th>Terminals</th>
                            <th>Joined</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="paginated.length === 0">
                            <td colspan="7" class="text-center py-10">
                                <v-icon size="40" color="grey-lighten-2" class="mb-2 d-block mx-auto">
                                    mdi-store-off-outline
                                </v-icon>
                                <div class="text-body-2 text-medium-emphasis">No merchants found</div>
                            </td>
                        </tr>
                        <tr v-for="(m, i) in paginated" :key="m.id" class="data-row">
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar :color="avatarColors[i % avatarColors.length]" size="30"
                                        style="font-size:10px;font-weight:700;color:#fff;flex-shrink:0">
                                        {{ initials(m.business_name) }}
                                    </v-avatar>
                                    <span class="text-body-2 font-weight-medium">{{ m.business_name ?? '—' }}</span>
                                </div>
                            </td>
                            <td>
                                <code class="ref-code">{{ m.merchant_code ?? '—' }}</code>
                            </td>
                            <td>
                                <v-chip size="x-small" :color="statusColor(m.status)" variant="tonal">
                                    {{ m.status }}
                                </v-chip>
                            </td>
                            <td class="text-right font-weight-bold">
                                KES {{ fmt(m.wallet?.balance) }}
                            </td>
                            <td class="text-caption text-medium-emphasis">
                                {{ m.terminals?.length ?? 0 }} terminal{{ (m.terminals?.length ?? 0) !== 1 ? 's' : '' }}
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(m.created_at) }}</td>
                            <td class="text-center">
                                <div class="d-flex justify-center gap-1">
                                    <v-btn v-if="m.status === 'pending'" size="x-small" color="success" variant="tonal"
                                        rounded="lg" :loading="actionLoading === m.id" @click="approve(m)">
                                        Approve
                                    </v-btn>
                                    <v-btn v-if="m.status === 'active'" size="x-small" color="error" variant="tonal"
                                        rounded="lg" :loading="actionLoading === m.id" @click="suspend(m)">
                                        Suspend
                                    </v-btn>
                                    <v-btn v-if="m.status === 'suspended'" size="x-small" color="success" variant="tonal"
                                        rounded="lg" :loading="actionLoading === m.id" @click="approve(m)">
                                        Reactivate
                                    </v-btn>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="d-flex align-center justify-space-between px-4 py-3 border-t">
                    <div class="text-caption text-medium-emphasis">
                        Showing {{ ((page - 1) * perPage) + 1 }}–{{ Math.min(page * perPage, filtered.length) }}
                        of {{ filtered.length }}
                    </div>
                    <v-pagination v-model="page" :length="totalPages" density="compact" color="primary"
                        :total-visible="5" rounded="circle" />
                </div>
                <div v-else class="px-4 py-2 border-t">
                    <div class="text-caption text-medium-emphasis">{{ filtered.length }} merchant{{ filtered.length !== 1 ? 's' : '' }}</div>
                </div>
            </template>

        </v-card>
    </div>
</template>

<style scoped>
.page-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.border-t { border-top: 1px solid rgba(0,0,0,0.06); }

.status-tab {
    padding: 5px 14px; font-size: 12px; font-weight: 500;
    border: none; background: none; border-radius: 20px; cursor: pointer;
    color: rgba(0,0,0,0.5); font-family: inherit;
    transition: background 0.15s, color 0.15s;
}
.status-tab:hover { background: rgba(0,0,0,0.05); color: rgba(0,0,0,0.75); }
.status-tab--active { background: rgba(43,157,143,0.12) !important; color: #2B9D8F !important; font-weight: 600; }

.data-table :deep(thead tr th) {
    color: rgba(0,0,0,0.45) !important; font-weight: 600 !important;
    font-size: 11px !important; letter-spacing: 0.03em !important;
    text-transform: uppercase; padding: 10px 16px !important;
    border-bottom: 1px solid rgba(0,0,0,0.07) !important;
    background: rgba(0,0,0,0.015);
}
.data-table :deep(tbody tr td) {
    font-size: 13px !important; padding: 9px 16px !important;
    border-bottom: 1px solid rgba(0,0,0,0.045) !important;
    vertical-align: middle;
}
.data-row { transition: background 0.1s; }
.data-row:hover { background: rgba(43,157,143,0.04) !important; }
.ref-code {
    font-family: 'Courier New', monospace; font-size: 11px;
    background: rgba(0,0,0,0.05); padding: 2px 6px;
    border-radius: 4px; color: rgba(0,0,0,0.65);
}
</style>
