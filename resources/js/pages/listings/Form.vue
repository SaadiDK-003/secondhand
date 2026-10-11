<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import AttributeFields from '@/components/listings/AttributeFields.vue';
import PhotoInput from '@/components/listings/PhotoInput.vue';
import type {
    AttributeValue,
    Category,
    ListingDetail,
    Province,
} from '@/types/marketplace';
const props = defineProps<{
    listing: ListingDetail | null;
    categories: Category[];
    provinces: Province[];
    conditions: { value: string; label: string }[];
}>();
const form = useForm({
    category_id: props.listing?.category_id ?? '',
    title: props.listing?.title ?? '',
    description: props.listing?.description ?? '',
    price: props.listing?.price ?? '',
    condition: props.listing?.condition ?? 'good',
    is_negotiable: props.listing?.is_negotiable ?? false,
    province_id: props.listing?.province_id ?? '',
    city_id: props.listing?.city_id ?? '',
    area_id: props.listing?.area_id ?? '',
    attributes:
        props.listing?.attributes ?? ({} as Record<string, AttributeValue>),
    images: [] as File[],
    remove_image_ids: [] as number[],
});
const initialCategory = props.categories.find(
    (category) => category.id === props.listing?.category_id,
);
const parentId = ref<number | ''>(
    initialCategory?.parent_id ?? initialCategory?.id ?? '',
);
const roots = computed(() =>
    props.categories.filter((category) => category.parent_id === null),
);
const children = computed(() =>
    props.categories.filter(
        (category) => category.parent_id === Number(parentId.value),
    ),
);
const selectedCategory = computed(() =>
    props.categories.find(
        (category) => category.id === Number(form.category_id),
    ),
);
const cities = computed(
    () =>
        props.provinces.find(
            (province) => province.id === Number(form.province_id),
        )?.cities ?? [],
);
const areas = computed(
    () =>
        cities.value.find((city) => city.id === Number(form.city_id))?.areas ??
        [],
);
const retainedPhotos = computed(
    () =>
        props.listing?.images.filter(
            (image) => !form.remove_image_ids.includes(image.id),
        ) ?? [],
);
const errors = computed(() => form.errors as Record<string, string>);
const errorSummary = ref<HTMLElement | null>(null);
function focusErrors() {
    void nextTick(() => errorSummary.value?.focus());
}
watch(parentId, () => {
    form.category_id = children.value.length ? '' : parentId.value;
});
watch(
    () => form.category_id,
    () => {
        form.attributes = {};
    },
);
watch(
    () => form.province_id,
    () => {
        form.city_id = '';
        form.area_id = '';
    },
);
watch(
    () => form.city_id,
    () => {
        form.area_id = '';
    },
);
function save() {
    if (props.listing) {
        form.transform((data) => ({ ...data, _method: 'patch' })).post(
            '/listings/' + props.listing.id,
            { forceFormData: true, onError: focusErrors },
        );
    } else {
        form.post('/listings', { forceFormData: true, onError: focusErrors });
    }
}
</script>
<template>
    <Head :title="listing ? 'Edit your ad' : 'Sell an item'" />
    <main class="mx-auto w-full max-w-4xl px-5 py-8 lg:px-8">
        <Link
            href="/my-listings"
            class="text-sm text-muted-foreground hover:underline"
            >← My listings</Link
        >
        <h1 class="mt-5 text-3xl font-bold tracking-tight sm:text-4xl">
            {{ listing ? 'Edit your ad' : 'Give it a second home' }}
        </h1>
        <p class="mt-3 text-muted-foreground">
            Tell buyers about your used item. You’ll preview your ad before
            submitting it.
        </p>
        <ol
            class="mt-6 grid grid-cols-3 gap-2 text-sm"
            aria-label="Ad posting steps"
        >
            <li class="rounded-xl bg-accent px-3 py-3 font-medium text-primary">
                1. Item details
            </li>
            <li class="rounded-xl bg-accent px-3 py-3 font-medium text-primary">
                2. Photos
            </li>
            <li class="rounded-xl bg-accent px-3 py-3 font-medium text-primary">
                3. Location
            </li>
        </ol>
        <p
            v-if="listing?.status === 'published'"
            class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900"
        >
            Saving changes will take this ad offline until it is reviewed again.
        </p>
        <form class="mt-8 space-y-8" @submit.prevent="save">
            <div
                v-if="Object.keys(errors).length"
                ref="errorSummary"
                tabindex="-1"
                role="alert"
                class="rounded-lg border border-red-300 p-4 text-sm text-red-600"
            >
                <p class="font-medium">Please check the following:</p>
                <ul class="mt-2 list-disc pl-5">
                    <li v-for="(error, key) in errors" :key="key">
                        {{ error }}
                    </li>
                </ul>
            </div>
            <section class="space-y-5 rounded-2xl border bg-card p-5 sm:p-8">
                <h2 class="text-xl font-semibold">1. What are you selling?</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="category" class="field-label"
                            >Category *</label
                        ><select
                            id="category"
                            v-model="parentId"
                            required
                            class="field"
                        >
                            <option value="">Choose a category</option>
                            <option
                                v-for="category in roots"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                    <div v-if="children.length">
                        <label for="subcategory" class="field-label"
                            >Subcategory *</label
                        ><select
                            id="subcategory"
                            v-model="form.category_id"
                            required
                            class="field"
                        >
                            <option value="">Choose a subcategory</option>
                            <option
                                v-for="category in children"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                </div>
                <AttributeFields
                    v-if="selectedCategory"
                    v-model="form.attributes"
                    :attributes="selectedCategory.attributes"
                    :errors="errors"
                />
                <div>
                    <label for="title" class="field-label">Ad title *</label
                    ><input
                        id="title"
                        v-model="form.title"
                        required
                        minlength="5"
                        maxlength="160"
                        class="field"
                        placeholder="e.g. Three-seater sofa in good condition"
                    />
                </div>
                <div>
                    <label for="description" class="field-label"
                        >Description *</label
                    ><textarea
                        id="description"
                        v-model="form.description"
                        required
                        minlength="20"
                        maxlength="10000"
                        rows="5"
                        class="field"
                        placeholder="Describe its condition, age and any wear or faults. Avoid sharing your phone number here."
                    ></textarea>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="price" class="field-label"
                            >Price (Rs) *</label
                        ><input
                            id="price"
                            v-model="form.price"
                            type="number"
                            min="0"
                            max="9999999999.99"
                            step="0.01"
                            required
                            class="field"
                        />
                    </div>
                    <div>
                        <label for="condition" class="field-label"
                            >Condition *</label
                        ><select
                            id="condition"
                            v-model="form.condition"
                            class="field"
                            required
                        >
                            <option
                                v-for="condition in conditions"
                                :key="condition.value"
                                :value="condition.value"
                            >
                                {{ condition.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="form.is_negotiable" type="checkbox" />Price
                    is negotiable</label
                >
            </section>
            <section class="space-y-5 rounded-2xl border bg-card p-5 sm:p-8">
                <h2 class="text-xl font-semibold">2. Add clear photos</h2>
                <p class="text-sm text-muted-foreground">
                    {{ retainedPhotos.length + form.images.length }} of 12
                    photos. The first photo is your cover.
                </p>
                <div
                    v-if="retainedPhotos.length"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
                >
                    <div
                        v-for="(image, index) in retainedPhotos"
                        :key="image.id"
                        class="overflow-hidden rounded-lg border"
                    >
                        <img
                            v-if="image.card_url"
                            :src="image.card_url"
                            :alt="'Existing photo ' + (index + 1)"
                            class="aspect-[4/3] w-full object-cover"
                        />
                        <p v-else class="p-6 text-sm">
                            {{
                                image.status === 'failed'
                                    ? 'Photo failed. Replace it.'
                                    : 'Processing photo…'
                            }}
                        </p>
                        <button
                            type="button"
                            class="w-full p-2 text-sm text-red-600"
                            @click="form.remove_image_ids.push(image.id)"
                        >
                            Remove photo {{ index + 1 }}
                        </button>
                    </div>
                </div>
                <PhotoInput
                    v-model="form.images"
                    :max-files="12 - retainedPhotos.length"
                />
            </section>
            <section class="space-y-5 rounded-2xl border bg-card p-5 sm:p-8">
                <h2 class="text-xl font-semibold">3. Where is your item?</h2>
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="province" class="field-label"
                            >Province / territory *</label
                        ><select
                            id="province"
                            v-model="form.province_id"
                            required
                            class="field"
                        >
                            <option value="">Choose province</option>
                            <option
                                v-for="province in provinces"
                                :key="province.id"
                                :value="province.id"
                            >
                                {{ province.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="city" class="field-label">City *</label
                        ><select
                            id="city"
                            v-model="form.city_id"
                            :disabled="!form.province_id"
                            required
                            class="field"
                        >
                            <option value="">Choose city</option>
                            <option
                                v-for="city in cities"
                                :key="city.id"
                                :value="city.id"
                            >
                                {{ city.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="area" class="field-label"
                            >Area (optional)</label
                        ><select
                            id="area"
                            v-model="form.area_id"
                            :disabled="!areas.length"
                            class="field"
                        >
                            <option value="">
                                {{
                                    areas.length
                                        ? 'Choose area'
                                        : 'No areas available yet'
                                }}
                            </option>
                            <option
                                v-for="area in areas"
                                :key="area.id"
                                :value="area.id"
                            >
                                {{ area.name }}
                            </option>
                        </select>
                    </div>
                </div>
            </section>
            <div
                class="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-5"
            >
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-primary px-6 py-3 font-semibold text-white hover:bg-[#0052A3] disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save & preview' }}</button
                ><span v-if="form.progress" role="status" class="text-sm"
                    >Uploading {{ form.progress.percentage }}%</span
                ><Link href="/my-listings" class="text-sm underline"
                    >Cancel</Link
                >
            </div>
            <p class="text-sm text-muted-foreground">
                Saving creates a private preview. Your ad goes live after you
                submit it and it passes review.
            </p>
        </form>
    </main>
</template>
