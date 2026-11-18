<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleCheck,
    CircleAlert,
    LoaderCircle,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { spend } from '@/routes/admin';
import type {
    PixelforgeComparison,
    ProviderDayComparison,
    SpendAmounts,
    SpendDay,
} from '@/types/admin';

defineOptions({
    layout: { variant: 'app', current: 'admin' },
});

const props = defineProps<{
    range: { from: string; to: string; max_days: number };
    report: { days: SpendDay[]; totals: SpendAmounts };
    /** Deferred: Pixelforge's own daily figures, loaded after the page renders. */
    pixelforge?: PixelforgeComparison;
    errors?: Record<string, string>;
}>();

// --- Date range (UTC days). Date arithmetic only; the browser never computes money.

const from = ref(props.range.from);
const to = ref(props.range.to);

const todayUtc = (): string => new Date().toISOString().slice(0, 10);
const daysBefore = (date: string, days: number): string => {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - days);

    return d.toISOString().slice(0, 10);
};

const presets = [
    { label: 'Today', days: 1 },
    { label: '7 days', days: 7 },
    { label: '14 days', days: 14 },
    { label: '30 days', days: 30 },
    { label: '92 days', days: 92 },
];

function show(range: { from: string; to: string }): void {
    router.get(spend({ query: range }).url, {}, { preserveScroll: true });
}

function applyPreset(days: number): void {
    const end = todayUtc();
    from.value = daysBefore(end, days - 1);
    to.value = end;
    show({ from: from.value, to: to.value });
}

const activePreset = computed(() =>
    to.value === todayUtc()
        ? presets.find((p) => daysBefore(to.value, p.days - 1) === from.value)
              ?.days
        : undefined,
);

// --- Pixelforge comparison (deferred prop).

const comparison = computed(() =>
    props.pixelforge?.available ? props.pixelforge : null,
);

const providerDay = (date: string): ProviderDayComparison | null =>
    comparison.value?.days[date] ?? null;

type KnownDay = Extract<ProviderDayComparison, { known: true }>;

/** Pixelforge's figure for a day, when it reported one. */
const knownDay = (date: string): KnownDay | null => {
    const theirs = providerDay(date);

    return theirs !== null && theirs.known ? theirs : null;
};

const mismatchedDays = computed(() =>
    props.report.days.filter((day) => {
        const theirs = providerDay(day.date);

        return theirs !== null && (!theirs.known || !theirs.matches);
    }),
);

// --- Table.

const showEmptyDays = ref(false);
const expanded = reactive(new Set<string>());

const visibleDays = computed(() =>
    props.report.days.filter((day) => {
        if (showEmptyDays.value || day.generations > 0) {
            return true;
        }

        // A day where Pixelforge billed something we have no record of must never be hidden.
        const theirs = providerDay(day.date);

        return theirs !== null && theirs.known && !theirs.matches;
    }),
);

function toggle(date: string): void {
    if (expanded.has(date)) {
        expanded.delete(date);
    } else {
        expanded.add(date);
    }
}

const dayLabel = (date: string): string =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });

const tiles = computed(() => [
    {
        label: 'Charged to users',
        value: props.report.totals.charge.display,
        hint: 'Credits spent (1 credit = $1)',
    },
    {
        label: 'Pixelforge cost',
        value: props.report.totals.provider_cost.display,
        hint: 'What Pixelforge charged us',
    },
    {
        label: 'Profit',
        value: props.report.totals.profit.display,
        hint:
            props.report.totals.margin === null
                ? 'Charged − Pixelforge cost'
                : `${props.report.totals.margin} margin on what users paid`,
        accent: true,
    },
    {
        label: 'Billed images',
        value: props.report.totals.generations.toLocaleString(),
        hint: 'Succeeded generations',
    },
]);

const inputClass =
    'h-11 rounded-[10px] border border-ink-border bg-ink-card px-3 font-inter text-[14px] text-ink-text [color-scheme:dark] focus-visible:border-lime focus-visible:outline-none';
</script>

