<script setup>
defineProps({
    label: String,
    value: String,
    icon: { type: String, default: 'mdi-chart-line' },
    trend: { type: String, default: null },
    trendUp: { type: Boolean, default: true },
})
</script>

<template>
    <v-card variant="outlined" rounded="lg" class="pa-4 stat-card">

        <!-- Top row: icon + 3-dot menu -->
        <div class="d-flex align-center justify-space-between mb-3">
            <v-avatar
                color="primary"
                variant="tonal"
                size="40"
                rounded="md"
                class="icon-circle"
            >
                <v-icon size="20" color="primary">{{ icon }}</v-icon>
            </v-avatar>
            <v-btn
                icon="mdi-dots-horizontal"
                variant="text"
                size="x-small"
                density="compact"
                class="text-medium-emphasis"
            />
        </div>

        <!-- Label -->
        <div class="text-caption text-medium-emphasis font-weight-medium mb-1" style="letter-spacing:0.01em">
            {{ label }}
        </div>

        <!-- Value -->
        <div class="text-h5 font-weight-bold mb-3" style="letter-spacing:-0.02em;line-height:1.2">
            {{ value }}
        </div>

        <!-- Trend line -->
        <div v-if="trend" class="d-flex align-center gap-1">
            <v-icon size="13" :color="trendUp ? 'success' : 'error'">
                {{ trendUp ? 'mdi-trending-up' : 'mdi-trending-down' }}
            </v-icon>
            <span
                class="text-caption"
                :class="trendUp ? 'text-success' : 'text-error'"
                style="font-weight:600"
            >
                {{ trend }}
            </span>
            <span class="text-caption text-medium-emphasis ml-1">Increase last Month</span>
        </div>
        <div v-else class="text-caption text-medium-emphasis">
            <v-icon size="12" color="primary" class="mr-1">mdi-arrow-right</v-icon>
            Live data
        </div>

    </v-card>
</template>

<style scoped>
.stat-card {
    transition: box-shadow 0.15s ease, transform 0.15s ease;
    border-color: rgba(0, 0, 0, 0.08) !important;
    background: #FFFFFF;
}

.stat-card:hover {
    box-shadow: 0 4px 16px rgba(43, 157, 143, 0.1) !important;
    transform: translateY(-1px);
}

.icon-circle {
    background: rgba(43, 157, 143, 0.1) !important;
}
</style>
