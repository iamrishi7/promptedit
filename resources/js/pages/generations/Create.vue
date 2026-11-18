<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import ArrowRightIcon from '@/components/atelier/ArrowRightIcon.vue';
import SparkleIcon from '@/components/atelier/SparkleIcon.vue';
import ReferenceImageFields from '@/components/generations/ReferenceImageFields.vue';
import StudioTile from '@/components/generations/StudioTile.vue';
import { useLiveGenerations } from '@/composables/useLiveGenerations';
import { IMG } from '@/lib/atelier';
import { QUALITY_INFO, qualityName, ratioOf } from '@/lib/generationLabels';
import { models } from '@/routes';
import { estimate, index, store } from '@/routes/generations';
import type { CostEstimate, GenerationHistoryItem } from '@/types/generations';

defineOptions({
    layout: { variant: 'app', current: 'create' },
});

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    maxPromptCharacters: number;
    maxReferenceImages: number;
    markupPercent: number;
    initial: { prompt: string; size: string | null; quality: string | null };
    recent: GenerationHistoryItem[];
    serverNow: string;
    spent: string;
}>();

const QUALITY_THUMBS: Record<string, string> = {
    low: IMG.bloom,
    medium: IMG.rain,
    high: IMG.hero,
};

// Pixelforge supports three sizes; the design's wide ratios are shown but not offered.
const RATIOS: { label: string; w: number; h: number; size: string | null }[] = [
    { label: '1:1', w: 18, h: 18, size: '1024x1024' },
    { label: '2:3', w: 14, h: 21, size: '1024x1536' },
    { label: '3:2', w: 21, h: 14, size: '1536x1024' },
    { label: '16:9', w: 24, h: 13.5, size: null },
    { label: '9:16', w: 12, h: 21, size: null },
];

const form = useForm({
    prompt: props.initial.prompt,
    size: props.initial.size ?? props.sizes[0] ?? '1024x1024',
    quality: props.initial.quality ?? props.qualities[1] ?? 'medium',
    reference_image_urls: [] as string[],
    // Images to make; each is its own Pixelforge generation, priced and charged separately.
    count: 1,
    // One id per form attempt: submitting it twice (double click, retried request) starts one generation.
    client_request_id: crypto.randomUUID(),
});

const showReferences = ref(false);

// Unicode characters, the way Pixelforge (and the server's mb_strlen) count them.
const promptLength = computed(() => [...form.prompt].length);
const promptTooLong = computed(
    () => promptLength.value > props.maxPromptCharacters,
);

const errorFor = (field: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[field];

const referenceErrors = computed(() =>
    Object.keys(form.errors).some((key) =>
        key.startsWith('reference_image_urls'),
    ),
);

// --- Price estimate: always computed by the server, with the formula used when the generation starts.

const estimateRequest = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_image_urls: string[];
        count: number;
    },
    CostEstimate
>({
    prompt: '',
    size: '',
    quality: '',
    reference_image_urls: [],
    count: 1,
});

/**
 * A server estimate plus what it holds for. The price depends only on the prompt's length (through
 * its text tokens) and the settings, so one estimate covers a whole range of lengths: typing
 * within it, or editing a reference link's text, never needs a new request.
 */
type HeldEstimate = CostEstimate & {
    settings: string;
    from: number;
    to: number;
    fetchedAt: number;
};

// The server caches Pixelforge's prices for 10 minutes; reuse an estimate for at most 5.
const REUSE_ESTIMATE_FOR_MS = 5 * 60 * 1000;
const MAX_HELD_ESTIMATES = 20;

const heldEstimates: HeldEstimate[] = [];

const referenceCount = computed(
    () => form.reference_image_urls.filter((url) => url.trim() !== '').length,
);
const priceSettings = computed(
    () => `${form.size}|${form.quality}|${referenceCount.value}|${form.count}`,
);

const covers = (held: HeldEstimate): boolean =>
    held.settings === priceSettings.value &&
    promptLength.value >= held.from &&
    promptLength.value <= held.to &&
    Date.now() - held.fetchedAt < REUSE_ESTIMATE_FOR_MS;

const price = ref<HeldEstimate | null>(null);
const priceError = ref<string | null>(null);
// True from the moment the inputs leave what the shown price covers until the new price arrives,
// so an old price is never shown as current or used to start a generation.
const priceStale = ref(false);

