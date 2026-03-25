<script setup>
import DataTable from './DataTable.vue';
import { fmtUsd, pnlClass, fmtDate, shortId, traderLabel, traderUrl } from '../utils/formatters.js';

defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

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
    <DataTable apiUrl="/api/trades" :columns="columns"
               defaultSort="closed_at" defaultOrder="desc" rowKey="asset_id"
               emptyMessage="No closed trades yet" loadingMessage="Loading trades..."
               :refreshTrigger="refreshTrigger">

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

        <template #cell-buy_price="{ row }">${{ row.buy_price.toFixed(2) }}</template>
        <template #cell-sell_price="{ row }">${{ row.sell_price.toFixed(2) }}</template>
        <template #cell-shares="{ row }">{{ row.shares.toFixed(2) }}</template>

        <template #cell-pnl="{ row }">
            <span :class="pnlClass(row.pnl)">{{ fmtUsd(row.pnl) }}</span>
        </template>

        <template #cell-opened_at="{ row }">{{ fmtDate(row.opened_at) }}</template>
        <template #cell-closed_at="{ row }">{{ fmtDate(row.closed_at) }}</template>
    </DataTable>
</template>
