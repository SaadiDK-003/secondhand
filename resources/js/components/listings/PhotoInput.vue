<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
const props = withDefaults(
    defineProps<{ modelValue: File[]; maxFiles?: number }>(),
    { maxFiles: 12 },
);
const emit = defineEmits<{ 'update:modelValue': [File[]] }>();
const previews = ref<{ name: string; url: string }[]>([]);
const error = ref('');
function clearUrls() {
    previews.value.forEach((photo) => URL.revokeObjectURL(photo.url));
}
watch(
    () => props.modelValue,
    (files) => {
        clearUrls();
        previews.value = files.map((file) => ({
            name: file.name,
            url: URL.createObjectURL(file),
        }));
    },
    { immediate: true },
);
onBeforeUnmount(clearUrls);
function choose(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    error.value = '';
    if (props.modelValue.length + files.length > props.maxFiles) {
        error.value =
            'You can add up to 12 photos in total. Remove a photo before adding more.';
    } else if (
        files.some(
            (file) =>
                !['image/jpeg', 'image/png', 'image/webp'].includes(
                    file.type,
                ) || file.size > 10 * 1024 * 1024,
        )
    ) {
        error.value =
            'Choose JPG, PNG or WebP photos no larger than 10 MB each.';
    }
    if (error.value) {
        input.value = '';
        return;
    }
    emit('update:modelValue', [...props.modelValue, ...files]);
    input.value = '';
}
</script>
<template>
    <label
        for="listing-photos"
        class="block rounded-xl border-2 border-dashed border-primary/25 bg-accent/50 p-6 text-center"
    >
        <span class="block font-medium">Add photos</span
        ><span class="mt-1 block text-sm text-muted-foreground"
            >JPG, PNG or WebP · up to 10 MB each · 1–12 photos</span
        >
        <input
            id="listing-photos"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            class="mt-4 max-w-full text-sm"
            :aria-invalid="!!error"
            aria-describedby="listing-photo-error"
            @change="choose"
        />
    </label>
    <p
        v-if="error"
        id="listing-photo-error"
        role="alert"
        class="text-sm text-destructive"
    >
        {{ error }}
    </p>
    <div v-if="previews.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div
            v-for="(photo, index) in previews"
            :key="photo.url"
            class="overflow-hidden rounded-lg border"
        >
            <img
                :src="photo.url"
                :alt="'New photo: ' + photo.name"
                class="aspect-[4/3] w-full object-cover"
            />
            <button
                type="button"
                class="w-full p-2 text-sm text-red-600"
                :aria-label="'Remove new photo ' + (index + 1)"
                @click="
                    emit(
                        'update:modelValue',
                        modelValue.filter((_, i) => i !== index),
                    )
                "
            >
                Remove
            </button>
        </div>
    </div>
</template>
