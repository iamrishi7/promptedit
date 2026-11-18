<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import ArrowRightIcon from '@/components/atelier/ArrowRightIcon.vue';
import { IMG } from '@/lib/atelier';
import { explore } from '@/routes';
import { create } from '@/routes/generations';

defineOptions({
    layout: { variant: 'site', current: 'models', footer: true },
});

// Pixelforge has one image model; what you choose is quality and size. Both change the price.
const QUALITIES = [
    {
        value: 'low',
        name: 'Low',
        kind: 'Quality',
        a: IMG.bloom,
        b: IMG.orchid,
        c: IMG.silk,
        desc: 'The fastest and cheapest option. Good for drafts and trying ideas.',
        tags: ['Drafts', 'Ideas', 'Fastest'],
    },
    {
        value: 'medium',
        name: 'Medium',
        kind: 'Quality',
        a: IMG.rain,
        b: IMG.harbor,
        c: IMG.ringed,
        desc: 'Balanced detail and price. A good default for most prompts.',
        tags: ['Default', 'Scenes', 'Balanced'],
    },
    {
        value: 'high',
        name: 'High',
        kind: 'Quality',
        a: IMG.glacier,
        b: IMG.forest,
        c: IMG.mesa,
        desc: 'The most detail Pixelforge offers. Uses the most output tokens, so it costs the most.',
        tags: ['Final art', 'Landscapes', 'Detail'],
    },
];

const SIZES = [
    { value: '1024x1024', name: 'Square', ratio: '1:1', src: IMG.moons },
    { value: '1536x1024', name: 'Landscape', ratio: '3:2', src: IMG.hero },
    { value: '1024x1536', name: 'Portrait', ratio: '2:3', src: IMG.rain },
];

type Tab = 'Quality' | 'Sizes';
const tab = ref<Tab>('Quality');
</script>

