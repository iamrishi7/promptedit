<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleX, Download, Expand, ImageOff, Share2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SparkleIcon from '@/components/atelier/SparkleIcon.vue';
import ImageLightbox from '@/components/generations/ImageLightbox.vue';
import { statusDisplay } from '@/composables/useGenerationStatus';
import {
    elapsedSecondsOf,
    formatElapsed,
} from '@/composables/useLiveGenerations';
import { titleOf } from '@/lib/generationLabels';
import { shareImage } from '@/lib/shareImage';
import { download, show } from '@/routes/generations';
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

const expanded = ref(false);

const elapsed = computed(() =>
    elapsedSecondsOf(
        props.generation.created_at,
        props.generation.finished_at,
        props.now,
    ),
);
const status = computed(() => statusDisplay(props.generation, elapsed.value));
const href = computed(() => show(props.generation.id));
const showImage = computed(
    () =>
        props.generation.image !== null &&
        !props.generation.image.expired &&
        !imageFailed.value,
);

const actionClass =
    'grid size-11 cursor-pointer place-items-center rounded-[10px] border-0 bg-ink/70 text-ink-text backdrop-blur-sm transition hover:bg-ink/90 hover:text-lime focus-visible:outline-2 focus-visible:outline-lime';
</script>

<template>
    <div
        class="group relative aspect-square overflow-hidden rounded-[14px] border border-ink-line bg-ink-card"
        :title="generation.prompt"
    >
        <template v-if="showImage && generation.image">
            <Link :href="href" class="block size-full">
                <img
                    :src="generation.image.url"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="block size-full object-cover transition duration-300 group-hover:scale-[1.03]"
                    @error="imageFailed = true"
                />
            </Link>
            <div class="absolute bottom-2 left-2 flex gap-1.5">
                <button
                    type="button"
                    aria-label="Expand"
                    :class="actionClass"
                    @click="expanded = true"
                >
                    <Expand class="size-[18px]" aria-hidden="true" />
                </button>
                <a
                    :href="download(generation.id).url"
                    aria-label="Download"
                    :class="actionClass"
                >
                    <Download class="size-[18px]" aria-hidden="true" />
                </a>
                <button
                    type="button"
                    aria-label="Share"
                    :class="actionClass"
                    @click="
                        shareImage(generation.image, titleOf(generation.prompt))
                    "
                >
                    <Share2 class="size-[18px]" aria-hidden="true" />
                </button>
            </div>
            <ImageLightbox
                v-model:open="expanded"
                :generation-id="generation.id"
                :url="generation.image.url"
                :stored="generation.image.stored"
                :prompt="generation.prompt"
            />
        </template>

        <Link
            v-else-if="generation.in_flight"
            :href="href"
            class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-ink-card text-center no-underline"
        >
            <SparkleIcon
                class="size-7 animate-pulse"
                :class="status.tone === 'warn' ? 'text-amber-200' : 'text-lime'"
            />
            <span class="px-3 text-[14px] text-ink-soft"
                >{{ status.busyLabel }}
                <span class="text-ink-muted tabular-nums">{{
                    formatElapsed(elapsed)
                }}</span></span
            >
            <span
                aria-hidden="true"
                class="block h-1 w-1/2 overflow-hidden rounded bg-ink-border"
                ><span
                    class="block h-full w-[62%] animate-pulse"
                    :class="
                        status.tone === 'warn' ? 'bg-amber-300' : 'bg-lime'
                    "
            /></span>
            <span
                v-if="status.hint"
                class="px-4 text-[12px] leading-snug text-ink-muted"
                >{{ status.hint }}</span
            >
        </Link>

        <Link
            v-else-if="generation.status === 'failed'"
            :href="href"
            class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-ink-card p-4 text-center no-underline"
        >
            <CircleX class="size-7 text-red-300" aria-hidden="true" />
            <span class="text-[14px] font-semibold text-ink-text">Failed</span>
            <span
                class="line-clamp-5 text-[12px] leading-snug text-ink-muted"
                >{{ generation.failure?.message }}</span
            >
        </Link>

        <Link
            v-else
            :href="href"
            class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center text-[12px] text-ink-muted no-underline"
        >
            <ImageOff class="size-6" aria-hidden="true" />
            <template v-if="generation.image?.expired">
                Image expired. Pixelforge keeps images for 24 hours.
            </template>
            <template v-else>Couldn't load the image.</template>
        </Link>
    </div>
</template>
