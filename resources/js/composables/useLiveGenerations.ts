import { router, usePoll } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { onBeforeUnmount, ref, watch } from 'vue';

const POLL_INTERVAL_MS = 3000;

/**
 * Reloads the given props every few seconds while anything shown can still change, and stops
 * once everything is final. Also ticks a clock on the server's time (timestamps come from the
 * server, so a browser clock that is minutes off would otherwise show nonsense elapsed times).
 */
export function useLiveGenerations(
    anyInFlight: ComputedRef<boolean>,
    serverNow: () => string,
    only: string[],
    /** Props reloaded once when everything settles instead of on every poll. */
    onSettle: string[] = [],
) {
    const poll = usePoll(POLL_INTERVAL_MS, { only }, { autoStart: false });

    let clockOffsetMs = 0;
    watch(
        serverNow,
        (now) => {
            clockOffsetMs = Date.parse(now) - Date.now();
        },
        { immediate: true },
    );

    const now = ref(Date.now() + clockOffsetMs);
    let clock: ReturnType<typeof setInterval> | undefined;

    watch(
        anyInFlight,
        (inFlight) => {
            if (inFlight) {
                poll.start();
                clock ??= setInterval(
                    () => (now.value = Date.now() + clockOffsetMs),
                    1000,
                );
            } else {
                poll.stop();

                if (clock !== undefined && onSettle.length > 0) {
                    router.reload({ only: onSettle });
                }

                clearInterval(clock);
                clock = undefined;
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => clearInterval(clock));

    return { now };
}

export function elapsedSecondsOf(
    createdAt: string,
    finishedAt: string | null,
    now: number,
): number {
    const end = finishedAt ? Date.parse(finishedAt) : now;

    return Math.max(0, Math.round((end - Date.parse(createdAt)) / 1000));
}

export function formatElapsed(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    return minutes < 60
        ? `${minutes}m ${seconds % 60}s`
        : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
