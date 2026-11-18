<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CircleX, Download, Expand, ImageOff, Share2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SparkleIcon from '@/components/atelier/SparkleIcon.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import ImageLightbox from '@/components/generations/ImageLightbox.vue';
import { costLine, statusDisplay } from '@/composables/useGenerationStatus';
import {
    elapsedSecondsOf,
    formatElapsed,
    useLiveGenerations,
} from '@/composables/useLiveGenerations';
import {
    qualityName,
    ratioOf,
    resolutionOf,
    SIZE_INFO,
    titleOf,
} from '@/lib/generationLabels';
import { shareImage } from '@/lib/shareImage';
import { create, download, index, show } from '@/routes/generations';
import type {
    GenerationDetail,
    GenerationHistoryItem,
} from '@/types/generations';

defineOptions({
    layout: { variant: 'app', current: 'library' },
});

const props = defineProps<{
    generation: GenerationDetail;
    variations: GenerationHistoryItem[];
    more: GenerationHistoryItem[];
    serverNow: string;
    spent: string;
}>();

const page = usePage();
const ownerName = computed(() => page.props.auth.user?.name ?? 'You');
const initial = computed(
    () => ownerName.value.trim().charAt(0).toUpperCase() || 'A',
);

// Only this generation is watched live; the other attempts and the total refresh once it settles.
const anyInFlight = computed(() => props.generation.in_flight);
const { now } = useLiveGenerations(
    anyInFlight,
    () => props.serverNow,
    ['generation', 'serverNow'],
    ['variations', 'more', 'spent'],
);

const elapsed = computed(() =>
    elapsedSecondsOf(
        props.generation.created_at,
        props.generation.finished_at,
        now.value,
    ),
);
const status = computed(() => statusDisplay(props.generation, elapsed.value));
const cost = computed(() => costLine(props.generation));

const imageFailed = ref(false);
// A new link (e.g. a refreshed one) gets a fresh chance to load.
watch(
    () => props.generation.image?.url,
    () => (imageFailed.value = false),
);
const showImage = computed(
    () =>
        props.generation.image !== null &&
        !props.generation.image.expired &&
        !imageFailed.value,
);

const createdLabel = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);

const remixHref = computed(() =>
    create({
        query: {
            prompt: props.generation.prompt,
            size: props.generation.size,
            quality: props.generation.quality,
        },
    }),
);

// The same prompt would be refused or fail again for these, so the action is to change it.
const PROMPT_PROBLEMS = ['content_policy_violation', 'generation_failed'];
const remixLabel = computed(() => {
    const { status, failure } = props.generation;

    if (status !== 'failed' || failure === null || failure.provisional) {
        return 'Remix';
    }

    return PROMPT_PROBLEMS.includes(failure.code) ? 'Edit prompt' : 'Try again';
});

const expanded = ref(false);

const copied = ref(false);
async function copyPrompt(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.generation.prompt);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {
        // Clipboard blocked (e.g. insecure context): the prompt is still selectable on the page.
    }
}

const details = computed(() => [
    { term: 'Quality', value: qualityName(props.generation.quality) },
    {
        term: 'Aspect ratio',
        value: `${ratioOf(props.generation.size)} · ${SIZE_INFO[props.generation.size]?.label ?? ''}`,
    },
    { term: 'Resolution', value: resolutionOf(props.generation.size) },
    {
        term: props.generation.in_flight ? 'Running for' : 'Took',
        value: formatElapsed(elapsed.value),
    },
    { term: 'Estimated', value: props.generation.estimate.display },
    props.generation.in_flight && !props.generation.failure?.provisional
        ? {
              term: 'Charged',
              value: 'Known when finished',
              tone: 'text-ink-muted',
          }
        : { term: 'Charged', value: cost.value.value, tone: cost.value.tone },
]);

const tags = computed(() => [
    `#${props.generation.quality}-quality`,
    `#${(SIZE_INFO[props.generation.size]?.label ?? 'image').toLowerCase()}`,
    `#${props.generation.status === 'succeeded' ? 'done' : props.generation.status}`,
]);

