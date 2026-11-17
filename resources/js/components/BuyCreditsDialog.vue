<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CreditCard } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/credits';

const page = usePage();
const credits = computed(() => page.props.credits);
const open = ref(false);
const buying = ref<number | null>(null);

function buy(amount: number): void {
    router.post(
        store.url(),
        { amount },
        {
            preserveScroll: true,
            onStart: () => (buying.value = amount),
            onFinish: () => (buying.value = null),
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger :as-child="true">
            <slot>
                <Button size="sm" data-test="buy-credits">
                    <CreditCard />
                    Buy credits
                </Button>
            </slot>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Credits are US dollars. Your balance is
                    <strong class="text-foreground">{{
                        credits?.balance
                    }}</strong
                    >. This is a demo, so no payment is taken.
                </DialogDescription>
            </DialogHeader>
            <div class="grid grid-cols-3 gap-3">
                <Button
                    v-for="amount in credits?.packs ?? []"
                    :key="amount"
                    variant="outline"
                    class="h-16 text-lg font-semibold"
                    :disabled="buying !== null"
                    :data-test="`buy-${amount}`"
                    @click="buy(amount)"
                >
                    <Spinner v-if="buying === amount" />
                    ${{ amount }}
                </Button>
            </div>
            <DialogFooter
                class="text-xs text-muted-foreground sm:justify-start"
            >
                Generations hold their maximum possible price while running,
                then charge the actual cost.
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
