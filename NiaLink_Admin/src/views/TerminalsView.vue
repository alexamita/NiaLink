<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../services/api.js'

const terminals = ref([])
const loading = ref(true)
const search = ref('')
const actionLoading = ref(null)

onMounted(load)

async function load() {
    loading.value = true
    try {
        const data = await api.terminals()
        terminals.value = Array.isArray(data) ? data : []
    } catch (e) {
        console.error('Terminals load failed', e)
    } finally {
        loading.value = false
    }
}

const filtered = computed(() => {
    if (!search.value) return terminals.value
    const q = search.value.toLowerCase()
    return terminals.value.filter(t =>
        t.terminal_code?.toLowerCase().includes(q) ||
        t.merchant?.business_name?.toLowerCase().includes(q)
    )
})

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Never'
const statusColor = (s) => ({ active: 'success', locked: 'error', inactive: 'warning' }[s] ?? 'default')

const lock = async (terminal) => {
    actionLoading.value = terminal.id
    try {
        await api.lockTerminal(terminal.id)
        terminal.status = 'locked'
    } catch (e) {
        console.error('Lock failed', e)
    } finally {
        actionLoading.value = null
    }
}

const unlock = async (terminal) => {
    actionLoading.value = terminal.id
    try {
        await api.unlockTerminal(terminal.id)
        terminal.status = 'active'
    } catch (e) {
        console.error('Unlock failed', e)
    } finally {
        actionLoading.value = null
    }
}
</script>

<template>
    <div>
        <div class="d-flex align-center justify-space-between mb-5">
            <div>
                <h1 class="text-h5 font-weight-bold" style="letter-spacing:-0.02em">Terminals</h1>
                <div class="text-caption text-medium-emphasis">Manage POS terminals across all merchants</div>
            </div>
            <v-btn prepend-icon="mdi-refresh" variant="tonal" color="primary" size="small" rounded="lg"
                :loading="loading" @click="load">Refresh</v-btn>
        </div>

        <v-card variant="outlined" rounded="lg" class="page-card">
            <div class="d-flex align-center justify-space-between px-4 pt-4 pb-3">
                <div class="text-body-2 font-weight-medium">{{ filtered.length }} terminal{{ filtered.length !== 1 ? 's' : '' }}</div>
                <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Search terminals..."
                    variant="solo-filled" flat density="compact" hide-details rounded="lg" style="max-width:240px"
                    bg-color="rgba(0,0,0,0.04)" />
            </div>
            <v-divider />

            <template v-if="loading">
                <div class="pa-4">
                    <v-skeleton-loader v-for="i in 6" :key="i" type="list-item-avatar" class="mb-1" />
                </div>
            </template>

            <template v-else>
                <v-table density="compact" class="data-table">
                    <thead>
                        <tr>
                            <th>Terminal Code</th>
                            <th>Merchant</th>
                            <th class="text-center">Status</th>
                            <th>Last Active</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="filtered.length === 0">
                            <td colspan="5" class="text-center py-10">
                                <v-icon size="40" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-point-of-sale</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No terminals found</div>
                            </td>
                        </tr>
                        <tr v-for="t in filtered" :key="t.id" class="data-row">
                            <td>
                                <code class="ref-code">{{ t.terminal_code }}</code>
                            </td>
                            <td class="text-body-2">{{ t.merchant?.business_name ?? '—' }}</td>
                            <td class="text-center">
                                <v-chip size="x-small" :color="statusColor(t.status)" variant="tonal">{{ t.status }}</v-chip>
                            </td>
                            <td class="text-caption text-medium-emphasis">{{ fmtDate(t.last_active_at) }}</td>
                            <td class="text-center">
                                <v-btn v-if="t.status === 'active'" size="x-small" color="error" variant="tonal"
                                    rounded="lg" :loading="actionLoading === t.id" @click="lock(t)">
                                    Lock
                                </v-btn>
                                <v-btn v-else size="x-small" color="success" variant="tonal"
                                    rounded="lg" :loading="actionLoading === t.id" @click="unlock(t)">
                                    Unlock
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </template>
        </v-card>
    </div>
</template>

<style scoped>
.page-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.data-table :deep(thead tr th) { color: rgba(0,0,0,0.45) !important; font-weight: 600 !important; font-size: 11px !important; letter-spacing: 0.03em !important; text-transform: uppercase; padding: 10px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.07) !important; background: rgba(0,0,0,0.015); }
.data-table :deep(tbody tr td) { font-size: 13px !important; padding: 9px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.045) !important; vertical-align: middle; }
.data-row { transition: background 0.1s; }
.data-row:hover { background: rgba(43,157,143,0.04) !important; }
.ref-code { font-family: 'Courier New', monospace; font-size: 11px; background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; color: rgba(0,0,0,0.65); }
</style>
