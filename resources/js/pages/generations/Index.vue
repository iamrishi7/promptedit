<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ArrowRightIcon from '@/components/atelier/ArrowRightIcon.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import LibraryTile from '@/components/generations/LibraryTile.vue';
import { costLine } from '@/composables/useGenerationStatus';
import {
    elapsedSecondsOf,
    useLiveGenerations,
} from '@/composables/useLiveGenerations';
import { qualityName, ratioOf, titleOf } from '@/lib/generationLabels';
import { create, index, show } from '@/routes/generations';
import type { CursorPage, GenerationHistoryItem } from '@/types/generations';

defineOptions({
    layout: { variant: 'app', current: 'library' },
});

const props = defineProps<{
    generations: CursorPage<GenerationHistoryItem>;
    serverNow: string;
    spent: string;
}>();

const anyInFlight = computed(() =>
    props.generations.data.some((generation) => generation.in_flight),
);

// Refresh only while something can still change; stop once everything on the page is final.
const { now } = useLiveGenerations(anyInFlight, () => props.serverNow, [
    'generations',
    'serverNow',
    'spent',
]);

// --- Filters over the page being shown (status tabs, quality chips, prompt search).

type StatusFilter = 'All' | 'Done' | 'In progress' | 'Failed';

const statusFilter = ref<StatusFilter>('All');
const qualityFilter = ref('All');
const search = ref('');

const matchesStatus = (g: GenerationHistoryItem, filter: StatusFilter) => {
    switch (filter) {
        case 'Done':
            return g.status === 'succeeded';
        case 'In progress':
            return g.in_flight;
        case 'Failed':
            return g.status === 'failed' && !g.in_flight;
        default:
            return true;
    }
};

const tabs = computed(() =>
    (['All', 'Done', 'In progress', 'Failed'] as StatusFilter[]).map(
        (label) => ({
            label,
            count: props.generations.data.filter((g) => matchesStatus(g, label))
                .length,
            on: statusFilter.value === label,
        }),
    ),
);

const chips = computed(() =>
    ['All', 'low', 'medium', 'high'].map((value) => ({
        value,
        label: value === 'All' ? 'All' : qualityName(value),
        on: qualityFilter.value === value,
    })),
);

const items = computed(() => {
    const term = search.value.trim().toLocaleLowerCase();

    return props.generations.data.filter(
        (g) =>
            matchesStatus(g, statusFilter.value) &&
            (qualityFilter.value === 'All' ||
                g.quality === qualityFilter.value) &&
            (term === '' || g.prompt.toLocaleLowerCase().includes(term)),
    );
});

// --- The newest generation, featured on the first page like the design's collection banner.

const latest = computed(() =>
    props.generations.prev_cursor === null
        ? (props.generations.data[0] ?? null)
        : null,
);
const latestThumbs = computed(() =>
    props.generations.data
        .slice(1)
        .filter((g) => g.image !== null && !g.image.expired)
        .slice(0, 3),
);
const latestElapsed = computed(() =>
    latest.value
        ? elapsedSecondsOf(
              latest.value.created_at,
              latest.value.finished_at,
              now.value,
          )
        : 0,
);
const latestImageFailed = ref(false);
watch(
    () => latest.value?.image?.url,
    () => (latestImageFailed.value = false),
);
const latestHasImage = computed(
    () =>
        latest.value?.image !== null &&
        latest.value?.image?.expired === false &&
        !latestImageFailed.value,
);
</script>

