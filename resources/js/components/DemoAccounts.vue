<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';

const accounts = [
    { role: 'User', email: 'demo@example.com' },
    { role: 'Admin', email: 'admin@example.com' },
];
const password = 'password';
const copied = ref<string | null>(null);

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = value;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        copied.value = null;
    }
}
</script>

<template>
    <div
        class="mb-6 rounded-lg border bg-muted/40 p-3 text-sm"
        data-test="demo-accounts"
    >
        <div class="mb-2 text-xs font-medium text-muted-foreground">
            Demo accounts (password:
            <button
                type="button"
                class="inline-flex items-center gap-1 font-mono text-foreground hover:underline"
                @click="copy(password)"
            >
                {{ password }}
                <Check v-if="copied === password" class="size-3" />
                <Copy v-else class="size-3 opacity-60" />
            </button>
            )
        </div>
        <ul class="space-y-1">
            <li
                v-for="account in accounts"
                :key="account.email"
                class="flex items-center justify-between gap-2"
            >
                <span class="text-xs text-muted-foreground">{{
                    account.role
                }}</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 font-mono text-xs hover:underline"
                    @click="copy(account.email)"
                >
                    {{ account.email }}
                    <Check v-if="copied === account.email" class="size-3" />
                    <Copy v-else class="size-3 opacity-60" />
                </button>
            </li>
        </ul>
    </div>
</template>
