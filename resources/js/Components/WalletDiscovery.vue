<script setup>
import { ref } from 'vue';

const emit = defineEmits(['refresh']);

const candidates = ref([]);
const loading = ref(false);
const adding = ref(new Set());
const scanned = ref(false);
const msg = ref('');
const msgIsError = ref(false);
const timePeriod = ref('WEEK');
const category = ref('OVERALL');

const timePeriods = ['DAY', 'WEEK', 'MONTH', 'ALL'];
const categories = ['OVERALL', 'POLITICS', 'SPORTS', 'CRYPTO', 'CULTURE', 'ECONOMICS', 'TECH', 'FINANCE'];

function showMsg(text, isError = false) {
    msg.value = text;
    msgIsError.value = isError;
    setTimeout(() => { msg.value = ''; }, 4000);
}

async function scan() {
    loading.value = true;
    scanned.value = false;
    try {
        const params = new URLSearchParams({
            time_period: timePeriod.value,
            category: category.value,
        });
        const r = await fetch(`/api/discover?${params}`);
        const d = await r.json();
        candidates.value = d.candidates || [];
        scanned.value = true;
        if (candidates.value.length === 0) {
            showMsg('No candidates found matching thresholds');
        }
    } catch (e) {
        showMsg('Failed to fetch leaderboard', true);
    } finally {
        loading.value = false;
    }
}

async function addWallet(wallet) {
    adding.value.add(wallet);
    try {
        const c = candidates.value.find(c => c.wallet === wallet);
        const r = await fetch('/api/discover', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ candidates: [c] }),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        if (c) c.already_tracked = true;
        showMsg(`Added ${c?.username || wallet.slice(0, 10) + '...'}`);
        emit('refresh');
    } catch (e) {
        showMsg('Failed to add wallet', true);
    } finally {
        adding.value.delete(wallet);
    }
}

async function addAll() {
    const toAdd = candidates.value.filter(c => !c.already_tracked);
    if (toAdd.length === 0) { showMsg('All candidates already tracked'); return; }
    loading.value = true;
    try {
        const r = await fetch('/api/discover', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ candidates: toAdd }),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        toAdd.forEach(c => { c.already_tracked = true; });
        showMsg(`Added ${d.added} wallet(s)`);
        emit('refresh');
    } catch (e) {
        showMsg('Failed to add wallets', true);
    } finally {
        loading.value = false;
    }
}

function profileUrl(c) {
    if (c.username) return `https://polymarket.com/@${c.username}`;
    return `https://polymarket.com/portfolio/${c.wallet}`;
}

function fmtUsd(v) {
    if (v >= 1000000) return '$' + (v / 1000000).toFixed(1) + 'M';
    if (v >= 1000) return '$' + (v / 1000).toFixed(1) + 'K';
    return '$' + v.toFixed(2);
}
</script>

<template>
    <div>
        <!-- Controls -->
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <select v-model="timePeriod"
                    class="bg-gray-800 border border-gray-700 text-gray-300 text-sm rounded px-3 py-1.5">
                <option v-for="tp in timePeriods" :key="tp" :value="tp">{{ tp }}</option>
            </select>
            <select v-model="category"
                    class="bg-gray-800 border border-gray-700 text-gray-300 text-sm rounded px-3 py-1.5">
                <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
            </select>
            <button @click="scan" :disabled="loading"
                    class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-sm px-4 py-1.5 rounded font-medium">
                {{ loading ? 'Scanning...' : 'Scan Leaderboard' }}
            </button>
            <button v-if="scanned && candidates.some(c => !c.already_tracked)"
                    @click="addAll" :disabled="loading"
                    class="bg-green-700 hover:bg-green-600 disabled:opacity-50 text-white text-sm px-4 py-1.5 rounded font-medium">
                Add All ({{ candidates.filter(c => !c.already_tracked).length }})
            </button>
        </div>

        <!-- Message -->
        <div v-if="msg" class="text-sm mb-3 px-1" :class="msgIsError ? 'text-red-400' : 'text-green-400'">
            {{ msg }}
        </div>

        <!-- Results -->
        <div v-if="!scanned && !loading" class="text-gray-500 text-sm py-8 text-center">
            Click "Scan Leaderboard" to discover top-performing traders from Polymarket.
        </div>

        <table v-if="scanned && candidates.length > 0" class="w-full text-left border-collapse">
            <thead>
                <tr>
                    <th class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700">Rank</th>
                    <th class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700">Trader</th>
                    <th class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700">PNL</th>
                    <th class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700">Volume</th>
                    <th class="px-3 py-2 border-b border-gray-700"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in candidates" :key="c.wallet" class="hover:bg-gray-900"
                    :class="{ 'opacity-50': c.already_tracked }">
                    <td class="px-3 py-2 border-b border-gray-800 text-sm text-gray-400">
                        #{{ c.rank }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm">
                        <a :href="profileUrl(c)" target="_blank"
                           class="text-blue-400 hover:text-blue-300 hover:underline font-medium">
                            {{ c.username || (c.wallet.slice(0, 8) + '...' + c.wallet.slice(-4)) }}
                        </a>
                        <div class="font-mono text-xs text-gray-600 mt-0.5">
                            {{ c.wallet.slice(0, 10) }}...{{ c.wallet.slice(-4) }}
                        </div>
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm font-medium"
                        :class="c.pnl >= 0 ? 'text-green-400' : 'text-red-400'">
                        {{ c.pnl >= 0 ? '+' : '' }}{{ fmtUsd(c.pnl) }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-sm text-gray-300">
                        {{ fmtUsd(c.volume) }}
                    </td>
                    <td class="px-3 py-2 border-b border-gray-800 text-right">
                        <span v-if="c.already_tracked"
                              class="bg-gray-700 text-gray-400 px-2 py-0.5 rounded text-xs font-semibold">
                            Tracked
                        </span>
                        <button v-else @click="addWallet(c.wallet)"
                                :disabled="adding.has(c.wallet)"
                                class="bg-green-700 hover:bg-green-600 disabled:opacity-50 text-white text-xs px-3 py-1 rounded">
                            {{ adding.has(c.wallet) ? 'Adding...' : 'Add' }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <div v-if="scanned && candidates.length === 0" class="text-gray-500 text-sm py-8 text-center">
            No candidates found matching the configured thresholds.
        </div>
    </div>
</template>
