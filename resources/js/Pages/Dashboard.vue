<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import BalanceBar from '../Components/BalanceBar.vue';
import PnlChart from '../Components/PnlChart.vue';
import StatsCards from '../Components/StatsCards.vue';
import StatsFilter from '../Components/StatsFilter.vue';
import ActivityTable from '../Components/ActivityTable.vue';
import WalletsManager from '../Components/WalletsManager.vue';
import WalletReport from '../Components/WalletReport.vue';
import WalletDiscovery from '../Components/WalletDiscovery.vue';
import AutoTrader from '../Components/AutoTrader.vue';
import Settings from '../Components/Settings.vue';

const activeTab = ref('dashboard');
const data = ref(null);         // Unfiltered — used by BalanceBar, header, global buttons.
const statsData = ref(null);    // Filtered — used by StatsCards and tables.
const loading = ref(true);
const pauseLoading = ref(false);
const closeAllLoading = ref(false);

// Stats filters.
const statsFilters = ref({ wallets: [], period: 'ALL' });
const hasFilters = computed(() => statsFilters.value.wallets.length > 0 || statsFilters.value.period !== 'ALL');

// Extra params object for DataTable components (derived from filters).
const tableFilterParams = computed(() => {
    const p = {};
    if (statsFilters.value.wallets.length > 0) {
        p['wallets[]'] = statsFilters.value.wallets;
    }
    if (statsFilters.value.period !== 'ALL') {
        p.period = statsFilters.value.period;
    }
    return p;
});

// Per-tab refresh triggers — only the active tab gets incremented.
const dashboardRefresh = ref(0);
const walletsRefresh = ref(0);
const reportRefresh = ref(0);
const arbRefresh = ref(0);

let interval = null;

async function fetchUnfilteredStats() {
    try {
        const r = await fetch('/api/data');
        data.value = await r.json();
    } catch (e) {
        console.error('Dashboard refresh failed', e);
    } finally {
        loading.value = false;
    }
}

async function fetchFilteredStats() {
    if (!hasFilters.value) {
        statsData.value = null;
        return;
    }
    try {
        const params = new URLSearchParams();
        if (statsFilters.value.period !== 'ALL') {
            params.set('period', statsFilters.value.period);
        }
        statsFilters.value.wallets.forEach(w => params.append('wallets[]', w));
        const r = await fetch('/api/data?' + params.toString());
        statsData.value = await r.json();
    } catch (e) {
        console.error('Filtered stats refresh failed', e);
    }
}

function fetchStats() {
    const promises = [fetchUnfilteredStats()];
    if (hasFilters.value) promises.push(fetchFilteredStats());
    return Promise.all(promises);
}

// The data StatsCards should display: filtered if active, unfiltered otherwise.
const displayData = computed(() => hasFilters.value && statsData.value ? statsData.value : data.value);

function onFilterChange(filters) {
    statsFilters.value = filters;
    // Clear stale filtered data immediately so displayData falls back to unfiltered while loading.
    if (!hasFilters.value) statsData.value = null;
    fetchFilteredStats();
}

function refresh() {
    fetchStats();
    // Trigger re-fetch only on the active tab's components.
    if (activeTab.value === 'dashboard') dashboardRefresh.value++;
    else if (activeTab.value === 'wallets') walletsRefresh.value++;
    else if (activeTab.value === 'report') reportRefresh.value++;
    else if (activeTab.value === 'arbitrage') arbRefresh.value++;
}

async function toggleGlobalPause() {
    const newState = !data.value?.global_paused;
    if (newState && !confirm('Pause the bot? No new trades will be copied until you resume.')) return;
    pauseLoading.value = true;
    try {
        await fetch('/api/global-pause', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ paused: newState }),
        });
        refresh();
    } catch (e) {
        console.error('Failed to toggle global pause', e);
    } finally {
        pauseLoading.value = false;
    }
}

async function closeAllPositions() {
    const count = data.value?.open_positions_count ?? 0;
    if (!confirm(`Close all ${count} open positions? This will sell everything at current market prices.`)) return;
    closeAllLoading.value = true;
    try {
        const r = await fetch('/api/close-all', { method: 'POST' });
        const d = await r.json();
        alert(`Closed ${d.closed} position(s)` + (d.failed > 0 ? `, ${d.failed} failed` : ''));
        refresh();
    } catch (e) {
        console.error('Failed to close all', e);
        alert('Failed to close positions');
    } finally {
        closeAllLoading.value = false;
    }
}

// On tab switch, bump the newly active tab's trigger so it fetches fresh data.
watch(activeTab, (newTab) => {
    if (newTab === 'dashboard') dashboardRefresh.value++;
    else if (newTab === 'wallets') walletsRefresh.value++;
    else if (newTab === 'report') reportRefresh.value++;
    else if (newTab === 'arbitrage') arbRefresh.value++;
});

