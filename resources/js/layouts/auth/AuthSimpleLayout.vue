<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <main
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        aria-label="Second Hand home"
                        class="mb-3 flex items-center gap-3 font-medium"
                    >
                        <div
                            class="flex size-12 items-center justify-center rounded-2xl bg-primary text-white"
                        >
                            <AppLogoIcon class="size-8" />
                        </div>
                        <span class="text-3xl font-extrabold tracking-tight"
                            ><span class="text-primary">2nd</span> Hand<span
                                class="text-primary"
                                >.</span
                            ></span
                        >
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ title }}
                        </h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </main>
</template>
