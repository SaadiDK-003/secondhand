<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useVisiblePoll } from '@/composables/useVisiblePoll';
import ReportForm from '@/components/listings/ReportForm.vue';
import { computed } from 'vue';
import ResultPagination from '@/components/listings/ResultPagination.vue';
import type { Page, Message } from '@/types/messaging';
const props = defineProps<{
    conversation: {
        id: number;
        title: string;
        listing_url: string | null;
        other: { id: number; name: string };
    };
    messages: Page<Message>;
    blocked: boolean;
    blockedByMe: boolean;
}>();
const form = useForm({ body: '' });
const blockForm = useForm({});
const ordered = computed(() => [...props.messages.data].reverse());
useVisiblePoll(2000, ['messages', 'blocked', 'blockedByMe', 'messaging']);
function send() {
    form.post('/messages/' + props.conversation.id, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function toggleBlock() {
    const url = '/blocked-users/' + props.conversation.other.id;
    if (props.blockedByMe) blockForm.delete(url, { preserveScroll: true });
    else blockForm.put(url, { preserveScroll: true });
}
</script>
<template>
    <Head :title="'Chat with ' + conversation.other.name"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <main class="mx-auto max-w-3xl px-5 py-8">
        <Link href="/messages" class="text-sm underline">All messages</Link>
        <div
            class="mt-5 flex flex-wrap items-start justify-between gap-4 rounded-2xl border bg-card p-5"
        >
            <div class="w-full min-w-0 sm:w-auto sm:flex-1">
                <h1 class="text-2xl font-semibold break-words">
                    {{ conversation.other.name }}
                </h1>
                <Link
                    v-if="conversation.listing_url"
                    :href="conversation.listing_url"
                    class="mt-1 inline-block text-sm break-words text-primary underline"
                    >{{ conversation.title }}</Link
                >
                <p v-else class="mt-1 text-sm text-muted-foreground">
                    {{ conversation.title }} · Ad unavailable
                </p>
            </div>
            <button
                class="rounded-lg border px-4 py-2 text-sm"
                :disabled="blockForm.processing"
                @click="toggleBlock"
            >
                {{ blockedByMe ? 'Unblock user' : 'Block user' }}
            </button>
        </div>
        <p class="mt-5 rounded-xl bg-accent p-4 text-sm text-muted-foreground">
            Keep your conversation here. Never share passwords or verification
            codes, and inspect the item before paying.
        </p>
        <ol
            aria-label="Conversation messages"
            aria-live="polite"
            aria-relevant="additions"
            class="my-6 min-h-48 space-y-4"
        >
            <li
                v-for="message in ordered"
                :key="message.id"
                class="flex"
                :class="message.mine ? 'justify-end' : 'justify-start'"
            >
                <div
                    class="max-w-[90%] min-w-0 rounded-2xl border px-4 py-3 sm:max-w-[85%]"
                    :class="message.mine ? 'bg-primary text-white' : 'bg-card'"
                >
                    <span class="sr-only"
                        >{{ message.mine ? 'You' : conversation.other.name }}:
                    </span>
                    <p class="[overflow-wrap:anywhere] whitespace-pre-wrap">
                        {{ message.body }}
                    </p>
                    <time class="mt-2 block text-xs">{{
                        new Date(message.created_at).toLocaleString('en-PK', {
                            timeZone: 'Asia/Karachi',
                        })
                    }}</time>
                </div>
            </li>
        </ol>
        <ResultPagination
            :page="messages.current_page"
            :last-page="messages.last_page"
            :previous="messages.prev_page_url"
            :next="messages.next_page_url"
        />
        <p v-if="messages.current_page > 1" class="my-4 text-sm">
            <Link :href="'/messages/' + conversation.id" class="underline"
                >Back to latest messages</Link
            >
        </p>
        <p v-if="blocked" role="status" class="mt-6 rounded-xl border p-5">
            {{
                blockedByMe
                    ? 'You blocked this user. Unblock them to allow messages again.'
                    : 'Messaging is unavailable between these accounts.'
            }}
        </p>
        <form
            v-else
            class="mt-6 rounded-2xl border bg-card p-5"
            @submit.prevent="send"
        >
            <label for="message-body" class="field-label">Your message</label
            ><textarea
                id="message-body"
                v-model="form.body"
                required
                maxlength="2000"
                rows="3"
                class="field resize-y"
                placeholder="Ask about the item or arrange a viewing…"
            />
            <p
                v-if="form.errors.body"
                role="alert"
                class="mt-2 text-sm text-red-600"
            >
                {{ form.errors.body }}
            </p>
            <div class="mt-3 flex items-center justify-between gap-3">
                <span class="text-xs text-muted-foreground"
                    >{{ form.body.length }} / 2,000</span
                ><button
                    :disabled="form.processing || !form.body.trim()"
                    class="rounded-lg bg-primary px-5 py-3 font-semibold text-white disabled:opacity-50"
                >
                    {{ form.processing ? 'Sending…' : 'Send message' }}
                </button>
            </div>
        </form>
        <ReportForm
            :url="'/users/' + conversation.other.id + '/report'"
            label="Report user"
        />
    </main>
</template>
