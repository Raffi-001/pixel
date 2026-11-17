<script setup lang="ts">
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Generation } from '@/types';

defineProps<{ generation: Generation }>();
const open = defineModel<boolean>('open', { default: false });

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '—';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    Generation details
                    <GenerationStatusBadge :generation="generation" />
                </DialogTitle>
                <DialogDescription class="break-words whitespace-pre-line">
                    {{ generation.prompt }}
                </DialogDescription>
            </DialogHeader>

            <a
                v-if="generation.image_url"
                :href="generation.image_url"
                target="_blank"
                rel="noopener noreferrer"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="w-full rounded-md border"
                />
            </a>

            <div
                v-if="generation.error_message"
                class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            >
                {{ generation.error_message }}
                <span class="block text-xs opacity-70">{{
                    generation.error_code
                }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Size / quality</dt>
                <dd>{{ generation.size }} · {{ generation.quality }}</dd>

                <dt class="text-muted-foreground">Estimate</dt>
                <dd class="tabular-nums">
                    {{ generation.estimated_min }} –
                    {{ generation.estimated_max }}
                </dd>

                <dt class="text-muted-foreground">Actual cost</dt>
                <dd class="tabular-nums">
                    <template v-if="generation.charged">
                        {{ generation.charged }}
                        <span class="text-xs text-muted-foreground"
                            >(exactly ${{ generation.charged_exact }}, incl.
                            {{ generation.markup_percent }}% markup)</span
                        >
                    </template>
                    <template v-else-if="generation.status === 'failed'"
                        >$0.00 — failed generations are free</template
                    >
                    <template v-else>Known once it finishes</template>
                </dd>

                <template v-if="generation.usage">
                    <dt class="text-muted-foreground">Tokens</dt>
                    <dd class="tabular-nums">
                        {{ generation.usage.input_text_tokens }} text ·
                        {{ generation.usage.input_image_tokens }} image ·
                        {{ generation.usage.output_tokens }} output ·
                        {{ generation.usage.reasoning_tokens }} reasoning
                    </dd>
                </template>

                <template v-if="generation.reference_images.length">
                    <dt class="text-muted-foreground">Reference images</dt>
                    <dd class="space-y-1">
                        <a
                            v-for="url in generation.reference_images"
                            :key="url"
                            :href="url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block truncate text-primary underline-offset-4 hover:underline"
                            >{{ url }}</a
                        >
                    </dd>
                </template>

                <dt class="text-muted-foreground">Created</dt>
                <dd>{{ formatDate(generation.created_at) }}</dd>

                <dt class="text-muted-foreground">Finished</dt>
                <dd>{{ formatDate(generation.completed_at) }}</dd>

                <template v-if="generation.provider_id">
                    <dt class="text-muted-foreground">Pixelforge id</dt>
                    <dd class="font-mono text-xs break-all">
                        {{ generation.provider_id }}
                    </dd>
                </template>
            </dl>
        </DialogContent>
    </Dialog>
</template>
