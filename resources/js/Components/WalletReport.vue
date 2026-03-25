<script setup>
import { ref } from 'vue';
import DataTable from './DataTable.vue';
import { fmtUsd, pnlClass, traderLabel, traderUrl } from '../utils/formatters.js';

const emit = defineEmits(['refresh']);
const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const tableRef = ref(null);

const columns = [
    { key: 'name', label: 'Trader' },
    { key: 'combined_pnl', label: 'Combined P&L' },
    { key: 'realized_pnl', label: 'Realized' },
    { key: 'unrealized_pnl', label: 'Unrealized' },
    { key: 'win_rate', label: 'Win Rate' },
    { key: 'total_trades', label: 'Trades' },
    { key: 'open_positions', label: 'Open' },
    { key: 'total_invested', label: 'Invested' },
    { key: 'is_paused', label: 'Status' },
];

async function togglePause(addr, paused) {
    try {
        const r = await fetch('/api/wallets/pause', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wallet: addr, paused }),
        });
        const d = await r.json();
        if (d.error) { console.error(d.error); return; }
        emit('refresh');
        tableRef.value?.fetchData();
    } catch (e) { console.error('Failed to toggle pause', e); }
}

function pauseStatusTag(w) {
    if (!w.is_paused) return { text: 'Active', cls: 'bg-green-900 text-green-400' };
    if (w.pause_reason?.startsWith('auto:')) return { text: 'Paused (Auto)', cls: 'bg-red-800 text-red-300' };
    return { text: 'Paused', cls: 'bg-orange-800 text-orange-300' };
}

function performanceTag(w) {
    if (w.total_trades === 0 && w.open_positions === 0) return { text: 'NO DATA', cls: 'bg-gray-700 text-gray-400' };
    if (w.combined_pnl > 0 && w.win_rate >= 55) return { text: 'STRONG', cls: 'bg-green-800 text-green-300' };
    if (w.combined_pnl > 0) return { text: 'GOOD', cls: 'bg-green-900 text-green-400' };
    if (w.combined_pnl === 0) return { text: 'NEUTRAL', cls: 'bg-gray-700 text-gray-300' };
    if (w.combined_pnl > -2) return { text: 'WEAK', cls: 'bg-yellow-900 text-yellow-300' };
    return { text: 'POOR', cls: 'bg-red-900 text-red-300' };
}
</script>

<template>
    <DataTable ref="tableRef" apiUrl="/api/wallet-report" :columns="columns"
               defaultSort="combined_pnl" defaultOrder="desc" rowKey="address"
               emptyMessage="No tracked wallets" loadingMessage="Loading report..."
               :refreshTrigger="refreshTrigger" @refresh="emit('refresh')">

        <template #above-table="{ rows, total, lastPage, sortKey, sortOrder }">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Total Wallets</div>
                    <div class="text-lg font-bold text-gray-200">{{ total }}</div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Profitable</div>
                    <div class="text-lg font-bold text-green-400">
                        {{ rows.filter(w => w.combined_pnl > 0).length }}<span v-if="lastPage > 1" class="text-gray-600 text-xs">+</span>
                    </div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Losing</div>
                    <div class="text-lg font-bold text-red-400">
                        {{ rows.filter(w => w.combined_pnl < 0).length }}<span v-if="lastPage > 1" class="text-gray-600 text-xs">+</span>
                    </div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Paused</div>
                    <div class="text-lg font-bold text-orange-400">
                        {{ rows.filter(w => w.is_paused).length }}<span v-if="lastPage > 1" class="text-gray-600 text-xs">+</span>
                    </div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3 overflow-hidden">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Best Performer</div>
                    <div class="text-lg font-bold truncate" :class="pnlClass(rows.length ? rows[0]?.combined_pnl : 0)">
                        {{ rows.length && sortKey === 'combined_pnl' && sortOrder === 'desc' ? traderLabel(rows[0]) : '-' }}
                    </div>
                </div>
            </div>
        </template>

        <template #cell-name="{ row }">
            <a :href="traderUrl(row)" target="_blank"
               class="text-blue-400 hover:text-blue-300 hover:underline">
                {{ traderLabel(row) }}
            </a>
        </template>

        <template #cell-combined_pnl="{ row }">
            <span class="font-semibold" :class="pnlClass(row.combined_pnl)">{{ fmtUsd(row.combined_pnl) }}</span>
        </template>

        <template #cell-realized_pnl="{ row }">
            <span :class="pnlClass(row.realized_pnl)">{{ fmtUsd(row.realized_pnl) }}</span>
        </template>

        <template #cell-unrealized_pnl="{ row }">
            <span :class="pnlClass(row.unrealized_pnl)">{{ fmtUsd(row.unrealized_pnl) }}</span>
        </template>

        <template #cell-win_rate="{ row }">
            <span :class="row.win_rate >= 55 ? 'text-green-400' : row.win_rate >= 45 ? 'text-gray-300' : 'text-red-400'">
                {{ row.total_trades > 0 ? row.win_rate + '%' : '-' }}
            </span>
            <span class="text-gray-600 text-xs ml-1" v-if="row.total_trades > 0">
                ({{ row.winning_trades }}W / {{ row.losing_trades }}L)
            </span>
        </template>

        <template #cell-total_trades="{ value }">
            <span class="text-gray-300">{{ value }}</span>
        </template>

        <template #cell-open_positions="{ value }">
            <span class="text-gray-300">{{ value }}</span>
        </template>

        <template #cell-total_invested="{ row }">
            <span class="text-gray-300">${{ row.total_invested.toFixed(2) }}</span>
        </template>

        <template #cell-is_paused="{ row }">
            <span :class="pauseStatusTag(row).cls"
                  class="px-2 py-0.5 rounded text-xs font-semibold">
                {{ pauseStatusTag(row).text }}
            </span>
        </template>

        <template #extra-headers>
            <th class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700 whitespace-nowrap">
                Rating
            </th>
            <th class="px-3 py-2 border-b border-gray-700"></th>
        </template>

        <template #row-actions="{ row }">
            <td class="px-3 py-2 border-b border-gray-800 text-sm">
                <span :class="performanceTag(row).cls"
                      class="px-2 py-0.5 rounded text-xs font-semibold">
                    {{ performanceTag(row).text }}
                </span>
            </td>
            <td class="px-3 py-2 border-b border-gray-800">
                <button v-if="!row.is_paused" @click="togglePause(row.address, true)"
                        class="bg-orange-700 hover:bg-orange-600 text-white text-xs px-3 py-1 rounded">
                    Pause
                </button>
                <button v-else @click="togglePause(row.address, false)"
                        class="bg-green-700 hover:bg-green-600 text-white text-xs px-3 py-1 rounded">
                    Resume
                </button>
            </td>
        </template>
    </DataTable>
</template>