<template>
    <Head title="Quality & sizes" />

    <main
        class="mx-auto box-border flex w-full max-w-[1440px] flex-col gap-9 px-[clamp(16px,4vw,48px)] pt-10 pb-24"
    >
        <div class="flex max-w-[720px] flex-col gap-2.5">
            <h1
                class="m-0 font-display text-[clamp(36px,4.4vw,60px)] leading-none font-semibold tracking-[-0.035em]"
            >
                Models &amp; styles
            </h1>
            <p class="m-0 text-[17px] leading-[1.55] text-ink-muted">
                Every image is made by Pixelforge. Pick a quality and a size,
                then describe the style in your prompt. The studio shows the
                price range for your exact choice before you generate.
            </p>
        </div>

        <section
            aria-labelledby="feat-h"
            class="relative flex min-h-[440px] items-end overflow-hidden rounded-[24px]"
        >
            <img
                :src="IMG.rain"
                alt="Neon skyscrapers in heavy rain"
                class="absolute inset-0 size-full object-cover object-[50%_40%]"
            />
            <div
                aria-hidden="true"
                class="absolute inset-0 bg-[linear-gradient(90deg,rgba(10,10,12,0.92)_0%,rgba(10,10,12,0.55)_45%,rgba(10,10,12,0)_75%)]"
            />
            <div
                class="relative box-border flex w-full flex-wrap items-end justify-between gap-6 p-[clamp(24px,4vw,48px)]"
            >
                <div
                    class="flex max-w-[520px] flex-[1_1_420px] flex-col gap-3.5"
                >
                    <span
                        class="self-start rounded-full bg-lime px-2.5 py-[5px] text-[12px] font-semibold tracking-[0.04em] text-ink uppercase"
                        >Most detail</span
                    >
                    <h2
                        id="feat-h"
                        class="m-0 font-display text-[clamp(34px,4vw,54px)] leading-none font-semibold tracking-[-0.035em]"
                    >
                        High quality
                    </h2>
                    <p class="m-0 text-[17px] leading-[1.55] text-ink-body">
                        Fine texture, believable light and crisp edges, for
                        images you want to keep. It costs the most, and you see
                        the range before you generate.
                    </p>
                    <div class="flex flex-wrap gap-2.5">
                        <Link
                            :href="create({ query: { quality: 'high' } })"
                            class="rounded-[12px] bg-lime px-5 py-3.5 font-semibold text-ink no-underline"
                            >Try High quality</Link
                        >
                        <Link
                            :href="explore()"
                            class="rounded-[12px] border border-white/25 bg-ink/60 px-5 py-3.5 font-semibold text-ink-text no-underline"
                            >See examples</Link
                        >
                    </div>
                </div>
                <div class="flex gap-2">
                    <img
                        :src="IMG.harbor"
                        alt="Amber city lights"
                        class="h-[140px] w-28 rounded-[12px] border-2 border-white/20 object-cover"
                    />
                    <img
                        :src="IMG.ringed"
                        alt="Ringed planet"
                        class="h-[140px] w-28 rounded-[12px] border-2 border-white/20 object-cover"
                    />
                    <img
                        :src="IMG.hero"
                        alt="Mountains at dusk"
                        class="h-[140px] w-28 rounded-[12px] border-2 border-white/20 object-cover"
                    />
                </div>
            </div>
        </section>

        <div
            role="tablist"
            aria-label="Catalogue"
            class="flex gap-1 border-b border-ink-line"
        >
            <button
                v-for="label in ['Quality', 'Sizes'] as Tab[]"
                :key="label"
                type="button"
                role="tab"
                :aria-selected="tab === label"
                class="min-h-12 cursor-pointer border-0 border-b-2 bg-transparent px-4 font-inter text-[16px] font-semibold"
                :class="
                    tab === label
                        ? 'border-lime text-ink-text'
                        : 'border-transparent text-ink-muted'
                "
                @click="tab = label"
            >
                {{ label }}
            </button>
        </div>

        <div
            v-if="tab === 'Quality'"
            class="grid grid-cols-[repeat(auto-fill,minmax(320px,1fr))] gap-[18px]"
        >
            <article
                v-for="quality in QUALITIES"
                :key="quality.value"
                class="flex flex-col overflow-hidden rounded-[20px] border border-ink-line bg-ink-card"
            >
                <div
                    class="grid h-60 grid-cols-[2fr_1fr] grid-rows-2 gap-[3px]"
                >
                    <img
                        :src="quality.a"
                        alt=""
                        loading="lazy"
                        class="row-span-2 size-full object-cover"
                    />
                    <img
                        :src="quality.b"
                        alt=""
                        loading="lazy"
                        class="size-full object-cover"
                    />
                    <img
                        :src="quality.c"
                        alt=""
                        loading="lazy"
                        class="size-full object-cover"
                    />
                </div>
                <div class="flex flex-1 flex-col gap-3 p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h3
                            class="m-0 font-display text-[22px] font-semibold tracking-[-0.02em]"
                        >
                            {{ quality.name }}
                        </h3>
                        <span class="text-[12px] text-ink-muted">{{
                            quality.kind
                        }}</span>
                    </div>
                    <p class="m-0 text-[14px] leading-[1.6] text-ink-muted">
                        {{ quality.desc }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="tag in quality.tags"
                            :key="tag"
                            class="rounded-full bg-ink-raised px-2.5 py-[5px] text-[12px] text-ink-subtle"
                            >{{ tag }}</span
                        >
                    </div>
                    <Link
                        :href="create({ query: { quality: quality.value } })"
                        class="mt-auto inline-flex min-h-[46px] items-center justify-center gap-2 rounded-[12px] border border-ink-border bg-ink-raised text-[15px] font-semibold text-ink-text no-underline"
                        >Use in Studio <ArrowRightIcon
                    /></Link>
                </div>
            </article>
        </div>

        <div
            v-else
            class="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-3.5"
        >
            <Link
                v-for="size in SIZES"
                :key="size.value"
                :href="create({ query: { size: size.value } })"
                class="relative block aspect-[4/5] overflow-hidden rounded-[16px] text-ink-text no-underline"
            >
                <img
                    :src="size.src"
                    alt=""
                    loading="lazy"
                    class="absolute inset-0 size-full object-cover"
                />
                <span
                    class="absolute inset-x-0 bottom-0 flex flex-col bg-linear-to-b from-ink/0 to-ink/85 px-3.5 pt-9 pb-3.5"
                >
                    <span class="text-[16px] font-semibold">{{
                        size.name
                    }}</span>
                    <span class="text-[13px] text-ink-soft"
                        >{{ size.ratio }} ·
                        {{ size.value.replace('x', ' × ') }}</span
                    >
                </span>
            </Link>
        </div>
    </main>
</template>
