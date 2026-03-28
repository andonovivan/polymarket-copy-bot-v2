<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const period = ref('ALL');
const points = ref([]);
const loading = ref(false);
const canvasRef = ref(null);
let chartInstance = null;

async function fetchData() {
    loading.value = true;
    try {
        const r = await fetch(`/api/pnl-chart?period=${period.value}`);
        const d = await r.json();
        points.value = d.points || [];
        await nextTick();
        renderChart();
    } catch (e) {
        console.error('Failed to fetch P&L chart data', e);
    } finally {
        loading.value = false;
    }
}

function renderChart() {
    if (!canvasRef.value || points.value.length === 0) return;

    if (chartInstance) {
        chartInstance.destroy();
    }

    const labels = points.value.map(p => {
        const d = new Date(p.ts * 1000);
        if (period.value === '1D') {
            return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    });

    const data = points.value.map(p => p.combined);
    const lastValue = data.length > 0 ? data[data.length - 1] : 0;
    const isPositive = lastValue >= 0;

    // Gradient fill
    const ctx = canvasRef.value.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, canvasRef.value.height);
    if (isPositive) {
        gradient.addColorStop(0, 'rgba(74, 222, 128, 0.3)');
        gradient.addColorStop(1, 'rgba(74, 222, 128, 0)');
    } else {
        gradient.addColorStop(0, 'rgba(248, 113, 113, 0)');
        gradient.addColorStop(1, 'rgba(248, 113, 113, 0.3)');
    }

    chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data,
                borderColor: isPositive ? '#4ade80' : '#f87171',
                backgroundColor: gradient,
                borderWidth: 2,
                fill: true,
                pointRadius: 0,
                pointHitRadius: 8,
                tension: 0.3,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: (ctx) => {
                            const v = ctx.parsed.y;
                            const abs = Math.abs(v).toFixed(2);
                            return v >= 0 ? `+$${abs}` : `-$${abs}`;
                        },
                    },
                    backgroundColor: '#1f2937',
                    titleColor: '#9ca3af',
                    bodyColor: '#e5e7eb',
                    borderColor: '#374151',
                    borderWidth: 1,
                },
            },
            scales: {
                x: {
                    display: true,
                    grid: { color: 'rgba(55, 65, 81, 0.3)' },
                    ticks: {
                        color: '#6b7280',
                        maxTicksLimit: 8,
                        font: { size: 10 },
                    },
                },
                y: {
                    display: true,
                    grid: { color: 'rgba(55, 65, 81, 0.3)' },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 10 },
                        callback: (v) => {
                            const abs = Math.abs(v).toFixed(2);
                            return v >= 0 ? `+$${abs}` : `-$${abs}`;
                        },
                    },
                },
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false,
            },
        },
    });
}

function setPeriod(p) {
    period.value = p;
    fetchData();
}

onMounted(fetchData);
// Only refresh chart every 5 minutes (snapshots update every 30 min, no need for 10s polling).
let lastFetch = 0;
watch(() => props.refreshTrigger, () => {
    if (Date.now() - lastFetch > 300000) {
        lastFetch = Date.now();
        fetchData();
    }
});

onUnmounted(() => {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }
});
</script>

<template>
    <div class="bg-gray-900 border border-gray-700 rounded-lg p-4 mb-6">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <span class="text-gray-500 text-xs uppercase tracking-wide">Profit / Loss</span>
            </div>
            <div class="flex gap-1">
                <button v-for="p in ['1D', '1W', '1M', 'ALL']" :key="p"
                        @click="setPeriod(p)"
                        :class="[
                            'px-2.5 py-1 text-xs rounded font-medium transition-colors',
                            period === p
                                ? 'bg-blue-700 text-white'
                                : 'text-gray-500 hover:text-gray-300 hover:bg-gray-800'
                        ]">
                    {{ p }}
                </button>
            </div>
        </div>

        <div class="relative" style="height: 200px;">
            <div v-if="loading && points.length === 0"
                 class="absolute inset-0 flex items-center justify-center text-gray-500 text-sm">
                Loading chart...
            </div>
            <div v-else-if="points.length === 0"
                 class="absolute inset-0 flex items-center justify-center text-gray-600 text-sm">
                No data yet — snapshots are recorded every 30 minutes
            </div>
            <canvas v-show="points.length > 0" ref="canvasRef"></canvas>
        </div>
    </div>
</template>
