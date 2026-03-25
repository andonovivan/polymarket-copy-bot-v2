<script setup>
import { ref, watch, onMounted } from 'vue';
import DataTable from './DataTable.vue';
import { fmtUsd, pnlClass, traderLabel, traderUrl } from '../utils/formatters.js';

const emit = defineEmits(['refresh']);
const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const tableRef = ref(null);
const summary = ref({ total: 0, profitable: 0, losing: 0, paused: 0, best_performer: '-', average_score: null });

async function fetchSummary() {
    try {
        const r = await fetch('/api/wallet-report/summary');
        summary.value = await r.json();
    } catch (e) {
        console.error('Failed to fetch wallet report summary', e);
    }
}

onMounted(fetchSummary);
watch(() => props.refreshTrigger, fetchSummary);

const columns = [
    { key: 'name', label: 'Trader' },
    { key: 'composite_score', label: 'Score', sortable: false },
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
        fetchSummary();
    } catch (e) { console.error('Failed to toggle pause', e); }
}

function pauseStatusTag(w) {
    if (!w.is_paused) return { text: 'Active', cls: 'bg-green-900 text-green-400' };
    if (w.pause_reason?.startsWith('auto:')) return { text: 'Paused (Auto)', cls: 'bg-red-800 text-red-300' };
    return { text: 'Paused', cls: 'bg-orange-800 text-orange-300' };
}

function scoreColor(score) {
    if (score === null || score === undefined) return '#6b7280';
    if (score >= 86) return '#4ade80';
    if (score >= 71) return '#22c55e';
    if (score >= 51) return '#eab308';
    if (score >= 31) return '#f97316';
    return '#ef4444';
}

function scoreLabel(score) {
    if (score === null || score === undefined) return 'N/A';
    if (score >= 86) return 'Excellent';
    if (score >= 71) return 'Strong';
    if (score >= 51) return 'Average';
    if (score >= 31) return 'Weak';
    return 'Poor';
}
</script>

<template>
    <DataTable ref="tableRef" apiUrl="/api/wallet-report" :columns="columns"
               defaultSort="combined_pnl" defaultOrder="desc" rowKey="address"
               emptyMessage="No tracked wallets" loadingMessage="Loading report..."
               :refreshTrigger="refreshTrigger" @refresh="emit('refresh')">

        <template #above-table>
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-5">
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Total Wallets</div>
                    <div class="text-lg font-bold text-gray-200">{{ summary.total }}</div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Profitable</div>
                    <div class="text-lg font-bold text-green-400">{{ summary.profitable }}</div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Losing</div>
                    <div class="text-lg font-bold text-red-400">{{ summary.losing }}</div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Paused</div>
                    <div class="text-lg font-bold text-orange-400">{{ summary.paused }}</div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Avg Score</div>
                    <div class="text-lg font-bold" :style="{ color: scoreColor(summary.average_score) }">
                        {{ summary.average_score !== null ? summary.average_score : '-' }}
                    </div>
                </div>
                <div class="bg-gray-900 border border-gray-800 rounded p-3 overflow-hidden">
                    <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">Best Performer</div>
                    <div class="text-lg font-bold text-green-400 truncate">{{ summary.best_performer || '-' }}</div>
                </div>
            </div>
        </template>

        <template #cell-name="{ row }">
            <a :href="traderUrl(row)" target="_blank"
               class="text-blue-400 hover:text-blue-300 hover:underline">
                {{ traderLabel(row) }}
            </a>
        </template>

        <template #cell-composite_score="{ row }">
            <div v-if="row.composite_score !== null" class="relative group inline-block">
                <span class="font-bold text-sm"
                      :style="{ color: scoreColor(row.composite_score) }">
                    {{ row.composite_score }}
                </span>
                <span class="text-xs ml-1" :style="{ color: scoreColor(row.composite_score), opacity: 0.7 }">
                    {{ scoreLabel(row.composite_score) }}
                </span>
                <!-- Hover tooltip with breakdown -->
                <div class="hidden group-hover:block absolute z-20 left-0 top-full mt-1 bg-gray-800 border border-gray-600 rounded-lg p-3 shadow-xl whitespace-nowrap text-xs min-w-[180px]">
                    <div class="font-semibold text-gray-300 mb-2 border-b border-gray-700 pb-1">Score Breakdown</div>
                    <div v-if="row.score_breakdown" class="space-y-1">
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-400">Profit Factor</span>
                            <span class="text-gray-200">{{ row.score_breakdown.profit_factor?.toFixed(0) ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-400">Expectancy</span>
                            <span class="text-gray-200">{{ row.score_breakdown.rolling_expectancy?.toFixed(0) ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-400">Win Rate</span>
                            <span class="text-gray-200">{{ row.score_breakdown.win_rate?.toFixed(0) ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-400">Drawdown</span>
                            <span class="text-gray-200">{{ row.score_breakdown.max_drawdown?.toFixed(0) ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-400">Consistency</span>
                            <span class="text-gray-200">{{ row.score_breakdown.consistency?.toFixed(0) ?? '-' }}</span>
                        </div>
                    </div>
                    <div class="mt-2 pt-1 border-t border-gray-700 text-gray-500">
                        PF: {{ row.profit_factor?.toFixed(2) ?? '-' }} ·
                        Exp: {{ row.rolling_expectancy !== null ? (row.rolling_expectancy >= 0 ? '+' : '') + row.rolling_expectancy.toFixed(3) : '-' }} ·
                        DD: {{ row.max_drawdown_pct?.toFixed(0) ?? '-' }}%
                    </div>
                </div>
            </div>
            <span v-else class="text-gray-600 text-xs">N/A</span>
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
            <th class="px-3 py-2 border-b border-gray-700"></th>
        </template>

        <template #row-actions="{ row }">
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
