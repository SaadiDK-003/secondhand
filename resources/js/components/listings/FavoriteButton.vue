<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Heart } from '@lucide/vue';
import { ref } from 'vue';
const props = defineProps<{ listingId: number; saved: boolean }>();
const page = usePage();
const busy = ref(false);
function toggle() {
    if (!page.props.auth.user) {
        router.visit('/login');
        return;
    }
    busy.value = true;
    router.visit('/favorites/' + props.listingId, {
        method: props.saved ? 'delete' : 'put',
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            busy.value = false;
        },
    });
}
</script>
<template>
    <button
        type="button"
        :disabled="busy"
        :aria-label="saved ? 'Remove from favorites' : 'Save to favorites'"
        :aria-pressed="saved"
        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full border bg-background p-2 text-primary disabled:opacity-50"
        @click="toggle"
    >
        <Heart class="size-5" :class="{ 'fill-current': saved }" />
    </button>
</template>
