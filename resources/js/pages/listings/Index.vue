<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ListingSummary } from '@/types/marketplace';
defineProps<{
    listings: {
        data: ListingSummary[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const deleting = ref<ListingSummary | null>(null);
const deletion = useForm({});
function remove() {
    if (deleting.value)
        deletion.delete('/listings/' + deleting.value.id, {
            onSuccess: () => {
                deleting.value = null;
            },
        });
}
function money(value: string) {
    return new Intl.NumberFormat('en-PK', { maximumFractionDigits: 2 }).format(
        Number(value),
    );
}
</script>
<template>
    <Head title="My listings" />
    <main class="mx-auto w-full max-w-6xl p-5 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">My listings</h1>
                <p class="mt-2 text-muted-foreground">
                    Manage your items and give them a second home.
                </p>
            </div>
            <Link
                href="/sell"
                class="rounded-lg bg-primary px-5 py-3 font-semibold text-white"
                >Sell an item</Link
            >
        </div>
        <div
            v-if="!listings.data.length"
            class="mt-10 rounded-2xl border bg-card px-5 py-16 text-center"
        >
            <h2 class="text-xl font-medium">Your first listing starts here</h2>
            <p class="mt-3 text-muted-foreground">
                Take a few photos and tell buyers what makes your item worth a
                second look.
            </p>
            <Link
                href="/sell"
                class="mt-6 inline-block font-medium text-primary underline"
                >Create your first ad</Link
            >
        </div>
        <div v-else class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="listing in listings.data"
                :key="listing.id"
                class="overflow-hidden rounded-2xl border bg-card"
            >
                <Link :href="'/listings/' + listing.id + '/preview'"
                    ><img
                        v-if="listing.images[0]?.card_url"
                        :src="listing.images[0].card_url"
                        :alt="listing.title"
                        loading="lazy"
                        class="aspect-[4/3] w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex aspect-[4/3] items-center justify-center bg-muted text-sm text-muted-foreground"
                    >
                        {{
                            listing.images.some(
                                (image) => image.status === 'failed',
                            )
                                ? 'Photo needs replacing'
                                : 'Photos are processing'
                        }}
                    </div></Link
                >
                <div class="space-y-3 p-5">
                    <span
                        class="inline-flex rounded-full bg-muted px-3 py-1 text-xs capitalize"
                        >{{
                            listing.status === 'pending'
                                ? 'Pending review'
                                : listing.status
                        }}</span
                    >
                    <p class="text-xl font-semibold">
                        Rs {{ money(listing.price) }}
                    </p>
                    <h2 class="font-medium [overflow-wrap:anywhere]">
                        <Link :href="'/listings/' + listing.id + '/preview'">{{
                            listing.title
                        }}</Link>
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ listing.city }} · {{ listing.category }}
                    </p>
                    <div class="flex flex-wrap gap-4 border-t pt-3 text-sm">
                        <Link
                            :href="'/listings/' + listing.id + '/preview'"
                            class="underline"
                            >Preview</Link
                        ><Link
                            v-if="
                                ['draft', 'rejected', 'published'].includes(
                                    listing.status,
                                )
                            "
                            :href="'/listings/' + listing.id + '/edit'"
                            class="underline"
                            >Edit</Link
                        ><button
                            v-if="listing.status !== 'blocked'"
                            type="button"
                            class="text-red-600 underline"
                            @click="deleting = listing"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </article>
        </div>
        <nav
            v-if="listings.last_page > 1"
            aria-label="Listing pages"
            class="mt-8 flex items-center justify-between"
        >
            <Link
                v-if="listings.prev_page_url"
                :href="listings.prev_page_url"
                class="underline"
                >Previous</Link
            ><span
                >Page {{ listings.current_page }} of
                {{ listings.last_page }}</span
            ><Link
                v-if="listings.next_page_url"
                :href="listings.next_page_url"
                class="underline"
                >Next</Link
            >
        </nav>
        <Dialog
            :open="deleting !== null"
            @update:open="
                (value) => {
                    if (!value) deleting = null;
                }
            "
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle>Delete this listing?</DialogTitle
                    ><DialogDescription
                        >{{ deleting?.title }} and its photos will be removed.
                        This cannot be undone.</DialogDescription
                    ></DialogHeader
                ><DialogFooter
                    ><button
                        type="button"
                        class="rounded-lg border px-4 py-2"
                        :disabled="deletion.processing"
                        @click="deleting = null"
                    >
                        Cancel</button
                    ><button
                        type="button"
                        class="rounded-lg bg-red-700 px-4 py-2 text-white"
                        :disabled="deletion.processing"
                        @click="remove"
                    >
                        {{
                            deletion.processing ? 'Deleting…' : 'Delete listing'
                        }}
                    </button></DialogFooter
                ></DialogContent
            ></Dialog
        >
    </main>
</template>
