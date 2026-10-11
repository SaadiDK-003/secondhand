import { router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import type { InboxStatus } from '@/types/messaging';

export function useMessageInbox() {
    const page = usePage();
    const inbox = ref<InboxStatus | null>(page.props.messaging);
    let latestSeen = page.props.messaging?.latest?.id ?? 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let request: AbortController | undefined;
    let mounted = false;

    function update(status: InboxStatus | null) {
        inbox.value = status;
        const latest = status?.latest;
        if (latest && latest.id > latestSeen) {
            latestSeen = latest.id;
            const currentConversation = page.url.split('?')[0];
            const historyPage = new URLSearchParams(page.url.split('?')[1]).get(
                'page',
            );
            if (
                currentConversation !== `/messages/${latest.conversation_id}` ||
                (historyPage && Number(historyPage) > 1)
            ) {
                toast.info(`New message from ${latest.sender_name}`, {
                    id: `message-${latest.id}`,
                    duration: 6000,
                    action: {
                        label: 'Open chat',
                        onClick: () =>
                            router.visit(`/messages/${latest.conversation_id}`),
                    },
                });
            }
        }
    }

    watch(
        () => page.props.auth.user?.id,
        () => {
            request?.abort();
            latestSeen = page.props.messaging?.latest?.id ?? 0;
            inbox.value = page.props.messaging;
        },
    );
    watch(
        () => page.props.messaging,
        (status) => {
            request?.abort();
            update(status);
        },
    );

    async function refresh() {
        clearTimeout(timer);
        if (!mounted || document.hidden || !page.props.auth.user || request)
            return;
        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('/messages/status', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (response.ok && !controller.signal.aborted) {
                const status = (await response.json()) as InboxStatus;
                if (!controller.signal.aborted) update(status);
            }
        } catch {
            // Keep the last count during a temporary connection failure; retry on the next check.
        } finally {
            clearTimeout(timeout);
            request = undefined;
            if (mounted && !document.hidden && page.props.auth.user) {
                timer = setTimeout(refresh, 3000);
            }
        }
    }

    function resume() {
        if (document.hidden) {
            clearTimeout(timer);
            request?.abort();
        } else {
            void refresh();
        }
    }

    onMounted(() => {
        mounted = true;
        timer = setTimeout(refresh, 3000);
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('online', resume);
    });
    onUnmounted(() => {
        mounted = false;
        clearTimeout(timer);
        request?.abort();
        document.removeEventListener('visibilitychange', resume);
        window.removeEventListener('online', resume);
    });

    return inbox;
}
