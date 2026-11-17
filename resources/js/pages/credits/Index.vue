<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

type Transaction = {
    id: string;
    generation_id: string | null;
    type: string;
    type_label: string;
    description: string;
    amount: string;
    amount_exact: string;
    is_credit: boolean;
    balance_after: string;
    created_at: string;
};

defineProps<{
    transactions: {
        data: Transaction[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-8">
        <div
            class="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <div class="text-sm text-muted-foreground">Balance</div>
                <div
                    class="text-3xl font-semibold tabular-nums"
                    data-test="credits-balance"
                >
                    {{ credits?.balance }}
                </div>
                <p class="mt-1 max-w-md text-xs text-muted-foreground">
                    1 credit = 1 US dollar. A running generation holds its
                    maximum possible price. When it finishes, the hold is
                    released and the actual cost is charged. Failed generations
                    cost nothing.
                </p>
            </div>
            <BuyCreditsDialog />
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium">Description</th>
                        <th class="px-4 py-2 text-right font-medium">Amount</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Balance
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="!transactions.data.length">
                        <td
                            colspan="4"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No transactions yet.
                        </td>
                    </tr>
                    <tr
                        v-for="transaction in transactions.data"
                        :key="transaction.id"
                    >
                        <td
                            class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            {{
                                new Date(
                                    transaction.created_at,
                                ).toLocaleString()
                            }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                class="mr-2 rounded bg-muted px-1.5 py-0.5 text-xs"
                                >{{ transaction.type_label }}</span
                            >
                            {{ transaction.description }}
                        </td>
                        <td
                            class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                            :class="
                                transaction.is_credit
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : ''
                            "
                            :title="'$' + transaction.amount_exact"
                        >
                            {{ transaction.is_credit ? '+' : ''
                            }}{{ transaction.amount }}
                        </td>
                        <td
                            class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                        >
                            {{ transaction.balance_after }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex justify-between text-sm">
            <Link
                v-if="transactions.prev_page_url"
                :href="transactions.prev_page_url"
                >← Newer</Link
            >
            <span v-else />
            <Link
                v-if="transactions.next_page_url"
                :href="transactions.next_page_url"
                >Older →</Link
            >
        </div>
    </div>
</template>
