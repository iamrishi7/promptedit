<script setup lang="ts">
import { Download, Share2, X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { shareImage } from '@/lib/shareImage';
import { titleOf } from '@/lib/generationLabels';
import { download } from '@/routes/generations';

const open = defineModel<boolean>('open', { required: true });

defineProps<{
    generationId: string;
    url: string;
    stored: boolean;
    prompt: string;
}>();
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-ink/90 backdrop-blur-sm data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 p-4 font-inter text-ink-text outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 sm:p-10"
            >
                <DialogTitle class="sr-only">{{ titleOf(prompt) }}</DialogTitle>
                <DialogDescription class="sr-only">{{
                    prompt
                }}</DialogDescription>
                <img
                    :src="url"
                    :alt="prompt"
                    class="max-h-[calc(100vh-160px)] max-w-full rounded-[16px] object-contain shadow-2xl"
                />
                <div
                    class="flex max-w-[900px] flex-wrap items-center justify-center gap-3"
                >
                    <p
                        class="m-0 line-clamp-2 max-w-[640px] text-center text-[14px] text-ink-soft"
                    >
                        {{ prompt }}
                    </p>
                    <a
                        :href="download(generationId).url"
                        class="inline-flex min-h-11 items-center gap-2 rounded-[12px] bg-lime px-4 font-semibold text-ink no-underline"
                    >
                        <Download class="size-[18px]" aria-hidden="true" />
                        Download
                    </a>
                    <button
                        type="button"
                        class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-[12px] border border-ink-border bg-ink-raised px-4 font-semibold text-ink-text"
                        @click="shareImage({ url, stored }, titleOf(prompt))"
                    >
                        <Share2 class="size-[18px]" aria-hidden="true" />
                        Share
                    </button>
                </div>
                <DialogClose
                    class="absolute top-4 right-4 grid size-11 cursor-pointer place-items-center rounded-full border border-ink-border bg-ink-raised text-ink-text"
                    aria-label="Close"
                >
                    <X class="size-5" aria-hidden="true" />
                </DialogClose>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
