<script setup>
const props = defineProps({ data: Object });

function pnlClass(v) {
    if (v > 0) return 'text-green-400';
    if (v < 0) return 'text-red-400';
    return 'text-gray-300';
}

function fmtUsd(v) {
    if (v === null || v === undefined) return '-';
    const abs = Math.abs(v).toFixed(2);
    return v >= 0 ? `+$${abs}` : `-$${abs}`;
}
</script>

<template>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Combined P&L</div>
            <div class="text-2xl font-bold mt-1" :class="pnlClass(data.combined_pnl)">
                {{ fmtUsd(data.combined_pnl) }}
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Unrealized P&L</div>
            <div class="text-2xl font-bold mt-1" :class="pnlClass(data.total_unrealized)">
                {{ fmtUsd(data.total_unrealized) }}
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Realized P&L</div>
            <div class="text-2xl font-bold mt-1" :class="pnlClass(data.realized.total)">
                {{ fmtUsd(data.realized.total) }}
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Win Rate</div>
            <div class="text-2xl font-bold mt-1"
                 :class="data.realized.trades > 0 ? (data.realized.win_rate >= 50 ? 'text-green-400' : 'text-red-400') : 'text-gray-300'">
                {{ data.realized.trades > 0 ? `${data.realized.win_rate}% (${data.realized.winning}/${data.realized.trades})` : '-' }}
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Open Positions</div>
            <div class="text-2xl font-bold mt-1 text-gray-300">{{ data.open_positions_count ?? 0 }}</div>
        </div>
        <div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
            <div class="text-gray-500 text-xs uppercase tracking-wide">Total Invested</div>
            <div class="text-2xl font-bold mt-1 text-gray-300">${{ data.total_cost.toFixed(2) }}</div>
        </div>
    </div>
</template>
