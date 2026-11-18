<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleX, ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SparkleIcon from '@/components/atelier/SparkleIcon.vue';
import { costLine, statusDisplay } from '@/composables/useGenerationStatus';
import {
    elapsedSecondsOf,
    formatElapsed,
} from '@/composables/useLiveGenerations';
import {
    aspectOf,
    qualityName,
    ratioOf,
    titleOf,
} from '@/lib/generationLabels';
import { show } from '@/routes/generations';
import type { GenerationHistoryItem } from '@/types/generations';

const props = defineProps<{
    generation: GenerationHistoryItem;
    now: number;
}>();

const imageFailed = ref(false);
// A new link (e.g. a refreshed one) gets a fresh chance to load.
watch(
    () => props.generation.image?.url,
    () => (imageFailed.value = false),
);

const elapsed = computed(() =>
    elapsedSecondsOf(
        props.generation.created_at,
        props.generation.finished_at,
        props.now,
    ),
);
const status = computed(() => statusDisplay(props.generation, elapsed.value));
const cost = computed(() => costLine(props.generation));
const showImage = computed(
    () =>
        props.generation.image !== null &&
        !props.generation.image.expired &&
        !imageFailed.value,
);
const meta = computed(() => {
    const g = props.generation;
    const base = `${qualityName(g.quality)} · ${ratioOf(g.size)}`;

    if (g.in_flight) {
        return `${base} · ${status.value.label} · ${formatElapsed(elapsed.value)}`;
    }

    return `${base} · ${cost.value.value}`;
});
</script>

<template>
    <div
        class="relative mb-4 break-inside-avoid overflow-hidden rounded-[14px] bg-ink-card"
    >
        <Link
            :href="show(generation.id)"
            class="block text-ink-text no-underline"
        >
            <img
                v-if="showImage && generation.image"
                :src="generation.image.url"
                :alt="generation.prompt"
                loading="lazy"
                class="block h-auto w-full"
                @error="imageFailed = true"
            />
            <div
                v-else
                class="flex w-full flex-col items-center justify-center gap-3 border border-ink-line px-4 pb-16 text-center"
                :style="{ aspectRatio: aspectOf(generation.size) }"
            >
                <template v-if="generation.in_flight">
                    <SparkleIcon
                        class="size-7"
                        :class="
                            status.tone === 'warn'
                                ? 'text-amber-200'
                                : 'text-lime'
                        "
                    />
                    <span class="text-[14px] text-ink-soft">{{
                        status.busyLabel
                    }}</span>
                    <span
                        aria-hidden="true"
                        class="block h-1 w-1/2 overflow-hidden rounded bg-ink-border"
                        ><span
                            class="block h-full w-[62%] animate-pulse"
                            :class="
                                status.tone === 'warn'
                                    ? 'bg-amber-300'
                                    : 'bg-lime'
                            "
                    /></span>
                </template>
                <template v-else-if="generation.status === 'failed'">
                    <CircleX class="size-7 text-red-300" aria-hidden="true" />
                    <span class="text-[13px] leading-snug text-ink-soft">{{
                        generation.failure?.message
                    }}</span>
                </template>
                <template v-else>
                    <ImageOff
                        class="size-6 text-ink-muted"
                        aria-hidden="true"
                    />
                    <span class="text-[13px] text-ink-muted">{{
                        generation.image?.expired
                            ? 'Image expired. Pixelforge keeps images for 24 hours.'
                            : "Couldn't load the image."
                    }}</span>
                </template>
            </div>
            <div
                class="absolute inset-x-0 bottom-0 flex flex-col gap-0.5 bg-linear-to-b from-ink/0 to-ink/85 px-3.5 pt-10 pb-3.5"
            >
                <span class="text-[15px] font-semibold">{{
                    titleOf(generation.prompt)
                }}</span>
                <span class="text-[12px] text-ink-soft tabular-nums">{{
                    meta
                }}</span>
            </div>
        </Link>
        <span class="absolute top-2.5 left-2.5" aria-live="polite">
            <span
                v-if="generation.status !== 'succeeded'"
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-[5px] text-[12px] font-semibold"
                :class="{
                    'border border-ink-border bg-ink/80 text-ink-soft':
                        status.tone === 'neutral',
                    'border border-lime/30 bg-ink/80 text-lime':
                        status.tone === 'active',
                    'border border-amber-400/30 bg-ink/80 text-amber-200':
                        status.tone === 'warn',
                    'border border-red-400/30 bg-ink/80 text-red-200':
                        status.tone === 'error',
                }"
            >
                <component
                    :is="status.icon"
                    class="size-3.5"
                    :class="{ 'animate-spin': status.spin }"
                    aria-hidden="true"
                />
                {{ status.label }}
            </span>
        </span>
        <button
            type="button"
            disabled
            aria-label="Save to favourites (coming soon)"
            title="Coming soon"
            class="absolute top-2.5 right-2.5 grid size-11 cursor-not-allowed place-items-center rounded-[12px] border-0 bg-ink/60 text-ink-text"
        >
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path
                    d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"
                />
            </svg>
        </button>
    </div>
</template>
