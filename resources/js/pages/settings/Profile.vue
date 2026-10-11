<script setup lang="ts">
import { Form, Head, usePage, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
defineProps<{ photoUrl: string | null }>();
const photo = useForm<{ photo: File | null }>({ photo: null });
const removal = useForm({});
const photoInput = ref<HTMLInputElement | null>(null);
function photoSaved() {
    photo.reset();
    if (photoInput.value) photoInput.value.value = '';
}
function selectPhoto(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    photo.clearErrors('photo');
    photo.photo = null;
    if (
        file &&
        (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ||
            !/\.(jpe?g|png|webp)$/i.test(file.name) ||
            file.size > 2 * 1024 * 1024)
    ) {
        photo.setError(
            'photo',
            'Choose a JPG, PNG or WebP photo no larger than 2 MB.',
        );
        input.value = '';
        return;
    }
    photo.photo = file;
}
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <section class="space-y-4 border-b pb-6">
            <Heading
                variant="small"
                title="Profile photo"
                description="Your photo appears on your public seller profile. Images are cropped to a square."
            />
            <img
                v-if="photoUrl"
                :src="photoUrl"
                alt="Your current profile photo"
                width="96"
                height="96"
                class="size-24 rounded-full object-cover"
            />
            <form
                class="space-y-3"
                @submit.prevent="
                    photo.post('/settings/profile/photo', {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: photoSaved,
                    })
                "
            >
                <Label for="profile-photo">Choose a photo</Label>
                <input
                    id="profile-photo"
                    ref="photoInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="block w-full text-sm"
                    :aria-invalid="!!photo.errors.photo"
                    aria-describedby="profile-photo-help profile-photo-error"
                    @change="selectPhoto"
                />
                <p
                    id="profile-photo-help"
                    class="text-sm text-muted-foreground"
                >
                    JPEG, PNG or WebP. Up to 2 MB and 2,000 × 2,000 pixels; at
                    least 64 × 64.
                </p>
                <InputError
                    id="profile-photo-error"
                    :message="photo.errors.photo"
                />
                <Button
                    :disabled="
                        !photo.photo || photo.processing || removal.processing
                    "
                    >{{
                        photo.processing ? 'Uploading…' : 'Save photo'
                    }}</Button
                >
                <p
                    v-if="photo.recentlySuccessful"
                    role="status"
                    class="text-sm"
                >
                    Photo updated.
                </p>
            </form>
            <Button
                v-if="photoUrl"
                variant="outline"
                :disabled="photo.processing || removal.processing"
                @click="
                    removal.delete('/settings/profile/photo', {
                        preserveScroll: true,
                    })
                "
                >Remove photo</Button
            >
        </section>
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    maxlength="255"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    maxlength="255"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
