<script setup>
import { ref, computed } from 'vue'
import { Chart } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Filler,
    Tooltip,
    Legend,
} from 'chart.js'

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Filler,
    Tooltip,
    Legend,
)

const props = defineProps({
    transactions: {
        type: Array,
        default: () => [],
    },
})

const period = ref('This Year')
const periods = ['This Year', 'Last 6 Months', 'Last Quarter']

const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// Aggregate transaction data by month
const chartData = computed(() => {
    // Build month buckets
    const p2mByMonth = new Array(12).fill(0)
    const p2pByMonth = new Array(12).fill(0)
    const feeByMonth = new Array(12).fill(0)

    const txList = Array.isArray(props.transactions) ? props.transactions : []

    for (const tx of txList) {
        if (!tx.created_at) continue
        const month = new Date(tx.created_at).getMonth() // 0-11
        const amount = Number(tx.amount || 0)
        const fee = Number(tx.fee || 0)

        if (tx.type === 'p2m') {
            p2mByMonth[month] += amount
        } else if (tx.type === 'p2p') {
            p2pByMonth[month] += amount
        }
        feeByMonth[month] += fee
    }

    // If no real data, show placeholder values so chart isn't blank
    const hasData = txList.length > 0
    const p2mData = hasData ? p2mByMonth : [12000, 18000, 14000, 22000, 19000, 25000, 21000, 28000, 23000, 30000, 26000, 32000]
    const p2pData = hasData ? p2pByMonth : [5000, 8000, 6000, 10000, 8500, 12000, 9000, 14000, 11000, 15000, 12000, 16000]
    const feeData = hasData ? feeByMonth : [800, 1200, 950, 1500, 1300, 1800, 1400, 2100, 1700, 2200, 1900, 2400]

    return {
        labels: monthLabels,
        datasets: [
            {
                type: 'bar',
                label: 'P2M Volume',
                data: p2mData,
                backgroundColor: '#2B9D8F',
                borderRadius: 4,
                stack: 'volume',
                order: 2,
            },
            {
                type: 'bar',
                label: 'P2P Volume',
                data: p2pData,
                backgroundColor: 'rgba(43,157,143,0.35)',
                borderRadius: 4,
                stack: 'volume',
                order: 3,
            },
            {
                type: 'line',
                label: 'Fee Revenue',
                data: feeData,
                borderColor: '#F59E0B',
                backgroundColor: 'rgba(245,158,11,0.08)',
                fill: false,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#F59E0B',
                borderWidth: 2,
                order: 1,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            display: true,
            position: 'bottom',
            labels: {
                color: 'rgba(0,0,0,0.5)',
                font: { size: 11 },
                boxWidth: 10,
                boxHeight: 10,
                padding: 16,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: '#fff',
            titleColor: '#111',
            bodyColor: '#555',
            borderColor: 'rgba(0,0,0,0.1)',
            borderWidth: 1,
            padding: 10,
            callbacks: {
                label: (ctx) => ` KES ${Number(ctx.parsed.y || 0).toLocaleString()}`,
            },
        },
    },
    scales: {
        x: {
            stacked: true,
            grid: { display: false },
            border: { display: false },
            ticks: { color: 'rgba(0,0,0,0.4)', font: { size: 11 } },
        },
        y: {
            stacked: true,
            grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
            border: { display: false },
            ticks: {
                color: 'rgba(0,0,0,0.4)',
                font: { size: 11 },
                maxTicksLimit: 6,
                callback: (v) => {
                    if (v >= 1000) return `${(v / 1000).toFixed(0)}k`
                    return v
                },
            },
        },
    },
}
</script>

<template>
    <v-card variant="outlined" rounded="lg" class="pa-4 chart-card">

        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-4">
            <div>
                <div class="text-body-2 font-weight-bold" style="letter-spacing:-0.01em">Cash Flow Summary</div>
                <div class="text-caption text-medium-emphasis mt-0.5">Monthly income, expenses & fee revenue</div>
            </div>
            <v-menu :close-on-content-click="true">
                <template v-slot:activator="{ props: menuProps }">
                    <button v-bind="menuProps" class="period-btn">
                        {{ period }}
                        <v-icon size="14">mdi-chevron-down</v-icon>
                    </button>
                </template>
                <v-list density="compact" nav style="min-width:140px">
                    <v-list-item v-for="p in periods" :key="p" :title="p" @click="period = p" />
                </v-list>
            </v-menu>
        </div>

        <!-- Chart -->
        <div class="chart-wrapper">
            <Chart type="bar" :data="chartData" :options="chartOptions" />
        </div>

    </v-card>
</template>

<style scoped>
.chart-card {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: #fff;
    border-color: rgba(0, 0, 0, 0.08) !important;
}

.chart-wrapper {
    position: relative;
    flex: 1;
    min-height: 220px;
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
