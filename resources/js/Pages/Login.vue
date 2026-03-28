<script setup>
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';

const form = useForm({ password: '' });
const page = usePage();

function submit() {
    form.post('/login', {
        preserveScroll: true,
        onFinish: () => {
            if (form.hasErrors) {
                form.password = '';
            }
        },
    });
}
</script>

<template>
    <div class="min-h-screen bg-gray-950 flex items-center justify-center p-5 font-mono">
        <div class="w-full max-w-sm">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-blue-400">Polymarket Bot</h1>
                <p class="text-gray-500 text-sm mt-2">Enter password to continue</p>
            </div>

            <form @submit.prevent="submit" class="bg-gray-900 border border-gray-800 rounded-lg p-6">
                <div class="mb-4">
                    <input v-model="form.password"
                           type="password"
                           placeholder="Password"
                           autofocus
                           class="w-full bg-gray-800 border border-gray-700 rounded px-4 py-2.5 text-gray-200 placeholder-gray-500 focus:outline-none focus:border-blue-500 text-sm"
                           :class="{ 'border-red-500': form.errors.password }" />
                    <p v-if="form.errors.password" class="text-red-400 text-xs mt-2">
                        {{ form.errors.password }}
                    </p>
                </div>

                <button type="submit"
                        :disabled="form.processing"
                        class="w-full bg-blue-700 hover:bg-blue-600 text-white font-semibold py-2.5 rounded text-sm transition-colors disabled:opacity-50">
                    {{ form.processing ? 'Signing in...' : 'Sign In' }}
                </button>
            </form>
        </div>
    </div>
</template>
