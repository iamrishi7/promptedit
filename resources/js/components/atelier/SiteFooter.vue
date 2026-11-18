<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useAppName } from '@/composables/useAppName';
import { explore, models } from '@/routes';

const year = new Date().getFullYear();
const appName = useAppName();
</script>

<template>
    <footer class="border-t border-ink-line px-[clamp(16px,4vw,48px)] py-10">
        <div
            class="mx-auto flex max-w-[1344px] flex-wrap items-center justify-between gap-6"
        >
            <span class="font-display text-[18px] font-bold">{{
                appName
            }}</span>
            <nav aria-label="Footer" class="flex flex-wrap gap-1 text-[14px]">
                <Link
                    :href="explore()"
                    class="px-3 py-2.5 text-ink-muted no-underline"
                    >Gallery</Link
                >
                <Link
                    :href="models()"
                    class="px-3 py-2.5 text-ink-muted no-underline"
                    >Models</Link
                >
                <a
                    href="/#pricing"
                    class="px-3 py-2.5 text-ink-muted no-underline"
                    >Pricing</a
                >
                <span class="px-3 py-2.5 text-ink-muted">Terms</span>
                <span class="px-3 py-2.5 text-ink-muted">Privacy</span>
            </nav>
            <span class="text-[14px] text-ink-muted"
                >© {{ year }} {{ appName }}</span
            >
        </div>
    </footer>
</template>
