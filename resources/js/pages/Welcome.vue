<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ArrowRightIcon from '@/components/atelier/ArrowRightIcon.vue';
import SparkleIcon from '@/components/atelier/SparkleIcon.vue';
import { IMG, SHOWCASE, showcaseByTitle } from '@/lib/atelier';
import { explore, models } from '@/routes';
import { create } from '@/routes/generations';

defineOptions({
    layout: { variant: 'site', current: null, footer: true },
});

const heroPrompt = ref('');

// The hero prompt and the "Try" chips open the studio with the prompt filled in (sign-in first if needed).
function startWith(prompt: string): void {
    router.visit(create({ query: { prompt } }));
}

const tryPrompts = [
    {
        label: 'Neon city in the rain',
        prompt: showcaseByTitle('Rain district, 2 a.m.').prompt,
    },
    {
        label: 'Ringed planet over the sea',
        prompt: showcaseByTitle('Ringed giant').prompt,
    },
    {
        label: 'Paper flower, macro',
        prompt: showcaseByTitle('Bloom no. 7').prompt,
    },
];

const featured = {
    title: 'Ember valley at dusk',
    prompt: 'Layered mountain ridges at dusk, a low amber sun glowing through violet haze, soft atmospheric perspective, quiet and vast, wide landscape photograph, fine film grain',
};

const picks = [
    showcaseByTitle('Ringed giant'),
    showcaseByTitle('Night orchid'),
    showcaseByTitle('Cloud forest'),
    showcaseByTitle('Molten silk'),
];

type Tab = 'Trending' | 'New' | 'Top this month';
const tab = ref<Tab>('Trending');
const ORDER: Record<Tab, number[]> = {
    Trending: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
    New: [5, 8, 11, 0, 2, 9, 4, 7, 1, 10, 3, 6],
    'Top this month': [10, 0, 6, 4, 8, 1, 3, 9, 7, 2, 11, 5],
};
const gallery = computed(() => ORDER[tab.value].map((i) => SHOWCASE[i]));

const rainPrompt = showcaseByTitle('Rain district, 2 a.m.').prompt;

const styles = [
    {
        src: IMG.glacier,
        name: 'Photoreal',
        desc: 'Natural light, real lenses, true-to-life texture.',
        hint: 'landscape photograph, natural light',
    },
    {
        src: IMG.rain,
        name: 'Cinematic',
        desc: 'Film-still framing and dramatic colour grading.',
        hint: 'cinematic wide shot, dramatic colour grading',
    },
    {
        src: IMG.bloom,
        name: 'Illustration',
        desc: 'Clean shapes, painterly and editorial looks.',
        hint: 'editorial illustration, painterly',
    },
    {
        src: IMG.moons,
        name: '3D Render',
        desc: 'Soft studio lighting and solid materials.',
        hint: '3D render, soft studio lighting',
    },
    {
        src: IMG.marble,
        name: 'Abstract',
        desc: 'Textures, patterns and generative forms.',
        hint: 'abstract texture, flowing forms',
    },
    {
        src: IMG.harbor,
        name: 'Concept Art',
        desc: 'Environments and worlds for games and film.',
        hint: 'concept art, matte painting',
    },
];

const h2 =
    'm-0 font-display text-[clamp(30px,3.4vw,46px)] font-semibold tracking-[-0.03em]';
</script>