/** Show a price we already hold for the current inputs. Returns whether there was one. */
function useHeldEstimate(): boolean {
    if (price.value !== null && covers(price.value)) {
        return true;
    }

    const held = heldEstimates.find(covers);

    if (held === undefined) {
        return false;
    }

    estimateRequest.cancel();
    price.value = held;
    priceError.value = null;

    return true;
}

const fetchEstimate = (): void => {
    if (form.prompt.trim() === '' || promptTooLong.value) {
        price.value = null;
        priceError.value = null;
        priceStale.value = false;

        return;
    }

    if (useHeldEstimate()) {
        priceStale.value = false;

        return;
    }

    // What this request prices, captured now: the inputs may change before the answer arrives.
    const settings = priceSettings.value;

    estimateRequest.cancel();
    estimateRequest.prompt = form.prompt;
    estimateRequest.size = form.size;
    estimateRequest.quality = form.quality;
    estimateRequest.count = form.count;
    estimateRequest.reference_image_urls = form.reference_image_urls.filter(
        (url) => url.trim() !== '',
    );

    estimateRequest
        .post(estimate().url, {
            onSuccess: (response) => {
                const held: HeldEstimate = {
                    ...response,
                    settings,
                    from: response.same_price_for_characters.from,
                    to: response.same_price_for_characters.to,
                    fetchedAt: Date.now(),
                };

                heldEstimates.unshift(held);
                heldEstimates.splice(MAX_HELD_ESTIMATES);
                priceError.value = null;

                if (covers(held)) {
                    price.value = held;
                    priceStale.value = false;
                } else {
                    // The inputs moved on while this was in flight.
                    refreshPrice();
                }
            },
            onError: (errors) => {
                priceStale.value = false;
                // The form shows field errors; the price simply isn't known for an invalid request.
                price.value = null;
                priceError.value =
                    Object.values(errors)[0] ??
                    'Fix the form to see the price.';
            },
            onHttpException: (response) => {
                priceStale.value = false;
                price.value = null;
                priceError.value =
                    response.status === 429
                        ? 'Too many price checks. Wait a moment.'
                        : "We can't get Pixelforge's prices right now. Try again in a moment.";

                return false;
            },
            onNetworkError: () => {
                priceStale.value = false;
                price.value = null;
                priceError.value =
                    "Couldn't reach the server to check the price.";

                return false;
            },
        })
        .catch(() => {
            // Handled by the callbacks above (or cancelled by a newer request).
        });
};

const requestEstimate = useDebounceFn(fetchEstimate, 400);

/** After any input change: keep or reuse a price that still applies, else ask (debounced). */
function refreshPrice(): void {
    if (form.prompt.trim() === '' || promptTooLong.value) {
        fetchEstimate();

        return;
    }

    if (useHeldEstimate()) {
        priceStale.value = false;

        return;
    }

    priceStale.value = true;
    void requestEstimate();
}

watch(
    () => [
        form.prompt,
        form.size,
        form.quality,
        form.reference_image_urls.join('\n'),
        form.count,
    ],
    () => {
        // Different values are a different request: a new id, so the server never mistakes
        // them for a repeat of an earlier submission.
        form.client_request_id = crypto.randomUUID();
        refreshPrice();
    },
);

if (form.prompt !== '') {
    priceStale.value = true;
    fetchEstimate();
}

const canSubmit = computed(
    () =>
        !form.processing &&
        form.prompt.trim() !== '' &&
        !promptTooLong.value &&
        // Never start a generation the user hasn't seen a price for.
        price.value !== null &&
        !priceStale.value,
);

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_image_urls: data.reference_image_urls.filter(
            (url) => url.trim() !== '',
        ),
    })).post(store().url, {
        // Stay in the studio with the same settings; the new generation appears below the form.
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            // The next click is a new generation, not a repeat of this one.
            form.client_request_id = crypto.randomUUID();
        },
        onError: () => {
            if (referenceErrors.value) {
                showReferences.value = true;
            }

            // Errors may be above the fold (especially on phones): bring the first one into view.
            void nextTick(() => {
                const invalid = document.querySelector<HTMLElement>(
                    '[role="alert"], [aria-invalid="true"]',
                );
                invalid?.scrollIntoView({ block: 'center' });
                invalid?.focus({ preventScroll: true });
            });
        },
    });
}

