<script setup>
import { ref, computed } from 'vue';

const props = defineProps({ positions: Array });
const emit = defineEmits(['refresh']);

const PAGE_SIZE = 10;
const page = ref(0);
const sortKey = ref('opened_at');
const sortAsc = ref(false);

const sorted = computed(() => {
    const items = [...props.positions];
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
const totalPages = computed(() => Math.max(1, Math.ceil(props.positions.length / PAGE_SIZE)));

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
        <table class="w-full mb-2">
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
                <tr v-if="positions.length === 0">
                    <td colspan="11" class="text-gray-500 px-3 py-2">No open positions</td>
                </tr>
                <tr v-for="p in paged" :key="p.asset_id" class="hover:bg-gray-900">
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
        <div v-if="positions.length > PAGE_SIZE" class="flex items-center gap-2 mt-2">
            <button @click="page = Math.max(0, page - 1)" :disabled="page <= 0"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                ← Prev
            </button>
            <span class="text-gray-500 text-xs">{{ page + 1 }} / {{ totalPages }} ({{ positions.length }} rows)</span>
            <button @click="page = Math.min(totalPages - 1, page + 1)" :disabled="page >= totalPages - 1"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                Next →
            </button>
        </div>
    </div>
</template>
