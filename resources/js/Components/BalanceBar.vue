<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    data: Object,
});

const emit = defineEmits(['refresh']);

const editing = ref(false);
const editValue = ref('');
const saving = ref(false);
const errorMsg = ref('');

const polymarketBalance = computed(() => {
    if (props.data?.dry_run) return null;
    const val = props.data?.polymarket_balance;
    return val !== null && val !== undefined && val !== '' ? parseFloat(val) : null;
});

const tradingBalance = computed(() => {
    const val = props.data?.trading_balance;
    return val !== null && val !== undefined && val !== '' ? parseFloat(val) : null;
});

const totalInvested = computed(() => {
    return props.data?.total_cost ?? 0;
});

const realizedPnl = computed(() => {
    return props.data?.realized?.total ?? 0;
});

// Polymarket-style: Available = Trading Limit - Invested + Realized P&L.
// Profits expand available capital, losses shrink it.
const available = computed(() => {
    if (tradingBalance.value === null) return null;
    return tradingBalance.value - totalInvested.value + realizedPnl.value;
});

// Used percent based on how much capital is deployed vs total effective capital.
const effectiveCapital = computed(() => {
    if (tradingBalance.value === null || tradingBalance.value <= 0) return 0;
    return tradingBalance.value + realizedPnl.value;
});

const usedPercent = computed(() => {
    if (effectiveCapital.value <= 0) return 0;
    return Math.min(100, (totalInvested.value / effectiveCapital.value) * 100);
});

const barColor = computed(() => {
    if (usedPercent.value >= 95) return 'bg-red-500';
    if (usedPercent.value >= 75) return 'bg-yellow-500';
    return 'bg-blue-500';
});

function startEdit() {
    editValue.value = tradingBalance.value !== null ? tradingBalance.value.toString() : '';
    errorMsg.value = '';
    editing.value = true;
}

function cancelEdit() {
    editing.value = false;
}

async function saveBalance() {
    saving.value = true;
    errorMsg.value = '';
    try {
        const val = String(editValue.value ?? '').trim();
        const numVal = val === '' ? null : parseFloat(val);

        // Client-side validation: cannot exceed Polymarket balance when not in dry-run.
        if (!props.data?.dry_run && polymarketBalance.value !== null && numVal !== null && numVal > polymarketBalance.value) {
            errorMsg.value = `Cannot exceed Polymarket balance ($${polymarketBalance.value.toFixed(2)})`;
            return;
        }

        const resp = await fetch('/api/balance', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trading_balance: numVal }),
        });
        const result = await resp.json();
        if (!resp.ok) {
            errorMsg.value = result.error || 'Failed to save';
            return;
        }
        editing.value = false;
        emit('refresh');
    } catch (e) {
        console.error('Failed to save balance', e);
        errorMsg.value = 'Network error';
    } finally {
        saving.value = false;
    }
}

function handleKeydown(e) {
    if (e.key === 'Enter') saveBalance();
    if (e.key === 'Escape') cancelEdit();
}

function fmtUsd(v) {
    if (v === null || v === undefined) return '-';
    return '$' + Math.abs(v).toFixed(2);
}
</script>

<template>
    <div class="bg-gray-900 border border-gray-700 rounded-lg p-4 mb-4">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <!-- Polymarket Balance -->
            <div class="flex items-center gap-2">
                <span class="text-gray-500 text-xs uppercase tracking-wide">Polymarket Balance</span>
                <span class="text-lg font-bold" :class="polymarketBalance !== null ? 'text-gray-300' : 'text-gray-600'">
                    {{ polymarketBalance !== null ? fmtUsd(polymarketBalance) : (data?.dry_run ? 'N/A (Dry Run)' : 'Loading...') }}
                </span>
            </div>

            <!-- Divider -->
            <div class="hidden sm:block w-px h-8 bg-gray-700"></div>

            <!-- Trading Balance -->
            <div class="flex items-center gap-2">
                <span class="text-gray-500 text-xs uppercase tracking-wide">Trading Balance Limit</span>
                <template v-if="!editing">
                    <span class="text-lg font-bold" :class="tradingBalance !== null ? 'text-blue-400' : 'text-gray-600'">
                        {{ tradingBalance !== null ? fmtUsd(tradingBalance) : 'No Limit' }}
                    </span>
                    <button @click="startEdit"
                            class="text-xs text-gray-500 hover:text-blue-400 border border-gray-700 hover:border-blue-400 rounded px-2 py-0.5 transition-colors">
                        Edit
                    </button>
                </template>
                <template v-else>
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-1">
                            <span class="text-gray-500">$</span>
                            <input
                                v-model="editValue"
                                @keydown="handleKeydown"
                                type="number"
                                min="0"
                                step="1"
                                :max="!data?.dry_run && polymarketBalance !== null ? polymarketBalance : undefined"
                                placeholder="No limit"
                                class="bg-gray-800 border border-gray-600 rounded px-2 py-1 text-sm text-gray-300 w-28 focus:border-blue-400 focus:outline-none"
                                autofocus
                            />
                            <button @click="saveBalance" :disabled="saving"
                                    class="text-xs bg-blue-600 hover:bg-blue-500 text-white rounded px-2 py-1 transition-colors disabled:opacity-50">
                                Save
                            </button>
                            <button @click="cancelEdit"
                                    class="text-xs text-gray-500 hover:text-gray-300 px-1">
                                Cancel
                            </button>
                        </div>
                        <span v-if="errorMsg" class="text-red-400 text-xs">{{ errorMsg }}</span>
                    </div>
                </template>
            </div>

            <!-- Divider -->
            <div class="hidden sm:block w-px h-8 bg-gray-700"></div>

            <!-- Available -->
            <div class="flex items-center gap-2">
                <span class="text-gray-500 text-xs uppercase tracking-wide">Available</span>
                <span class="text-lg font-bold"
                      :class="available === null ? 'text-gray-600' : available > 0 ? 'text-green-400' : 'text-red-400'">
                    {{ available !== null ? fmtUsd(available) : 'Unlimited' }}
                </span>
            </div>
        </div>

        <!-- Progress bar -->
        <div v-if="tradingBalance !== null && tradingBalance > 0" class="mt-3">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>{{ fmtUsd(totalInvested) }} invested of {{ fmtUsd(effectiveCapital) }}</span>
                <span>{{ usedPercent.toFixed(1) }}% used</span>
            </div>
            <div class="w-full bg-gray-800 rounded-full h-2">
                <div :class="['h-2 rounded-full transition-all duration-500', barColor]"
                     :style="{ width: usedPercent + '%' }">
                </div>
            </div>
        </div>
    </div>
</template>
