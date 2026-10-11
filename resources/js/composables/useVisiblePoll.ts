import { router, usePoll } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

export function useVisiblePoll(interval: number, only: string[]) {
    const { start, stop } = usePoll(interval, { only }, { autoStart: false });
    function resume() {
        if (document.hidden) stop();
        else {
            start();
            router.reload({ only });
        }
    }
    onMounted(() => {
        if (!document.hidden) start();
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('online', resume);
    });
    onUnmounted(() => {
        document.removeEventListener('visibilitychange', resume);
        window.removeEventListener('online', resume);
    });
}
