<script setup>
defineProps({
    page: { type: Number, required: true },
    lastPage: { type: Number, required: true },
    total: { type: Number, required: true },
    pageSize: { type: Number, required: true },
    label: { type: String, default: 'rows' },
});

const emit = defineEmits(['go']);
</script>

<template>
    <div v-if="total > pageSize" class="flex items-center gap-2 mt-2">
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
    </div>
</template>
