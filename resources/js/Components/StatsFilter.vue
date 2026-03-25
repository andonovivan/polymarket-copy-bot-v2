<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    refreshTrigger: { type: Number, default: 0 },
});

const emit = defineEmits(['change']);

const wallets = ref([]);
const selectedWallets = ref([]);
const selectedPeriod = ref('ALL');
const dropdownOpen = ref(false);
const searchQuery = ref('');

const periods = [
    { value: '1D', label: '1D' },
    { value: '1W', label: '1W' },
    { value: '1M', label: '1M' },
    { value: 'ALL', label: 'All' },
];

const filteredWallets = computed(() => {
    if (!searchQuery.value) return wallets.value;
    const q = searchQuery.value.toLowerCase();
    return wallets.value.filter(w =>
        (w.name && w.name.toLowerCase().includes(q)) ||
        w.address.toLowerCase().includes(q)
    );
});

const selectedCount = computed(() => selectedWallets.value.length);

const filterLabel = computed(() => {
    if (selectedCount.value === 0) return 'All Wallets';
    if (selectedCount.value === 1) {
        const w = wallets.value.find(w => w.address === selectedWallets.value[0]);
        return w?.name || shortAddr(selectedWallets.value[0]);
    }
    return `${selectedCount.value} wallets`;
});

function shortAddr(addr) {
    return addr.slice(0, 6) + '...' + addr.slice(-4);
}

function walletLabel(w) {
    return w.name || shortAddr(w.address);
}

function toggleWallet(address) {
    const idx = selectedWallets.value.indexOf(address);
    if (idx === -1) {
        selectedWallets.value.push(address);
    } else {
        selectedWallets.value.splice(idx, 1);
    }
    emitChange();
}

function selectAll() {
    selectedWallets.value = [];
    emitChange();
}

function setPeriod(p) {
    selectedPeriod.value = p;
    emitChange();
}

function emitChange() {
    emit('change', {
        wallets: [...selectedWallets.value],
        period: selectedPeriod.value,
    });
}

async function fetchWallets() {
    try {
        const r = await fetch('/api/wallets');
        const d = await r.json();
        wallets.value = d.data || [];
    } catch (e) {
        console.error('Failed to fetch wallets for filter', e);
    }
}

function handleClickOutside(e) {
    const el = document.getElementById('wallet-filter-dropdown');
    if (el && !el.contains(e.target)) {
        dropdownOpen.value = false;
    }
}

onMounted(() => {
    fetchWallets();
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

watch(() => props.refreshTrigger, fetchWallets);
</script>

<template>
    <div class="flex items-center gap-4 mb-4">
        <!-- Wallet Filter -->
        <div id="wallet-filter-dropdown" class="relative">
            <button @click.stop="dropdownOpen = !dropdownOpen"
                    class="flex items-center gap-2 bg-gray-900 border border-gray-700 hover:border-gray-500 rounded-lg px-3 py-1.5 text-sm transition-colors">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                </svg>
                <span :class="selectedCount > 0 ? 'text-blue-400' : 'text-gray-400'">{{ filterLabel }}</span>
                <svg class="w-3 h-3 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div v-if="dropdownOpen"
                 class="absolute top-full left-0 mt-1 w-72 bg-gray-900 border border-gray-700 rounded-lg shadow-xl z-50 overflow-hidden">
                <!-- Search -->
                <div class="p-2 border-b border-gray-700">
                    <input v-model="searchQuery" type="text" placeholder="Search wallets..."
                           class="w-full bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-gray-300 focus:border-blue-400 focus:outline-none" />
                </div>

                <!-- All option -->
                <button @click="selectAll"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-gray-800 flex items-center gap-2 border-b border-gray-800">
                    <span class="w-4 h-4 flex items-center justify-center rounded border"
                          :class="selectedCount === 0 ? 'border-blue-400 bg-blue-400' : 'border-gray-600'">
                        <svg v-if="selectedCount === 0" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>
                    <span class="text-gray-300">All Wallets</span>
                </button>

                <!-- Wallet list -->
                <div class="max-h-60 overflow-y-auto">
                    <button v-for="w in filteredWallets" :key="w.address"
                            @click="toggleWallet(w.address)"
                            class="w-full text-left px-3 py-2 text-sm hover:bg-gray-800 flex items-center gap-2">
                        <span class="w-4 h-4 flex items-center justify-center rounded border shrink-0"
                              :class="selectedWallets.includes(w.address) ? 'border-blue-400 bg-blue-400' : 'border-gray-600'">
                            <svg v-if="selectedWallets.includes(w.address)" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        <span class="text-gray-300 truncate">{{ walletLabel(w) }}</span>
                        <span class="text-gray-600 text-xs ml-auto shrink-0">{{ shortAddr(w.address) }}</span>
                    </button>
                    <div v-if="filteredWallets.length === 0" class="px-3 py-2 text-gray-600 text-sm">
                        No wallets found
                    </div>
                </div>
            </div>
        </div>

        <!-- Time Period Filter -->
        <div class="flex bg-gray-900 border border-gray-700 rounded-lg overflow-hidden">
            <button v-for="p in periods" :key="p.value"
                    @click="setPeriod(p.value)"
                    :class="[
                        'px-3 py-1.5 text-sm font-medium transition-colors',
                        selectedPeriod === p.value
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-400 hover:text-gray-200 hover:bg-gray-800'
                    ]">
                {{ p.label }}
            </button>
        </div>

        <!-- Clear filters -->
        <button v-if="selectedCount > 0 || selectedPeriod !== 'ALL'"
                @click="selectedWallets = []; selectedPeriod = 'ALL'; emitChange()"
                class="text-xs text-gray-500 hover:text-gray-300 transition-colors">
            Clear filters
        </button>
    </div>
</template>
