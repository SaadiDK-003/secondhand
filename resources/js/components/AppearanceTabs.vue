<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div
        class="flex w-full gap-1 rounded-xl bg-muted p-1 sm:inline-flex sm:w-auto"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            @click="updateAppearance(value)"
            :class="[
                'flex flex-1 items-center justify-center rounded-lg px-2 py-2 transition-colors sm:flex-none sm:px-3.5',
                appearance === value
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            ]"
        >
            <component :is="Icon" class="size-4 shrink-0" aria-hidden="true" />
            <span class="ml-1.5 text-sm">{{ label }}</span>
        </button>
    </div>
</template>
