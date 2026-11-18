<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ArrowRightIcon from '@/components/atelier/ArrowRightIcon.vue';
import { useAppName } from '@/composables/useAppName';
import { IMG, SHOWCASE, showcaseByTitle } from '@/lib/atelier';
import type { ShowcaseItem } from '@/lib/atelier';
import { create } from '@/routes/generations';

defineOptions({
    layout: { variant: 'site', current: 'explore', footer: true },
});

// The design's gallery: twelve showcase images plus four second takes.
const ALL: ShowcaseItem[] = [
    showcaseByTitle('Rain district, 2 a.m.'),
    showcaseByTitle('Liquid brass study'),
    showcaseByTitle('Glacier ridge, blue hour'),
    showcaseByTitle('Night orchid'),
    showcaseByTitle('Twin moons'),
    showcaseByTitle('Red mesa'),
    showcaseByTitle('Molten silk'),
    showcaseByTitle('Harbor lights'),
    showcaseByTitle('Bloom no. 7'),
    showcaseByTitle('Cloud forest'),
    showcaseByTitle('Ringed giant'),
    showcaseByTitle('Marble current'),
    {
        src: IMG.hero,
        title: 'Ember valley at dusk',
        style: 'Photoreal',
        alt: 'Layered mountain ridges at dusk',
        prompt: 'Layered mountain ridges at dusk, a low amber sun glowing through violet haze, soft atmospheric perspective, wide landscape photograph',
    },
    {
        ...showcaseByTitle('Liquid brass study'),
        title: 'Brass tide II',
        alt: 'Teal and gold fluid, second variation',
    },
    {
        ...showcaseByTitle('Night orchid'),
        title: 'Night orchid, alt',
        alt: 'Violet petals variation',
    },
    {
        ...showcaseByTitle('Harbor lights'),
        title: 'Harbor at closing time',
        alt: 'Warm city lights at night',
    },
];

type Sort = 'Trending' | 'New' | 'Top';
const sort = ref<Sort>('Trending');
const style = ref('All');
const search = ref('');

const STYLES = [
    'All',
    'Photoreal',
    'Cinematic',
    'Illustration',
    '3D Render',
    'Abstract',
];

const items = computed(() => {
    const term = search.value.trim().toLocaleLowerCase();
    let list = ALL.filter(
        (item) =>
            (style.value === 'All' || item.style === style.value) &&
            (term === '' ||
                `${item.title} ${item.prompt} ${item.style}`
                    .toLocaleLowerCase()
                    .includes(term)),
    );

    if (sort.value === 'New') {
        list = list.slice().reverse();
    }

    if (sort.value === 'Top') {
        list = list
            .filter((_, i) => i % 2 === 0)
            .concat(list.filter((_, i) => i % 2 === 1));
    }

    return list;
});

const promptHref = (item: ShowcaseItem) =>
    create({ query: { prompt: item.prompt } });

const appName = useAppName();

const otherWorlds = SHOWCASE.find((item) => item.title === 'Ringed giant');
</script>

