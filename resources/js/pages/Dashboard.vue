<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { edit } from '@/routes/profile';
defineOptions({
    layout: { breadcrumbs: [{ title: 'My account', href: dashboard() }] },
});
</script>
<template>
    <Head title="My account" />
    <main class="mx-auto max-w-6xl px-5 py-8 lg:px-8">
        <Link
            href="/sell"
            class="mb-6 inline-flex rounded-lg bg-primary px-5 py-3 font-semibold text-white"
            >Sell an item</Link
        >
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
            Welcome to Second Hand
        </h1>
        <p class="mt-4 max-w-xl text-muted-foreground">
            Your account is ready. Post a used item, add photos, and preview
            your ad before submitting it for review.
        </p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <Link
                href="/my-listings"
                class="rounded-2xl border bg-card p-6 hover:border-primary/40"
                ><h2 class="text-lg font-semibold">Your listings</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Preview, edit and manage your ads.
                </p></Link
            >
            <Link
                href="/favorites"
                class="rounded-2xl border bg-card p-6 hover:border-primary/40"
                ><h2 class="text-lg font-semibold">Saved finds</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Pick up where you left off.
                </p></Link
            >
            <Link
                href="/messages"
                class="rounded-2xl border bg-card p-6 hover:border-primary/40"
                ><h2 class="text-lg font-semibold">Messages</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Keep your buying and selling conversations together.
                </p></Link
            >
            <Link
                :href="edit()"
                class="rounded-2xl border bg-card p-6 hover:border-primary/40"
                ><h2 class="text-lg font-semibold">Manage your profile</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Update your name, photo and account settings.
                </p></Link
            >
        </div>
    </main>
</template>
