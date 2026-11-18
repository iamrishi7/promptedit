import type { Component } from 'vue';
import { computed } from 'vue';
import {
    CircleCheck,
    CircleX,
    Clock,
    LoaderCircle,
    SearchCheck,
} from '@lucide/vue';
import type { GenerationHistoryItem } from '@/types/generations';

// Pixelforge usually accepts within seconds. Longer means the submission is still being retried
// (Pixelforge busy, or our queue behind); we don't know which, so the copy doesn't guess.
const WAITING_FOR_CAPACITY_AFTER_SECONDS = 20;

export type StatusDisplay = {
    label: string;
    /** Longer wording for the in-progress tile. */
    busyLabel: string;
    hint: string | null;
    icon: Component;
    spin: boolean;
    tone: 'neutral' | 'active' | 'done' | 'warn' | 'error';
};

export function statusDisplay(
    generation: GenerationHistoryItem,
    elapsedSeconds: number,
): StatusDisplay {
    switch (generation.status) {
        case 'pending':
            return elapsedSeconds > WAITING_FOR_CAPACITY_AFTER_SECONDS
                ? {
                      label: 'Waiting to start',
                      busyLabel: 'Waiting to start…',
                      hint: 'Taking longer than usual. We keep trying automatically.',
                      icon: Clock,
                      spin: false,
                      tone: 'warn',
                  }
                : {
                      label: 'Submitting',
                      busyLabel: 'Submitting…',
                      hint: null,
                      icon: LoaderCircle,
                      spin: true,
                      tone: 'neutral',
                  };
        case 'queued':
            return {
                label: 'Queued',
                busyLabel: 'Queued at Pixelforge…',
                hint: null,
                icon: Clock,
                spin: false,
                tone: 'neutral',
            };
        case 'processing':
            return {
                label: 'Generating',
                busyLabel: 'Generating…',
                hint: null,
                icon: LoaderCircle,
                spin: true,
                tone: 'active',
            };
        case 'succeeded':
            return {
                label: 'Done',
                busyLabel: 'Done',
                hint: null,
                icon: CircleCheck,
                spin: false,
                tone: 'done',
            };
        case 'failed':
        default:
            return generation.failure?.provisional
                ? {
                      label: 'Checking',
                      busyLabel: 'Checking with Pixelforge…',
                      hint: 'Confirming with Pixelforge whether it ran.',
                      icon: SearchCheck,
                      spin: false,
                      tone: 'warn',
                  }
                : {
                      label: 'Failed',
                      busyLabel: 'Failed',
                      hint: null,
                      icon: CircleX,
                      spin: false,
                      tone: 'error',
                  };
    }
}

export const TONE_CLASSES: Record<StatusDisplay['tone'], string> = {
    neutral: 'bg-ink-raised text-ink-soft border border-ink-border',
    active: 'bg-lime/15 text-lime border border-lime/30',
    done: 'bg-lime text-ink border border-lime',
    warn: 'bg-amber-400/15 text-amber-200 border border-amber-400/30',
    error: 'bg-red-500/15 text-red-200 border border-red-400/30',
};

export function useGenerationStatus(
    generation: () => GenerationHistoryItem,
    elapsedSeconds: () => number,
) {
    return computed(() => statusDisplay(generation(), elapsedSeconds()));
}

/** What the user pays, in words: exact once known, "only if it completed" while unconfirmed. */
export function costLine(generation: GenerationHistoryItem): {
    label: string;
    value: string;
    tone: string;
} {
    const { status, charge, failure } = generation;

    if (status === 'succeeded' && charge) {
        return {
            label: 'Charged',
            value: charge.display,
            tone: 'text-ink-text',
        };
    }

    if (failure?.provisional) {
        return {
            label: 'Charged',
            value: 'Only if it completed',
            tone: 'text-amber-200',
        };
    }

    if (status === 'failed') {
        return {
            label: 'Charged',
            value: '$0.00 — not charged',
            tone: 'text-lime',
        };
    }

    // Still running: the price the user accepted, until the exact charge is known.
    return {
        label: 'Estimated',
        value: generation.estimate.display,
        tone: 'text-ink-muted',
    };
}
