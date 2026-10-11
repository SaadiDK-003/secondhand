<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ListingCard from '@/components/listings/ListingCard.vue';
import ReportForm from '@/components/listings/ReportForm.vue';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type { ListingResults } from '@/types/marketplace';
defineProps<{
    seller: {
        id: number;
        name: string;
        joined: string | null;
        email_verified: boolean;
        photo_url: string | null;
        active_count: number;
        sold_count: number;
        response_summary: {
            sample_size: number;
            replied_percent: number;
        } | null;
        cities: string[];
    };
    listings: ListingResults;
    isOwner: boolean;
}>();
</script>
<template>
    <main class="mx-auto max-w-6xl px-5 py-8">
        <nav aria-label="Breadcrumb" class="mb-6 text-sm">
            <Link href="/" class="underline">Home</Link> / Seller profile
        </nav>
        <section class="rounded-2xl border bg-card p-6 sm:p-8">
            <img
                v-if="seller.photo_url"
                :src="seller.photo_url"
                :alt="seller.name + ' profile photo'"
                width="96"
                height="96"
                class="mb-4 size-24 rounded-full object-cover"
            />
            <p class="text-sm text-muted-foreground">Seller profile</p>
            <h1
                class="mt-2 text-3xl font-bold tracking-tight [overflow-wrap:anywhere]"
            >
                {{ seller.name }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Joined {{ seller.joined }}
            </p>
            <dl class="my-6 flex flex-wrap gap-8">
                <div>
                    <dt class="text-sm text-muted-foreground">
                        Available items
                    </dt>
                    <dd class="text-2xl font-semibold">
                        {{ seller.active_count }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        Marked as sold
                    </dt>
                    <dd class="text-2xl font-semibold">
                        {{ seller.sold_count }}
                    </dd>
                </div>
            </dl>
            <p v-if="seller.cities.length" class="text-sm">
                Items listed in {{ seller.cities.join(', ') }}
            </p>
            <p class="mt-3 text-sm">
                Email
                {{ seller.email_verified ? 'verified' : 'not verified' }} ·
                Phone verification not available yet
            </p>
            <p class="mt-3 text-sm text-muted-foreground">
                Open an available item to message this seller. Sold counts are
                reported by the seller, not confirmed transactions.
            </p>
            <div class="mt-4 text-sm">
                <p v-if="seller.response_summary">
                    Replied in {{ seller.response_summary.replied_percent }}% of
                    {{ seller.response_summary.sample_size }} recent
                    conversations.
                </p>
                <p v-else>Not enough conversations to show a reply rate yet.</p>
                <p
                    v-if="seller.response_summary"
                    class="mt-1 text-muted-foreground"
                >
                    Based on up to 100 conversations started in the last 90
                    days, excluding the newest 24 hours. Updated about every
                    five minutes.
                </p>
            </div>
            <Link
                v-if="isOwner"
                href="/settings/profile"
                class="mt-5 inline-block underline"
                >Edit your account</Link
            >
            <ReportForm
                v-else
                :key="seller.id"
                :url="'/users/' + seller.id + '/report'"
                label="Report seller"
            />
        </section>
        <h2 class="mt-10 mb-5 text-2xl font-semibold">Available items</h2>
        <p v-if="!listings.data.length" class="rounded-2xl border bg-card p-8">
            This seller has no available items right now.
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
