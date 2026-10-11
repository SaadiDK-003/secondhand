<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email or mobile number and password',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    demoCredentials?: {
        password: string;
        accounts: { label: string; email: string }[];
    } | null;
}>();

const email = ref('');
const password = ref('');
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        role="status"
        class="mb-4 text-center text-sm font-medium text-green-700 dark:text-green-400"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email or mobile number</Label>
                <Input
                    id="email"
                    v-model="email"
                    type="text"
                    name="email"
                    maxlength="255"
                    required
                    v-focus
                    autocomplete="username"
                    placeholder="Email or 0300 1234567"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    v-model="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Don't have an account?
            <TextLink :href="register()">Sign up</TextLink>
        </div>
    </Form>

    <section
        v-if="demoCredentials"
        aria-labelledby="demo-heading"
        class="mt-6 rounded-xl border border-primary/15 bg-primary/5 p-4 text-sm"
    >
        <h2 id="demo-heading" class="font-semibold">Try a demo account</h2>
        <p class="mt-1 text-xs text-muted-foreground">
            Available in this local preview only.
        </p>
        <div class="mt-3 grid gap-3">
            <div
                v-for="account in demoCredentials.accounts"
                :key="account.email"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium">{{ account.label }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-label="`Use ${account.label.toLowerCase()} demo account`"
                        @click="
                            email = account.email;
                            password = demoCredentials.password;
                        "
                    >
                        Use account
                    </Button>
                </div>
                <code class="mt-1 block text-xs break-all">{{
                    account.email
                }}</code>
            </div>
        </div>
        <p class="mt-3 border-t border-primary/15 pt-3 text-xs">
            Password for both:
            <code class="font-semibold">{{ demoCredentials.password }}</code>
        </p>
    </section>
</template>
