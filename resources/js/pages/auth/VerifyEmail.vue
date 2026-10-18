<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        title: 'Activate your email',
        description: 'Confirm your email address before using your account.',
    },
});

const props = defineProps<{
    email: string;
    expiresAt: string | null;
    registrationExpiresAt: string | null;
    resendAvailableAt: string | null;
    serverNow: string;
    status?: string;
}>();

const activation = useForm({ code: '' });
const resend = useForm({ resend: '', code: '' });
const localNow = ref(Date.now());
const serverOffset = computed(() => Date.parse(props.serverNow) - Date.now());
const now = computed(() => localNow.value + serverOffset.value);
const secondsUntilResend = computed(() =>
    props.resendAvailableAt
        ? Math.max(
              0,
              Math.ceil(
                  (Date.parse(props.resendAvailableAt) - now.value) / 1000,
              ),
          )
        : 0,
);
const countdown = computed(
    () =>
        `${Math.floor(secondsUntilResend.value / 60)}:${String(secondsUntilResend.value % 60).padStart(2, '0')}`,
);
const registrationExpired = computed(
    () =>
        !!props.registrationExpiresAt &&
        Date.parse(props.registrationExpiresAt) <= now.value,
);
const codeExpired = computed(
    () => !!props.expiresAt && Date.parse(props.expiresAt) <= now.value,
);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => (localNow.value = Date.now()), 1000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head title="Activate your email" />

    <div class="space-y-6">
        <p class="text-sm text-muted-foreground">
            Enter the six-digit code sent to
            <strong class="break-all text-foreground">{{ email }}</strong
            >. Check your spam folder if it hasn't arrived.
        </p>
        <p v-if="status" role="status" class="text-sm text-green-700">
            {{ status }}
        </p>
        <div v-if="registrationExpired" role="alert" class="space-y-3 text-sm">
            <p>
                Your registration has expired. Please register again to receive
                a new code.
            </p>
            <Link href="/register" class="font-medium text-primary underline"
                >Register again</Link
            >
        </div>
        <template v-else>
            <form
                class="space-y-4"
                @submit.prevent="
                    activation.post('/email/verify', {
                        onError: () => activation.reset('code'),
                    })
                "
            >
                <div class="grid gap-2">
                    <Label for="activation-code">Activation code</Label>
                    <Input
                        id="activation-code"
                        v-model="activation.code"
                        name="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9]{6}"
                        minlength="6"
                        maxlength="6"
                        placeholder="123456"
                        required
                        v-focus
                        :aria-invalid="!!activation.errors.code"
                        aria-describedby="activation-code-help activation-code-error"
                    />
                    <p
                        id="activation-code-help"
                        class="text-sm text-muted-foreground"
                    >
                        Use the latest code. Codes are valid for up to one hour.
                    </p>
                    <InputError
                        id="activation-code-error"
                        :message="activation.errors.code"
                    />
                </div>
                <p
                    v-if="codeExpired"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    This code has expired. Request a new code below.
                </p>
                <Button
                    class="w-full"
                    :disabled="activation.processing || codeExpired"
                >
                    {{
                        activation.processing
                            ? 'Activating…'
                            : 'Activate account'
                    }}
                </Button>
            </form>
            <div class="space-y-2">
                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    :disabled="resend.processing || secondsUntilResend > 0"
                    @click="
                        resend.post('/email/verification-notification', {
                            preserveScroll: true,
                        })
                    "
                >
                    {{
                        resend.processing
                            ? 'Sending…'
                            : secondsUntilResend > 0
                              ? `Resend code in ${countdown}`
                              : 'Send a new activation code'
                    }}
                </Button>
                <InputError :message="resend.errors.resend" />
                <InputError :message="resend.errors.code" />
            </div>
            <p
                v-if="registrationExpiresAt"
                class="text-sm text-muted-foreground"
            >
                Activate within one hour of registration. Unactivated
                registrations are automatically deleted after that deadline.
                Resending does not extend it.
            </p>
        </template>
        <Link
            href="/logout"
            method="post"
            as="button"
            class="block w-full text-center text-sm text-primary underline underline-offset-4"
        >
            Sign out
        </Link>
    </div>
</template>
