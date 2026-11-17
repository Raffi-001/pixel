import { usePoll } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { watch } from 'vue';
import type { Generation } from '@/types';

/**
 * Reloads the given props every few seconds while any generation on the page is still in flight.
 */
export function useLiveGenerations(
    generations: Ref<Generation[]>,
    only: string[],
): void {
    const { start, stop } = usePoll(
        3000,
        { only: [...only, 'credits'] },
        { autoStart: false, mode: 'rest' },
    );

    watch(
        generations,
        (list) => {
            if (list.some((generation) => !generation.is_final)) {
                start();
            } else {
                stop();
            }
        },
        { immediate: true },
    );
}
