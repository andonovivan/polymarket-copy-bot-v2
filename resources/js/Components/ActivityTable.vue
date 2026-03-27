<script setup>
import { ref, computed, watch } from 'vue';
import DataTable from './DataTable.vue';
import { fmtUsd, pnlClass, fmtDate, timeAgo, shortId, traderLabel, traderUrl, marketUrl } from '../utils/formatters.js';

const emit = defineEmits(['refresh']);
const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
});

const activeSubTab = ref('active');
const positionsRef = ref(null);
const activityRef = ref(null);

// Positions tab columns.
const positionColumns = [
    { key: 'market', label: 'Market', sortable: false },
    { key: 'trader_name', label: 'Trader' },
    { key: 'buy_price', label: 'Avg' },
    { key: 'current_price', label: 'Current' },
    { key: 'value', label: 'Value', sortable: false },
    { key: 'opened_at', label: 'Opened' },
];

// Activity tab columns — Polymarket style.
const activityColumns = [
    { key: 'type', label: 'Type', sortable: false },
    { key: 'market', label: 'Market', sortable: false },
    { key: 'trader_name', label: 'Trader', sortable: false },
    { key: 'amount', label: 'Amount' },
];

function fmtPrice(v) {
    if (v === null || v === undefined) return '-';
    if (v < 1) {
        const cents = (v * 100).toFixed(1);
        return `${cents}¢`;
    }
    return `$${v.toFixed(2)}`;
}

function fmtAmount(v) {
    if (v === null || v === undefined) return '-';
    return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pnlPct(row) {
    const cost = row.buy_price * row.shares;
    if (!cost || cost === 0) return '';
    const pnl = row.unrealized_pnl ?? 0;
    const pct = (pnl / cost) * 100;
    return `(${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%)`;
}

function typeClass(type) {
    if (type === 'Buy') return 'text-green-400';
    if (type === 'Sell') return 'text-red-400';
    return 'text-gray-400'; // Redeem
}

function outcomeBadgeClass(outcome) {
    if (!outcome) return 'bg-gray-700 text-gray-300';
    const lower = outcome.toLowerCase();
    if (lower === 'yes') return 'bg-green-900/60 text-green-400';
    if (lower === 'no' || lower.startsWith('no ')) return 'bg-red-900/60 text-red-400';
    return 'bg-green-900/60 text-green-400';
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
        positionsRef.value?.fetchData();
    } catch (e) { alert('Failed to close position'); }
}
</script>

