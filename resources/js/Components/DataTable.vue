<script setup>
import { ref, watch, onMounted, useSlots } from 'vue';
import Pagination from './Pagination.vue';

const props = defineProps({
    apiUrl: { type: String, required: true },
    columns: { type: Array, required: true },
    defaultSort: { type: String, default: 'id' },
    defaultOrder: { type: String, default: 'desc' },
    refreshTrigger: { type: Number, default: 0 },
    rowKey: { type: String, default: 'id' },
    emptyMessage: { type: String, default: 'No data' },
    loadingMessage: { type: String, default: 'Loading...' },
    label: { type: String, default: 'rows' },
});

const emit = defineEmits(['refresh']);
const slots = useSlots();

const perPage = ref(10);
const page = ref(1);
const sortKey = ref(props.defaultSort);
const sortOrder = ref(props.defaultOrder);
const rows = ref([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(false);

async function fetchData() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            page: page.value,
            per_page: perPage.value,
            sort: sortKey.value,
            order: sortOrder.value,
        });
        const r = await fetch(`${props.apiUrl}?${params}`);
        const d = await r.json();
        rows.value = d.data;
        total.value = d.total;
        lastPage.value = d.last_page;
        if (page.value > d.last_page && d.last_page > 0) {
            page.value = d.last_page;
            await fetchData();
        }
    } catch (e) {
        console.error(`Failed to fetch ${props.apiUrl}`, e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchData);
watch(() => props.refreshTrigger, fetchData);

function setSort(key) {
    if (sortKey.value === key) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortOrder.value = props.defaultOrder;
    }
    page.value = 1;
    fetchData();
}

function goPage(p) {
    page.value = p;
    fetchData();
}

function changePerPage(size) {
    perPage.value = size;
    page.value = 1;
    fetchData();
}

function arrow(key) {
    if (sortKey.value !== key) return '';
    return sortOrder.value === 'asc' ? '\u25B2' : '\u25BC';
}

function hasSlot(name) {
    return !!slots[name];
}

// Expose fetchData so parents can call it after mutations (e.g. close position).
defineExpose({ fetchData, rows, total, lastPage, sortKey, sortOrder, page });
</script>

<template>
    <div>
        <!-- Loading spinner (initial load only) -->
        <div v-if="loading && rows.length === 0" class="flex items-center justify-center py-8">
            <svg class="animate-spin h-6 w-6 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="ml-2 text-gray-500 text-sm">{{ loadingMessage }}</span>
        </div>

        <template v-else>
            <slot name="above-table" />

            <table class="w-full mb-2">
                <thead>
                    <tr>
                        <th v-for="col in columns" :key="col.key"
                            @click="setSort(col.key)"
                            class="text-left text-gray-500 text-xs uppercase tracking-wide px-3 py-2 border-b border-gray-700 cursor-pointer hover:text-gray-300 select-none whitespace-nowrap">
                            {{ col.label }} <span class="text-[0.6em] ml-1">{{ arrow(col.key) }}</span>
                        </th>
                        <slot name="extra-headers" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td :colspan="columns.length + (hasSlot('extra-headers') ? 2 : 0)"
                            class="text-gray-500 px-3 py-2">{{ emptyMessage }}</td>
                    </tr>
                    <tr v-for="row in rows" :key="row[rowKey]" class="hover:bg-gray-900">
                        <td v-for="col in columns" :key="col.key"
                            class="px-3 py-2 border-b border-gray-800 text-sm">
                            <slot :name="'cell-' + col.key" :row="row" :value="row[col.key]">
                                {{ row[col.key] }}
                            </slot>
                        </td>
                        <slot name="row-actions" :row="row" />
                    </tr>
                </tbody>
            </table>

            <Pagination :page="page" :lastPage="lastPage" :total="total" :pageSize="perPage"
                        :label="label" @go="goPage" @update:perPage="changePerPage" />
        </template>
    </div>
</template>
