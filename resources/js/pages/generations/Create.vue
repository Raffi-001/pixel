<script setup lang="ts">
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { ImagePlus, Sparkles, X as RemoveIcon } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLiveGenerations } from '@/composables/useLiveGenerations';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    estimate as estimateRoute,
    index as historyRoute,
    store,
} from '@/routes/generations';
import type { CostEstimate, Generation, Option } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    generations: { data: Generation[] };
    hasMore: boolean;
    sizes: Option[];
    qualities: Option[];
}>();

const MAX_REFERENCE_IMAGES = 4;
const REFERENCE_PLACEHOLDER = 'https://example.com/style.png';

const page = usePage();
const credits = computed(() => page.props.credits);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const estimate = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    },
    CostEstimate
>({ prompt: '', size: '', quality: '', reference_images: [] });

const filledReferenceImages = computed(() =>
    form.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);

const cost = ref<CostEstimate | null>(null);

const refreshEstimate = useDebounceFn(() => {
    estimate.prompt = form.prompt;
    estimate.size = form.size;
    estimate.quality = form.quality;
    estimate.reference_images = filledReferenceImages.value;
    estimate
        .post(estimateRoute.url())
        .then((response) => (cost.value = response))
        .catch(() => undefined);
}, 300);

watch(
    () => [
        form.prompt,
        form.size,
        form.quality,
        filledReferenceImages.value.length,
    ],
    () => void refreshEstimate(),
);
onMounted(() => void refreshEstimate());

const canAfford = computed(
    () =>
        !cost.value ||
        !credits.value ||
        credits.value.balance_nano >= cost.value.max_price_nano,
);
const canSubmit = computed(
    () => form.prompt.trim() !== '' && canAfford.value && !form.processing,
);

function addReferenceImage(): void {
    if (form.reference_images.length < MAX_REFERENCE_IMAGES) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_images: filledReferenceImages.value,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

const referenceRows = computed(() =>
    form.reference_images.map((_url, position) => position),
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const balanceError = computed(() => errors.value.balance);
const referenceImageError = (position: number): string | undefined =>
    errors.value[`reference_images.${position}`];

useLiveGenerations(
    computed(() => props.generations.data),
    ['generations', 'hasMore'],
);
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Generate an image
            </h1>
            <p class="text-sm text-muted-foreground">
                Describe what you want. You see the price range before you
                submit, and pay only the actual cost once it's done. Failed
                generations are free.
            </p>
        </div>

        <form
            class="flex flex-col gap-5 rounded-xl border bg-card p-5 shadow-xs"
            @submit.prevent="submit"
            @keydown.meta.enter.prevent="submit"
            @keydown.ctrl.enter.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="prompt">Prompt</Label>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="4"
                    maxlength="4000"
                    :disabled="form.processing"
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    class="min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 md:text-sm dark:bg-input/30"
                    data-test="prompt"
                />
                <div class="flex justify-between text-xs text-muted-foreground">
                    <span>
                        Press
                        <kbd class="rounded border px-1 text-[10px]">⌘</kbd> +
                        <kbd class="rounded border px-1 text-[10px]">Enter</kbd>
                        to submit
                    </span>
                    <span class="tabular-nums"
                        >{{ form.prompt.length }} / 4000</span
                    >
                </div>
                <InputError :message="form.errors.prompt" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>Size</Label>
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                        <button
                            v-for="size in sizes"
                            :key="size.value"
                            type="button"
                            class="rounded-md px-2 py-1.5 text-xs font-medium transition"
                            :class="
                                form.size === size.value
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            :title="size.label"
                            @click="form.size = size.value"
                        >
                            {{ size.label.split(' ')[0] }}
                            <span class="block text-[10px] opacity-60">{{
                                size.value
                            }}</span>
                        </button>
                    </div>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="grid gap-2">
                    <Label>Quality</Label>
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                        <button
                            v-for="quality in qualities"
                            :key="quality.value"
                            type="button"
                            class="rounded-md px-2 py-1.5 text-xs font-medium transition"
                            :class="
                                form.quality === quality.value
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="form.quality = quality.value"
                        >
                            {{ quality.label }}
                            <span class="block text-[10px] opacity-0">.</span>
                        </button>
                    </div>
                    <InputError :message="form.errors.quality" />
                </div>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label
                        >Reference images
                        <span class="font-normal text-muted-foreground"
                            >optional, up to 4</span
                        ></Label
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="
                            form.reference_images.length >= MAX_REFERENCE_IMAGES
                        "
                        @click="addReferenceImage"
                    >
                        <ImagePlus />
                        Add URL
                    </Button>
                </div>
                <div
                    v-for="position in referenceRows"
                    :key="position"
                    class="grid gap-1"
                >
                    <div class="flex gap-2">
                        <Input
                            v-model="form.reference_images[position]"
                            type="url"
                            :placeholder="REFERENCE_PLACEHOLDER"
                            :data-test="'reference-image-' + position"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="removeReferenceImage(position)"
                        >
                            <RemoveIcon />
                            <span class="sr-only">Remove</span>
                        </Button>
                    </div>
                    <InputError :message="referenceImageError(position)" />
                </div>
                <InputError :message="form.errors.reference_images" />
            </div>

            <div
                class="flex flex-col gap-4 border-t pt-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="text-sm" data-test="estimate">
                    <div class="text-muted-foreground">Estimated price</div>
                    <div
                        v-if="cost"
                        class="text-2xl font-semibold tabular-nums"
                        :class="{ 'opacity-50': estimate.processing }"
                    >
                        {{ cost.min_price }} – {{ cost.max_price }}
                    </div>
                    <div v-else class="text-2xl font-semibold">
                        <Spinner />
                    </div>
                    <div v-if="cost" class="mt-1 text-xs text-muted-foreground">
                        {{ cost.tokens.output }} output tokens (exact) ·
                        {{ cost.tokens.input_text }} text ·
                        {{ cost.tokens.reasoning }} reasoning
                        <template v-if="cost.tokens.input_image !== '0'">
                            · {{ cost.tokens.input_image }} image</template
                        >
                        · incl. {{ cost.markup_percent }}% fee. We hold
                        {{ cost.max_price }} while it runs and charge the actual
                        cost.
                    </div>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <div
                        v-if="!canAfford"
                        class="flex items-center gap-2 text-sm text-destructive"
                        data-test="insufficient-credits"
                    >
                        Not enough credits ({{ credits?.balance }}).
                        <BuyCreditsDialog>
                            <Button type="button" size="sm" variant="outline">
                                Buy credits
                            </Button>
                        </BuyCreditsDialog>
                    </div>
                    <InputError :message="balanceError" />
                    <Button
                        type="submit"
                        size="lg"
                        :disabled="!canSubmit"
                        data-test="generate"
                    >
                        <Spinner v-if="form.processing" />
                        <Sparkles v-else />
                        Generate
                    </Button>
                </div>
            </div>
        </form>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Recent generations</h2>
                <Link
                    :href="historyRoute()"
                    class="text-sm text-primary underline-offset-4 hover:underline"
                    >Full history →</Link
                >
            </div>
            <div
                v-if="!generations.data.length"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                Nothing here yet. Type something in the box above to generate
                your first image.
            </div>
            <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
            <Link
                v-if="hasMore"
                :href="historyRoute()"
                class="self-center text-sm text-muted-foreground hover:text-foreground"
                >See all generations</Link
            >
        </section>
    </div>
</template>
