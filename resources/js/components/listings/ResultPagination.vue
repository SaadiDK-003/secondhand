<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{
    page: number;
    lastPage: number;
    previous: string | null;
    next: string | null;
}>();
</script>
<template>
    <nav
        v-if="lastPage > 1"
        aria-label="Results pages"
        class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:gap-5"
    >
        <Link
            v-if="previous"
            :href="previous"
            class="inline-flex min-h-11 items-center rounded-lg border px-4 py-2"
            >Previous</Link
        ><span
            class="order-first w-full text-center text-sm sm:order-none sm:w-auto"
            >Page {{ page }} of {{ lastPage }}</span
        ><Link
            v-if="next"
            :href="next"
            class="inline-flex min-h-11 items-center rounded-lg border px-4 py-2"
            >Next</Link
        >
    </nav>
</template>
