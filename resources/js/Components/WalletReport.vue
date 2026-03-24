<script setup>
import { ref, computed } from 'vue';

const props = defineProps({ wallets: Array });

const sortKey = ref('combined_pnl');
const sortAsc = ref(false);

const sorted = computed(() => {
    const items = [...props.wallets];
    items.sort((a, b) => {
        let va = a[sortKey.value], vb = b[sortKey.value];
        if (va === null || va === undefined) va = -Infinity;
        if (vb === null || vb === undefined) vb = -Infinity;
        if (typeof va === 'string') return sortAsc.value ? va.localeCompare(vb) : vb.localeCompare(va);
        return sortAsc.value ? va - vb : vb - va;
    });
    return items;
});

function setSort(key) {
    if (sortKey.value === key) { sortAsc.value = !sortAsc.value; }
    else { sortKey.value = key; sortAsc.value = true; }
}

function arrow(key) {
    if (sortKey.value !== key) return '';
    return sortAsc.value ? '▲' : '▼';
}

function fmtUsd(v) {
    if (v === null || v === undefined) return '-';
    const abs = Math.abs(v).toFixed(2);
    return v >= 0 ? `+$${abs}` : `-$${abs}`;
}

function pnlClass(v) {
    if (v > 0) return 'text-green-400';
    if (v < 0) return 'text-red-400';
    return 'text-gray-300';
}

function traderLabel(w) {
    return w.name || w.address.slice(0, 8) + '...';
}

function traderUrl(w) {
    if (w.profile_slug) return `https://polymarket.com/@${w.profile_slug}`;
    return `https://polymarket.com/portfolio/${w.address}`;
}

function performanceTag(w) {
    if (w.total_trades === 0 && w.open_positions === 0) return { text: 'NO DATA', cls: 'bg-gray-700 text-gray-400' };
    if (w.combined_pnl > 0 && w.win_rate >= 55) return { text: 'STRONG', cls: 'bg-green-800 text-green-300' };
    if (w.combined_pnl > 0) return { text: 'GOOD', cls: 'bg-green-900 text-green-400' };
    if (w.combined_pnl === 0) return { text: 'NEUTRAL', cls: 'bg-gray-700 text-gray-300' };
    if (w.combined_pnl > -2) return { text: 'WEAK', cls: 'bg-yellow-900 text-yellow-300' };
    return { text: 'POOR', cls: 'bg-red-900 text-red-300' };
}

const columns = [
    { key: 'name', label: 'Trader' },
    { key: 'combined_pnl', label: 'Combined P&L' },
    { key: 'realized_pnl', label: 'Realized' },
    { key: 'unrealized_pnl', label: 'Unrealized' },
    { key: 'win_rate', label: 'Win Rate' },
    { key: 'total_trades', label: 'Trades' },
    { key: 'open_positions', label: 'Open' },
    { key: 'total_invested', label: 'Invested' },
];
</script>

<template>
    <div>
        <!-- Summary cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Total Wallets</div>
                <div class="text-lg font-bold text-gray-200">{{ wallets.length }}</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Profitable</div>
                <div class="text-lg font-bold text-green-400">
                    {{ wallets.filter(w => w.combined_pnl > 0).length }}
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Losing</div>
                <div class="text-lg font-bold text-red-400">
                    {{ wallets.filter(w => w.combined_pnl < 0).length }}
                </div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded p-3">
                <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Best Performer</div>
                <div class="text-lg font-bold" :class="pnlClass(sorted.length ? sorted[sortKey === 'combined_pnl' && !sortAsc ? 0 : sorted.length - 1]?.combined_pnl : 0)">
                    {{ wallets.length ? traderLabel(wallets.reduce((best, w) => w.combined_pnl > best.combined_pnl ? w : best, wallets[0])) : '-' }}
                </div>
            </div>
        </div>

        <!-- Table -->
        <table class="w-full mb-2">
            <thead>
                <tr>
                    <th v-for="col in columns" :key="col.key"
                        @click="setSort(col.key)"
                        class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700 cursor-pointer hover:text-gray-300 select-none whitespace-nowrap">
                        {{ col.label }} <span class="text-[0.6em] ml-1">{{ arrow(col.key) }}</span>
                    </th>
                    <th class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700 whitespace-nowrap">
                        Rating
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="wallets.length === 0">
                    <td colspan="9" class="text-gray-500 px-3 py-2">No tracked wallets</td>
                </tr>
                <tr v-for="w in sorted" :key="w.address" class="hover:bg-gray-900">
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <a :href="traderUrl(w)" target="_blank"
                           class="text-blue-400 hover:text-blue-300 hover:underline">
                            {{ traderLabel(w) }}
                        </a>
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm font-semibold" :class="pnlClass(w.combined_pnl)">
                        {{ fmtUsd(w.combined_pnl) }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm" :class="pnlClass(w.realized_pnl)">
                        {{ fmtUsd(w.realized_pnl) }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm" :class="pnlClass(w.unrealized_pnl)">
                        {{ fmtUsd(w.unrealized_pnl) }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <span :class="w.win_rate >= 55 ? 'text-green-400' : w.win_rate >= 45 ? 'text-gray-300' : 'text-red-400'">
                            {{ w.total_trades > 0 ? w.win_rate + '%' : '-' }}
                        </span>
                        <span class="text-gray-600 text-xs ml-1" v-if="w.total_trades > 0">
                            ({{ w.winning_trades }}W / {{ w.losing_trades }}L)
                        </span>
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm text-gray-300">
                        {{ w.total_trades }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm text-gray-300">
                        {{ w.open_positions }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm text-gray-300">
                        ${{ w.total_invested.toFixed(2) }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <span :class="performanceTag(w).cls"
                              class="px-2 py-0.5 rounded text-xs font-semibold">
                            {{ performanceTag(w).text }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
