<script setup>
import { ref, computed, onMounted } from 'vue';

const settings = ref({});
const loading = ref(true);
const saving = ref(false);
const saveMsg = ref('');
const editValues = ref({});

const groups = [
    { key: 'sizing', label: 'Trade Sizing', description: 'Controls how much USDC is allocated per trade.' },
    { key: 'limits', label: 'Risk Limits', description: 'Per-market and per-wallet exposure caps.' },
    { key: 'behavior', label: 'Trade Behavior', description: 'Core trade copy behavior.' },
    { key: 'polling', label: 'Polling', description: 'How often and how wallets are polled for trades.' },
];

function groupSettings(groupKey) {
    return Object.entries(settings.value)
        .filter(([, s]) => s.group === groupKey)
        .map(([key, s]) => ({ key, ...s }));
}

const hasChanges = computed(() => {
    for (const [key, val] of Object.entries(editValues.value)) {
        const s = settings.value[key];
        if (!s) continue;
        const current = s.value === null || s.value === undefined ? '' : String(s.value);
        if (String(val) !== current) return true;
    }
    return false;
});

async function fetchSettings() {
    loading.value = true;
    try {
        const r = await fetch('/api/settings');
        const d = await r.json();
        settings.value = d.settings;
        // Initialize edit values from current settings.
        const ev = {};
        for (const [key, s] of Object.entries(d.settings)) {
            ev[key] = s.value === null || s.value === undefined ? '' : String(s.value);
        }
        editValues.value = ev;
    } catch (e) {
        console.error('Failed to fetch settings', e);
    } finally {
        loading.value = false;
    }
}

async function saveAll() {
    saving.value = true;
    saveMsg.value = '';
    try {
        const payload = {};
        for (const [key, val] of Object.entries(editValues.value)) {
            const s = settings.value[key];
            if (!s) continue;
            const current = s.value === null || s.value === undefined ? '' : String(s.value);
            if (String(val) !== current) {
                // Send null to clear overrides (revert to env default).
                if (val === '' && s.nullable) {
                    payload[key] = null;
                } else if (val === '' && !s.nullable) {
                    payload[key] = null; // Clear override → revert to env.
                } else if (s.type === 'bool') {
                    payload[key] = val === 'true' || val === '1' || val === true;
                } else {
                    payload[key] = val;
                }
            }
        }

        if (Object.keys(payload).length === 0) return;

        const r = await fetch('/api/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings: payload }),
        });
        const d = await r.json();

        if (d.ok) {
            settings.value = d.settings;
            for (const [key, s] of Object.entries(d.settings)) {
                editValues.value[key] = s.value === null || s.value === undefined ? '' : String(s.value);
            }
            saveMsg.value = 'Settings saved.';
            setTimeout(() => saveMsg.value = '', 3000);
        } else {
            saveMsg.value = 'Errors: ' + Object.values(d.errors || {}).join(', ');
        }
    } catch (e) {
        console.error('Failed to save settings', e);
        saveMsg.value = 'Network error';
    } finally {
        saving.value = false;
    }
}

async function resetSetting(key) {
    try {
        const r = await fetch(`/api/settings/${key}`, { method: 'DELETE' });
        const d = await r.json();
        if (d.ok) {
            settings.value = d.settings;
            const s = d.settings[key];
            editValues.value[key] = s.value === null || s.value === undefined ? '' : String(s.value);
        }
    } catch (e) {
        console.error('Failed to reset setting', e);
    }
}

function fmtDefault(s) {
    if (s.default === null || s.default === undefined) return 'none';
    if (s.type === 'bool') return s.default ? 'on' : 'off';
    return String(s.default);
}

onMounted(fetchSettings);
</script>

<template>
    <div>
        <div v-if="loading" class="text-gray-500 text-sm py-8 text-center">Loading settings...</div>

        <template v-else>
            <div v-for="group in groups" :key="group.key" class="mb-6">
                <h3 class="text-blue-400 text-sm font-semibold mb-1">{{ group.label }}</h3>
                <p class="text-gray-600 text-xs mb-3">{{ group.description }}</p>

                <div class="bg-gray-900 border border-gray-700 rounded-lg overflow-hidden">
                    <div v-for="(s, idx) in groupSettings(group.key)" :key="s.key"
                         :class="['flex items-center gap-4 px-4 py-3', idx > 0 ? 'border-t border-gray-800' : '']">

                        <!-- Label -->
                        <div class="w-64 shrink-0">
                            <span class="text-gray-300 text-sm">{{ s.label }}</span>
                            <span v-if="s.has_override" class="ml-1.5 text-[10px] text-blue-400 bg-blue-400/10 px-1.5 py-0.5 rounded">custom</span>
                        </div>

                        <!-- Input -->
                        <div class="flex-1 flex items-center gap-2">
                            <!-- Boolean toggle -->
                            <template v-if="s.type === 'bool'">
                                <button @click="editValues[s.key] = editValues[s.key] === '1' || editValues[s.key] === 'true' ? '0' : '1'"
                                        :class="[
                                            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                                            editValues[s.key] === '1' || editValues[s.key] === 'true' ? 'bg-blue-600' : 'bg-gray-700'
                                        ]">
                                    <span :class="[
                                        'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                                        editValues[s.key] === '1' || editValues[s.key] === 'true' ? 'translate-x-6' : 'translate-x-1'
                                    ]" />
                                </button>
                                <span class="text-xs text-gray-500">
                                    {{ editValues[s.key] === '1' || editValues[s.key] === 'true' ? 'On' : 'Off' }}
                                </span>
                            </template>

                            <!-- Number/text input -->
                            <template v-else>
                                <input v-model="editValues[s.key]"
                                       type="number"
                                       step="any"
                                       min="0"
                                       :placeholder="'default: ' + fmtDefault(s)"
                                       class="bg-gray-800 border border-gray-600 rounded px-3 py-1.5 text-sm text-gray-300 w-40
                                              focus:border-blue-400 focus:outline-none placeholder:text-gray-600" />
                            </template>
                        </div>

                        <!-- Default / Reset -->
                        <div class="shrink-0 flex items-center gap-2">
                            <span class="text-xs text-gray-600">env: {{ fmtDefault(s) }}</span>
                            <button v-if="s.has_override" @click="resetSetting(s.key)"
                                    class="text-xs text-gray-500 hover:text-red-400 transition-colors"
                                    title="Reset to env default">
                                reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save bar -->
            <div class="sticky bottom-0 bg-gray-950/90 backdrop-blur border-t border-gray-800 -mx-5 px-5 py-3 flex items-center gap-3">
                <button @click="saveAll" :disabled="saving || !hasChanges"
                        class="text-sm font-semibold px-5 py-2 rounded bg-blue-600 hover:bg-blue-500 text-white
                               transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ saving ? 'Saving...' : 'Save Changes' }}
                </button>
                <span v-if="saveMsg" class="text-sm" :class="saveMsg.startsWith('Error') ? 'text-red-400' : 'text-green-400'">
                    {{ saveMsg }}
                </span>
                <span v-else-if="hasChanges" class="text-xs text-yellow-500">Unsaved changes</span>
            </div>
        </template>
    </div>
</template>
