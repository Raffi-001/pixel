<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ImageOff, RotateCcw, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import GenerationCost from '@/components/GenerationCost.vue';
import GenerationDetailsDialog from '@/components/GenerationDetailsDialog.vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { retry as retryRoute } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const detailsOpen = ref(false);
const retrying = ref(false);

const waitingText: Record<string, string> = {
    pending: 'Waiting for a free slot…',
    queued: 'Queued at Pixelforge…',
    processing: 'Generating…',
};

function retry(): void {
    router.post(
        retryRoute.url(props.generation.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (retrying.value = true),
            onFinish: () => (retrying.value = false),
        },
    );
}
</script>

<template>
    <div
        class="group flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs"
        data-test="generation-card"
    >
        <button
            type="button"
            class="relative flex aspect-square items-center justify-center overflow-hidden bg-muted/60 text-left"
            @click="detailsOpen = true"
        >
            <img
                v-if="generation.image_url"
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                class="h-full w-full object-contain transition group-hover:scale-[1.02]"
            />
            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-red-700 dark:text-red-300"
            >
                <TriangleAlert class="size-6" />
                <span class="line-clamp-4">{{ generation.error_message }}</span>
            </div>
            <div
                v-else-if="generation.image_expired"
                class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                Image link expired
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner class="size-6" />
                {{ waitingText[generation.status] }}
            </div>
        </button>

        <div class="flex flex-1 flex-col gap-2 p-3">
            <div class="flex items-center justify-between gap-2">
                <GenerationStatusBadge :generation="generation" />
                <span class="text-xs text-muted-foreground"
                    >{{ generation.quality }} · {{ generation.size }}</span
                >
            </div>
            <p class="line-clamp-2 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>
            <div
                class="mt-auto flex items-center justify-between gap-2 text-xs"
            >
                <GenerationCost :generation="generation" />
                <Button
                    v-if="generation.status === 'failed'"
                    size="sm"
                    variant="outline"
                    class="h-7"
                    :disabled="retrying"
                    data-test="retry"
                    @click="retry"
                >
                    <Spinner v-if="retrying" />
                    <RotateCcw v-else />
                    Retry
                </Button>
            </div>
        </div>

        <GenerationDetailsDialog
            v-model:open="detailsOpen"
            :generation="generation"
        />
    </div>
</template>
