<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Search, SlidersHorizontal } from '@lucide/vue';
import SearchFiltersPanel from '@/components/listings/SearchFilters.vue';
import ListingCard from '@/components/listings/ListingCard.vue';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type {
    Category,
    Province,
    SearchFilters,
    ListingResults,
} from '@/types/marketplace';
const props = defineProps<{
    filters: SearchFilters;
    listings: ListingResults;
    categories: Category[];
    provinces: Province[];
    conditions: { value: string; label: string }[];
    unavailable: boolean;
}>();
const initial = () => ({
    q: '',
    category_id: '',
    province_id: '',
    city_id: '',
    area_id: '',
    min_price: '',
    max_price: '',
    condition: '',
    sort: 'relevant',
    ...Object.fromEntries(
        Object.entries(props.filters).filter(([, value]) => value != null),
    ),
    attributes: { ...props.filters.attributes },
});
const showFilters = ref(false);
const filters = ref<SearchFilters>(initial());
const errors = ref<Record<string, string>>({});
watch(
    () => props.filters,
    () => {
        filters.value = initial();
    },
);
function search() {
    router.get(
        '/search',
        { ...filters.value, page: 1 },
        {
            preserveScroll: true,
            onError: (e) => {
                errors.value = e;
            },
            onSuccess: () => {
                errors.value = {};
            },
        },
    );
}
</script>
<template>
    <Head title="Browse used items"
        ><meta name="robots" content="noindex, follow"
    /></Head>
    <main class="mx-auto max-w-7xl px-5 py-8 lg:px-8">
        <p class="text-xs font-semibold tracking-widest text-primary">
            SECOND HAND. FIRST CHOICE.
        </p>
        <h1 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
            Find your next good thing
        </h1>
        <p class="mt-3 text-muted-foreground">
            Explore pre-owned finds. Make room for something you love.
        </p>
        <form class="mt-6" @submit.prevent="search">
            <div
                class="flex gap-2 rounded-2xl border bg-card p-2 shadow-sm focus-within:ring-2 focus-within:ring-primary"
            >
                <label for="search-query" class="sr-only"
                    >Search used items</label
                ><input
                    id="search-query"
                    v-model="filters.q"
                    type="search"
                    maxlength="120"
                    class="min-w-0 flex-1 rounded-xl bg-transparent px-3 py-3 outline-none"
                    placeholder="Search sofas, phones, laptops…"
                /><button
                    class="rounded-xl bg-primary px-5 py-3 font-semibold text-white hover:bg-[#0052A3]"
                >
                    Search
                </button>
            </div>
            <div
                v-if="Object.keys(errors).length"
                role="alert"
                class="mt-4 rounded-lg border border-red-300 p-4 text-red-700"
            >
                <p v-for="(error, key) in errors" :key="key">{{ error }}</p>
            </div>
            <div
                class="mt-8 grid items-start gap-6 lg:grid-cols-[260px_minmax(0,1fr)]"
            >
                <div>
                    <button
                        type="button"
                        class="mb-4 inline-flex items-center gap-2 rounded-xl border bg-card px-5 py-3 font-medium lg:hidden"
                        :aria-expanded="showFilters"
                        aria-controls="search-filters"
                        @click="showFilters = !showFilters"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                        Filters
                    </button>
                    <aside
                        id="search-filters"
                        class="self-start rounded-2xl border bg-card p-5 lg:block"
                        :class="{ hidden: !showFilters }"
                    >
                        <div>
                            <h2
                                class="mb-5 hidden text-lg font-semibold lg:block"
                            >
                                Filters
                            </h2>
                            <SearchFiltersPanel
                                v-model="filters"
                                :categories="categories"
                                :provinces="provinces"
                                :conditions="conditions"
                            /><Link
                                href="/search"
                                class="mt-4 block text-center text-sm underline"
                                >Clear filters</Link
                            >
                        </div>
                    </aside>
                </div>
                <section class="min-w-0">
                    <div
                        class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{
                                unavailable
                                    ? 'Search unavailable'
                                    : listings.total + ' results'
                            }}
                        </p>
                        <div class="flex items-center gap-2">
                            <label for="sort" class="text-sm">Sort</label
                            ><select
                                id="sort"
                                v-model="filters.sort"
                                class="field w-auto"
                                @change="search"
                            >
                                <option value="relevant">Most relevant</option>
                                <option value="newest">Newest</option>
                                <option value="price_asc">
                                    Price: low to high
                                </option>
                                <option value="price_desc">
                                    Price: high to low
                                </option>
                            </select>
                        </div>
                    </div>
                    <div
                        v-if="unavailable"
                        role="status"
                        class="rounded-2xl border bg-card p-8"
                    >
                        <h2 class="text-xl font-semibold">
                            Search is temporarily unavailable
                        </h2>
                        <p class="mt-2 text-muted-foreground">
                            Please try again shortly. You can still browse
                            recent items on the homepage.
                        </p>
                        <Link href="/" class="mt-4 inline-block underline"
                            >View recent items</Link
                        >
                    </div>
                    <div
                        v-else-if="!listings.data.length"
                        class="rounded-2xl border bg-card px-5 py-16 text-center"
                    >
                        <span
                            class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-accent text-primary"
                            ><Search class="size-6" aria-hidden="true"
                        /></span>
                        <h2 class="text-xl font-semibold">
                            No matching items yet
                        </h2>
                        <p class="mt-3 text-muted-foreground">
                            Try fewer filters or a different search.
                        </p>
                        <Link
                            href="/search"
                            class="mt-6 inline-block rounded-xl bg-primary px-5 py-3 font-semibold text-white"
                            >Clear filters</Link
                        >
                    </div>
                    <div
                        v-else
                        class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3"
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
                </section>
            </div>
        </form>
    </main>
</template>
