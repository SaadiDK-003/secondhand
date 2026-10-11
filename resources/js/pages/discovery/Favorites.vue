<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Heart } from '@lucide/vue';
import ListingCard from '@/components/listings/ListingCard.vue';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type { ListingResults } from '@/types/marketplace';
defineProps<{ listings: ListingResults }>();
</script>
<template>
    <Head title="My favorites" />
    <main class="mx-auto max-w-7xl px-5 py-8 lg:px-8">
        <p class="mb-3 text-xs font-semibold tracking-widest text-primary">
            WORTH A SECOND LOOK
        </p>
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
            My favorites
        </h1>
        <p class="mt-3 text-muted-foreground">
            Keep good finds close. Only currently available ads appear here.
        </p>
        <div
            v-if="!listings.data.length"
            class="mt-8 rounded-2xl border bg-card px-5 py-16 text-center"
        >
            <span
                class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-accent text-primary"
                ><Heart class="size-6" aria-hidden="true"
            /></span>
            <h2 class="text-xl font-semibold">
                Your saved finds will appear here
            </h2>
            <Link
                href="/search"
                class="mt-6 inline-block rounded-xl bg-primary px-5 py-3 font-semibold text-white"
                >Browse used items</Link
            >
        </div>
        <div v-else class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
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
