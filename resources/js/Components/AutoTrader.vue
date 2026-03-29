<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const candidates = ref([]);
const total = ref(0);
const loading = ref(false);
const lastScan = ref(null);

async function fetchData() {
    loading.value = true;
    try {
        const r = await fetch('/api/snipe-candidates');
        const d = await r.json();
        candidates.value = d.candidates || [];
        total.value = d.total || 0;
        lastScan.value = new Date().toLocaleTimeString();
    } catch (e) {
        console.error('Failed to fetch snipe candidates', e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchData);
// Refresh every 5 min max (candidates don't change rapidly).
let lastFetch = 0;
watch(() => props.refreshTrigger, () => {
    if (Date.now() - lastFetch > 300000) {
        lastFetch = Date.now();
        fetchData();
    }
});

function profitClass(pct) {
    if (pct >= 10) return 'text-green-400 font-bold';
    if (pct >= 5) return 'text-green-400';
    return 'text-yellow-400';
}

function hoursClass(hours) {
    if (hours <= 6) return 'text-red-400 font-bold';
    if (hours <= 24) return 'text-orange-400';
    return 'text-gray-300';
}

function fmtHours(h) {
    if (h < 1) return Math.round(h * 60) + 'm';
    if (h < 24) return h.toFixed(1) + 'h';
    return (h / 24).toFixed(1) + 'd';
}
</script>

<template>
    <div>
        <!-- Summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Candidates</div>
                <div class="text-lg font-bold text-gray-200">{{ total }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Best Profit</div>
                <div class="text-lg font-bold" :class="total > 0 ? 'text-green-400' : 'text-gray-600'">
                    {{ total > 0 ? candidates[0].potential_profit_pct.toFixed(1) + '%' : '-' }}
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Next Resolution</div>
                <div class="text-lg font-bold" :class="total > 0 ? hoursClass(candidates[0].hours_until_end) : 'text-gray-600'">
                    {{ total > 0 ? fmtHours(candidates[0].hours_until_end) : '-' }}
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3 flex items-center justify-center">
                <button @click="fetchData" :disabled="loading"
                        class="bg-blue-700 hover:bg-blue-600 text-white text-sm font-semibold px-4 py-2 rounded transition-colors disabled:opacity-50">
                    {{ loading ? 'Scanning...' : 'Scan Now' }}
                </button>
            </div>
        </div>

        <!-- Candidates table -->
        <div class="bg-gray-900 border border-gray-800 rounded overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-500 text-xs uppercase tracking-wide">
                        <th class="px-3 py-2 text-left border-b border-gray-700">Market</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Outcome</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Price</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Resolves In</th>
                        <th class="px-3 py-2 text-left border-b border-gray-700">Potential Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading && candidates.length === 0">
                        <td colspan="5" class="px-3 py-8 text-center text-gray-500">Scanning markets...</td>
                    </tr>
                    <tr v-else-if="candidates.length === 0">
                        <td colspan="5" class="px-3 py-8 text-center text-gray-500">No candidates found — scanning markets resolving within the configured window</td>
                    </tr>
                    <tr v-for="c in candidates" :key="c.token_id"
                        class="border-b border-gray-800 hover:bg-gray-800/50">
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-2">
                                <img v-if="c.image" :src="c.image" alt=""
                                     class="w-7 h-7 rounded-full object-cover shrink-0 bg-gray-800"
                                     loading="lazy" @error="$event.target.style.display='none'" />
                                <a :href="'https://polymarket.com/event/' + c.slug" target="_blank"
                                   class="text-blue-400 hover:text-blue-300 hover:underline">
                                    {{ c.question }}
                                </a>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-green-900 text-green-400">
                                {{ c.outcome }} {{ (c.price * 100).toFixed(1) }}%
                            </span>
                        </td>
                        <td class="px-3 py-2 text-gray-300">${{ c.price.toFixed(3) }}</td>
                        <td class="px-3 py-2" :class="hoursClass(c.hours_until_end)">
                            {{ fmtHours(c.hours_until_end) }}
                        </td>
                        <td class="px-3 py-2" :class="profitClass(c.potential_profit_pct)">
                            +{{ c.potential_profit_pct.toFixed(1) }}%
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
