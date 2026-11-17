<script setup lang="ts">
import { computed } from 'vue';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const styles: Record<Generation['status'], string> = {
    pending:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    queued: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    processing:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    succeeded:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

const className = computed(() => styles[props.generation.status]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="className"
        :data-status="generation.status"
    >
        <span
            v-if="!generation.is_final"
            class="size-1.5 animate-pulse rounded-full bg-current"
        />
        {{ generation.status_label }}
    </span>
</template>
