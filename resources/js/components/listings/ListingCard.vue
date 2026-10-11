<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FavoriteButton from './FavoriteButton.vue';
import type { PublicListing } from '@/types/marketplace';
defineProps<{ listing: PublicListing }>();
</script>
<template>
    <article
        class="relative overflow-hidden rounded-2xl border bg-card transition hover:border-primary/30 hover:shadow-md"
    >
        <Link :href="'/ad/' + listing.slug" class="block"
            ><img
                v-if="listing.image_url"
                :src="listing.image_url"
                :srcset="listing.image_srcset ?? undefined"
                sizes="(min-width: 1280px) 300px, (min-width: 640px) 45vw, calc(100vw - 40px)"
                :alt="listing.title"
                loading="lazy"
                decoding="async"
                width="600"
                height="450"
                class="aspect-[4/3] w-full bg-muted object-cover"
            />
            <div
                v-else
                class="flex aspect-[4/3] items-center justify-center bg-muted text-sm text-muted-foreground"
            >
                No photo available
            </div></Link
        >
        <div class="absolute top-3 right-3">
            <FavoriteButton
                :listing-id="listing.id"
                :saved="listing.is_favorite"
            />
        </div>
        <div class="space-y-2 p-4">
            <p class="text-xl font-bold">
                Rs {{ Number(listing.price).toLocaleString('en-PK') }}
            </p>
            <h2 class="font-medium [overflow-wrap:anywhere]">
                <Link
                    :href="'/ad/' + listing.slug"
                    class="line-clamp-2 hover:underline"
                    >{{ listing.title }}</Link
                >
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ [listing.area, listing.city].filter(Boolean).join(', ') }}
            </p>
            <p
                v-if="listing.published_at"
                class="text-xs text-muted-foreground"
            >
                {{
                    new Date(listing.published_at).toLocaleDateString('en-PK', {
                        timeZone: 'Asia/Karachi',
                    })
                }}
            </p>
        </div>
    </article>
</template>