<template>
    <div>
        <!-- Sub-tabs: Positions / Activity -->
        <div class="flex items-center gap-1 mb-4">
            <button @click="activeSubTab = 'active'"
                    :class="[
                        'px-4 py-1.5 text-sm font-medium rounded-md transition-colors',
                        activeSubTab === 'active'
                            ? 'bg-gray-700 text-white'
                            : 'text-gray-400 hover:text-gray-200 hover:bg-gray-800'
                    ]">
                Positions
            </button>
            <button @click="activeSubTab = 'activity'"
                    :class="[
                        'px-4 py-1.5 text-sm font-medium rounded-md transition-colors',
                        activeSubTab === 'activity'
                            ? 'bg-gray-700 text-white'
                            : 'text-gray-400 hover:text-gray-200 hover:bg-gray-800'
                    ]">
                Activity
            </button>
        </div>

        <!-- Active Positions -->
        <DataTable v-if="activeSubTab === 'active'"
                   ref="positionsRef"
                   apiUrl="/api/positions"
                   :columns="positionColumns"
                   defaultSort="opened_at" defaultOrder="desc" rowKey="asset_id"
                   emptyMessage="No open positions" loadingMessage="Loading positions..."
                   :refreshTrigger="refreshTrigger" :extraParams="filters" @refresh="emit('refresh')">

            <!-- Market cell: image + question + outcome badge + shares -->
            <template #cell-market="{ row }">
                <div class="flex items-center gap-3 min-w-0">
                    <img v-if="row.market_image" :src="row.market_image"
                         class="w-9 h-9 rounded-full object-cover shrink-0 bg-gray-800"
                         loading="lazy" @error="$event.target.style.display='none'" />
                    <div v-else class="w-9 h-9 rounded-full bg-gray-800 shrink-0 flex items-center justify-center">
                        <span class="text-gray-600 text-xs">?</span>
                    </div>
                    <div class="min-w-0">
                        <a v-if="marketUrl(row)" :href="marketUrl(row)" target="_blank"
                           class="text-sm text-gray-200 hover:text-white hover:underline line-clamp-1"
                           :title="row.market_question || shortId(row.asset_id)">
                            {{ row.market_question || shortId(row.asset_id) }}
                        </a>
                        <span v-else class="text-sm text-gray-200 line-clamp-1">
                            {{ row.market_question || shortId(row.asset_id) }}
                        </span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span v-if="row.outcome"
                                  :class="outcomeBadgeClass(row.outcome)"
                                  class="text-xs font-semibold px-1.5 py-0.5 rounded">
                                {{ row.outcome }} {{ fmtPrice(row.buy_price) }}
                            </span>
                            <span class="text-xs text-gray-500">
                                {{ Number(row.shares).toLocaleString(undefined, { maximumFractionDigits: 1 }) }} shares
                            </span>
                        </div>
                    </div>
                </div>
            </template>

            <template #cell-trader_name="{ row }">
                <a v-if="traderUrl(row)" :href="traderUrl(row)" target="_blank"
                   class="text-blue-400 hover:text-blue-300 hover:underline text-sm">
                    {{ traderLabel(row) }}
                </a>
                <span v-else class="text-gray-500 text-sm">-</span>
            </template>

            <template #cell-buy_price="{ row }">
                <span class="text-sm">{{ fmtPrice(row.buy_price) }}</span>
            </template>

            <template #cell-current_price="{ row }">
                <span class="text-sm">{{ row.current_price !== null ? fmtPrice(row.current_price) : '-' }}</span>
            </template>

            <!-- Value + P&L -->
            <template #cell-value="{ row }">
                <div class="text-right">
                    <div class="text-sm font-medium text-gray-200">
                        {{ row.current_value !== null ? '$' + row.current_value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '-' }}
                    </div>
                    <div v-if="row.unrealized_pnl !== null"
                         :class="pnlClass(row.unrealized_pnl)"
                         class="text-xs">
                        {{ fmtUsd(row.unrealized_pnl) }} {{ pnlPct(row) }}
                    </div>
                </div>
            </template>

            <template #cell-opened_at="{ row }">
                <span class="text-sm text-gray-400">{{ fmtDate(row.opened_at) }}</span>
            </template>

            <template #extra-headers>
                <th class="px-3 py-2 border-b border-gray-700"></th>
            </template>

            <template #row-actions="{ row }">
                <td class="px-3 py-2 border-b border-gray-800">
                    <button v-if="!row.status || row.status === 'active'"
                            @click="closePosition(row.asset_id)"
                            class="bg-red-600 hover:bg-red-500 text-white text-xs px-3 py-1 rounded whitespace-nowrap">
                        Close
                    </button>
                    <span v-else-if="row.status === 'resolved_won'"
                          class="bg-green-800 text-green-300 px-2 py-0.5 rounded text-xs font-semibold">WON</span>
                    <span v-else-if="row.status === 'resolved_lost'"
                          class="bg-red-800 text-red-300 px-2 py-0.5 rounded text-xs font-semibold">LOST</span>
                    <span v-else-if="row.status === 'resolved_voided'"
                          class="bg-yellow-800 text-yellow-300 px-2 py-0.5 rounded text-xs font-semibold">VOIDED</span>
                </td>
            </template>
        </DataTable>

        <!-- Activity Feed — Polymarket style -->
        <DataTable v-if="activeSubTab === 'activity'"
                   ref="activityRef"
                   apiUrl="/api/activity"
                   :columns="activityColumns"
                   defaultSort="event_ts" defaultOrder="desc" rowKey="row_id"
                   emptyMessage="No activity yet" loadingMessage="Loading activity..."
                   :refreshTrigger="refreshTrigger" :extraParams="filters">

            <!-- Type column -->
            <template #cell-type="{ row }">
                <span :class="typeClass(row.type)" class="text-sm font-medium">
                    {{ row.type }}
                </span>
            </template>

            <!-- Market cell: image + question + outcome badge with price + shares -->
            <template #cell-market="{ row }">
                <div class="flex items-center gap-3 min-w-0">
                    <img v-if="row.market_image" :src="row.market_image"
                         class="w-9 h-9 rounded-full object-cover shrink-0 bg-gray-800"
                         loading="lazy" @error="$event.target.style.display='none'" />
                    <div v-else class="w-9 h-9 rounded-full bg-gray-800 shrink-0 flex items-center justify-center">
                        <span class="text-gray-600 text-xs">?</span>
                    </div>
                    <div class="min-w-0">
                        <a v-if="marketUrl(row)" :href="marketUrl(row)" target="_blank"
                           class="text-sm text-gray-200 hover:text-white hover:underline line-clamp-1"
                           :title="row.market_question || shortId(row.asset_id)">
                            {{ row.market_question || shortId(row.asset_id) }}
                        </a>
                        <span v-else class="text-sm text-gray-200 line-clamp-1">
                            {{ row.market_question || shortId(row.asset_id) }}
                        </span>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span v-if="row.outcome"
                                  :class="outcomeBadgeClass(row.outcome)"
                                  class="text-xs font-semibold px-1.5 py-0.5 rounded">
                                {{ row.outcome }} {{ fmtPrice(row.price) }}
                            </span>
                            <span class="text-xs text-gray-500">
                                {{ Number(row.shares).toLocaleString(undefined, { maximumFractionDigits: 1 }) }} shares
                            </span>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Trader column -->
            <template #cell-trader_name="{ row }">
                <a v-if="traderUrl(row)" :href="traderUrl(row)" target="_blank"
                   class="text-blue-400 hover:text-blue-300 hover:underline text-sm">
                    {{ traderLabel(row) }}
                </a>
                <span v-else class="text-gray-500 text-sm">-</span>
            </template>

            <!-- Amount + relative time -->
            <template #cell-amount="{ row }">
                <div class="text-right">
                    <div class="text-sm font-medium text-gray-200">
                        {{ fmtAmount(row.amount) }}
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ timeAgo(row.event_ts) }}
                    </div>
                </div>
            </template>
        </DataTable>
    </div>
</template>
