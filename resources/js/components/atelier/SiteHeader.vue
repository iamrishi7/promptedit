<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandMark from '@/components/atelier/BrandMark.vue';
import SparkleIcon from '@/components/atelier/SparkleIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { explore, login, models, register } from '@/routes';
import { spend } from '@/routes/admin';
import { create, index } from '@/routes/generations';

export type NavKey =
    | 'create'
    | 'library'
    | 'explore'
    | 'models'
    | 'admin'
    | null;

const props = defineProps<{
    /** "app": the signed-in studio header. "site": the public pages' header. */
    variant: 'app' | 'site';
    current: NavKey;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const spent = computed(() =>
    typeof page.props.spent === 'string' ? page.props.spent : null,
);
const initial = computed(
    () => user.value?.name?.trim().charAt(0).toUpperCase() || 'A',
);

const appNav = computed(() => [
    { key: 'create', label: 'Create', href: create() },
    { key: 'library', label: 'My library', href: index() },
    { key: 'explore', label: 'Explore', href: explore() },
    { key: 'models', label: 'Models', href: models() },
    ...(page.props.auth.isAdmin
        ? [{ key: 'admin', label: 'Admin', href: spend() }]
        : []),
]);

const siteNav = computed(() => [
    { key: 'explore', label: 'Explore', href: explore() },
    { key: 'models', label: 'Models', href: models() },
    { key: 'create', label: 'Studio', href: create() },
    { key: 'pricing', label: 'Pricing', href: '/#pricing' },
]);

const nav = computed(() =>
    props.variant === 'app' ? appNav.value : siteNav.value,
);
</script>

<template>
    <header
        class="flex flex-wrap items-center justify-between border-b border-ink-line bg-ink"
        :class="
            variant === 'app'
                ? 'gap-3 px-[clamp(16px,2vw,24px)] py-3'
                : 'gap-4 px-[clamp(16px,4vw,48px)] py-4'
        "
    >
        <div
            class="flex flex-wrap items-center"
            :class="variant === 'app' ? 'gap-5' : 'contents'"
        >
            <BrandMark :size="variant === 'app' ? 'sm' : 'md'" />
            <nav
                :aria-label="variant === 'app' ? 'App' : 'Primary'"
                class="flex flex-wrap"
                :class="variant === 'app' ? 'gap-0.5' : 'gap-1'"
            >
                <Link
                    v-for="item in nav"
                    :key="item.key"
                    :href="item.href"
                    :aria-current="item.key === current ? 'page' : undefined"
                    class="rounded-[10px] px-3.5 py-2.5 no-underline"
                    :class="[
                        variant === 'app' ? 'text-[14px]' : 'text-[15px]',
                        item.key === current
                            ? 'bg-ink-raised font-medium text-ink-text'
                            : 'text-ink-soft hover:text-ink-text',
                    ]"
                    >{{ item.label }}</Link
                >
            </nav>
        </div>

        <div
            class="flex items-center"
            :class="variant === 'app' ? 'gap-2.5' : 'gap-2'"
        >
            <template v-if="variant === 'app'">
                <span
                    v-if="spent"
                    class="inline-flex items-center gap-2 rounded-[10px] border border-ink-border bg-ink-card px-3 py-2 text-[14px] text-ink-text"
                    title="Total charged for your succeeded generations"
                >
                    <SparkleIcon class="size-4 text-lime" />{{ spent }} spent
                </span>
                <button
                    type="button"
                    disabled
                    title="Coming soon"
                    class="cursor-not-allowed rounded-[10px] border border-ink-border px-3.5 py-2.5 text-[14px] font-semibold text-ink-text"
                >
                    Upgrade
                </button>
            </template>

            <template v-if="user">
                <Link
                    v-if="variant === 'site'"
                    :href="index()"
                    class="rounded-[10px] px-4 py-[11px] text-[15px] text-ink-text no-underline"
                    >My library</Link
                >
                <Link
                    v-if="variant === 'site'"
                    :href="create()"
                    class="rounded-[10px] bg-lime px-[18px] py-[11px] text-[15px] font-semibold text-ink no-underline"
                    >Start creating</Link
                >
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Account menu"
                            class="size-11 cursor-pointer rounded-full border-0 bg-ink-border font-inter font-semibold text-ink-text"
                        >
                            {{ initial }}
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent class="w-56" align="end">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
            <template v-else>
                <Link
                    :href="login()"
                    class="rounded-[10px] px-4 py-[11px] text-[15px] text-ink-text no-underline"
                    >Sign in</Link
                >
                <Link
                    :href="register()"
                    class="rounded-[10px] bg-lime px-[18px] py-[11px] text-[15px] font-semibold text-ink no-underline"
                    >Start creating</Link
                >
            </template>
        </div>
    </header>
</template>
