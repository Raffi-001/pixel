<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { useLiveGenerations } from '@/composables/useLiveGenerations';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index } from '@/routes/generations';
import type { Generation, Option, Paginated } from '@/types';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    generations: Paginated<Generation>;
    status: string | null;
    statuses: Option[];
}>();

const filters = computed(() => [
    { value: null, label: 'All' },
    ...props.statuses,
]);

useLiveGenerations(
    computed(() => props.generations.data),
    ['generations'],
);
</script>

<template>
    <Head title="History" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h1 class="text-xl font-semibold tracking-tight">History</h1>
                <p class="text-sm text-muted-foreground">
                    {{ generations.meta.total }} generation{{
                        generations.meta.total === 1 ? '' : 's'
                    }}. Images are hosted by Pixelforge and their links expire
                    24 hours after they finish.
                </p>
            </div>
            <nav class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                <Link
                    v-for="filter in filters"
                    :key="filter.label"
                    :href="
                        index({
                            query: filter.value ? { status: filter.value } : {},
                        })
                    "
                    preserve-scroll
                    class="rounded-md px-3 py-1 text-xs font-medium transition"
                    :class="
                        status === filter.value
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    >{{ filter.label }}</Link
                >
            </nav>
        </div>

        <div
            v-if="!generations.data.length"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No generations here.
            <Link
                :href="dashboard()"
                class="text-primary underline-offset-4 hover:underline"
                >Generate one</Link
            >.
        </div>

        <div
            v-else
            class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <div
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between"
        >
            <Button v-if="generations.links.prev" variant="outline" as-child>
                <Link :href="generations.links.prev">Previous</Link>
            </Button>
            <span v-else />
            <span class="text-sm text-muted-foreground"
                >Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}</span
            >
            <Button v-if="generations.links.next" variant="outline" as-child>
                <Link :href="generations.links.next">Next</Link>
            </Button>
            <span v-else />
        </div>
    </div>
</template>
