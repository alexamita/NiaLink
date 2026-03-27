<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

const logs = ref([])
const loading = ref(true)
const search = ref('')
const page = ref(1)
const perPage = 15

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

const filtered = computed(() => {
    if (!search.value) return logs.value
    const q = search.value.toLowerCase()
    return logs.value.filter(l =>
        l.action?.toLowerCase().includes(q) ||
        l.resource_type?.toLowerCase().includes(q) ||
        l.ip_address?.includes(q)
    )
})

const paginated = computed(() => {
    const start = (page.value - 1) * perPage
    return filtered.value.slice(start, start + perPage)
})

const totalPages = computed(() => Math.ceil(filtered.value.length / perPage))

const fmtDate = (d) => d ? new Date(d).toLocaleString('en-KE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
const actionColor = (action) => {
    if (!action) return 'grey'
    if (action.includes('approve') || action.includes('unlock')) return 'success'
    if (action.includes('suspend') || action.includes('lock')) return 'error'
    if (action.includes('create')) return 'primary'
    return 'secondary'
}
const initials = (action) => {
    if (!action) return '??'
    return action.replace(/_/g, ' ').split(' ').slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('')
}
</script>

<template>
    <div>
        <div class="d-flex align-center justify-space-between mb-5">
            <div>
                <h1 class="text-h5 font-weight-bold" style="letter-spacing:-0.02em">Audit Log</h1>
                <div class="text-caption text-medium-emphasis">Immutable record of all admin actions</div>
            </div>
        </div>

        <v-card variant="outlined" rounded="lg" class="page-card">
            <div class="d-flex align-center justify-space-between px-4 pt-4 pb-3">
                <div class="text-body-2 font-weight-medium">
                    {{ filtered.length }} entries
                </div>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Search actions..."
                    variant="solo-filled" flat density="compact" hide-details rounded="lg" style="max-width:240px"
                    bg-color="rgba(0,0,0,0.04)" @update:model-value="page = 1" />
            </div>
            <v-divider />

            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 8" :key="i" type="list-item-avatar-two-line" class="mb-1" />
                </div>
            </template>

            <template v-else>
                <v-table density="compact" class="data-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>Resource ID</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="paginated.length === 0">
                            <td colspan="5" class="text-center py-10">
                                <v-icon size="40" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-shield-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No audit logs yet</div>
                            </td>
                        </tr>
                        <tr v-for="log in paginated" :key="log.id" class="data-row">
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <v-avatar :color="actionColor(log.action)" size="26"
                                        style="font-size:9px;font-weight:700;color:#fff;flex-shrink:0" variant="tonal">
                                        {{ initials(log.action) }}
                                    </v-avatar>
                                    <span class="text-body-2 font-weight-medium">
                                        {{ log.action?.replace(/_/g, ' ') ?? '—' }}
                                    </span>
                                </div>
                            </td>
                            <td class="text-caption text-medium-emphasis">
                                {{ log.resource_type?.split('\\').pop() ?? '—' }}
                            </td>
                            <td>
                                <code class="ref-code">{{ log.resource_id ?? '—' }}</code>
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ log.ip_address ?? '—' }}</td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(log.created_at) }}</td>
                        </tr>
                    </tbody>
                </v-table>

                <div v-if="totalPages > 1" class="d-flex align-center justify-space-between px-4 py-3 border-t">
                    <div class="text-caption text-medium-emphasis">
                        Showing {{ ((page - 1) * perPage) + 1 }}–{{ Math.min(page * perPage, filtered.length) }} of {{ filtered.length }}
                    </div>
                    <v-pagination v-model="page" :length="totalPages" density="compact" color="primary" :total-visible="5" rounded="circle" />
                </div>
            </template>
        </v-card>
    </div>
</template>

<style scoped>
.page-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.border-t { border-top: 1px solid rgba(0,0,0,0.06); }
.data-table :deep(thead tr th) { color: rgba(0,0,0,0.45) !important; font-weight: 600 !important; font-size: 11px !important; letter-spacing: 0.03em !important; text-transform: uppercase; padding: 10px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.07) !important; background: rgba(0,0,0,0.015); }
.data-table :deep(tbody tr td) { font-size: 13px !important; padding: 9px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.045) !important; vertical-align: middle; }
.data-row { transition: background 0.1s; }
.data-row:hover { background: rgba(43,157,143,0.04) !important; }
.ref-code { font-family: 'Courier New', monospace; font-size: 11px; background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; color: rgba(0,0,0,0.65); }
</style>
