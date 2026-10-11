<script setup lang="ts">
import type { AttributeValue, CategoryAttribute } from '@/types/marketplace';
const props = defineProps<{
    attributes: CategoryAttribute[];
    modelValue: Record<string, AttributeValue>;
    errors: Record<string, string>;
    textMaxLength?: number;
}>();
const emit = defineEmits<{
    'update:modelValue': [Record<string, AttributeValue>];
}>();
function set(slug: string, value: AttributeValue) {
    emit('update:modelValue', { ...props.modelValue, [slug]: value });
}
function textValue(slug: string): string | number {
    const value = props.modelValue[slug];
    return typeof value === 'string' || typeof value === 'number' ? value : '';
}
function toggle(slug: string, option: string, checked: boolean) {
    const value = props.modelValue[slug];
    const selected = Array.isArray(value) ? value : [];
    set(
        slug,
        checked
            ? [...selected, option]
            : selected.filter((item) => item !== option),
    );
}
</script>
<template>
    <div v-if="attributes.length" class="grid gap-5 sm:grid-cols-2">
        <div
            v-for="attribute in attributes"
            :key="attribute.id"
            class="space-y-2"
        >
            <label
                :for="'attribute-' + attribute.slug"
                class="block text-sm font-medium"
                >{{ attribute.name }}
                {{ attribute.is_required ? '*' : '(optional)' }}</label
            >
            <select
                v-if="attribute.type === 'select'"
                :id="'attribute-' + attribute.slug"
                :value="textValue(attribute.slug)"
                :required="attribute.is_required"
                class="field"
                @change="
                    set(
                        attribute.slug,
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">Choose an option</option>
                <option
                    v-for="option in attribute.options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <select
                v-else-if="attribute.type === 'boolean'"
                :id="'attribute-' + attribute.slug"
                :value="
                    modelValue[attribute.slug] === true
                        ? 'yes'
                        : modelValue[attribute.slug] === false
                          ? 'no'
                          : ''
                "
                :required="attribute.is_required"
                class="field"
                @change="
                    set(
                        attribute.slug,
                        ($event.target as HTMLSelectElement).value === ''
                            ? null
                            : ($event.target as HTMLSelectElement).value ===
                                  'yes',
                    )
                "
            >
                <option value="">Choose an option</option>
                <option value="yes">Yes</option>
                <option value="no">No</option>
            </select>
            <fieldset
                v-else-if="attribute.type === 'multi_select'"
                :id="'attribute-' + attribute.slug"
                class="space-y-2"
            >
                <legend class="sr-only">{{ attribute.name }}</legend>
                <label
                    v-for="option in attribute.options"
                    :key="option.value"
                    class="flex items-center gap-2 text-sm"
                    ><input
                        type="checkbox"
                        :checked="
                            Array.isArray(modelValue[attribute.slug]) &&
                            (modelValue[attribute.slug] as string[]).includes(
                                option.value,
                            )
                        "
                        @change="
                            toggle(
                                attribute.slug,
                                option.value,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />{{ option.label }}</label
                >
            </fieldset>
            <input
                v-else
                :id="'attribute-' + attribute.slug"
                :type="attribute.type === 'number' ? 'number' : 'text'"
                :step="attribute.type === 'number' ? 'any' : undefined"
                :min="attribute.type === 'number' ? -1000000000000 : undefined"
                :max="attribute.type === 'number' ? 1000000000000 : undefined"
                :maxlength="
                    attribute.type === 'text'
                        ? (textMaxLength ?? 1000)
                        : undefined
                "
                :value="textValue(attribute.slug)"
                :required="attribute.is_required"
                class="field"
                @input="
                    set(
                        attribute.slug,
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <p
                v-if="errors['attributes.' + attribute.slug]"
                class="text-sm text-red-600"
                role="alert"
            >
                {{ errors['attributes.' + attribute.slug] }}
            </p>
        </div>
    </div>
</template>
