<script setup lang="ts">
import { computed } from 'vue';
import { statusDisplay, TONE_CLASSES } from '@/composables/useGenerationStatus';
import type { GenerationHistoryItem } from '@/types/generations';

const props = withDefaults(
    defineProps<{
        generation: GenerationHistoryItem;
        elapsedSeconds: number;
        showHint?: boolean;
    }>(),
    { showHint: true },
);

const display = computed(() =>
    statusDisplay(props.generation, props.elapsedSeconds),
);
</script>

<template>
    <span class="inline-flex flex-col items-start gap-1">
        <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-[5px] text-[12px] font-semibold tracking-[0.04em] uppercase"
            :class="TONE_CLASSES[display.tone]"
        >
            <component
                :is="display.icon"
                class="size-3.5"
                :class="{ 'animate-spin': display.spin }"
                aria-hidden="true"
            />
            {{ display.label }}
        </span>
        <span
            v-if="showHint && display.hint"
            class="text-[12px] text-ink-muted"
            >{{ display.hint }}</span
        >
    </span>
</template>
