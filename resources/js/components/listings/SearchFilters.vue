<script setup lang="ts">
import { computed, watch } from 'vue';
import AttributeFields from './AttributeFields.vue';
import type { Category, Province, SearchFilters } from '@/types/marketplace';
const model = defineModel<SearchFilters>({ required: true });
const props = defineProps<{
    categories: Category[];
    provinces: Province[];
    conditions: { value: string; label: string }[];
}>();
const roots = computed(() =>
    props.categories.filter((c) => c.parent_id === null),
);
const selected = computed(() =>
    props.categories.find((c) => c.id === Number(model.value.category_id)),
);
const cities = computed(
    () =>
        props.provinces.find((p) => p.id === Number(model.value.province_id))
            ?.cities ?? [],
);
const areas = computed(
    () =>
        cities.value.find((c) => c.id === Number(model.value.city_id))?.areas ??
        [],
);
watch(
    () => model.value.category_id,
    () => {
        model.value.attributes = {};
    },
);
watch(
    () => model.value.province_id,
    () => {
        model.value.city_id = '';
        model.value.area_id = '';
    },
);
watch(
    () => model.value.city_id,
    () => {
        model.value.area_id = '';
    },
);
</script>
<template>
    <div class="space-y-5">
        <div>
            <label for="filter-category" class="field-label">Category</label
            ><select
                id="filter-category"
                v-model="model.category_id"
                class="field"
            >
                <option value="">All categories</option>
                <optgroup
                    v-for="root in roots"
                    :key="root.id"
                    :label="root.name"
                >
                    <option :value="root.id">All {{ root.name }}</option>
                    <option
                        v-for="child in categories.filter(
                            (c) => c.parent_id === root.id,
                        )"
                        :key="child.id"
                        :value="child.id"
                    >
                        {{ child.name }}
                    </option>
                </optgroup>
            </select>
        </div>
        <div>
            <label for="filter-province" class="field-label"
                >Province / territory</label
            ><select
                id="filter-province"
                v-model="model.province_id"
                class="field"
            >
                <option value="">All Pakistan</option>
                <option v-for="p in provinces" :key="p.id" :value="p.id">
                    {{ p.name }}
                </option>
            </select>
        </div>
        <div>
            <label for="filter-city" class="field-label">City</label
            ><select
                id="filter-city"
                v-model="model.city_id"
                class="field"
                :disabled="!model.province_id"
            >
                <option value="">All cities</option>
                <option v-for="city in cities" :key="city.id" :value="city.id">
                    {{ city.name }}
                </option>
            </select>
        </div>
        <div>
            <label for="filter-area" class="field-label">Area</label
            ><select
                id="filter-area"
                v-model="model.area_id"
                class="field"
                :disabled="!areas.length"
            >
                <option value="">All areas</option>
                <option v-for="area in areas" :key="area.id" :value="area.id">
                    {{ area.name }}
                </option>
            </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="min-price" class="field-label">Min price (Rs)</label
                ><input
                    id="min-price"
                    v-model="model.min_price"
                    class="field"
                    type="number"
                    min="0"
                    step="0.01"
                />
            </div>
            <div>
                <label for="max-price" class="field-label">Max price (Rs)</label
                ><input
                    id="max-price"
                    v-model="model.max_price"
                    class="field"
                    type="number"
                    min="0"
                    step="0.01"
                />
            </div>
        </div>
        <div>
            <label for="filter-condition" class="field-label">Condition</label
            ><select
                id="filter-condition"
                v-model="model.condition"
                class="field"
            >
                <option value="">Any used condition</option>
                <option
                    v-for="condition in conditions"
                    :key="condition.value"
                    :value="condition.value"
                >
                    {{ condition.label }}
                </option>
            </select>
        </div>
        <AttributeFields
            v-if="selected && model.attributes"
            v-model="model.attributes"
            :attributes="
                selected.attributes.map((a) => ({ ...a, is_required: false }))
            "
            :errors="{}"
            :text-max-length="100"
        />
        <p
            v-if="selected?.attributes.length"
            class="text-xs text-muted-foreground"
        >
            Item details match exact values. Leave blank for any.
        </p>
        <button
            type="submit"
            class="w-full rounded-lg bg-primary px-4 py-3 font-semibold text-white"
        >
            Apply filters
        </button>
    </div>
</template>
