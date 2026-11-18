<script setup lang="ts">
import type { NavKey } from '@/components/atelier/SiteHeader.vue';
import SiteFooter from '@/components/atelier/SiteFooter.vue';
import SiteHeader from '@/components/atelier/SiteHeader.vue';
import { Toaster } from '@/components/ui/sonner';

withDefaults(
    defineProps<{
        variant?: 'app' | 'site';
        current?: NavKey;
        footer?: boolean;
    }>(),
    { variant: 'app', current: null, footer: false },
);
</script>

<template>
    <div
        class="flex min-h-screen flex-col bg-ink font-inter text-ink-text antialiased"
    >
        <SiteHeader :variant="variant" :current="current" />
        <div class="flex flex-1 flex-col">
            <slot />
        </div>
        <SiteFooter v-if="footer" />
        <Toaster />
    </div>
</template>
