<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const PAGE_SIZE = 10;
const page = ref(1);
const sortKey = ref('closed_at');
const sortOrder = ref('desc');
const rows = ref([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(false);

async function fetchData() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            page: page.value,
            per_page: PAGE_SIZE,
            sort: sortKey.value,
            order: sortOrder.value,
        });
        const r = await fetch(`/api/trades?${params}`);
        const d = await r.json();
        rows.value = d.data;
        total.value = d.total;
        lastPage.value = d.last_page;
        if (page.value > d.last_page && d.last_page > 0) {
            page.value = d.last_page;
            await fetchData();
        }
    } catch (e) {
        console.error('Failed to fetch trades', e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchData);

watch(() => props.refreshTrigger, fetchData);

function setSort(key) {
    if (sortKey.value === key) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortOrder.value = 'asc';
    }
    page.value = 1;
    fetchData();
}

function goPage(p) {
    page.value = p;
    fetchData();
}

function arrow(key) {
    if (sortKey.value !== key) return '';
    return sortOrder.value === 'asc' ? '\u25B2' : '\u25BC';
}

function shortId(id) { return id ? id.slice(0, 8) + '...' + id.slice(-6) : '-'; }

function fmtUsd(v) {
    if (v === null || v === undefined) return '-';
    const abs = Math.abs(v).toFixed(2);
    return v >= 0 ? `+$${abs}` : `-$${abs}`;
}

function fmtDate(ts) {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString(undefined, {month:'short', day:'numeric'}) + ' ' +
           d.toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit'});
}

function pnlClass(v) {
    if (v > 0) return 'text-green-400';
    if (v < 0) return 'text-red-400';
    return 'text-gray-300';
}

function traderLabel(t) {
    return t.trader_name || (t.trader_wallet ? t.trader_wallet.slice(0, 8) + '...' : '-');
}

function traderUrl(t) {
    if (t.trader_slug) return `https://polymarket.com/@${t.trader_slug}`;
    if (t.trader_wallet) return `https://polymarket.com/portfolio/${t.trader_wallet}`;
    return null;
}

const columns = [
    { key: 'trader_name', label: 'Trader' },
    { key: 'asset_id', label: 'Asset' },
    { key: 'buy_price', label: 'Buy' },
    { key: 'sell_price', label: 'Sell' },
    { key: 'shares', label: 'Shares' },
    { key: 'pnl', label: 'P&L' },
    { key: 'opened_at', label: 'Opened' },
    { key: 'closed_at', label: 'Closed' },
];
</script>

<template>
    <div>
        <!-- Loading spinner (initial load only) -->
        <div v-if="loading && rows.length === 0" class="flex items-center justify-center py-8">
            <svg class="animate-spin h-6 w-6 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="ml-2 text-gray-500 text-sm">Loading trades...</span>
        </div>

        <table v-else class="w-full mb-2">
            <thead>
                <tr>
                    <th v-for="col in columns" :key="col.key"
                        @click="setSort(col.key)"
                        class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700 cursor-pointer hover:text-gray-300 select-none whitespace-nowrap">
                        {{ col.label }} <span class="text-[0.6em] ml-1">{{ arrow(col.key) }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td colspan="8" class="text-gray-500 px-3 py-2">No closed trades yet</td>
                </tr>
                <tr v-for="t in rows" :key="t.asset_id + t.closed_at" class="hover:bg-gray-900">
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <a v-if="traderUrl(t)" :href="traderUrl(t)" target="_blank"
                           class="text-blue-400 hover:text-blue-300 hover:underline">
                            {{ traderLabel(t) }}
                        </a>
                        <span v-else class="text-gray-500">-</span>
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 font-mono text-xs text-gray-500">{{ shortId(t.asset_id) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">${{ t.buy_price.toFixed(2) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">${{ t.sell_price.toFixed(2) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ t.shares.toFixed(2) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm" :class="pnlClass(t.pnl)">{{ fmtUsd(t.pnl) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ fmtDate(t.opened_at) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ fmtDate(t.closed_at) }}</td>
                </tr>
            </tbody>
        </table>
        <div v-if="total > PAGE_SIZE" class="flex items-center gap-2 mt-2">
            <button @click="goPage(Math.max(1, page - 1))" :disabled="page <= 1"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                &larr; Prev
            </button>
            <span class="text-gray-500 text-xs">{{ page }} / {{ lastPage }} ({{ total }} rows)</span>
            <button @click="goPage(Math.min(lastPage, page + 1))" :disabled="page >= lastPage"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                Next &rarr;
            </button>
        </div>
    </div>
</template>
