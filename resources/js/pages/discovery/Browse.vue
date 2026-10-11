<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ListingCard from '@/components/listings/ListingCard.vue';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type { ListingResults } from '@/types/marketplace';
defineProps<{ heading: string; listings: ListingResults; searchUrl: string }>();
</script>
<template>
    <main class="mx-auto max-w-6xl px-5 py-8">
        <nav aria-label="Breadcrumb" class="mb-5 text-sm">
            <Link href="/" class="underline">Home</Link> / {{ heading }}
        </nav>
        <h1
            class="text-3xl font-bold tracking-tight [overflow-wrap:anywhere] sm:text-4xl"
        >
            {{ heading }}
        </h1>
        <p class="mt-3 text-muted-foreground">
            Find pre-owned items and message their sellers directly.
        </p>
        <Link
            :href="searchUrl"
            class="my-6 inline-block rounded-lg border px-5 py-3"
            >Search and filter these items</Link
        >
        <p v-if="!listings.data.length" class="rounded-2xl border bg-card p-8">
            No available items here yet. Check back for new finds.
        </p>
        <div
            v-else
            class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <ListingCard
                v-for="listing in listings.data"
                :key="listing.id"
                :listing="listing"
            />
        </div>
        <ResultPagination
            :page="listings.current_page"
            :last-page="listings.last_page"
            :previous="listings.prev_page_url"
            :next="listings.next_page_url"
        />
    </main>
</template>