<template>
    <Head title="Explore" />

    <main
        class="mx-auto box-border flex w-full max-w-[1440px] flex-col gap-8 px-[clamp(16px,4vw,48px)] pt-10 pb-20"
    >
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex max-w-[640px] flex-col gap-2">
                <h1
                    class="m-0 font-display text-[clamp(36px,4.4vw,60px)] leading-none font-semibold tracking-[-0.035em]"
                >
                    Explore
                </h1>
                <p class="m-0 text-[17px] leading-[1.55] text-ink-muted">
                    Ideas to start from, hand-picked for {{ appName }}. Open any
                    one to generate it with its prompt.
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
                <label for="q" class="sr-only">Search the gallery</label>
                <input
                    id="q"
                    v-model="search"
                    type="search"
                    placeholder="Search prompts, styles, subjects"
                    class="h-full min-w-0 flex-1 border-0 bg-transparent font-inter text-[15px] text-ink-text outline-none placeholder:text-ink-dim"
                />
            </form>
        </div>

        <section
            aria-label="Featured collection"
            class="flex flex-wrap overflow-hidden rounded-[22px] border border-ink-line bg-ink-card"
        >
            <div class="relative min-h-[320px] flex-[2_1_520px]">
                <img
                    :src="IMG.ringed"
                    alt="A banded gas giant with glowing rings in deep space"
                    class="absolute inset-0 size-full object-cover object-[50%_55%]"
                />
            </div>
            <div
                class="box-border flex flex-[1_1_360px] flex-col justify-between gap-6 p-8"
            >
                <div class="flex flex-col gap-3">
                    <span
                        class="text-[12px] tracking-[0.08em] text-lime uppercase"
                        >Featured collection</span
                    >
                    <h2
                        class="m-0 font-display text-[34px] leading-[1.05] font-semibold tracking-[-0.03em]"
                    >
                        Other worlds
                    </h2>
                    <p class="m-0 text-[15px] leading-[1.6] text-ink-muted">
                        Planets, moons and skies that don't exist. Cinematic and
                        3D render prompts to start from.
                    </p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <img
                        :src="IMG.moons"
                        alt="Teal planet with two moons"
                        class="aspect-square w-full rounded-[10px] object-cover"
                    />
                    <img
                        :src="IMG.hero"
                        alt="Mountains at dusk"
                        class="aspect-square w-full rounded-[10px] object-cover"
                    />
                    <img
                        :src="IMG.glacier"
                        alt="Blue ridges at blue hour"
                        class="aspect-square w-full rounded-[10px] object-cover"
                    />
                </div>
                <Link
                    v-if="otherWorlds"
                    :href="promptHref(otherWorlds)"
                    class="inline-flex items-center gap-2 self-start rounded-[12px] bg-ink-text px-[18px] py-[13px] font-semibold text-ink no-underline"
                    >Try this collection <ArrowRightIcon
                /></Link>
            </div>
        </section>

        <div
            class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-line pb-1"
        >
            <div role="tablist" aria-label="Sort" class="flex gap-1">
                <button
                    v-for="label in ['Trending', 'New', 'Top'] as Sort[]"
                    :key="label"
                    type="button"
                    role="tab"
                    :aria-selected="sort === label"
                    class="min-h-11 cursor-pointer border-0 border-b-2 bg-transparent px-3.5 font-inter text-[15px] font-semibold"
                    :class="
                        sort === label
                            ? 'border-lime text-ink-text'
                            : 'border-transparent text-ink-muted'
                    "
                    @click="sort = label"
                >
                    {{ label }}
                </button>
            </div>
            <div
                class="flex flex-wrap gap-2"
                role="group"
                aria-label="Filter by style"
            >
                <button
                    v-for="label in STYLES"
                    :key="label"
                    type="button"
                    :aria-pressed="style === label"
                    class="min-h-10 cursor-pointer rounded-full px-3.5 font-inter text-[14px] font-medium"
                    :class="
                        style === label
                            ? 'border border-ink-text bg-ink-text text-ink'
                            : 'border border-ink-border bg-ink-card text-ink-subtle'
                    "
                    @click="style = label"
                >
                    {{ label }}
                </button>
            </div>
        </div>

        <div class="columns-[4_280px] gap-4">
            <div
                v-for="item in items"
                :key="item.title"
                class="relative mb-4 break-inside-avoid overflow-hidden rounded-[14px] bg-ink-card"
            >
                <Link
                    :href="promptHref(item)"
                    class="block text-ink-text no-underline"
                >
                    <img
                        :src="item.src"
                        :alt="item.alt"
                        loading="lazy"
                        class="block h-auto w-full"
                    />
                    <div
                        class="absolute inset-x-0 bottom-0 flex flex-col gap-0.5 bg-linear-to-b from-ink/0 to-ink/85 px-3.5 pt-10 pb-3.5"
                    >
                        <span class="text-[15px] font-semibold">{{
                            item.title
                        }}</span>
                        <span class="text-[12px] text-ink-soft">{{
                            item.style
                        }}</span>
                    </div>
                </Link>
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
        </div>

        <p
            v-if="items.length === 0"
            class="m-0 p-12 text-center text-ink-muted"
        >
            No images in this style yet.
        </p>
    </main>
</template>
