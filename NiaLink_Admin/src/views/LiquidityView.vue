<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../services/api.js'

const data = ref({ total_liquidity: 0, active_liquidity: 0, frozen_liquidity: 0, wallets: [] })
const loading = ref(true)

onMounted(async () => {
    try {
        const res = await api.wallets()
        if (res && typeof res === 'object') data.value = res
    } catch (e) {
        console.error('Wallets load failed', e)
    } finally {
        loading.value = false
    }
})

const fmt = (n) => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor = (s) => ({ active: 'success', frozen: 'error', suspended: 'warning' }[s] ?? 'default')
const typeLabel = (type) => type?.split('\\').pop() ?? type ?? '—'
</script>

<template>
    <div>
        <div class="d-flex align-center justify-space-between mb-5">
            <div>
                <h1 class="text-h5 font-weight-bold" style="letter-spacing:-0.02em">Liquidity</h1>
                <div class="text-caption text-medium-emphasis">System-wide wallet float overview</div>
            </div>
        </div>

        <v-row class="mb-5" dense>
            <v-col cols="12" sm="4">
                <v-card variant="outlined" rounded="lg" class="pa-4 summary-card">
                    <div class="d-flex align-center gap-3 mb-2">
                        <v-avatar color="primary" variant="tonal" size="38" rounded="md">
                            <v-icon size="19" color="primary">mdi-wallet-outline</v-icon>
                        </v-avatar>
                        <span class="text-caption text-medium-emphasis font-weight-medium">Total Liquidity</span>
                    </div>
                    <v-skeleton-loader v-if="loading" type="text" width="160" />
                    <div v-else class="text-h5 font-weight-bold">KES {{ fmt(data.total_liquidity) }}</div>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="outlined" rounded="lg" class="pa-4 summary-card">
                    <div class="d-flex align-center gap-3 mb-2">
                        <v-avatar color="success" variant="tonal" size="38" rounded="md">
                            <v-icon size="19" color="success">mdi-check-circle-outline</v-icon>
                        </v-avatar>
                        <span class="text-caption text-medium-emphasis font-weight-medium">Active Liquidity</span>
                    </div>
                    <v-skeleton-loader v-if="loading" type="text" width="160" />
                    <div v-else class="text-h5 font-weight-bold text-success">KES {{ fmt(data.active_liquidity) }}</div>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="outlined" rounded="lg" class="pa-4 summary-card">
                    <div class="d-flex align-center gap-3 mb-2">
                        <v-avatar color="error" variant="tonal" size="38" rounded="md">
                            <v-icon size="19" color="error">mdi-lock-outline</v-icon>
                        </v-avatar>
                        <span class="text-caption text-medium-emphasis font-weight-medium">Frozen Liquidity</span>
                    </div>
                    <v-skeleton-loader v-if="loading" type="text" width="160" />
                    <div v-else class="text-h5 font-weight-bold text-error">KES {{ fmt(data.frozen_liquidity) }}</div>
                </v-card>
            </v-col>
        </v-row>

        <v-card variant="outlined" rounded="lg" class="page-card">
            <div class="px-4 pt-4 pb-3">
                <div class="text-body-2 font-weight-bold">All Wallets</div>
                <div class="text-caption text-medium-emphasis">Sorted by balance descending</div>
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
                            <th>#</th>
                            <th>Owner Type</th>
                            <th>Currency</th>
                            <th class="text-right">Balance</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!data.wallets?.length">
                            <td colspan="5" class="text-center py-10">
                                <v-icon size="40" color="grey-lighten-2" class="mb-2 d-block mx-auto">mdi-wallet-off-outline</v-icon>
                                <div class="text-body-2 text-medium-emphasis">No wallets found</div>
                            </td>
                        </tr>
                        <tr v-for="(w, i) in data.wallets" :key="w.id" class="data-row">
                            <td class="text-caption text-medium-emphasis">{{ i + 1 }}</td>
                            <td class="text-body-2">{{ typeLabel(w.walletable_type) }} #{{ w.walletable_id }}</td>
                            <td>
                                <v-chip size="x-small" color="primary" variant="tonal">{{ w.currency ?? 'KES' }}</v-chip>
                            </td>
                            <td class="text-right font-weight-bold">KES {{ fmt(w.balance) }}</td>
                            <td class="text-center">
                                <v-chip size="x-small" :color="statusColor(w.status)" variant="tonal">{{ w.status }}</v-chip>
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
.summary-card { background: #fff; border-color: rgba(0,0,0,0.08) !important; }
.data-table :deep(thead tr th) { color: rgba(0,0,0,0.45) !important; font-weight: 600 !important; font-size: 11px !important; letter-spacing: 0.03em !important; text-transform: uppercase; padding: 10px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.07) !important; background: rgba(0,0,0,0.015); }
.data-table :deep(tbody tr td) { font-size: 13px !important; padding: 9px 16px !important; border-bottom: 1px solid rgba(0,0,0,0.045) !important; vertical-align: middle; }
.data-row { transition: background 0.1s; }
.data-row:hover { background: rgba(43,157,143,0.04) !important; }
</style>