// --- Settings panel options (the design's look; selected = lime outline).

const qualityOptions = computed(() =>
    props.qualities.map((quality) => ({
        value: quality,
        name: QUALITY_INFO[quality]?.name ?? quality,
        desc: QUALITY_INFO[quality]?.desc ?? '',
        src: QUALITY_THUMBS[quality] ?? IMG.hero,
        on: form.quality === quality,
    })),
);

const ratioOptions = computed(() =>
    RATIOS.map((ratio) => ({
        ...ratio,
        supported:
            ratio.size !== null && props.sizes.includes(ratio.size ?? ''),
        on: ratio.size !== null && form.size === ratio.size,
    })),
);

// --- Recent generations, grouped like the design's batches: same prompt and settings in a row.

const anyInFlight = computed(() => props.recent.some((g) => g.in_flight));
const { now } = useLiveGenerations(
    anyInFlight,
    () => props.serverNow,
    ['recent', 'serverNow'],
    // The total only changes when a generation settles.
    ['spent'],
);

const batches = computed(() => {
    const groups: {
        key: string;
        prompt: string;
        meta: string;
        items: GenerationHistoryItem[];
    }[] = [];

    for (const generation of props.recent) {
        const last = groups[groups.length - 1];
        const first = last?.items[0];

        if (
            first &&
            first.prompt === generation.prompt &&
            first.size === generation.size &&
            first.quality === generation.quality
        ) {
            last.items.push(generation);
            continue;
        }

        groups.push({
            key: '',
            prompt: generation.prompt,
            meta: '',
            items: [generation],
        });
    }

    return groups.map((group) => {
        const first = group.items[0];
        const when = group.items.some((g) => g.in_flight)
            ? 'In progress'
            : new Date(first.created_at).toLocaleString(undefined, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              });

        return {
            ...group,
            // Keyed by the oldest item, so generating again with the same settings (which prepends
            // to the group) doesn't remount the section and reload every image.
            key: group.items[group.items.length - 1].id,
            meta: `${group.items.length} images · ${qualityName(first.quality)} · ${ratioOf(first.size)} · ${when}`,
        };
    });
});

// One continuous grid. A prompt with several images in a row is fenced by dividers so the set
// reads as one; single images just flow.
type Cell =
    | { kind: 'tile'; key: string; generation: GenerationHistoryItem }
    | { kind: 'start'; key: string; label: string; prompt: string }
    | { kind: 'end'; key: string };

const cells = computed(() => {
    const list: Cell[] = [];

    batches.value.forEach((batch, position) => {
        const grouped = batch.items.length > 1;

        if (grouped) {
            list.push({
                kind: 'start',
                key: `start-${batch.key}`,
                label: batch.meta,
                prompt: batch.prompt,
            });
        }

        for (const generation of batch.items) {
            list.push({ kind: 'tile', key: generation.id, generation });
        }

        const next = batches.value[position + 1];

        if (grouped && next !== undefined && next.items.length === 1) {
            list.push({ kind: 'end', key: `end-${batch.key}` });
        }
    });

    return list;
});

const inputClass =
    'h-11 rounded-[10px] border border-ink-border bg-ink-card px-3 font-inter text-[14px] text-ink-text';
const legendClass =
    'p-0 pb-2.5 text-[12px] font-semibold tracking-[0.08em] text-ink-muted uppercase';
</script>

