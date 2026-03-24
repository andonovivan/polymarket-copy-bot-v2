<script setup>
import { ref, computed } from 'vue';

const props = defineProps({ trades: Array });

const PAGE_SIZE = 10;
const page = ref(0);
const sortKey = ref('closed_at');
const sortAsc = ref(false);

const sorted = computed(() => {
    const items = [...props.trades];
    items.sort((a, b) => {
        let va = a[sortKey.value], vb = b[sortKey.value];
        if (va === null || va === undefined) va = -Infinity;
        if (vb === null || vb === undefined) vb = -Infinity;
        if (typeof va === 'string') return sortAsc.value ? va.localeCompare(vb) : vb.localeCompare(va);
        return sortAsc.value ? va - vb : vb - va;
    });
    return items;
});

const paged = computed(() => sorted.value.slice(page.value * PAGE_SIZE, (page.value + 1) * PAGE_SIZE));
const totalPages = computed(() => Math.max(1, Math.ceil(props.trades.length / PAGE_SIZE)));

function setSort(key) {
    if (sortKey.value === key) { sortAsc.value = !sortAsc.value; }
    else { sortKey.value = key; sortAsc.value = true; }
    page.value = 0;
}

function arrow(key) {
    if (sortKey.value !== key) return '';
    return sortAsc.value ? '▲' : '▼';
}

function shortId(id) { return id ? id.slice(0, 8) + '...' + id.slice(-6) : '-'; }

function fmtUsd(v) {
    if (v === null || v === undefined) return '-';
    const abs = Math.abs(v).toFixed(4);
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

const columns = [
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
        <table class="w-full mb-2">
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
                <tr v-if="trades.length === 0">
                    <td colspan="7" class="text-gray-500 px-3 py-2">No closed trades yet</td>
                </tr>
                <tr v-for="t in paged" :key="t.asset_id + t.closed_at" class="hover:bg-gray-900">
                    <td class="px-3 py-2 border-b border-gray-800 font-mono text-xs text-gray-500">{{ shortId(t.asset_id) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">${{ t.buy_price }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">${{ t.sell_price }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ t.shares }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm" :class="pnlClass(t.pnl)">{{ fmtUsd(t.pnl) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ fmtDate(t.opened_at) }}</td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">{{ fmtDate(t.closed_at) }}</td>
                </tr>
            </tbody>
        </table>
        <div v-if="trades.length > PAGE_SIZE" class="flex items-center gap-2 mt-2">
            <button @click="page = Math.max(0, page - 1)" :disabled="page <= 0"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                ← Prev
            </button>
            <span class="text-gray-500 text-xs">{{ page + 1 }} / {{ totalPages }} ({{ trades.length }} rows)</span>
            <button @click="page = Math.min(totalPages - 1, page + 1)" :disabled="page >= totalPages - 1"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                Next →
            </button>
        </div>
    </div>
</template>
