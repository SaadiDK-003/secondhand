<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Bike,
    BookOpen,
    Camera,
    Gamepad2,
    Laptop,
    MapPin,
    MessageCircle,
    Package,
    Shirt,
    Smartphone,
    Sofa,
    Sparkles,
    ToyBrick,
    WashingMachine,
    Wrench,
} from '@lucide/vue';
import MarketplaceLayout from '@/layouts/MarketplaceLayout.vue';
import ListingCard from '@/components/listings/ListingCard.vue';
import type { PublicListing } from '@/types/marketplace';
defineProps<{
    categories: { id: number; name: string; slug: string }[];
    cities: { name: string; slug: string }[];
    listings: PublicListing[];
}>();
const icons: Record<string, typeof Package> = {
    furniture: Sofa,
    'mobiles-tablets': Smartphone,
    'computers-laptops': Laptop,
    electronics: Camera,
    'home-appliances': WashingMachine,
    fashion: Shirt,
    'wedding-clothes-accessories': Sparkles,
    'kids-baby-items': ToyBrick,
    gaming: Gamepad2,
    'bikes-cycles': Bike,
    books: BookOpen,
    'tools-equipment': Wrench,
};
</script>
<template>
    <MarketplaceLayout>
        <main class="mx-auto max-w-7xl px-5 py-6 lg:px-8 lg:py-8">
            <section
                class="grid overflow-hidden rounded-3xl border bg-[#EDF4FC] md:grid-cols-[1.05fr_1fr]"
            >
                <div class="flex flex-col justify-center p-6 sm:p-8 lg:p-12">
                    <p
                        class="mb-5 flex items-center gap-2 text-xs font-bold tracking-[0.15em] text-[#0052A3] uppercase"
                    >
                        <span class="size-2 rounded-full bg-[#FFC107]"></span
                        >Less new. More you.
                    </p>
                    <h1
                        class="max-w-lg text-[32px] leading-[1.12] font-bold tracking-tight text-[#243B53] sm:text-[42px] lg:text-[54px]"
                    >
                        Great finds.<br />Fresh beginnings.
                    </h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-[#526477]">
                        Find pre-loved furniture, phones and everyday essentials
                        from people across Pakistan.
                    </p>
                    <Link
                        href="/search"
                        class="mt-7 inline-flex w-fit items-center gap-3 rounded-xl bg-primary px-5 py-3.5 text-sm font-semibold text-white hover:bg-[#0052A3]"
                        >Explore pre-owned finds<ArrowRight class="size-4"
                    /></Link>
                    <p class="mt-6 text-xs text-[#526477]">
                        Good Things Deserve a Second Home.
                    </p>
                </div>
                <Link
                    href="/category/furniture"
                    class="group relative block min-h-56 overflow-hidden sm:min-h-72 lg:min-h-[410px]"
                    ><img
                        src="/images/editorial/living-room.webp"
                        srcset="
                            /images/editorial/living-room-600.webp  600w,
                            /images/editorial/living-room.webp     1000w
                        "
                        sizes="(min-width: 1280px) 580px, (min-width: 768px) 45vw, calc(100vw - 40px)"
                        alt="Leather sofa and wooden furniture in a sunlit living room"
                        width="1000"
                        height="750"
                        fetchpriority="high"
                        class="absolute h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                    <div
                        class="absolute right-5 bottom-5 left-5 flex items-center justify-between rounded-2xl bg-white/95 p-5 text-[#2C3E50] backdrop-blur-sm"
                    >
                        <div>
                            <p
                                class="text-xs font-semibold tracking-wide text-[#526477] uppercase"
                            >
                                Room for something different
                            </p>
                            <p class="mt-1 text-xl font-semibold">
                                Make yourself at home.
                            </p>
                        </div>
                        <ArrowUpRight class="size-6 shrink-0" /></div
                ></Link>
            </section>
            <section class="mt-10">
                <div class="mb-5 flex items-end justify-between gap-3">
                    <div>
                        <p
                            class="mb-1 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            A little of everything
                        </p>
                        <h2 class="text-2xl font-bold tracking-tight">
                            Browse categories
                        </h2>
                    </div>
                    <Link
                        href="/search"
                        class="shrink-0 text-sm font-semibold whitespace-nowrap text-primary hover:underline"
                        >View all →</Link
                    >
                </div>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:gap-3">
                    <Link
                        v-for="category in categories"
                        :key="category.id"
                        :href="'/category/' + category.slug"
                        class="group flex flex-col items-center gap-2 rounded-xl border border-transparent bg-card px-2 py-4 text-center transition hover:border-primary/30 hover:shadow-sm lg:flex-row lg:gap-3 lg:px-4 lg:text-left"
                        ><span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-primary"
                            ><component
                                :is="icons[category.slug] || Package"
                                class="size-5"
                                :stroke-width="1.6" /></span
                        ><span class="text-sm leading-5 font-semibold">{{
                            category.name
                        }}</span></Link
                    >
                </div>
            </section>
            <section class="mt-10 grid gap-5 md:grid-cols-2">
                <Link
                    href="/category/electronics"
                    class="group relative flex min-h-56 overflow-hidden rounded-2xl bg-[#243B53]"
                    ><img
                        src="/images/editorial/everyday-tech.webp"
                        srcset="
                            /images/editorial/everyday-tech-600.webp 600w,
                            /images/editorial/everyday-tech.webp     800w
                        "
                        sizes="(min-width: 1280px) 600px, (min-width: 768px) 45vw, calc(100vw - 40px)"
                        alt="Camera, headphones and everyday tech accessories"
                        width="800"
                        height="600"
                        loading="lazy"
                        class="absolute h-full w-full object-cover opacity-45 transition group-hover:opacity-60" />
                    <div
                        class="relative flex flex-col justify-end p-7 text-white"
                    >
                        <p
                            class="text-xs font-semibold tracking-widest uppercase"
                        >
                            Upgrade your everyday
                        </p>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight">
                            Big possibilities.<br />Smaller price tags.
                        </h2>
                        <span
                            class="mt-4 inline-flex items-center gap-2 text-sm font-semibold"
                            >Explore electronics <ArrowRight class="size-4"
                        /></span></div
                ></Link>
                <div
                    class="flex flex-col justify-center rounded-2xl border bg-[#FFF6DD] p-7 sm:p-8"
                >
                    <p
                        class="text-xs font-semibold tracking-widest text-[#70530A] uppercase"
                    >
                        Someone's looking for it
                    </p>
                    <h2
                        class="mt-3 text-3xl font-semibold tracking-tight text-[#2C3E50]"
                    >
                        Your unused.<br />Their next favourite.
                    </h2>
                    <p class="mt-3 max-w-sm text-sm leading-6 text-[#526477]">
                        Clear a little space. Take a few photos, tell the story,
                        and give your things another chapter.
                    </p>
                    <Link
                        href="/sell"
                        class="mt-5 inline-flex w-fit items-center gap-3 text-sm font-bold text-[#2C3E50]"
                        >Post your first ad<ArrowUpRight class="size-5"
                    /></Link>
                </div>
            </section>
            <section class="mt-10">
                <div class="mb-5 flex items-end justify-between">
                    <div>
                        <p
                            class="mb-1 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Discover your next favourite
                        </p>
                        <h2 class="text-2xl font-bold tracking-tight">
                            Recently added
                        </h2>
                    </div>
                    <Link
                        href="/search"
                        class="shrink-0 text-sm font-semibold whitespace-nowrap text-primary hover:underline"
                        >Browse all →</Link
                    >
                </div>
                <div
                    v-if="listings.length"
                    class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <ListingCard
                        v-for="listing in listings"
                        :key="listing.id"
                        :listing="listing"
                    />
                </div>
                <div
                    v-else
                    class="flex flex-wrap items-center justify-between gap-5 rounded-2xl border bg-card p-7"
                >
                    <div class="flex items-center gap-4">
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent text-primary"
                            ><Package class="size-6"
                        /></span>
                        <div>
                            <h3 class="font-semibold">
                                Every great find starts with someone's first ad.
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                No available items yet. Be the first to give
                                something a second home.
                            </p>
                        </div>
                    </div>
                    <Link
                        href="/sell"
                        class="shrink-0 text-sm font-bold text-primary"
                        >List an item →</Link
                    >
                </div>
            </section>
            <section class="mt-10 rounded-2xl border bg-card p-6 sm:p-8">
                <div class="flex items-center gap-3">
                    <MapPin class="size-5 text-primary" />
                    <h2 class="text-xl font-bold">
                        Good finds, closer to home.
                    </h2>
                </div>
                <div class="mt-5 flex flex-wrap gap-2">
                    <Link
                        v-for="city in cities"
                        :key="city.slug"
                        :href="'/browse/' + city.slug"
                        class="inline-flex min-h-11 items-center rounded-full border px-4 py-2 text-sm hover:border-primary hover:text-primary"
                        >{{ city.name }}</Link
                    >
                </div>
            </section>
            <div class="mt-8 grid gap-5 text-sm sm:grid-cols-3">
                <div class="flex gap-3">
                    <Camera class="mt-1 size-5 shrink-0 text-primary" />
                    <div>
                        <h3 class="font-semibold">See the details</h3>
                        <p class="mt-1 leading-6 text-muted-foreground">
                            Check photos, condition and item information.
                        </p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <MessageCircle class="mt-1 size-5 shrink-0 text-primary" />
                    <div>
                        <h3 class="font-semibold">Talk before you decide</h3>
                        <p class="mt-1 leading-6 text-muted-foreground">
                            Ask the seller questions through private chat.
                        </p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <MapPin class="mt-1 size-5 shrink-0 text-primary" />
                    <div>
                        <h3 class="font-semibold">Meet with care</h3>
                        <p class="mt-1 leading-6 text-muted-foreground">
                            Choose a public place and inspect before paying.
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </MarketplaceLayout>
</template>
