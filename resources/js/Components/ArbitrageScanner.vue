<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const opportunities = ref([]);
const total = ref(0);
const loading = ref(false);
const lastScan = ref(null);

async function fetchData() {
    loading.value = true;
    try {
        const r = await fetch('/api/arbitrage');
        const d = await r.json();
        opportunities.value = d.opportunities || [];
        total.value = d.total || 0;
        lastScan.value = new Date().toLocaleTimeString();
    } catch (e) {
        console.error('Failed to fetch arbitrage data', e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchData);
watch(() => props.refreshTrigger, fetchData);

function deviationClass(deviation) {
    const abs = Math.abs(deviation);
    if (abs >= 10) return 'text-green-400 font-bold';
    if (abs >= 5) return 'text-green-400';
    if (abs >= 2) return 'text-yellow-400';
    return 'text-gray-400';
}

function typeClass(type) {
    return type === 'underround' ? 'bg-green-900 text-green-400' : 'bg-red-900 text-red-400';
}

function typeLabel(type) {
    return type === 'underround' ? 'Under' : 'Over';
}
</script>

<template>
    <div>
        <!-- Summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Opportunities</div>
                <div class="text-lg font-bold text-gray-200">{{ total }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Best Spread</div>
                <div class="text-lg font-bold" :class="total > 0 ? 'text-green-400' : 'text-gray-600'">
                    {{ total > 0 ? Math.abs(opportunities[0].deviation_pct).toFixed(1) + '%' : '-' }}
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Last Scan</div>
                <div class="text-lg font-bold text-gray-200">{{ lastScan || '-' }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3 flex items-center justify-center">
                <button @click="fetchData" :disabled="loading"
                        class="bg-blue-700 hover:bg-blue-600 text-white text-sm font-semibold px-4 py-2 rounded transition-colors disabled:opacity-50">
                    {{ loading ? 'Scanning...' : 'Scan Now' }}
                </button>
            </div>
        </div>

        <!-- Opportunities table -->
        <div class="bg-gray-900 border border-gray-800 rounded overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-500 text-xs uppercase tracking-wide">
                        <th class="px-3 py-2 text-left border-b border-gray-700">Event</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Outcomes</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Price Sum</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Deviation</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading && opportunities.length === 0">
                        <td colspan="5" class="px-3 py-8 text-center text-gray-500">Scanning markets...</td>
                    </tr>
                    <tr v-else-if="opportunities.length === 0">
                        <td colspan="5" class="px-3 py-8 text-center text-gray-500">No arbitrage opportunities found above threshold</td>
                    </tr>
                    <tr v-for="opp in opportunities" :key="opp.event_slug"
                        class="border-b border-gray-800 hover:bg-gray-800/50">
                        <td class="px-3 py-2">
                            <a :href="'https://polymarket.com/event/' + opp.event_slug" target="_blank"
                               class="text-blue-400 hover:text-blue-300 hover:underline">
                                {{ opp.event_title }}
                            </a>
                        </td>
                        <td class="px-3 py-2 text-gray-300">{{ opp.market_count }}</td>
                        <td class="px-3 py-2 text-gray-300">{{ opp.price_sum.toFixed(4) }}</td>
                        <td class="px-3 py-2" :class="deviationClass(opp.deviation_pct)">
                            {{ opp.deviation_pct > 0 ? '+' : '' }}{{ opp.deviation_pct.toFixed(2) }}%
                        </td>
                        <td class="px-3 py-2">
                            <span :class="typeClass(opp.type)"
                                  class="px-2 py-0.5 rounded text-xs font-semibold">
                                {{ typeLabel(opp.type) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
