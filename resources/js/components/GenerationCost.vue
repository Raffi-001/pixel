<script setup lang="ts">
import type { Generation } from '@/types';

defineProps<{ generation: Generation }>();
</script>

<template>
    <span v-if="generation.status === 'succeeded'" class="tabular-nums">
        <span
            class="font-medium text-foreground"
            :title="`$${generation.charged_exact}`"
            >{{ generation.charged }}</span
        >
        <span class="text-muted-foreground">
            · est. {{ generation.estimated_min }}–{{
                generation.estimated_max
            }}</span
        >
    </span>
    <span
        v-else-if="generation.status === 'failed'"
        class="text-muted-foreground"
    >
        Not charged
    </span>
    <span v-else class="text-muted-foreground tabular-nums">
        Est. {{ generation.estimated_min }}–{{ generation.estimated_max }}
    </span>
</template>
