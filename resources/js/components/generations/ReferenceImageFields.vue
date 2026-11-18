<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    max: number;
    errors: Record<string, string | undefined>;
}>();

const urls = defineModel<string[]>({ required: true });

// Bound rather than a static attribute: vue-tsc 3.3 turns a literal "//" in a component
// attribute into a comment and loses the v-for scope.
const PLACEHOLDER_URL = 'https://example.com/style.png';

function add(): void {
    if (urls.value.length < props.max) {
        urls.value = [...urls.value, ''];
    }
}

function update(position: number, url: string): void {
    urls.value = urls.value.map((current, index) =>
        index === position ? url : current,
    );
}

function remove(position: number): void {
    urls.value = urls.value.filter((_, index) => index !== position);
}

const canAdd = computed(() => urls.value.length < props.max);

const errorAt = (position: number): string | undefined =>
    props.errors[`reference_image_urls.${position}`];
</script>

<template>
    <fieldset
        class="m-0 flex flex-col gap-2.5 rounded-[12px] border border-ink-line bg-ink p-3"
    >
        <legend class="sr-only">Reference images</legend>
        <p class="m-0 text-[13px] text-ink-muted">
            Link up to {{ max }} reference images. Each adds 765 input tokens to
            the price.
        </p>

        <div
            v-for="(url, position) in urls"
            :key="position"
            class="flex flex-col gap-1"
        >
            <div class="flex gap-2">
                <input
                    :value="url"
                    type="url"
                    inputmode="url"
                    :placeholder="PLACEHOLDER_URL"
                    :aria-label="`Reference image ${position + 1} link`"
                    :aria-invalid="errorAt(position) !== undefined"
                    class="h-11 min-w-0 flex-1 rounded-[10px] border border-ink-border bg-ink-card px-3 text-[14px] text-ink-text outline-none placeholder:text-ink-dim focus-visible:border-lime aria-invalid:border-red-400"
                    @input="
                        update(
                            position,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <button
                    type="button"
                    :aria-label="`Remove reference image ${position + 1}`"
                    class="grid size-11 cursor-pointer place-items-center rounded-[10px] border border-ink-border bg-ink-raised text-ink-text"
                    @click="remove(position)"
                >
                    <svg
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        aria-hidden="true"
                    >
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>
            <p
                v-if="errorAt(position)"
                class="m-0 text-[13px] text-red-300"
                role="alert"
            >
                {{ errorAt(position) }}
            </p>
        </div>

        <button
            v-if="canAdd"
            type="button"
            class="inline-flex min-h-10 cursor-pointer items-center gap-2 self-start rounded-[10px] border border-ink-border bg-ink-raised px-3.5 text-[14px] text-ink-text"
            @click="add"
        >
            + Add image link
        </button>
        <p
            v-if="errors.reference_image_urls"
            class="m-0 text-[13px] text-red-300"
            role="alert"
        >
            {{ errors.reference_image_urls }}
        </p>
    </fieldset>
</template>
