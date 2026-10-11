<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useVisiblePoll } from '@/composables/useVisiblePoll';
import { MessageCircle } from '@lucide/vue';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type { Page } from '@/types/messaging';
defineProps<{
    conversations: Page<{
        id: number;
        title: string;
        other_name: string;
        last_message: string | null;
        unread_count: number;
        updated_at: string;
    }>;
}>();
useVisiblePoll(3000, ['conversations', 'messaging']);
</script>
<template>
    <Head title="Messages"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <main class="mx-auto max-w-3xl px-5 py-8">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
                Messages
            </h1>
            <Link href="/blocked-users" class="text-sm underline"
                >Blocked users</Link
            >
        </div>
        <p class="mt-3 text-muted-foreground">
            Talk about the item, arrange a viewing and keep your contact details
            private.
        </p>
        <div
            v-if="!conversations.data.length"
            class="mt-8 rounded-2xl border bg-card px-5 py-16 text-center"
        >
            <span
                class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-accent text-primary"
                ><MessageCircle class="size-6" aria-hidden="true"
            /></span>
            <h2 class="text-xl font-semibold">Your conversations start here</h2>
            <p class="mt-2">
                Find an item you like and send its seller a message.
            </p>
            <Link
                href="/search"
                class="mt-6 inline-block rounded-xl bg-primary px-5 py-3 font-semibold text-white"
                >Browse items</Link
            >
        </div>
        <div
            v-else
            class="mt-8 divide-y overflow-hidden rounded-2xl border bg-card"
        >
            <Link
                v-for="conversation in conversations.data"
                :key="conversation.id"
                :href="'/messages/' + conversation.id"
                class="block p-5 hover:bg-muted/50"
                ><div class="flex justify-between gap-4">
                    <h2
                        class="min-w-0 flex-1 font-semibold [overflow-wrap:anywhere]"
                    >
                        {{ conversation.other_name }}
                    </h2>
                    <span
                        v-if="conversation.unread_count"
                        class="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-semibold text-white"
                        >{{
                            conversation.unread_count > 99
                                ? '99+'
                                : conversation.unread_count
                        }}<span class="sr-only"> unread messages</span></span
                    >
                    <time class="shrink-0 text-xs text-muted-foreground">{{
                        new Date(conversation.updated_at).toLocaleDateString(
                            'en-PK',
                            { timeZone: 'Asia/Karachi' },
                        )
                    }}</time>
                </div>
                <p class="mt-1 text-sm [overflow-wrap:anywhere] text-primary">
                    {{ conversation.title }}
                </p>
                <p class="mt-2 truncate text-sm text-muted-foreground">
                    {{ conversation.last_message }}
                </p></Link
            >
        </div>
        <ResultPagination
            :page="conversations.current_page"
            :last-page="conversations.last_page"
            :previous="conversations.prev_page_url"
            :next="conversations.next_page_url"
        />
    </main>
</template>
