<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});
const emit = defineEmits(['refresh']);

const PAGE_SIZE = 10;
const page = ref(1);
const sortKey = ref('opened_at');
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
        const r = await fetch(`/api/positions?${params}`);
        const d = await r.json();
        rows.value = d.data;
        total.value = d.total;
        lastPage.value = d.last_page;
        // If current page is beyond last page, go back.
        if (page.value > d.last_page && d.last_page > 0) {
            page.value = d.last_page;
            await fetchData();
        }
    } catch (e) {
        console.error('Failed to fetch positions', e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchData);

// Re-fetch when parent triggers a refresh (10s timer or manual action).
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

async function closePosition(assetId) {
    const short = assetId.slice(0,8) + '...' + assetId.slice(-6);
    if (!confirm(`Close position ${short} at current market price?`)) return;
    try {
        const r = await fetch('/api/close', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({asset_id: assetId}),
        });
        const d = await r.json();
        if (d.error) { alert('Failed: ' + d.error); return; }
        alert(`Position closed at $${d.price} | P&L: ${d.pnl >= 0 ? '+' : ''}$${d.pnl.toFixed(2)}`);
        emit('refresh');
        fetchData();
    } catch(e) { alert('Failed to close position'); }
}

function statusBadge(status) {
    if (status === 'resolved_won') return { text: 'WON', cls: 'bg-green-800 text-green-300' };
    if (status === 'resolved_lost') return { text: 'LOST', cls: 'bg-red-800 text-red-300' };
    if (status === 'resolved_voided') return { text: 'VOIDED', cls: 'bg-yellow-800 text-yellow-300' };
    return null;
}

function traderLabel(p) {
    return p.trader_name || (p.trader_wallet ? p.trader_wallet.slice(0, 8) + '...' : '-');
}

function traderUrl(p) {
    if (p.trader_slug) return `https://polymarket.com/@${p.trader_slug}`;
    if (p.trader_wallet) return `https://polymarket.com/portfolio/${p.trader_wallet}`;
    return null;
}

const columns = [
    { key: 'trader_name', label: 'Trader' },
    { key: 'asset_id', label: 'Asset' },
    { key: 'shares', label: 'Shares' },
    { key: 'buy_price', label: 'Buy Price' },
    { key: 'current_price', label: 'Current' },
    { key: 'cost', label: 'Cost' },
    { key: 'current_value', label: 'Value' },
    { key: 'unrealized_pnl', label: 'P&L' },
    { key: 'opened_at', label: 'Opened' },
    { key: 'status', label: 'Status' },
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
            <span class="ml-2 text-gray-500 text-sm">Loading positions...</span>
        </div>

        <table v-else class="w-full mb-2">
            <thead>
                <tr>
                    <th v-for="col in columns" :key="col.key"
                        @click="setSort(col.key)"
                        class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700 cursor-pointer hover:text-gray-300 select-none whitespace-nowrap">
                        {{ col.label }} <span class="text-[0.6em] ml-1">{{ arrow(col.key) }}</span>
                    </th>
                    <th class="px-3 py-2 border-b border-gray-700"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td colspan="11" class="text-gray-500 px-3 py-2">No open positions</td>
                </tr>
                <tr v-for="p in rows" :key="p.asset_id" class="hover:bg-gray-900">
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <a v-if="traderUrl(p)" :href="traderUrl(p)" target="_blank"
                           class="text-blue-400 hover:text-blue-300 hover:underline">
                            {{ traderLabel(p) }}
                        </a>
                        <span v-else class="text-gray-500">-</span>
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 font-mono text-xs text-gray-500">{{ shortId(p.asset_id) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ p.shares.toFixed(2) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">${{ p.buy_price.toFixed(2) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ p.current_price !== null ? '$' + p.current_price.toFixed(2) : '-' }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">${{ p.cost.toFixed(2) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ p.current_value !== null ? '$' + p.current_value.toFixed(2) : '-' }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm" :class="pnlClass(p.unrealized_pnl)">
                        {{ p.unrealized_pnl !== null ? fmtUsd(p.unrealized_pnl) : '-' }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ fmtDate(p.opened_at) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <span v-if="statusBadge(p.status)" :class="statusBadge(p.status).cls"
                              class="px-2 py-0.5 rounded text-xs font-semibold">
                            {{ statusBadge(p.status).text }}
                        </span>
                        <span v-else class="text-gray-600 text-xs">Active</span>
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800">
                        <button v-if="!p.status || p.status === 'active'"
                                @click="closePosition(p.asset_id)"
                                class="bg-red-600 hover:bg-red-500 text-white text-xs px-3 py-1 rounded">
                            Close
                        </button>
                    </td>
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