const thumbOf = (g: GenerationHistoryItem): string | null =>
    g.image !== null && !g.image.expired ? g.image.url : null;

const iconButton =
    'grid size-12 place-items-center rounded-[12px] border border-ink-border bg-ink-raised text-ink-text';
const iconHover =
    'cursor-pointer transition hover:border-lime/50 hover:text-lime focus-visible:outline-2 focus-visible:outline-lime';
</script>

<template>
    <Head :title="titleOf(generation.prompt)" />

    <main
        class="mx-auto box-border flex w-full max-w-[1440px] flex-col gap-5 px-[clamp(16px,4vw,48px)] pt-5 pb-20"
    >
        <Link
            :href="index()"
            class="inline-flex items-center gap-2 self-start px-0.5 py-2.5 text-[15px] text-ink-soft no-underline hover:text-white"
        >
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <path d="M19 12H5M11 6l-6 6 6 6" />
            </svg>
            Back to My library</Link
        >

        <div class="flex flex-wrap items-start gap-6">
            <section
                aria-label="Image"
                class="flex min-w-0 flex-[999_1_640px] flex-col gap-3"
            >
                <div
                    class="flex justify-center overflow-hidden rounded-[20px] border border-ink-line bg-ink-deep"
                >
                    <button
                        v-if="showImage && generation.image"
                        type="button"
                        class="block w-full cursor-zoom-in border-0 bg-transparent p-0"
                        aria-label="Expand image"
                        @click="expanded = true"
                    >
                        <img
                            :src="generation.image.url"
                            :alt="generation.prompt"
                            class="block h-auto max-h-[720px] w-full object-contain"
                            @error="imageFailed = true"
                        />
                    </button>
                    <ImageLightbox
                        v-if="showImage && generation.image"
                        v-model:open="expanded"
                        :generation-id="generation.id"
                        :url="generation.image.url"
                        :stored="generation.image.stored"
                        :prompt="generation.prompt"
                    />
                    <div
                        v-else
                        class="flex aspect-[4/3] max-h-[720px] w-full flex-col items-center justify-center gap-4 p-8 text-center"
                    >
                        <template v-if="generation.in_flight">
                            <SparkleIcon
                                class="size-9"
                                :class="
                                    status.tone === 'warn'
                                        ? 'text-amber-200'
                                        : 'text-lime'
                                "
                            />
                            <span class="text-[16px] text-ink-soft">{{
                                status.busyLabel
                            }}</span>
                            <span
                                aria-hidden="true"
                                class="block h-1 w-1/3 overflow-hidden rounded bg-ink-border"
                                ><span
                                    class="block h-full w-[62%] animate-pulse"
                                    :class="
                                        status.tone === 'warn'
                                            ? 'bg-amber-300'
                                            : 'bg-lime'
                                    "
                            /></span>
                            <span class="text-[13px] text-ink-muted"
                                >Usually about a minute. This page updates by
                                itself.</span
                            >
                        </template>
                        <template v-else-if="generation.status === 'failed'">
                            <CircleX
                                class="size-9 text-red-300"
                                aria-hidden="true"
                            />
                            <span class="text-[16px] text-ink-soft"
                                >No image: this generation failed.</span
                            >
                        </template>
                        <template v-else>
                            <ImageOff
                                class="size-8 text-ink-muted"
                                aria-hidden="true"
                            />
                            <span class="text-[15px] text-ink-muted">
                                {{
                                    generation.image?.expired
                                        ? 'Image expired. Pixelforge keeps images for 24 hours.'
                                        : "Couldn't load the image."
                                }}
                            </span>
                            <a
                                v-if="
                                    generation.image &&
                                    !generation.image.expired
                                "
                                :href="generation.image.url"
                                target="_blank"
                                rel="noopener"
                                class="text-[14px] text-ink-text underline underline-offset-4"
                                >Open it directly</a
                            >
                        </template>
                    </div>
                </div>
                <div
                    v-if="variations.length > 0"
                    class="flex flex-wrap items-center gap-2.5"
                >
                    <span class="mr-1 text-[13px] text-ink-muted"
                        >Other attempts at this prompt</span
                    >
                    <Link
                        v-for="(variation, position) in variations"
                        :key="variation.id"
                        :href="show(variation.id)"
                        :aria-label="`Open attempt ${position + 1}`"
                        class="grid size-[72px] place-items-center rounded-[12px] border-2 border-ink-border bg-transparent p-[3px]"
                    >
                        <img
                            v-if="thumbOf(variation)"
                            :src="thumbOf(variation) ?? ''"
                            alt=""
                            class="block size-full rounded-[8px] object-cover"
                        />
                        <span
                            v-else
                            class="grid size-full place-items-center rounded-[8px] bg-ink-card text-[11px] text-ink-muted"
                            >{{
                                variation.in_flight
                                    ? '…'
                                    : variation.status === 'failed'
                                      ? 'Failed'
                                      : '—'
                            }}</span
                        >
                    </Link>
                </div>
            </section>

            <aside
                aria-label="Generation details"
                class="box-border flex min-w-0 flex-[1_1_380px] flex-col gap-[18px] rounded-[20px] border border-ink-line bg-ink-card p-6"
            >
                <div class="flex flex-col gap-2.5">
                    <div aria-live="polite">
                        <GenerationStatusBadge
                            :generation="generation"
                            :elapsed-seconds="elapsed"
                        />
                    </div>
                    <h1
                        class="m-0 font-display text-[34px] leading-[1.05] font-semibold tracking-[-0.03em]"
                    >
                        {{ titleOf(generation.prompt, 60) }}
                    </h1>
                    <div
                        class="flex items-center gap-2.5 text-[14px] text-ink-muted"
                    >
                        <span
                            aria-hidden="true"
                            class="grid size-7 place-items-center rounded-full bg-lime text-[13px] font-bold text-ink"
                            >{{ initial }}</span
                        >
                        <span>by {{ ownerName }} · {{ createdLabel }}</span>
                    </div>
                </div>

                <p
                    v-if="generation.failure"
                    role="status"
                    class="m-0 rounded-[12px] px-3.5 py-3 text-[14px] leading-normal"
                    :class="
                        generation.failure.provisional
                            ? 'border border-amber-400/30 bg-amber-400/10 text-amber-100'
                            : 'border border-red-400/30 bg-red-500/10 text-red-100'
                    "
                >
                    {{ generation.failure.message }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="remixHref"
                        class="inline-flex min-h-12 flex-[1_1_160px] items-center justify-center gap-2 rounded-[12px] bg-lime font-semibold text-ink no-underline"
                    >
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path
                                d="M4 12a8 8 0 0 1 14-5.3L20 8M20 4v4h-4M20 12a8 8 0 0 1-14 5.3L4 16M4 20v-4h4"
                            />
                        </svg>
                        {{ remixLabel }}</Link
                    >
                    <template v-if="showImage && generation.image">
                        <a
                            :href="download(generation.id).url"
                            aria-label="Download"
                            :class="[iconButton, iconHover]"
                        >
                            <Download class="size-5" aria-hidden="true" />
                        </a>
                        <button
                            type="button"
                            aria-label="Expand"
                            :class="[iconButton, iconHover]"
                            @click="expanded = true"
                        >
                            <Expand class="size-5" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            aria-label="Share"
                            :class="[iconButton, iconHover]"
                            @click="
                                shareImage(
                                    generation.image,
                                    titleOf(generation.prompt),
                                )
                            "
                        >
                            <Share2 class="size-5" aria-hidden="true" />
                        </button>
                    </template>
                    <template v-else>
                        <button
                            v-for="action in [
                                { label: 'Download', icon: Download },
                                { label: 'Expand', icon: Expand },
                                { label: 'Share', icon: Share2 },
                            ]"
                            :key="action.label"
                            type="button"
                            disabled
                            :aria-label="`${action.label} (no image)`"
                            :class="[
                                iconButton,
                                'cursor-not-allowed opacity-50',
                            ]"
                        >
                            <component
                                :is="action.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </button>
                    </template>
                    <button
                        type="button"
                        disabled
                        aria-label="Save to favourites (coming soon)"
                        title="Coming soon"
                        :class="[iconButton, 'cursor-not-allowed']"
                    >
                        <svg
                            width="20"
                            height="20"
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

                <section
                    aria-labelledby="p-h"
                    class="flex flex-col gap-2.5 rounded-[14px] border border-ink-border bg-ink p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            id="p-h"
                            class="m-0 text-[12px] font-semibold tracking-[0.08em] text-ink-muted uppercase"
                        >
                            Prompt
                        </h2>
                        <button
                            type="button"
                            class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-[8px] border border-ink-border bg-ink-raised px-3 font-inter text-[13px] text-ink-text"
                            @click="copyPrompt"
                        >
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <rect
                                    x="8"
                                    y="8"
                                    width="12"
                                    height="12"
                                    rx="2"
                                />
                                <path
                                    d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"
                                />
                            </svg>
                            <span aria-live="polite">{{
                                copied ? 'Copied' : 'Copy'
                            }}</span>
                        </button>
                    </div>
                    <p
                        class="m-0 text-[16px] leading-[1.6] break-words whitespace-pre-line text-ink-body"
                    >
                        {{ generation.prompt }}
                    </p>
                    <template v-if="generation.reference_image_urls.length > 0">
                        <h3
                            class="mt-1.5 mb-0 text-[12px] font-semibold tracking-[0.08em] text-ink-muted uppercase"
                        >
                            Reference images
                        </h3>
                        <ul class="m-0 flex list-none flex-col gap-1 p-0">
                            <li
                                v-for="url in generation.reference_image_urls"
                                :key="url"
                                class="truncate text-[14px] leading-[1.55] text-ink-muted"
                            >
                                <a
                                    :href="url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-ink-muted underline-offset-4 hover:underline"
                                    >{{ url }}</a
                                >
                            </li>
                        </ul>
                    </template>
                </section>

                <dl class="m-0 grid grid-cols-2 gap-2">
                    <div
                        v-for="item in details"
                        :key="item.term"
                        class="rounded-[12px] bg-ink-raised px-3.5 py-3"
                    >
                        <dt class="text-[12px] text-ink-muted">
                            {{ item.term }}
                        </dt>
                        <dd
                            class="mt-1 mb-0 ml-0 text-[15px] font-semibold tabular-nums"
                            :class="item.tone"
                        >
                            {{ item.value }}
                        </dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="tag in tags"
                        :key="tag"
                        :href="index()"
                        class="rounded-full border border-ink-border px-3 py-2 text-[13px] text-ink-subtle no-underline"
                        >{{ tag }}</Link
                    >
                </div>
            </aside>
        </div>

        <section
            v-if="more.length > 0"
            aria-labelledby="more-h"
            class="flex flex-col gap-5 pt-12"
        >
            <h2
                id="more-h"
                class="m-0 font-display text-[30px] font-semibold tracking-[-0.03em]"
            >
                More from your library
            </h2>
            <div
                class="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-3.5"
            >
                <Link
                    v-for="item in more"
                    :key="item.id"
                    :href="show(item.id)"
                    class="flex flex-col gap-2 text-ink-text no-underline"
                >
                    <img
                        v-if="thumbOf(item)"
                        :src="thumbOf(item) ?? ''"
                        :alt="item.prompt"
                        loading="lazy"
                        class="aspect-square w-full rounded-[14px] object-cover"
                    />
                    <span
                        v-else
                        class="grid aspect-square w-full place-items-center rounded-[14px] border border-ink-line bg-ink-card px-4 text-center text-[13px] text-ink-muted"
                        >{{
                            item.in_flight
                                ? 'In progress'
                                : item.status === 'failed'
                                  ? 'Failed · not charged'
                                  : 'Image expired'
                        }}</span
                    >
                    <span class="text-[14px] font-medium">{{
                        titleOf(item.prompt, 36)
                    }}</span>
                </Link>
            </div>
        </section>
    </main>
</template>
