<script setup>
import { ref, computed } from 'vue';

const props = defineProps({ wallets: Array });
const emit = defineEmits(['refresh']);

const PAGE_SIZE = 10;
const page = ref(0);
const walletInput = ref('');
const msg = ref('');
const msgIsError = ref(false);
let msgTimeout = null;

const paged = computed(() => props.wallets.slice(page.value * PAGE_SIZE, (page.value + 1) * PAGE_SIZE));
const totalPages = computed(() => Math.max(1, Math.ceil(props.wallets.length / PAGE_SIZE)));
const offset = computed(() => page.value * PAGE_SIZE);

function showMsg(text, isError) {
    msg.value = text;
    msgIsError.value = isError;
    if (msgTimeout) clearTimeout(msgTimeout);
    msgTimeout = setTimeout(() => { msg.value = ''; }, 4000);
}

async function addWallet() {
    const addr = walletInput.value.trim().toLowerCase();
    if (!addr) return;
    if (!/^0x[a-f0-9]{40}$/i.test(addr)) {
        showMsg('Invalid address — must be 0x + 40 hex chars', true);
        return;
    }
    try {
        const r = await fetch('/api/wallets', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({wallet: addr}),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        walletInput.value = '';
        showMsg('Added ' + addr.slice(0, 8) + '...', false);
        emit('refresh');
    } catch(e) { showMsg('Failed to add wallet', true); }
}

async function removeWallet(addr) {
    if (!confirm(`Remove wallet ${addr.slice(0, 10)}... from tracking?`)) return;
    try {
        const r = await fetch('/api/wallets', {
            method: 'DELETE',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({wallet: addr}),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        showMsg('Removed ' + addr.slice(0, 8) + '...', false);
        emit('refresh');
    } catch(e) { showMsg('Failed to remove wallet', true); }
}
</script>

<template>
    <div>
        <div class="flex gap-2 mb-4">
            <input v-model="walletInput"
                   @keyup.enter="addWallet"
                   type="text"
                   placeholder="0x... wallet address"
                   class="flex-1 bg-gray-950 border border-gray-700 rounded px-3 py-2 font-mono text-sm text-gray-300 placeholder-gray-600 focus:outline-none focus:border-blue-500">
            <button @click="addWallet"
                    class="bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded text-sm whitespace-nowrap">
                Add Wallet
            </button>
        </div>

        <div v-if="msg" class="text-sm mb-2" :class="msgIsError ? 'text-red-400' : 'text-green-400'">
            {{ msg }}
        </div>

        <div class="text-gray-500 text-sm mb-3" v-if="wallets.length > 0">
            {{ wallets.length }} wallet{{ wallets.length !== 1 ? 's' : '' }} tracked
        </div>

        <ul v-if="wallets.length > 0">
            <li v-for="(w, i) in paged" :key="w"
                class="flex items-center justify-between bg-gray-900 border border-gray-700 rounded-md px-4 py-3 mb-2 font-mono text-sm">
                <span class="text-gray-600 text-xs mr-3 min-w-[24px]">#{{ offset + i + 1 }}</span>
                <span class="text-gray-300 overflow-hidden text-ellipsis flex-1">{{ w }}</span>
                <button @click="removeWallet(w)"
                        class="bg-red-600 hover:bg-red-500 text-white text-xs px-3 py-1 rounded ml-3 shrink-0">
                    Remove
                </button>
            </li>
        </ul>
        <p v-else class="text-gray-500 py-3">No wallets tracked. Add one above.</p>

        <div v-if="wallets.length > PAGE_SIZE" class="flex items-center gap-2 mt-2">
            <button @click="page = Math.max(0, page - 1)" :disabled="page <= 0"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                ← Prev
            </button>
            <span class="text-gray-500 text-xs">{{ page + 1 }} / {{ totalPages }} ({{ wallets.length }} wallets)</span>
            <button @click="page = Math.min(totalPages - 1, page + 1)" :disabled="page >= totalPages - 1"
                    class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
                Next →
            </button>
        </div>
    </div>
</template>