<template>
    <Head title="Spend & profit" />

    <main
        class="mx-auto box-border flex w-full max-w-[1440px] flex-col gap-8 px-[clamp(16px,4vw,48px)] pt-10 pb-20"
    >
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex max-w-[720px] flex-col gap-2">
                <span
                    class="text-[12px] font-semibold tracking-[0.08em] text-lime uppercase"
                    >Admin</span
                >
                <h1
                    class="m-0 font-display text-[clamp(36px,4.4vw,56px)] leading-none font-semibold tracking-[-0.035em]"
                >
                    Spend &amp; profit
                </h1>
                <p class="m-0 text-[16px] leading-[1.55] text-ink-muted">
                    Per UTC day and per user: what users were charged, what
                    Pixelforge charged us, and the profit. Only succeeded
                    generations are billed, on the day they completed, the same
                    way Pixelforge's usage report counts.
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="show({ from, to })"
            >
                <label class="flex flex-col gap-1 text-[12px] text-ink-muted">
                    From (UTC)
                    <input
                        v-model="from"
                        type="date"
                        :max="to"
                        required
                        :class="inputClass"
                    />
                </label>
                <label class="flex flex-col gap-1 text-[12px] text-ink-muted">
                    To (UTC)
                    <input
                        v-model="to"
                        type="date"
                        :min="from"
                        :max="todayUtc()"
                        required
                        :class="inputClass"
                    />
                </label>
                <button
                    type="submit"
                    class="h-11 cursor-pointer rounded-[10px] border-0 bg-lime px-4 font-inter text-[14px] font-semibold text-ink"
                >
                    Show
                </button>
            </form>
        </div>

        <div class="-mt-4 flex flex-wrap items-center justify-between gap-3">
            <div
                class="flex flex-wrap gap-1.5"
                role="group"
                aria-label="Quick ranges"
            >
                <button
                    v-for="preset in presets"
                    :key="preset.days"
                    type="button"
                    :aria-pressed="activePreset === preset.days"
                    class="min-h-9 cursor-pointer rounded-full px-3 font-inter text-[13px] font-medium"
                    :class="
                        activePreset === preset.days
                            ? 'border border-ink-text bg-ink-text text-ink'
                            : 'border border-ink-border bg-ink-card text-ink-subtle'
                    "
                    @click="applyPreset(preset.days)"
                >
                    {{ preset.label }}
                </button>
            </div>
            <p
                v-if="errors?.from || errors?.to"
                role="alert"
                class="m-0 text-[13px] text-red-300"
            >
                {{ errors?.from ?? errors?.to }}
            </p>
        </div>

        <section
            aria-label="Totals for the range"
            class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3"
        >
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="flex flex-col gap-1 rounded-[18px] border border-ink-line bg-ink-card p-5"
            >
                <span class="text-[13px] text-ink-muted">{{ tile.label }}</span>
                <span
                    class="font-display text-[28px] font-semibold tracking-[-0.02em] tabular-nums"
                    :class="{ 'text-lime': tile.accent }"
                    >{{ tile.value }}</span
                >
                <span class="text-[12px] text-ink-muted">{{ tile.hint }}</span>
            </div>

            <div
                class="flex flex-col gap-1 rounded-[18px] border p-5"
                :class="
                    comparison && !comparison.matches
                        ? 'border-amber-400/40 bg-amber-400/10'
                        : 'border-ink-line bg-ink-card'
                "
                aria-live="polite"
            >
                <span class="text-[13px] text-ink-muted"
                    >Checked against Pixelforge</span
                >
                <template v-if="pixelforge === undefined">
                    <span
                        class="inline-flex items-center gap-2 text-[16px] text-ink-soft"
                    >
                        <LoaderCircle
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Asking Pixelforge…
                    </span>
                </template>
                <template v-else-if="!pixelforge.available">
                    <span class="text-[14px] leading-snug text-amber-200">{{
                        pixelforge.message
                    }}</span>
                </template>
                <template v-else-if="pixelforge.matches">
                    <span
                        class="inline-flex items-center gap-2 font-display text-[22px] font-semibold text-lime"
                    >
                        <CircleCheck class="size-5" aria-hidden="true" />
                        Every day matches
                    </span>
                    <span class="text-[12px] text-ink-muted"
                        >Our Pixelforge cost equals GET /v1/usage to the
                        nanodollar</span
                    >
                </template>
                <template v-else>
                    <span
                        class="inline-flex items-center gap-2 font-display text-[22px] font-semibold text-amber-200"
                    >
                        <CircleAlert class="size-5" aria-hidden="true" />
                        {{ mismatchedDays.length }}
                        {{
                            mismatchedDays.length === 1
                                ? 'day differs'
                                : 'days differ'
                        }}
                    </span>
                    <span class="text-[12px] text-ink-muted"
                        >See the highlighted days below</span
                    >
                </template>
            </div>
        </section>

        <section aria-labelledby="days-h" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2
                    id="days-h"
                    class="m-0 font-display text-[24px] font-semibold tracking-[-0.02em]"
                >
                    By day
                </h2>
                <label
                    class="inline-flex cursor-pointer items-center gap-2 text-[14px] text-ink-soft"
                >
                    <input
                        v-model="showEmptyDays"
                        type="checkbox"
                        class="size-4 accent-lime"
                    />
                    Show days without billed images
                </label>
            </div>

            <div
                class="overflow-x-auto rounded-[18px] border border-ink-line bg-ink-card"
            >
                <table class="w-full min-w-[860px] border-collapse text-[14px]">
                    <thead>
                        <tr
                            class="border-b border-ink-line text-left text-[12px] tracking-[0.06em] text-ink-muted uppercase"
                        >
                            <th scope="col" class="px-4 py-3 font-semibold">
                                Day (UTC)
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-semibold"
                            >
                                Images
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-semibold"
                            >
                                Charged
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-semibold"
                            >
                                Pixelforge cost
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-semibold"
                            >
                                Pixelforge says
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-semibold"
                            >
                                Profit
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-semibold"
                                title="Profit as a share of what users paid. A 25% markup on Pixelforge's cost is a 20% margin."
                            >
                                Margin
                                <span class="sr-only"
                                    >(profit as a share of what users
                                    paid)</span
                                >
                            </th>
                            <th scope="col" class="w-12 px-2 py-3">
                                <span class="sr-only">Users</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in visibleDays" :key="day.date">
                            <tr
                                class="border-b border-ink-line last:border-b-0"
                                :class="{
                                    'bg-amber-400/5':
                                        knownDay(day.date)?.matches === false,
                                    'text-ink-muted': day.generations === 0,
                                }"
                            >
                                <th
                                    scope="row"
                                    class="px-4 py-3 text-left font-medium whitespace-nowrap"
                                >
                                    {{ dayLabel(day.date) }}
                                </th>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ day.generations }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ day.charge.display }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ day.provider_cost.display }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        v-if="pixelforge === undefined"
                                        class="inline-block h-4 w-20 animate-pulse rounded bg-ink-raised align-middle"
                                        aria-hidden="true"
                                    />
                                    <span
                                        v-else-if="!comparison"
                                        class="text-ink-muted"
                                        >—</span
                                    >
                                    <template v-else-if="knownDay(day.date)">
                                        <span
                                            v-for="theirs in [
                                                knownDay(day.date)!,
                                            ]"
                                            :key="day.date"
                                            class="inline-flex flex-col items-end"
                                        >
                                            <span
                                                class="inline-flex items-center gap-1.5"
                                                :class="
                                                    theirs.matches
                                                        ? 'text-lime'
                                                        : 'text-amber-200'
                                                "
                                            >
                                                <CircleCheck
                                                    v-if="theirs.matches"
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                <CircleAlert
                                                    v-else
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{ theirs.cost.display }}
                                                <span class="sr-only">{{
                                                    theirs.matches
                                                        ? '(matches)'
                                                        : '(differs)'
                                                }}</span>
                                            </span>
                                            <span
                                                v-if="theirs.difference"
                                                class="text-[12px] text-amber-200"
                                                >We recorded
                                                {{
                                                    theirs.difference.amount
                                                        .display
                                                }}
                                                {{
                                                    theirs.difference
                                                        .we_recorded
                                                }}</span
                                            >
                                            <span
                                                v-else-if="
                                                    theirs.generations !==
                                                    day.generations
                                                "
                                                class="text-[12px] text-amber-200"
                                                >{{ theirs.generations }} billed
                                                at Pixelforge</span
                                            >
                                        </span>
                                    </template>
                                    <span v-else class="text-ink-muted"
                                        >Not reported</span
                                    >
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                    :class="{
                                        'text-lime': day.generations > 0,
                                    }"
                                >
                                    {{ day.profit.display }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right tabular-nums"
                                    :class="
                                        day.margin === null
                                            ? 'text-ink-muted'
                                            : 'text-ink-soft'
                                    "
                                >
                                    {{ day.margin ?? '—' }}
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <button
                                        v-if="day.users.length > 0"
                                        type="button"
                                        :aria-expanded="expanded.has(day.date)"
                                        :aria-label="`${expanded.has(day.date) ? 'Hide' : 'Show'} users for ${dayLabel(day.date)}`"
                                        class="grid size-9 cursor-pointer place-items-center rounded-[8px] border-0 bg-transparent text-ink-soft hover:bg-ink-raised hover:text-ink-text"
                                        @click="toggle(day.date)"
                                    >
                                        <ChevronDown
                                            class="size-4 transition-transform"
                                            :class="{
                                                'rotate-180': expanded.has(
                                                    day.date,
                                                ),
                                            }"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </td>
                            </tr>
                            <template v-if="expanded.has(day.date)">
                                <tr
                                    v-for="user in day.users"
                                    :key="`${day.date}-${user.user_id}`"
                                    class="border-b border-ink-line bg-ink text-[13px]"
                                >
                                    <th
                                        scope="row"
                                        class="py-2.5 pr-4 pl-10 text-left font-normal"
                                    >
                                        <span class="text-ink-text">{{
                                            user.name
                                        }}</span>
                                        <span
                                            v-if="user.email"
                                            class="block text-[12px] text-ink-muted"
                                            >{{ user.email }}</span
                                        >
                                        <span
                                            v-else-if="user.deleted"
                                            class="block text-[12px] text-ink-muted"
                                            >Deleted account</span
                                        >
                                    </th>
                                    <td
                                        class="px-4 py-2.5 text-right tabular-nums"
                                    >
                                        {{ user.generations }}
                                    </td>
                                    <td
                                        class="px-4 py-2.5 text-right tabular-nums"
                                    >
                                        {{ user.charge.display }}
                                    </td>
                                    <td
                                        class="px-4 py-2.5 text-right tabular-nums"
                                    >
                                        {{ user.provider_cost.display }}
                                    </td>
                                    <td class="px-4 py-2.5" />
                                    <td
                                        class="px-4 py-2.5 text-right tabular-nums"
                                    >
                                        {{ user.profit.display }}
                                    </td>
                                    <td
                                        class="px-4 py-2.5 text-right text-ink-soft tabular-nums"
                                    >
                                        {{ user.margin ?? '—' }}
                                    </td>
                                    <td />
                                </tr>
                            </template>
                        </template>
                        <tr v-if="visibleDays.length === 0">
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-ink-muted"
                            >
                                No billed images in this range.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-ink-border font-semibold">
                            <th scope="row" class="px-4 py-3 text-left">
                                Total
                            </th>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ report.totals.generations }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ report.totals.charge.display }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ report.totals.provider_cost.display }}
                            </td>
                            <td />
                            <td
                                class="px-4 py-3 text-right text-lime tabular-nums"
                            >
                                {{ report.totals.profit.display }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ report.totals.margin ?? '—' }}
                            </td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="m-0 text-[12px] text-ink-muted">
                Pixelforge's figures come from GET /v1/usage for this
                environment's API key and refresh every minute. Amounts are
                exact; nothing is rounded.
            </p>
        </section>
    </main>
</template>
