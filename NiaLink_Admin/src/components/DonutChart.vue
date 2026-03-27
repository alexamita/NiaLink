<script setup>
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip } from 'chart.js'
ChartJS.register(ArcElement, Tooltip)

const total = '15,000'
const legend = [
    { label: 'Customers', color: '#7C3AED', value: '1,254' },
    { label: 'Subscription', color: '#F59E0B', value: '1,145' },
]
const chartData = {
    labels: ['Customers', 'Subscription'],
    datasets: [{
        data: [1254, 1145],
        backgroundColor: ['#7C3AED', '#F59E0B'],
        borderWidth: 0,
        cutout: '72%',
    }]
}
const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { enabled: true } },
}
</script>

<template>
    <v-card variant="outlined" rounded="lg" class="pa-4">
        <div class="text-subtitle-2 font-weight-bold mb-3">
            Net Revenue
        </div>
        <div style="position:relative;height:160px">
            <Doughnut :data="chartData" :options="options" />
            <!-- Centre label -->
            <div style="position:absolute;inset:0;display:flex;
                flex-direction:column;align-items:center;
                justify-content:center;pointer-events:none">
                <div class="text-caption text-medium-emphasis">Revenue</div>
                <div class="text-h6 font-weight-bold">KES {{ total }}</div>
            </div>
        </div>
        <!-- Legend -->
        <div class="d-flex justify-space-around mt-3">
            <div v-for="item in legend" :key="item.label" class="text-center">
                <div class="d-flex align-center gap-1 justify-center mb-1">
                    <div :style="`width:8px;height:8px;border-radius:50%;
                        background:${item.color}`" />
                    <span class="text-caption text-medium-emphasis">
                        {{ item.label }}
                    </span>
                </div>
                <div class="text-body-2 font-weight-bold">{{ item.value }}</div>
            </div>
        </div>
    </v-card>
</template>