onMounted(() => {
    fetchStats();
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
            <div class="flex items-start justify-between mb-1">
                <div>
                    <h1 class="text-2xl font-bold text-blue-400">
                        Polymarket Bot
                        <span v-if="data?.dry_run"
                              class="ml-2 text-xs bg-yellow-600 text-gray-950 px-2 py-0.5 rounded font-bold align-middle">
                            DRY RUN
                        </span>
                        <span v-if="data?.global_paused"
                              class="ml-2 text-xs bg-red-600 text-white px-2 py-0.5 rounded font-bold align-middle animate-pulse">
                            BOT PAUSED
                        </span>
                    </h1>
                    <p class="text-gray-500 text-sm mt-1">
                        Tracking {{ data?.tracked_wallets ?? '-' }} wallets &middot;
                        Updated {{ data ? fmtTime(data.ts) : '-' }}
                    </p>
                </div>
                <div class="flex gap-2 shrink-0 ml-4">
                    <button @click="toggleGlobalPause" :disabled="pauseLoading"
                            :class="[
                                'text-sm font-semibold px-4 py-2 rounded transition-colors disabled:opacity-50',
                                data?.global_paused
                                    ? 'bg-green-700 hover:bg-green-600 text-white'
                                    : 'bg-orange-600 hover:bg-orange-500 text-white'
                            ]">
                        {{ data?.global_paused ? '&#9654; Resume Bot' : '&#9208; Pause Bot' }}
                    </button>
                    <button @click="closeAllPositions" :disabled="closeAllLoading || (data?.open_positions_count ?? 0) === 0"
                            class="text-sm font-semibold px-4 py-2 rounded bg-red-700 hover:bg-red-600 text-white transition-colors disabled:opacity-50">
                        {{ closeAllLoading ? 'Closing...' : 'Close All' }}
                    </button>
                    <button @click="router.post('/logout')"
                            class="text-sm px-3 py-2 rounded text-gray-400 hover:text-gray-200 hover:bg-gray-800 transition-colors">
                        Logout
                    </button>
                </div>
            </div>
            <div class="mb-5"></div>

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
                <button @click="activeTab = 'discover'"
                        :class="['px-5 py-2.5 text-sm border-b-2 -mb-px', activeTab === 'discover' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                    Discover
                </button>
                <button @click="activeTab = 'arbitrage'"
                        :class="['px-5 py-2.5 text-sm border-b-2 -mb-px', activeTab === 'arbitrage' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                    Auto Trader
                </button>
                <button @click="activeTab = 'settings'"
                        :class="['px-5 py-2.5 text-sm border-b-2 -mb-px', activeTab === 'settings' ? 'text-blue-400 border-blue-400' : 'text-gray-500 border-transparent hover:text-gray-300']">
                    Settings
                </button>
            </div>

            <!-- Dashboard Tab -->
            <div v-if="activeTab === 'dashboard'">
                <BalanceBar v-if="data" :data="data" @refresh="refresh" />
                <PnlChart :refreshTrigger="dashboardRefresh" />
                <StatsFilter :refreshTrigger="dashboardRefresh" @change="onFilterChange" />
                <StatsCards v-if="displayData" :data="displayData" />

                <div class="mt-5">
                    <ActivityTable :refreshTrigger="dashboardRefresh" :filters="tableFilterParams" @refresh="refresh" />
                </div>
            </div>

            <!-- Wallets Tab -->
            <div v-if="activeTab === 'wallets'">
                <WalletsManager :refreshTrigger="walletsRefresh" @refresh="refresh" />
            </div>

            <!-- Report Tab -->
            <div v-if="activeTab === 'report'">
                <h2 class="text-blue-400 text-base mb-3">Wallet Performance Report</h2>
                <WalletReport :refreshTrigger="reportRefresh" @refresh="refresh" />
            </div>

            <!-- Discover Tab -->
            <div v-if="activeTab === 'discover'">
                <h2 class="text-blue-400 text-base mb-3">Discover Top Traders</h2>
                <WalletDiscovery @refresh="refresh" />
            </div>

            <!-- Auto Trader Tab -->
            <div v-if="activeTab === 'arbitrage'">
                <h2 class="text-blue-400 text-base mb-3">Auto Trader</h2>
                <AutoTrader :refreshTrigger="arbRefresh" />
            </div>

            <!-- Settings Tab -->
            <div v-if="activeTab === 'settings'">
                <h2 class="text-blue-400 text-base mb-3">Bot Settings</h2>
                <Settings />
            </div>

            <p class="text-gray-600 text-xs mt-6">Auto-refreshes every 10s</p>
        </div>
    </div>
</template>