<template>
    <Head title="Turn a sentence into a finished image" />

    <section
        aria-label="Featured generation"
        class="relative flex min-h-[760px] items-end overflow-hidden"
    >
        <img
            :src="IMG.hero"
            alt="Layered mountain ridges at dusk under an amber sun"
            class="absolute inset-0 size-full object-cover"
        />
        <div
            aria-hidden="true"
            class="absolute inset-0 bg-[linear-gradient(180deg,rgba(10,10,12,0)_30%,rgba(10,10,12,0.72)_72%,#0A0A0C_100%)]"
        />
        <div
            class="relative mx-auto box-border flex w-full max-w-[1440px] flex-wrap items-end justify-between gap-8 px-[clamp(16px,4vw,48px)] pb-14"
        >
            <div class="flex max-w-[760px] flex-[1_1_560px] flex-col gap-5">
                <span
                    class="self-start rounded-full border border-white/18 bg-ink/60 px-3 py-1.5 text-[13px] font-medium tracking-[0.04em] uppercase"
                    >AI image generation</span
                >
                <h1
                    class="m-0 font-display text-[clamp(40px,6vw,84px)] leading-[0.98] font-semibold tracking-[-0.035em]"
                >
                    Turn a sentence into a finished image.
                </h1>
                <p
                    class="m-0 max-w-[600px] text-[19px] leading-[1.55] text-ink-subtle"
                >
                    Photoreal, illustrated and 3D images from a single prompt.
                    See the price before you generate, and pay only for images
                    that succeed.
                </p>
                <form
                    class="flex max-w-[680px] flex-wrap gap-2 rounded-[16px] border border-ink-border bg-ink-card/88 p-2"
                    @submit.prevent="startWith(heroPrompt)"
                >
                    <label for="hero-prompt" class="sr-only"
                        >Describe the image you want</label
                    >
                    <input
                        id="hero-prompt"
                        v-model="heroPrompt"
                        type="text"
                        placeholder="A lighthouse on a cliff during a thunderstorm, cinematic"
                        class="h-12 min-w-0 flex-[1_1_280px] rounded-[10px] border-0 bg-transparent px-3.5 font-inter text-[16px] text-ink-text outline-none placeholder:text-ink-dim"
                    />
                    <button
                        type="submit"
                        class="inline-flex h-12 cursor-pointer items-center gap-2 rounded-[10px] border-0 bg-lime px-[22px] font-inter text-[16px] font-semibold text-ink"
                    >
                        <SparkleIcon class="size-[18px]" />
                        Generate
                    </button>
                </form>
                <div
                    class="flex flex-wrap items-center gap-2 text-[14px] text-ink-muted"
                >
                    <span>Try:</span>
                    <Link
                        v-for="chip in tryPrompts"
                        :key="chip.label"
                        :href="create({ query: { prompt: chip.prompt } })"
                        class="rounded-full border border-ink-border bg-ink-card/70 px-3 py-2 text-ink-body no-underline"
                        >{{ chip.label }}</Link
                    >
                </div>
            </div>
            <Link
                :href="
                    create({
                        query: {
                            prompt: featured.prompt,
                            size: '1536x1024',
                            quality: 'high',
                        },
                    })
                "
                class="flex flex-[0_1_340px] items-center gap-3.5 rounded-[16px] border border-ink-border bg-ink-card/85 p-3 text-ink-text no-underline"
            >
                <img
                    :src="IMG.hero"
                    alt=""
                    class="size-[72px] rounded-[10px] object-cover"
                />
                <div class="flex min-w-0 flex-col gap-1">
                    <span
                        class="text-[12px] tracking-[0.06em] text-lime uppercase"
                        >Featured generation</span
                    >
                    <span class="text-[16px] font-semibold">{{
                        featured.title
                    }}</span>
                    <span class="text-[13px] text-ink-muted"
                        >High · 3:2 · Use this prompt →</span
                    >
                </div>
            </Link>
        </div>
    </section>

    <main
        class="mx-auto box-border w-full max-w-[1440px] px-[clamp(16px,4vw,48px)]"
    >
        <section
            aria-labelledby="picks-h"
            class="flex flex-col gap-7 pt-[88px] pb-6"
        >
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <span
                        class="text-[13px] tracking-[0.08em] text-ink-muted uppercase"
                        >Curated by our team</span
                    >
                    <h2 id="picks-h" :class="h2">Editor's picks this week</h2>
                </div>
                <Link
                    :href="explore()"
                    class="inline-flex items-center gap-2 px-1 py-3 font-medium text-ink-text no-underline"
                    >See all picks <ArrowRightIcon
                /></Link>
            </div>
            <div
                class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-4"
            >
                <Link
                    v-for="pick in picks"
                    :key="pick.title"
                    :href="create({ query: { prompt: pick.prompt } })"
                    class="relative block aspect-[3/4] overflow-hidden rounded-[18px] bg-ink-card text-ink-text no-underline"
                >
                    <img
                        :src="pick.src"
                        :alt="pick.alt"
                        loading="lazy"
                        class="absolute inset-0 size-full object-cover"
                    />
                    <div
                        class="absolute inset-x-0 bottom-0 flex flex-col gap-1 bg-linear-to-b from-ink/0 to-ink/88 px-[18px] pt-12 pb-[18px]"
                    >
                        <span
                            class="font-display text-[20px] font-semibold tracking-[-0.01em]"
                            >{{ pick.title }}</span
                        >
                        <span class="text-[13px] text-ink-soft">{{
                            pick.style
                        }}</span>
                    </div>
                </Link>
            </div>
        </section>

        <section
            aria-labelledby="gal-h"
            class="flex flex-col gap-7 pt-[72px] pb-6"
        >
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 id="gal-h" :class="h2">From the gallery</h2>
                <div
                    role="tablist"
                    aria-label="Gallery view"
                    class="flex gap-1 rounded-[12px] border border-ink-line bg-ink-card p-1"
                >
                    <button
                        v-for="label in Object.keys(
                            ORDER,
                        ) as (keyof typeof ORDER)[]"
                        :key="label"
                        type="button"
                        role="tab"
                        :aria-selected="tab === label"
                        class="min-h-10 cursor-pointer rounded-[9px] border-0 px-3.5 font-inter text-[14px] font-medium"
                        :class="
                            tab === label
                                ? 'bg-ink-text text-ink'
                                : 'bg-transparent text-ink-soft'
                        "
                        @click="tab = label"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>
            <div class="columns-[4_280px] gap-4">
                <Link
                    v-for="item in gallery"
                    :key="item.title"
                    :href="create({ query: { prompt: item.prompt } })"
                    class="relative mb-4 block break-inside-avoid overflow-hidden rounded-[14px] bg-ink-card text-ink-text no-underline"
                >
                    <img
                        :src="item.src"
                        :alt="item.alt"
                        loading="lazy"
                        class="block h-auto w-full"
                    />
                    <div
                        class="absolute right-2.5 bottom-2.5 left-2.5 flex items-center justify-between gap-2"
                    >
                        <span
                            class="rounded-full bg-ink/72 px-2.5 py-[5px] text-[12px] font-medium"
                            >{{ item.style }}</span
                        >
                    </div>
                </Link>
            </div>
            <div class="flex justify-center pt-2">
                <Link
                    :href="explore()"
                    class="inline-flex items-center gap-2.5 rounded-[12px] border border-ink-border bg-ink-card px-[22px] py-3.5 font-semibold text-ink-text no-underline"
                    >Open the full gallery <ArrowRightIcon
                /></Link>
            </div>
        </section>

        <section aria-labelledby="prompt-h" class="pt-24 pb-6">
            <div
                class="flex flex-wrap overflow-hidden rounded-[24px] border border-ink-line bg-ink-card"
            >
                <div class="relative min-h-[420px] flex-[1_1_560px]">
                    <img
                        :src="IMG.rain"
                        alt="Neon-lit skyscrapers in heavy rain at night"
                        loading="lazy"
                        class="absolute inset-0 size-full object-cover"
                    />
                </div>
                <div
                    class="box-border flex flex-[1_1_420px] flex-col gap-[22px] p-[clamp(28px,4vw,56px)]"
                >
                    <span
                        class="text-[13px] tracking-[0.08em] text-lime uppercase"
                        >Prompt to picture</span
                    >
                    <h2
                        id="prompt-h"
                        class="m-0 font-display text-[clamp(28px,3vw,40px)] leading-[1.1] font-semibold tracking-[-0.03em]"
                    >
                        Every image comes with its recipe.
                    </h2>
                    <p class="m-0 text-[16px] leading-[1.6] text-ink-muted">
                        Open any image in your library to see the exact prompt,
                        settings and what it cost, then remix it with your own
                        idea.
                    </p>
                    <figure
                        class="m-0 flex flex-col gap-3.5 rounded-[14px] border border-ink-border bg-ink p-[18px]"
                    >
                        <figcaption
                            class="text-[12px] tracking-[0.06em] text-ink-muted uppercase"
                        >
                            Prompt
                        </figcaption>
                        <p class="m-0 font-display text-[18px] leading-normal">
                            {{ rainPrompt }}
                        </p>
                        <div class="flex flex-wrap gap-2 text-[13px]">
                            <span
                                class="rounded-[8px] bg-ink-raised px-2.5 py-1.5 text-ink-subtle"
                                >Quality · High</span
                            >
                            <span
                                class="rounded-[8px] bg-ink-raised px-2.5 py-1.5 text-ink-subtle"
                                >Aspect · 2:3</span
                            >
                            <span
                                class="rounded-[8px] bg-ink-raised px-2.5 py-1.5 text-ink-subtle"
                                >Size · 1024 × 1536</span
                            >
                        </div>
                    </figure>
                    <div class="flex flex-wrap gap-2.5">
                        <Link
                            :href="
                                create({
                                    query: {
                                        prompt: rainPrompt,
                                        size: '1024x1536',
                                        quality: 'high',
                                    },
                                })
                            "
                            class="inline-flex items-center gap-2 rounded-[12px] bg-lime px-5 py-3.5 font-semibold text-ink no-underline"
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
                            Remix this prompt</Link
                        >
                        <Link
                            :href="explore()"
                            class="inline-flex items-center rounded-[12px] border border-ink-border px-5 py-3.5 font-semibold text-ink-text no-underline"
                            >View gallery</Link
                        >
                    </div>
                </div>
            </div>
        </section>

        <section
            aria-labelledby="models-h"
            class="flex flex-col gap-7 pt-24 pb-6"
        >
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex max-w-[640px] flex-col gap-2">
                    <h2 id="models-h" :class="h2">One prompt, many styles</h2>
                    <p class="m-0 text-[17px] leading-[1.55] text-ink-muted">
                        Start from a style that suits the look you're after.
                    </p>
                </div>
                <Link
                    :href="models()"
                    class="inline-flex items-center gap-2 px-1 py-3 font-medium text-ink-text no-underline"
                    >Quality &amp; sizes <ArrowRightIcon
                /></Link>
            </div>
            <div
                class="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-4"
            >
                <Link
                    v-for="style in styles"
                    :key="style.name"
                    :href="
                        create({
                            query: {
                                prompt: `Your subject here, ${style.hint}`,
                            },
                        })
                    "
                    class="flex flex-col gap-3 rounded-[16px] border border-ink-line bg-ink-card px-2.5 pt-2.5 pb-4 text-ink-text no-underline"
                >
                    <img
                        :src="style.src"
                        alt=""
                        loading="lazy"
                        class="aspect-square w-full rounded-[10px] object-cover"
                    />
                    <div class="flex flex-col gap-1 px-1.5">
                        <span class="text-[16px] font-semibold">{{
                            style.name
                        }}</span>
                        <span
                            class="text-[13px] leading-[1.45] text-ink-muted"
                            >{{ style.desc }}</span
                        >
                    </div>
                </Link>
            </div>
        </section>

        <section aria-labelledby="how-h" class="flex flex-col gap-7 pt-24 pb-6">
            <h2 id="how-h" :class="h2">From idea to image in three steps</h2>
            <ol
                class="m-0 grid list-none grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-4 p-0"
            >
                <li
                    v-for="step in [
                        {
                            n: '01',
                            title: 'Describe it',
                            text: 'Write what you want to see. Link up to four reference images if words aren\'t enough.',
                        },
                        {
                            n: '02',
                            title: 'Check the price',
                            text: 'Choose quality and aspect ratio. The price range updates as you type, before you spend anything.',
                        },
                        {
                            n: '03',
                            title: 'Generate and download',
                            text: 'Follow it live, then download it. You pay the exact cost only if it succeeds.',
                        },
                    ]"
                    :key="step.n"
                    class="flex flex-col gap-3 rounded-[18px] border border-ink-line bg-ink-card p-7"
                >
                    <span class="font-display text-[15px] text-lime">{{
                        step.n
                    }}</span>
                    <span class="font-display text-[22px] font-semibold">{{
                        step.title
                    }}</span>
                    <span class="text-[15px] leading-[1.6] text-ink-muted">{{
                        step.text
                    }}</span>
                </li>
            </ol>
        </section>

        <section id="pricing" aria-labelledby="cta-h" class="py-24">
            <div
                class="relative flex min-h-[380px] items-center overflow-hidden rounded-[28px]"
            >
                <img
                    :src="IMG.marble"
                    alt=""
                    loading="lazy"
                    class="absolute inset-0 size-full object-cover"
                />
                <div aria-hidden="true" class="absolute inset-0 bg-ink/62" />
                <div
                    class="relative flex max-w-[680px] flex-col gap-5 p-[clamp(28px,5vw,72px)]"
                >
                    <h2
                        id="cta-h"
                        class="m-0 font-display text-[clamp(32px,4vw,56px)] leading-[1.02] font-semibold tracking-[-0.035em]"
                    >
                        Your next image is one prompt away.
                    </h2>
                    <p class="m-0 text-[18px] leading-[1.55] text-ink-body">
                        No plan to pick. You see each image's price range before
                        you generate, and failed generations are never charged.
                    </p>
                    <div class="flex flex-wrap gap-2.5">
                        <Link
                            :href="create()"
                            class="rounded-[12px] bg-lime px-6 py-[15px] text-[16px] font-semibold text-ink no-underline"
                            >Start creating</Link
                        >
                        <Link
                            :href="explore()"
                            class="rounded-[12px] border border-white/25 bg-ink/60 px-6 py-[15px] text-[16px] font-semibold text-ink-text no-underline"
                            >Browse the gallery</Link
                        >
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
