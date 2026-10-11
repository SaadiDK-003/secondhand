<script setup lang="ts">
import { Head, Link, useForm, usePoll, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { MapPin, ShieldCheck } from '@lucide/vue';
import ReportForm from '@/components/listings/ReportForm.vue';
import FavoriteButton from '@/components/listings/FavoriteButton.vue';
import type { ListingDetail } from '@/types/marketplace';
const props = defineProps<{
    listing: ListingDetail;
    preview: boolean;
    canEdit: boolean;
    canSubmit: boolean;
    canMarkSold?: boolean;
    canRenew?: boolean;
    isPublic?: boolean;
    isFavorite?: boolean;
    moderationNote?: string | null;
    canMessage?: boolean;
}>();
const page = usePage();
const breadcrumbs = computed(
    () =>
        (
            page.props.seo as
                | { breadcrumbs?: { name: string; url: string }[] }
                | undefined
        )?.breadcrumbs ?? [],
);
const message = useForm({ body: 'Is this still available?' });
const selected = ref(0);
const ready = computed(() =>
    props.listing.images.filter((image) => image.status === 'ready'),
);
const processing = computed(() =>
    props.listing.images.some((image) =>
        ['pending', 'processing'].includes(image.status),
    ),
);
const failed = computed(() =>
    props.listing.images.some((image) => image.status === 'failed'),
);
const { start, stop } = usePoll(
    3000,
    { only: ['listing'] },
    { autoStart: false },
);
watch(
    processing,
    (value) => {
        if (value && props.preview) start();
        else stop();
    },
    { immediate: true },
);
const form = useForm({});
const price = computed(() =>
    new Intl.NumberFormat('en-PK', { maximumFractionDigits: 2 }).format(
        Number(props.listing.price),
    ),
);
</script>
<template>
    <Head v-if="preview" :title="listing.title"
        ><meta
            name="description"
            :content="listing.description.slice(0, 155)" /><meta
            v-if="preview"
            name="robots"
            content="noindex, nofollow"
    /></Head>
    <main class="mx-auto max-w-7xl px-5 py-8 lg:px-8">
        <div
            v-if="preview"
            class="mb-8 space-y-3 rounded-xl border bg-muted/40 p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{
                        listing.status === 'pending'
                            ? 'Your ad is awaiting review'
                            : 'Your listing preview'
                    }}
                </h2>
                <Link href="/my-listings" class="text-sm underline"
                    >My listings</Link
                >
            </div>
            <p class="text-sm text-muted-foreground">
                {{
                    listing.status === 'pending'
                        ? 'We’ll check your ad before it appears publicly.'
                        : isPublic
                          ? 'Your ad is live.'
                          : listing.status === 'sold'
                            ? 'Marked as sold. This item is no longer visible to buyers.'
                            : canRenew
                              ? 'This ad has expired. Renew it to review the details and submit it again.'
                              : 'Your ad is private until you submit it and it is approved.'
                }}
            </p>
            <p v-if="moderationNote" class="text-sm">
                Review note: {{ moderationNote }}
            </p>
            <p v-if="processing" role="status" class="text-sm">
                We’re preparing your photos. This page refreshes automatically.
            </p>
            <p v-if="failed" role="alert" class="text-sm text-red-600">
                A photo could not be processed. Edit your ad to remove it and
                upload a replacement.
            </p>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                role="alert"
                class="text-sm text-red-600"
            >
                {{ error }}
            </p>
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="canMarkSold"
                    :disabled="form.processing"
                    class="rounded-lg border px-5 py-3 disabled:opacity-50"
                    @click="form.post('/listings/' + listing.id + '/sold')"
                >
                    Mark as sold
                </button>
                <button
                    v-if="canRenew"
                    :disabled="form.processing"
                    class="rounded-lg border px-5 py-3 disabled:opacity-50"
                    @click="form.post('/listings/' + listing.id + '/renew')"
                >
                    Renew and review ad
                </button>
                <button
                    v-if="canSubmit"
                    :disabled="
                        processing || failed || !ready.length || form.processing
                    "
                    class="rounded-lg bg-primary px-5 py-3 font-medium text-white disabled:opacity-50"
                    @click="form.post('/listings/' + listing.id + '/submit')"
                >
                    {{
                        form.processing ? 'Submitting…' : 'Submit for review'
                    }}</button
                ><Link
                    v-if="canEdit"
                    :href="'/listings/' + listing.id + '/edit'"
                    class="rounded-lg border px-5 py-3"
                    >Edit ad</Link
                >
            </div>
        </div>
        <nav aria-label="Breadcrumb" class="mb-5 text-sm text-muted-foreground">
            <template v-if="breadcrumbs.length"
                ><span v-for="(crumb, index) in breadcrumbs" :key="crumb.url"
                    ><span v-if="index"> / </span
                    ><Link
                        v-if="index < breadcrumbs.length - 1"
                        :href="crumb.url"
                        class="underline"
                        >{{ crumb.name }}</Link
                    ><span v-else aria-current="page">{{
                        crumb.name
                    }}</span></span
                ></template
            ><template v-else
                ><Link href="/" class="underline">Home</Link> /
                {{ listing.category }} / {{ listing.city }}</template
            >
        </nav>
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <section
                aria-label="Item photos"
                class="min-w-0 lg:col-start-1 lg:row-start-1"
            >
                <img
                    v-if="ready[selected]"
                    :src="ready[selected].large_url!"
                    :srcset="ready[selected].srcset ?? undefined"
                    sizes="(min-width: 1280px) 832px, (min-width: 1024px) calc(100vw - 448px), calc(100vw - 40px)"
                    width="1600"
                    height="1200"
                    decoding="async"
                    fetchpriority="high"
                    :alt="listing.title + ', photo ' + (selected + 1)"
                    class="aspect-[4/3] max-h-[580px] w-full rounded-2xl border bg-card object-contain"
                />
                <div
                    v-else
                    class="flex aspect-[4/3] items-center justify-center rounded-xl bg-muted text-muted-foreground"
                >
                    Photos are being prepared
                </div>
                <p
                    v-if="ready.length"
                    class="mt-2 text-center text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    Photo {{ selected + 1 }} of {{ ready.length }}
                </p>
                <div
                    v-if="ready.length > 1"
                    class="mt-3 flex gap-3 overflow-x-auto pb-2"
                >
                    <button
                        v-for="(image, index) in ready"
                        :key="image.id"
                        :aria-label="'Show photo ' + (index + 1)"
                        :aria-pressed="selected === index"
                        class="shrink-0 overflow-hidden rounded-lg border-2"
                        :class="
                            selected === index
                                ? 'border-primary'
                                : 'border-transparent'
                        "
                        @click="selected = index"
                    >
                        <img
                            :src="image.thumbnail_url!"
                            alt=""
                            loading="lazy"
                            class="h-20 w-24 object-cover"
                        />
                    </button>
                </div>
            </section>
            <aside
                class="row-start-2 space-y-5 lg:col-start-2 lg:row-span-3 lg:row-start-1"
            >
                <section class="rounded-2xl border bg-card p-6 shadow-sm">
                    <p
                        class="mb-3 text-xs font-semibold tracking-widest text-primary"
                    >
                        {{ listing.category }}
                    </p>
                    <div class="flex items-center justify-between gap-3">
                        <p
                            class="text-3xl font-bold tracking-tight [overflow-wrap:anywhere]"
                        >
                            Rs {{ price }}
                        </p>
                        <FavoriteButton
                            v-if="!preview"
                            :listing-id="listing.id"
                            :saved="isFavorite ?? false"
                        />
                    </div>
                    <p
                        v-if="listing.is_negotiable"
                        class="mt-1 text-sm text-primary"
                    >
                        Negotiable
                    </p>
                    <h1
                        class="mt-4 text-2xl leading-snug font-semibold break-words"
                    >
                        {{ listing.title }}
                    </h1>
                    <p
                        class="mt-5 flex items-start gap-2 border-t pt-5 text-sm text-muted-foreground"
                    >
                        <MapPin class="size-4 shrink-0" aria-hidden="true" />
                        {{
                            [listing.area, listing.city, listing.province]
                                .filter(Boolean)
                                .join(', ')
                        }}
                    </p>
                    <p
                        v-if="listing.published_at"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Posted
                        {{
                            new Date(listing.published_at).toLocaleDateString(
                                'en-PK',
                                { timeZone: 'Asia/Karachi' },
                            )
                        }}
                    </p>
                </section>
                <section class="rounded-2xl border bg-card p-6">
                    <h2 class="text-sm text-muted-foreground">Seller</h2>
                    <p class="mt-2 text-lg font-semibold">
                        <Link
                            v-if="!preview"
                            :href="listing.seller.url"
                            class="text-primary hover:underline"
                            >{{ listing.seller.name }}</Link
                        >
                        <span v-else>{{ listing.seller.name }}</span>
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Joined {{ listing.seller.joined }}
                    </p>
                    <div v-if="!preview && canMessage" class="mt-5">
                        <Link
                            v-if="!page.props.auth.user"
                            href="/login"
                            class="block rounded-lg bg-primary px-4 py-3 text-center font-semibold text-white"
                            >Log in to message seller</Link
                        >
                        <form
                            v-else
                            @submit.prevent="
                                message.post(
                                    '/listings/' + listing.id + '/messages',
                                )
                            "
                        >
                            <label for="seller-message" class="field-label"
                                >Message seller</label
                            ><textarea
                                id="seller-message"
                                v-model="message.body"
                                class="field"
                                rows="3"
                                maxlength="2000"
                                required
                            />
                            <div class="my-3 flex flex-wrap gap-2">
                                <button
                                    v-for="text in [
                                        'Is this still available?',
                                        'Final price?',
                                        'Can I see it today?',
                                    ]"
                                    :key="text"
                                    type="button"
                                    class="rounded-full border px-3 py-2 text-xs"
                                    @click="message.body = text"
                                >
                                    {{ text }}
                                </button>
                            </div>
                            <p
                                v-if="message.errors.body"
                                role="alert"
                                class="my-3 text-sm text-red-600"
                            >
                                {{ message.errors.body }}
                            </p>
                            <button
                                :disabled="
                                    message.processing || !message.body.trim()
                                "
                                class="w-full rounded-lg bg-primary px-4 py-3 font-semibold text-white disabled:opacity-50"
                            >
                                {{
                                    message.processing
                                        ? 'Sending…'
                                        : 'Send message'
                                }}
                            </button>
                        </form>
                    </div>
                </section>
                <section
                    class="rounded-2xl border border-primary/15 bg-accent p-5"
                >
                    <h2 class="flex items-center gap-2 font-semibold">
                        <ShieldCheck
                            class="size-5 text-primary"
                            aria-hidden="true"
                        />
                        A safer second-hand deal
                    </h2>
                    <p
                        class="mt-3 text-sm leading-relaxed text-muted-foreground"
                    >
                        Meet in a public place, inspect the item and pay only
                        when you’re happy. Never share a verification code or
                        send an advance payment.
                    </p>
                </section>
                <ReportForm
                    v-if="!preview && canMessage"
                    :url="'/listings/' + listing.id + '/report'"
                    label="Report listing"
                />
            </aside>
            <section
                class="rounded-2xl border bg-card p-6 sm:p-8 lg:col-start-1 lg:row-start-2"
            >
                <h2 class="text-xl font-semibold">About this item</h2>
                <p class="mt-4 leading-relaxed break-words whitespace-pre-wrap">
                    {{ listing.description }}
                </p>
            </section>
            <section
                class="rounded-2xl border bg-card p-6 sm:p-8 lg:col-start-1 lg:row-start-3"
            >
                <h2 class="text-xl font-semibold">Item details</h2>
                <dl
                    class="mt-5 grid gap-5 rounded-xl bg-muted/40 p-5 sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-sm text-muted-foreground">Condition</dt>
                        <dd class="mt-1 capitalize">
                            {{ listing.condition.replaceAll('_', ' ') }}
                        </dd>
                    </div>
                    <div v-for="detail in listing.details" :key="detail.name">
                        <dt class="text-sm text-muted-foreground">
                            {{ detail.name }}
                        </dt>
                        <dd class="mt-1 break-words">{{ detail.value }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </main>
</template>
