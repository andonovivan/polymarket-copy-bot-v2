<script setup>
import { ref, computed } from 'vue';

const props = defineProps({ wallets: Array });
const emit = defineEmits(['refresh']);

const PAGE_SIZE = 10;
const page = ref(0);
const walletInput = ref('');
const nameInput = ref('');
const slugInput = ref('');
const msg = ref('');
const msgIsError = ref(false);
const editingWallet = ref(null);
const editName = ref('');
const editSlug = ref('');
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
            body: JSON.stringify({
                wallet: addr,
                name: nameInput.value.trim(),
                profile_slug: slugInput.value.trim(),
            }),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        walletInput.value = '';
        nameInput.value = '';
        slugInput.value = '';
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

function startEdit(w) {
    editingWallet.value = w.address;
    editName.value = w.name || '';
    editSlug.value = w.profile_slug || '';
}

function cancelEdit() {
    editingWallet.value = null;
}

async function saveEdit(addr) {
    try {
        const r = await fetch('/api/wallets', {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                wallet: addr,
                name: editName.value.trim(),
                profile_slug: editSlug.value.trim(),
            }),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        editingWallet.value = null;
        showMsg('Updated', false);
        emit('refresh');
    } catch(e) { showMsg('Failed to update', true); }
}

async function togglePause(addr, paused) {
    try {
        const r = await fetch('/api/wallets/pause', {
            method: 'PATCH',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ wallet: addr, paused }),
        });
        const d = await r.json();
        if (d.error) { showMsg(d.error, true); return; }
        showMsg(paused ? 'Paused ' + addr.slice(0, 8) + '...' : 'Resumed ' + addr.slice(0, 8) + '...', false);
        emit('refresh');
    } catch(e) { showMsg('Failed to toggle pause', true); }
}

function pauseLabel(w) {
    if (!w.is_paused) return null;
    if (w.pause_reason?.startsWith('auto:')) return 'Auto-Paused';
    return 'Paused';
}

function pauseBadgeClass(w) {
    if (!w.is_paused) return '';
    if (w.pause_reason?.startsWith('auto:')) return 'bg-red-800 text-red-300';
    return 'bg-orange-800 text-orange-300';
}

function profileUrl(w) {
    if (w.profile_slug) return `https://polymarket.com/@${w.profile_slug}`;
    return `https://polymarket.com/portfolio/${w.address}`;
}
</script>

<template>
    <div>
        <div class="mb-4 p-4 bg-gray-900 border border-gray-700 rounded-lg">
            <div class="flex gap-2 mb-2">
                <input v-model="walletInput"
                       @keyup.enter="addWallet"
                       type="text"
                       placeholder="0x... wallet address"
                       class="flex-1 bg-gray-950 border border-gray-700 rounded px-3 py-2 font-mono text-sm text-gray-300 placeholder-gray-600 focus:outline-none focus:border-blue-500">
            </div>
            <div class="flex gap-2 mb-2">
                <input v-model="nameInput"
                       type="text"
                       placeholder="Trader name (e.g. BeachBoy)"
                       class="flex-1 bg-gray-950 border border-gray-700 rounded px-3 py-2 text-sm text-gray-300 placeholder-gray-600 focus:outline-none focus:border-blue-500">
                <input v-model="slugInput"
                       type="text"
                       placeholder="Profile slug (e.g. beachboy4)"
                       class="flex-1 bg-gray-950 border border-gray-700 rounded px-3 py-2 text-sm text-gray-300 placeholder-gray-600 focus:outline-none focus:border-blue-500">
            </div>
            <button @click="addWallet"
                    class="bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded text-sm whitespace-nowrap w-full">
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
            <li v-for="(w, i) in paged" :key="w.address"
                class="bg-gray-900 border border-gray-700 rounded-md px-4 py-3 mb-2">

                <!-- View mode -->
                <div v-if="editingWallet !== w.address" class="flex items-center justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-600 text-xs">#{{ offset + i + 1 }}</span>
                            <a :href="profileUrl(w)" target="_blank"
                               class="text-blue-400 hover:text-blue-300 hover:underline text-sm font-medium">
                                {{ w.name || 'Unnamed' }}
                            </a>
                            <span v-if="w.is_paused" :class="pauseBadgeClass(w)"
                                  class="px-2 py-0.5 rounded text-xs font-semibold">
                                {{ pauseLabel(w) }}
                            </span>
                        </div>
                        <div class="font-mono text-xs text-gray-500 mt-1 overflow-hidden text-ellipsis">{{ w.address }}</div>
                        <div v-if="w.profile_slug" class="text-xs text-gray-600 mt-0.5">
                            polymarket.com/@{{ w.profile_slug }}
                        </div>
                    </div>
                    <div class="flex gap-2 ml-3 shrink-0">
                        <button v-if="!w.is_paused" @click="togglePause(w.address, true)"
                                class="bg-orange-700 hover:bg-orange-600 text-white text-xs px-3 py-1 rounded">
                            Pause
                        </button>
                        <button v-else @click="togglePause(w.address, false)"
                                class="bg-green-700 hover:bg-green-600 text-white text-xs px-3 py-1 rounded">
                            Resume
                        </button>
                        <button @click="startEdit(w)"
                                class="bg-gray-700 hover:bg-gray-600 text-white text-xs px-3 py-1 rounded">
                            Edit
                        </button>
                        <button @click="removeWallet(w.address)"
                                class="bg-red-600 hover:bg-red-500 text-white text-xs px-3 py-1 rounded">
                            Remove
                        </button>
                    </div>
                </div>

                <!-- Edit mode -->
                <div v-else>
                    <div class="font-mono text-xs text-gray-500 mb-2">{{ w.address }}</div>
                    <div class="flex gap-2 mb-2">
                        <input v-model="editName" type="text" placeholder="Trader name"
                               class="flex-1 bg-gray-950 border border-gray-700 rounded px-2 py-1 text-sm text-gray-300 focus:outline-none focus:border-blue-500">
                        <input v-model="editSlug" type="text" placeholder="Profile slug"
                               class="flex-1 bg-gray-950 border border-gray-700 rounded px-2 py-1 text-sm text-gray-300 focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="flex gap-2">
                        <button @click="saveEdit(w.address)"
                                class="bg-green-700 hover:bg-green-600 text-white text-xs px-3 py-1 rounded">
                            Save
                        </button>
                        <button @click="cancelEdit"
                                class="bg-gray-700 hover:bg-gray-600 text-white text-xs px-3 py-1 rounded">
                            Cancel
                        </button>
                    </div>
                </div>
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
