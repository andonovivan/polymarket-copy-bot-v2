<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import BalanceBar from '../Components/BalanceBar.vue';
import StatsCards from '../Components/StatsCards.vue';
import PositionsTable from '../Components/PositionsTable.vue';
import TradeHistoryTable from '../Components/TradeHistoryTable.vue';
import WalletsManager from '../Components/WalletsManager.vue';
import WalletReport from '../Components/WalletReport.vue';

const activeTab = ref('dashboard');
const data = ref(null);
const loading = ref(true);
let interval = null;

async function refresh() {
    try {
        const r = await fetch('/api/data');
        data.value = await r.json();
    } catch (e) {
        console.error('Dashboard refresh failed', e);
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    refresh();
    interval = setInterval(refresh, 10000);
});

onUnmounted(() => {
    if (interval) clearInterval(interval);
});

function fmtTime(ts) {
    if (!ts) return '-';
    return new Date(ts * 1000).toLocaleTimeString();
}
</script>

<template>
    <div class="min-h-screen bg-gray-950 text-gray-300 p-5 font-mono">
        <div class="max-w-7xl mx-auto">
            <h1 class="text-2xl font-bold text-blue-400 mb-1">
                Polymarket Copy Bot
                <span v-if="data?.dry_run"
                      class="ml-2 text-xs bg-yellow-600 text-gray-950 px-2 py-0.5 rounded font-bold">
                    DRY RUN
                </span>
            </h1>
            <p class="text-gray-500 text-sm mb-5">
                Tracking {{ data?.tracked_wallets ?? '-' }} wallets &middot;
                Updated {{ data ? fmtTime(data.ts) : '-' }}
            </p>

            <!-- Tabs -->
            <div class="flex border-b border-gray-700 mb-5">
                <button @click="activeTab = 'dashboard'"
                        :class="['px-5 py-2.5 text-sm border-b-2 -mb-px', activeTab === 'dashboard' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                    Dashboard
                </button>
                <button @click="activeTab = 'wallets'"
                        :class="['px-5 py-2.5 text-sm border-b-2 -mb-px', activeTab === 'wallets' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                    Wallets
                </button>
                <button @click="activeTab = 'report'"
                        :class="['px-5 py-2.5 text-sm border-b-2 -mb-px', activeTab === 'report' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                    Report
                </button>
            </div>

            <!-- Dashboard Tab -->
            <div v-show="activeTab === 'dashboard'">
                <BalanceBar v-if="data" :data="data" @refresh="refresh" />
                <StatsCards v-if="data" :data="data" />

                <h2 class="text-blue-400 text-base mt-5 mb-3">Open Positions</h2>
                <PositionsTable v-if="data" :positions="data.positions" @refresh="refresh" />

                <h2 class="text-blue-400 text-base mt-5 mb-3">Recent Closed Trades</h2>
                <TradeHistoryTable v-if="data" :trades="data.recent_trades" />
            </div>

            <!-- Wallets Tab -->
            <div v-show="activeTab === 'wallets'">
                <WalletsManager v-if="data" :wallets="data.tracked_wallets_list" @refresh="refresh" />
            </div>

            <!-- Report Tab -->
            <div v-show="activeTab === 'report'">
                <h2 class="text-blue-400 text-base mb-3">Wallet Performance Report</h2>
                <WalletReport v-if="data" :wallets="data.wallet_report || []" />
            </div>

            <p class="text-gray-600 text-xs mt-6">Auto-refreshes every 10s</p>
        </div>
    </div>
</template>