<template>
    <Head title="Studio" />

    <div class="flex flex-1 flex-wrap items-stretch">
        <aside
            aria-label="Generation settings"
            class="order-last box-border flex max-w-full flex-[1_1_320px] flex-col gap-6 border-t border-ink-line bg-ink-panel p-5 min-[880px]:order-first min-[880px]:border-t-0 min-[880px]:border-r"
        >
            <fieldset class="m-0 flex flex-col gap-2.5 border-0 p-0">
                <legend :class="legendClass">Quality</legend>
                <button
                    v-for="option in qualityOptions"
                    :key="option.value"
                    type="button"
                    :aria-pressed="option.on"
                    class="flex w-full cursor-pointer items-center gap-3 rounded-[12px] p-2 font-inter text-ink-text"
                    :class="
                        option.on
                            ? 'border border-lime bg-ink-raised'
                            : 'border border-ink-line bg-transparent'
                    "
                    @click="form.quality = option.value"
                >
                    <img
                        :src="option.src"
                        alt=""
                        class="size-11 rounded-[8px] object-cover"
                    />
                    <span class="flex flex-col gap-0.5 text-left">
                        <span class="text-[14px] font-semibold">{{
                            option.name
                        }}</span>
                        <span class="text-[12px] text-ink-muted">{{
                            option.desc
                        }}</span>
                    </span>
                </button>
                <Link
                    :href="models()"
                    class="py-1.5 text-[13px] text-ink-soft no-underline hover:text-white"
                    >Compare quality and sizes →</Link
                >
                <p
                    v-if="errorFor('quality')"
                    role="alert"
                    class="m-0 text-[13px] text-red-300"
                >
                    {{ errorFor('quality') }}
                </p>
            </fieldset>

            <fieldset class="m-0 border-0 p-0">
                <legend :class="legendClass">Aspect ratio</legend>
                <div class="grid grid-cols-5 gap-1.5">
                    <button
                        v-for="ratio in ratioOptions"
                        :key="ratio.label"
                        type="button"
                        :aria-pressed="ratio.on"
                        :disabled="!ratio.supported"
                        :title="
                            ratio.supported
                                ? undefined
                                : 'Pixelforge does not offer this size'
                        "
                        class="flex h-16 flex-col items-center justify-end gap-1.5 rounded-[10px] py-2 font-inter disabled:cursor-not-allowed disabled:opacity-45"
                        :class="
                            ratio.on
                                ? 'cursor-pointer border border-lime bg-ink-raised text-ink-text'
                                : 'cursor-pointer border border-ink-line bg-ink-card text-ink-soft'
                        "
                        @click="ratio.size && (form.size = ratio.size)"
                    >
                        <span
                            aria-hidden="true"
                            class="block rounded-[3px] border-2"
                            :class="ratio.on ? 'border-lime' : 'border-ink-dim'"
                            :style="{
                                width: `${ratio.w}px`,
                                height: `${ratio.h}px`,
                            }"
                        />
                        <span class="text-[12px]">{{ ratio.label }}</span>
                    </button>
                </div>
                <p
                    v-if="errorFor('size')"
                    role="alert"
                    class="m-0 pt-2 text-[13px] text-red-300"
                >
                    {{ errorFor('size') }}
                </p>
            </fieldset>

            <fieldset class="m-0 border-0 p-0">
                <legend :class="legendClass">Number of images</legend>
                <div
                    class="grid grid-cols-4 gap-1.5 rounded-[12px] border border-ink-line bg-ink-card p-1"
                >
                    <button
                        v-for="n in 4"
                        :key="n"
                        type="button"
                        :aria-pressed="form.count === n"
                        class="min-h-10 cursor-pointer rounded-[9px] border-0 font-inter text-[14px] font-semibold transition-colors"
                        :class="
                            form.count === n
                                ? 'bg-ink-text text-ink'
                                : 'bg-transparent text-ink-soft hover:bg-ink-raised hover:text-ink-text'
                        "
                        @click="form.count = n"
                    >
                        {{ n }}
                    </button>
                </div>
            </fieldset>

            <div class="flex flex-col gap-2">
                <label
                    for="style"
                    class="flex items-center justify-between text-[12px] font-semibold tracking-[0.08em] text-ink-muted uppercase"
                    >Style preset
                    <span class="font-medium tracking-normal normal-case"
                        >Coming soon</span
                    ></label
                >
                <select
                    id="style"
                    disabled
                    :class="[inputClass, 'cursor-not-allowed opacity-60']"
                >
                    <option>None</option>
                </select>
            </div>

            <details class="border-t border-ink-line pt-4">
                <summary
                    class="min-h-8 cursor-pointer text-[14px] font-semibold"
                >
                    Advanced settings
                </summary>
                <div class="flex flex-col gap-3.5 pt-3">
                    <p class="m-0 text-[13px] text-ink-muted">
                        Pixelforge doesn't support seeds or negative prompts
                        yet.
                    </p>
                    <div class="flex flex-col gap-1.5">
                        <label for="seed" class="text-[13px] text-ink-muted"
                            >Seed (leave blank for random)</label
                        >
                        <input
                            id="seed"
                            type="text"
                            inputmode="numeric"
                            placeholder="Random"
                            disabled
                            :class="[
                                inputClass,
                                'cursor-not-allowed opacity-60',
                            ]"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="neg" class="text-[13px] text-ink-muted"
                            >Negative prompt</label
                        >
                        <textarea
                            id="neg"
                            rows="3"
                            placeholder="What to leave out"
                            disabled
                            class="cursor-not-allowed resize-y rounded-[10px] border border-ink-border bg-ink-card px-3 py-2.5 font-inter text-[14px] text-ink-text opacity-60"
                        />
                    </div>
                </div>
            </details>
        </aside>

        <main
            class="box-border flex min-w-0 flex-[999_1_560px] flex-col gap-7 px-[clamp(16px,2.5vw,32px)] pt-5 pb-10"
        >
            <form
                class="prompt-glow flex flex-col gap-3 rounded-[18px] border border-ink-border bg-ink-card p-4"
                novalidate
                @submit.prevent="submit"
            >
                <div
                    v-if="errorFor('form')"
                    role="alert"
                    tabindex="-1"
                    class="rounded-[10px] border border-red-400/30 bg-red-500/15 px-3 py-2 text-[14px] text-red-100"
                >
                    {{ errorFor('form') }} Nothing was started and you were not
                    charged.
                </div>

                <div class="flex items-baseline justify-between gap-2">
                    <label
                        for="prompt"
                        class="text-[12px] font-semibold tracking-[0.08em] text-ink-muted uppercase"
                        >Prompt</label
                    >
                    <span
                        class="text-[12px] tabular-nums"
                        id="prompt-count"
                        :class="
                            promptTooLong ? 'text-red-300' : 'text-ink-muted'
                        "
                        >{{ promptLength.toLocaleString() }} /
                        {{ maxPromptCharacters.toLocaleString() }}</span
                    >
                </div>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="3"
                    required
                    :aria-invalid="promptTooLong || !!errorFor('prompt')"
                    aria-describedby="prompt-count"
                    placeholder="A lighthouse on a cliff during a thunderstorm, cinematic"
                    class="resize-y border-0 bg-transparent font-inter text-[18px] leading-normal text-ink-text outline-none placeholder:text-ink-dim"
                    @keydown.meta.enter.prevent="submit"
                    @keydown.ctrl.enter.prevent="submit"
                />
                <p
                    v-if="errorFor('prompt') || promptTooLong"
                    role="alert"
                    class="m-0 text-[13px] text-red-300"
                >
                    {{
                        errorFor('prompt') ??
                        `The prompt can be at most ${maxPromptCharacters.toLocaleString()} characters.`
                    }}
                </p>

                <ReferenceImageFields
                    v-if="
                        showReferences || form.reference_image_urls.length > 0
                    "
                    v-model="form.reference_image_urls"
                    :max="maxReferenceImages"
                    :errors="form.errors"
                />

                <div
                    class="flex flex-wrap items-center justify-between gap-2.5"
                >
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            :aria-expanded="
                                showReferences ||
                                form.reference_image_urls.length > 0
                            "
                            class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border border-ink-border bg-ink-raised px-3.5 font-inter text-[14px] text-ink-text"
                            @click="
                                showReferences = !showReferences;
                                if (
                                    showReferences &&
                                    form.reference_image_urls.length === 0
                                )
                                    form.reference_image_urls = [''];
                            "
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
                                <rect
                                    x="3"
                                    y="4"
                                    width="18"
                                    height="16"
                                    rx="2"
                                />
                                <circle cx="9" cy="10" r="2" />
                                <path d="M21 16l-5-5-9 9" />
                            </svg>
                            Image reference
                            <span
                                v-if="form.reference_image_urls.length > 0"
                                class="rounded-[6px] bg-ink-border px-1.5 text-[12px]"
                                >{{ form.reference_image_urls.length }}</span
                            >
                        </button>
                        <button
                            type="button"
                            disabled
                            title="Coming soon"
                            class="inline-flex min-h-11 cursor-not-allowed items-center gap-2 rounded-[10px] border border-ink-border bg-ink-raised px-3.5 font-inter text-[14px] text-ink-text opacity-60"
                        >
                            <SparkleIcon class="size-[18px]" />Enhance prompt
                        </button>
                    </div>
                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="group/generate relative inline-flex min-h-12 cursor-pointer items-center gap-2.5 overflow-hidden rounded-[12px] border-0 bg-lime px-[22px] font-inter text-[16px] font-semibold text-ink shadow-[0_0_0_0_rgba(212,255,79,0)] transition duration-200 ease-out hover:-translate-y-px hover:shadow-[0_8px_28px_-6px_rgba(212,255,79,0.55)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime active:translate-y-0 active:scale-[0.97] disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-60 disabled:shadow-none"
                    >
                        <span
                            aria-hidden="true"
                            class="pointer-events-none absolute inset-y-0 -left-1/2 w-1/3 -skew-x-12 bg-white/40 opacity-0 transition-all duration-700 ease-out group-hover/generate:left-[120%] group-hover/generate:opacity-100 group-disabled/generate:hidden"
                        />
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-[18px] animate-spin"
                            aria-hidden="true"
                        />
                        <SparkleIcon
                            v-else
                            class="size-[18px] transition-transform duration-300 group-hover/generate:scale-110 group-hover/generate:rotate-90"
                        />
                        {{
                            form.processing
                                ? 'Starting…'
                                : form.count > 1
                                  ? `Generate ${form.count} images`
                                  : 'Generate'
                        }}
                    </button>
                </div>

                <div
                    class="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-ink-line pt-3 text-[13px]"
                >
                    <span class="sr-only" aria-live="polite">{{
                        price && !priceStale
                            ? `Estimated price ${price.min_display} to ${price.max_display} for ${form.count === 1 ? 'one image' : `${form.count} images`}`
                            : (priceError ?? '')
                    }}</span>
                    <span class="text-ink-muted">Estimated price</span>
                    <span
                        v-if="form.prompt.trim() === ''"
                        class="text-ink-muted"
                        >Enter a prompt to see the price.</span
                    >
                    <span
                        v-else-if="priceStale && !price"
                        class="inline-block h-4 w-28 animate-pulse rounded bg-ink-raised"
                        ><span class="sr-only">Calculating price…</span></span
                    >
                    <span
                        v-else-if="priceError"
                        class="inline-flex flex-wrap items-center gap-2 text-red-300"
                    >
                        {{ priceError }} Generate is paused until we can show
                        the price.
                        <button
                            type="button"
                            class="cursor-pointer rounded-[8px] border border-ink-border bg-ink-raised px-2.5 py-1 text-[13px] text-ink-text"
                            @click="fetchEstimate"
                        >
                            Try again
                        </button>
                    </span>
                    <template v-else-if="price">
                        <span
                            class="font-semibold text-ink-text tabular-nums"
                            :class="{ 'opacity-50': priceStale }"
                            >{{ price.min_display }} –
                            {{ price.max_display }}</span
                        >
                        <span class="text-ink-muted">{{
                            priceStale
                                ? 'Updating price…'
                                : `${form.count === 1 ? 'For one image' : `For ${form.count} images`}. You pay at most ${price.max_display}; failed images are not charged.`
                        }}</span>
                    </template>
                </div>
            </form>

            <section
                v-if="cells.length > 0"
                aria-label="Recent generations"
                class="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-3"
            >
                <template v-for="cell in cells" :key="cell.key">
                    <StudioTile
                        v-if="cell.kind === 'tile'"
                        :generation="cell.generation"
                        :now="now"
                    />
                    <div
                        v-else-if="cell.kind === 'start'"
                        class="col-span-full flex items-center gap-3 pt-1"
                        :title="cell.prompt"
                    >
                        <span class="h-px flex-1 bg-ink-border" />
                        <span
                            class="rounded-full border border-ink-border bg-ink-card px-3 py-1 text-[12px] font-medium text-ink-soft tabular-nums"
                            >{{ cell.label }}</span
                        >
                        <span class="h-px flex-1 bg-ink-border" />
                    </div>
                    <div
                        v-else
                        aria-hidden="true"
                        class="col-span-full h-px bg-ink-border"
                    />
                </template>
            </section>

            <p
                v-if="batches.length === 0"
                class="m-0 rounded-[18px] border border-dashed border-ink-border p-10 text-center text-[15px] text-ink-muted"
            >
                Your images will appear here. You see the price range before you
                generate, and you only pay for images that succeed.
            </p>
            <Link
                v-else
                :href="index()"
                class="inline-flex items-center gap-2 self-start py-3 text-[15px] font-medium text-ink-text no-underline hover:text-white"
                >See everything in My library <ArrowRightIcon
            /></Link>
        </main>
    </div>
</template>
