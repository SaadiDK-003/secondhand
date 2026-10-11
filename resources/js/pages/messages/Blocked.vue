<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type { Page } from '@/types/messaging';
defineProps<{ users: Page<{ id: number; name: string }> }>();
</script>
<template>
    <Head title="Blocked users"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <main class="mx-auto max-w-3xl px-5 py-8">
        <Link href="/messages" class="text-sm underline">All messages</Link>
        <h1 class="mt-5 text-3xl font-semibold">Blocked users</h1>
        <p class="mt-3 text-muted-foreground">
            Blocking stops messages in both directions. Your previous
            conversations remain available.
        </p>
        <p
            v-if="!users.data.length"
            class="mt-8 rounded-2xl border bg-card p-6"
        >
            You haven’t blocked anyone.
        </p>
        <ul v-else class="mt-8 divide-y rounded-2xl border bg-card">
            <li
                v-for="user in users.data"
                :key="user.id"
                class="flex items-center justify-between gap-4 p-5"
            >
                <span class="break-words">{{ user.name }}</span
                ><button
                    class="rounded-lg border px-4 py-2"
                    @click="
                        router.delete('/blocked-users/' + user.id, {
                            preserveScroll: true,
                        })
                    "
                >
                    Unblock
                </button>
            </li>
        </ul>
        <ResultPagination
            :page="users.current_page"
            :last-page="users.last_page"
            :previous="users.prev_page_url"
            :next="users.next_page_url"
        />
    </main>
</template>
