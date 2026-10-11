<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
const props = defineProps<{ url: string; label: string }>();
const page = usePage();
const open = ref(false);
const received = ref(false);
const form = useForm({ reason: 'scam', details: '' });
const id = useId();
function send() {
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            received.value = true;
            open.value = false;
            form.reset();
        },
    });
}
</script>
<template>
    <div class="mt-5 text-sm">
        <p v-if="received" role="status">
            Thank you. Your report has been received for review.
        </p>
        <template v-else
            ><Link v-if="!page.props.auth.user" href="/login" class="underline"
                >Log in to {{ label.toLowerCase() }}</Link
            ><button
                v-else
                type="button"
                class="underline"
                :aria-expanded="open"
                @click="open = !open"
            >
                {{ label }}
            </button>
            <form
                v-if="open"
                class="mt-4 space-y-3 rounded-lg border p-4"
                @submit.prevent="send"
            >
                <label :for="id + '-reason'" class="block">Reason</label
                ><select
                    :id="id + '-reason'"
                    v-model="form.reason"
                    class="field mt-1"
                >
                    <option value="scam">Scam or fraud</option>
                    <option value="prohibited">Prohibited item</option>
                    <option value="misleading">Misleading information</option>
                    <option value="harassment">Harassment</option>
                    <option value="other">Other</option></select
                ><label :for="id + '-details'" class="block"
                    >Details (optional)</label
                ><textarea
                    :id="id + '-details'"
                    v-model="form.details"
                    class="field mt-1"
                    rows="3"
                    maxlength="2000"
                />
                <p
                    v-for="error in form.errors"
                    :key="error"
                    role="alert"
                    class="text-red-600"
                >
                    {{ error }}
                </p>
                <button
                    :disabled="form.processing"
                    class="rounded-lg bg-primary px-4 py-2 text-white"
                >
                    Submit report
                </button>
            </form></template
        >
    </div>
</template>
