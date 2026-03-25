<script setup>
import { ref } from 'vue';
import DataTable from './DataTable.vue';
import { fmtUsd, pnlClass, fmtDate, shortId, traderLabel, traderUrl } from '../utils/formatters.js';

const emit = defineEmits(['refresh']);
const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const tableRef = ref(null);

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

function statusBadge(status) {
    if (status === 'resolved_won') return { text: 'WON', cls: 'bg-green-800 text-green-300' };
    if (status === 'resolved_lost') return { text: 'LOST', cls: 'bg-red-800 text-red-300' };
    if (status === 'resolved_voided') return { text: 'VOIDED', cls: 'bg-yellow-800 text-yellow-300' };
    return null;
}

async function closePosition(assetId) {
    const short = assetId.slice(0, 8) + '...' + assetId.slice(-6);
    if (!confirm(`Close position ${short} at current market price?`)) return;
    try {
        const r = await fetch('/api/close', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ asset_id: assetId }),
        });
        const d = await r.json();
        if (d.error) { alert('Failed: ' + d.error); return; }
        alert(`Position closed at $${d.price} | P&L: ${d.pnl >= 0 ? '+' : ''}$${d.pnl.toFixed(2)}`);
        emit('refresh');
        tableRef.value?.fetchData();
    } catch (e) { alert('Failed to close position'); }
}
</script>

<template>
    <DataTable ref="tableRef" apiUrl="/api/positions" :columns="columns"
               defaultSort="opened_at" defaultOrder="desc" rowKey="asset_id"
               emptyMessage="No open positions" loadingMessage="Loading positions..."
               :refreshTrigger="refreshTrigger" @refresh="emit('refresh')">

        <template #cell-trader_name="{ row }">
            <a v-if="traderUrl(row)" :href="traderUrl(row)" target="_blank"
               class="text-blue-400 hover:text-blue-300 hover:underline">
                {{ traderLabel(row) }}
            </a>
            <span v-else class="text-gray-500">-</span>
        </template>

        <template #cell-asset_id="{ row }">
            <span class="font-mono text-xs text-gray-500">{{ shortId(row.asset_id) }}</span>
        </template>

        <template #cell-shares="{ row }">{{ row.shares.toFixed(2) }}</template>
        <template #cell-buy_price="{ row }">${{ row.buy_price.toFixed(2) }}</template>
        <template #cell-current_price="{ row }">{{ row.current_price !== null ? '$' + row.current_price.toFixed(2) : '-' }}</template>
        <template #cell-cost="{ row }">${{ row.cost.toFixed(2) }}</template>
        <template #cell-current_value="{ row }">{{ row.current_value !== null ? '$' + row.current_value.toFixed(2) : '-' }}</template>

        <template #cell-unrealized_pnl="{ row }">
            <span :class="pnlClass(row.unrealized_pnl)">
                {{ row.unrealized_pnl !== null ? fmtUsd(row.unrealized_pnl) : '-' }}
            </span>
        </template>

        <template #cell-opened_at="{ row }">{{ fmtDate(row.opened_at) }}</template>

        <template #cell-status="{ row }">
            <span v-if="statusBadge(row.status)" :class="statusBadge(row.status).cls"
                  class="px-2 py-0.5 rounded text-xs font-semibold">
                {{ statusBadge(row.status).text }}
            </span>
            <span v-else class="text-gray-600 text-xs">Active</span>
        </template>

        <template #extra-headers>
            <th class="px-3 py-2 border-b border-gray-700"></th>
        </template>

        <template #row-actions="{ row }">
            <td class="px-3 py-2 border-b border-gray-800">
                <button v-if="!row.status || row.status === 'active'"
                        @click="closePosition(row.asset_id)"
                        class="bg-red-600 hover:bg-red-500 text-white text-xs px-3 py-1 rounded">
                    Close
                </button>
            </td>
        </template>
    </DataTable>
</template>
