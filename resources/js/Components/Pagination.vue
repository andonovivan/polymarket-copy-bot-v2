<script setup>
const props = defineProps({
    page: { type: Number, required: true },
    lastPage: { type: Number, required: true },
    total: { type: Number, required: true },
    pageSize: { type: Number, required: true },
    label: { type: String, default: 'rows' },
    perPageOptions: { type: Array, default: () => [10, 25, 50, 100] },
});

const emit = defineEmits(['go', 'update:perPage']);
</script>

<template>
    <div v-if="total > perPageOptions[0]" class="flex items-center gap-2 mt-2">
        <button @click="emit('go', 1)" :disabled="page <= 1"
                class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
            &laquo; First
        </button>
        <button @click="emit('go', Math.max(1, page - 1))" :disabled="page <= 1"
                class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
            &larr; Prev
        </button>
        <span class="text-gray-500 text-xs">{{ page }} / {{ lastPage }} ({{ total }} {{ label }})</span>
        <button @click="emit('go', Math.min(lastPage, page + 1))" :disabled="page >= lastPage"
                class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
            Next &rarr;
        </button>
        <button @click="emit('go', lastPage)" :disabled="page >= lastPage"
                class="bg-gray-800 border border-gray-700 text-gray-300 px-3 py-1 rounded text-xs disabled:opacity-40">
            Last &raquo;
        </button>
        <select :value="pageSize" @change="emit('update:perPage', Number($event.target.value))"
                class="bg-gray-800 border border-gray-700 text-gray-300 px-2 py-1 rounded text-xs ml-2">
            <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }} / page</option>
        </select>
    </div>
</template>