<template>
    <Head title="My library" />

    <main
        class="mx-auto box-border flex w-full max-w-[1440px] flex-col gap-8 px-[clamp(16px,4vw,48px)] pt-10 pb-20"
    >
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex max-w-[640px] flex-col gap-2">
                <h1
                    class="m-0 font-display text-[clamp(36px,4.4vw,60px)] leading-none font-semibold tracking-[-0.035em]"
                >
                    My library
                </h1>
                <p class="m-0 text-[17px] leading-[1.55] text-ink-muted">
                    Every image you've generated, with its status, estimated
                    price and what you were actually charged. Open one to see
                    its prompt.
                </p>
            </div>
            <form
                role="search"
                class="flex h-[52px] flex-[0_1_420px] items-center gap-2.5 rounded-[14px] border border-ink-border bg-ink-card px-3.5"
                @submit.prevent
            >
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#A1A1AA"
                    stroke-width="2"
                    stroke-linecap="round"
                    aria-hidden="true"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="M20 20l-3.5-3.5" />
                </svg>
                <label for="q" class="sr-only">Search your prompts</label>
                <input
                    id="q"
                    v-model="search"
                    type="search"
                    placeholder="Search your prompts"
                    class="h-full min-w-0 flex-1 border-0 bg-transparent font-inter text-[15px] text-ink-text outline-none placeholder:text-ink-dim"
                />
            </form>
        </div>

        <section
            v-if="latest"
            aria-label="Latest generation"
            class="flex flex-wrap overflow-hidden rounded-[22px] border border-ink-line bg-ink-card"
        >
            <div class="relative min-h-[320px] flex-[2_1_520px] bg-ink-deep">
                <img
                    v-if="latestHasImage && latest.image"
                    :src="latest.image.url"
                    :alt="latest.prompt"
                    class="absolute inset-0 size-full object-cover"
                    @error="latestImageFailed = true"
                />
                <div
                    v-else
                    class="absolute inset-0 grid place-items-center p-6 text-center"
                >
                    <GenerationStatusBadge
                        :generation="latest"
                        :elapsed-seconds="latestElapsed"
                    />
                </div>
            </div>
            <div
                class="box-border flex flex-[1_1_360px] flex-col justify-between gap-6 p-8"
            >
                <div class="flex flex-col gap-3">
                    <span
                        class="text-[12px] tracking-[0.08em] text-lime uppercase"
                        >Latest generation</span
                    >
                    <h2
                        class="m-0 font-display text-[34px] leading-[1.05] font-semibold tracking-[-0.03em]"
                    >
                        {{ titleOf(latest.prompt, 40) }}
                    </h2>
                    <p
                        class="m-0 line-clamp-3 text-[15px] leading-[1.6] text-ink-muted"
                    >
                        {{ latest.prompt }}
                    </p>
                    <p class="m-0 text-[14px] text-ink-soft tabular-nums">
                        {{ qualityName(latest.quality) }} quality ·
                        {{ ratioOf(latest.size) }} ·
                        <span :class="costLine(latest).tone">{{
                            costLine(latest).value
                        }}</span>
                    </p>
                </div>
                <div
                    v-if="latestThumbs.length > 0"
                    class="grid grid-cols-3 gap-2"
                >
                    <Link
                        v-for="thumb in latestThumbs"
                        :key="thumb.id"
                        :href="show(thumb.id)"
                    >
                        <img
                            :src="thumb.image?.url"
                            :alt="thumb.prompt"
                            loading="lazy"
                            class="aspect-square w-full rounded-[10px] object-cover"
                        />
                    </Link>
                </div>
                <Link
                    :href="show(latest.id)"
                    class="inline-flex items-center gap-2 self-start rounded-[12px] bg-ink-text px-[18px] py-[13px] font-semibold text-ink no-underline"
                    >View details <ArrowRightIcon
                /></Link>
            </div>
        </section>

        <div
            v-if="generations.data.length > 0"
            class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-line pb-1"
        >
            <div
                role="group"
                :aria-label="`Filter the ${generations.data.length} generations on this page by status`"
                class="flex flex-wrap gap-1"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.label"
                    type="button"
                    :aria-pressed="tab.on"
                    class="min-h-11 cursor-pointer border-0 border-b-2 bg-transparent px-3.5 font-inter text-[15px] font-semibold"
                    :class="
                        tab.on
                            ? 'border-lime text-ink-text'
                            : 'border-transparent text-ink-muted'
                    "
                    @click="statusFilter = tab.label"
                >
                    {{ tab.label }}
                    <span class="text-[13px] font-medium text-ink-muted">{{
                        tab.count
                    }}</span>
                </button>
            </div>
            <div
                class="flex flex-wrap gap-2"
                role="group"
                aria-label="Filter by quality"
            >
                <button
                    v-for="chip in chips"
                    :key="chip.value"
                    type="button"
                    :aria-pressed="chip.on"
                    class="min-h-10 cursor-pointer rounded-full px-3.5 font-inter text-[14px] font-medium"
                    :class="
                        chip.on
                            ? 'border border-ink-text bg-ink-text text-ink'
                            : 'border border-ink-border bg-ink-card text-ink-subtle'
                    "
                    @click="qualityFilter = chip.value"
                >
                    {{ chip.label }}
                </button>
            </div>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-4 rounded-[22px] border border-dashed border-ink-border p-12 text-center"
        >
            <p class="m-0 font-display text-[24px] font-semibold">
                No generations yet
            </p>
            <p class="m-0 max-w-sm text-[15px] text-ink-muted">
                Describe an image, see its price range up front, and you only
                pay for generations that succeed.
            </p>
            <Link
                :href="create()"
                class="rounded-[12px] bg-lime px-5 py-3.5 font-semibold text-ink no-underline"
                >Generate your first image</Link
            >
        </div>

        <div v-else class="columns-[4_280px] gap-4">
            <LibraryTile
                v-for="generation in items"
                :key="generation.id"
                :generation="generation"
                :now="now"
            />
        </div>

        <p
            v-if="generations.data.length > 0 && items.length === 0"
            class="m-0 p-12 text-center text-ink-muted"
        >
            No match among the {{ generations.data.length }} generations on this
            page.
            <template v-if="generations.next_page_url"
                >Older ones are under “Load older”.</template
            >
        </p>

        <nav
            v-if="generations.prev_page_url || generations.next_page_url"
            aria-label="Pagination"
            class="flex flex-wrap justify-center gap-3"
        >
            <Link
                v-if="generations.prev_page_url"
                :href="index()"
                class="inline-flex min-h-12 items-center rounded-[12px] border border-ink-border bg-ink-card px-6 font-semibold text-ink-text no-underline"
                >Back to newest</Link
            >
            <Link
                v-if="generations.next_page_url"
                :href="generations.next_page_url"
                class="inline-flex min-h-12 items-center rounded-[12px] border border-ink-border bg-ink-card px-6 font-semibold text-ink-text no-underline"
                >Load older</Link
            >
        </nav>
    </main>
</template>
