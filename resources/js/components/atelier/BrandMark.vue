<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useAppName } from '@/composables/useAppName';
import { home } from '@/routes';

withDefaults(defineProps<{ size?: 'sm' | 'md' }>(), { size: 'md' });

const appName = useAppName();
</script>

<template>
    <Link
        :href="home()"
        class="flex items-center gap-2.5 text-ink-text no-underline"
    >
        <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
            <rect x="1" y="1" width="26" height="26" rx="8" fill="#D4FF4F" />
            <circle
                cx="14"
                cy="14"
                r="6"
                fill="none"
                stroke="#0A0A0C"
                stroke-width="2.5"
            />
            <circle cx="14" cy="14" r="1.8" fill="#0A0A0C" />
        </svg>
        <span
            class="font-display font-bold tracking-[-0.02em]"
            :class="size === 'sm' ? 'text-[19px]' : 'text-[20px]'"
            >{{ appName }}</span
        >
    </Link>
</template>
