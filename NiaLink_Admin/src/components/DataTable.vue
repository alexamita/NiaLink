<script setup>
import { ref, computed } from 'vue'

const filter = ref('All')

const statusColor = (s) => ({
    active: 'success', pending: 'warning', suspended: 'error'
}[s] || 'default')

const headers = [
    { title: 'Business', key: 'business_name' },
    { title: 'Code', key: 'merchant_code' },
    { title: 'Category', key: 'category' },
    { title: 'Balance', key: 'balance' },
    { title: 'Terminals', key: 'terminals' },
    { title: 'Status', key: 'status' },
    { title: 'Actions', key: 'actions', sortable: false },
]
</script>

<template>
    <v-card variant="outlined" rounded="lg">

        <!-- Table toolbar -->
        <v-card-title class="pa-4 d-flex align-center">
            <span>Merchants</span>
            <v-spacer />
            <v-chip-group v-model="filter" mandatory>
                <v-chip v-for="f in ['All', 'Active', 'Pending', 'Suspended']" :key="f" :value="f" size="small" filter>{{ f
                    }}</v-chip>
            </v-chip-group>
        </v-card-title>

        <v-data-table :headers="headers" :items="filteredMerchants" density="comfortable" hover>
            <!-- Status badge -->
            <template v-slot:item.status="{ item }">
                <v-chip :color="statusColor(item.status)" size="x-small" variant="tonal">
                    {{ item.status }}
                </v-chip>
            </template>

            <!-- Wallet balance -->
            <template v-slot:item.balance="{ item }">
                <span class="font-weight-medium">
                    KES {{ item.balance?.toLocaleString() ?? '—' }}
                </span>
            </template>

            <!-- Action buttons -->
            <template v-slot:item.actions="{ item }">
                <v-btn v-if="item.status === 'pending'" size="x-small" color="success" variant="tonal" rounded="lg"
                    @click="approve(item)">Approve</v-btn>
                <v-btn v-if="item.status === 'active'" size="x-small" color="error" variant="tonal" rounded="lg"
                    @click="suspend(item)">Suspend</v-btn>
            </template>
        </v-data-table>

    </v-card>
</template>
