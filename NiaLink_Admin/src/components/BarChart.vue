<script setup>
import { ref } from 'vue'
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS, CategoryScale, LinearScale,
    BarElement, Tooltip
} from 'chart.js'
ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip)

const period = ref('Last 7 days')
const periods = ['Last 7 days', 'Last 30 days', 'Last 90 days']
const activeIndex = ref(1) // Mon 28 highlighted

const labels = ['Sat 26', 'Sun 27', 'Mon 28', 'Tue 29', 'Wed 30', 'Thu 31', 'Fri 01']
const rawData = [3000, 5000, 15000, 7000, 6000, 10000, 12000]

const chartData = {
    labels,
    datasets: [
        {
            label: 'Revenue',
            data: rawData,
            backgroundColor: rawData.map((_, i) =>
                i === activeIndex.value ? '#22C55E' : 'rgba(0,0,0,0.08)'
            ),
            borderRadius: 4,
            barPercentage: 0.55,
            categoryPercentage: 0.7,
        }
    ]
}

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#fff',
            titleColor: '#111',
            bodyColor: '#555',
            borderColor: 'rgba(0,0,0,0.1)',
            borderWidth: 1,
            padding: 10,
            callbacks: {
                label: (ctx) => ` KES ${ctx.parsed.y.toLocaleString()}`
            }
        }
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 11 } }
        },
        y: {
            grid: { color: 'rgba(0,0,0,0.04)' },
            border: { display: false },
            ticks: {
                color: 'rgba(0,0,0,0.4)',
                font: { size: 11 },
                maxTicksLimit: 5,
                callback: (v) => `KES ${(v / 1000).toFixed(0)}k`
            }
        }
    }
}
</script>

<template>
    <v-card variant="outlined" rounded="lg" class="pa-4 chart-card">
        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-1">
            <div>
                <div class="text-caption text-medium-emphasis mb-0">Revenue growth</div>
                <div class="text-h6 font-weight-bold" style="letter-spacing:-0.02em">KES 12,220.64</div>
            </div>
            <v-menu :close-on-content-click="true">
                <template v-slot:activator="{ props }">
                    <button v-bind="props" class="period-btn">
                        {{ period }}
                        <v-icon size="14">mdi-chevron-down</v-icon>
                    </button>
                </template>
                <v-list density="compact" nav style="min-width:140px">
                    <v-list-item v-for="p in periods" :key="p" :title="p" @click="period = p" />
                </v-list>
            </v-menu>
        </div>

        <!-- Chart wrapper: position:relative + explicit height is required by Chart.js -->
        <div class="chart-wrapper">
            <Bar :data="chartData" :options="chartOptions" />
        </div>
    </v-card>
</template>

<style scoped>
.chart-card {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.chart-wrapper {
    position: relative;
    flex: 1;
    min-height: 200px;
    margin-top: 12px;
}

.period-btn {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-size: 12px;
    color: rgba(0, 0, 0, 0.55);
    background: rgba(0, 0, 0, 0.04);
    border: none;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 20px;
    font-family: inherit;
    white-space: nowrap;
}

.period-btn:hover {
    background: rgba(0, 0, 0, 0.08);
}
</style>
