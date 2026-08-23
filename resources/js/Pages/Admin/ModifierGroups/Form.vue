<script setup>
import { computed, ref } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import draggable from 'vuedraggable';

const props = defineProps({
    languages: {
        type: Array,
        required: true,
    },
    group: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => props.group !== null);

function emptyTranslations() {
    return Object.fromEntries(props.languages.map((l) => [l.code, { name: '' }]));
}

// Every row carries the cents the backend wants *and* the raw text the
// admin is typing, kept apart for the same reason the product form keeps
// them apart (Products/Form.vue): a box whose value is re-derived as
// `(price_cents / 100).toFixed(2)` on every keystroke rewrites itself
// mid-entry — "5" became "5.00" with the caret pushed to the end, so
// typing "5.50" produced "5.00.5", which is not a valid number input, and
// `Math.round(Number(value) * 100) || 0` then silently zeroed the price.
// A size that costs 5.50 could not be entered at all.
function newModifier() {
    return { id: null, price_cents: 0, price_input: '0.00', is_active: true, translations: emptyTranslations() };
}

function withPriceInput(modifiers) {
    return modifiers.map((modifier) => ({
        ...modifier,
        price_input: (modifier.price_cents / 100).toFixed(2),
    }));
}

// The text is stored exactly as typed and only tidied on blur, so a
// half-finished "5." survives long enough to become "5.50".
function setModifierPrice(modifier, value) {
    modifier.price_input = value;

    const cents = Math.round(Number(value) * 100);

    modifier.price_cents = value !== '' && Number.isFinite(cents) && cents >= 0 ? cents : 0;
}

function normaliseModifierPrice(modifier) {
    modifier.price_input = (modifier.price_cents / 100).toFixed(2);
}

const form = useForm({
    is_required: props.group?.is_required ?? false,
    is_variation: props.group?.is_variation ?? false,
    min_select: props.group?.min_select ?? 0,
    max_select: props.group?.max_select ?? null,
    is_active: props.group?.is_active ?? true,
    sort_order: props.group?.sort_order ?? 0,
    translations: props.group?.translations ?? emptyTranslations(),
    modifiers: props.group?.modifiers?.length ? withPriceInput(props.group.modifiers) : [newModifier()],
});

const activeTab = ref(props.languages.find((l) => l.is_default)?.code ?? props.languages[0]?.code);

function addModifier() {
    form.modifiers.push(newModifier());
}

function removeModifier(index) {
    form.modifiers.splice(index, 1);
}

function submit() {
    // price_input is display state; the request only ever wants the cents.
    // (validated() would drop it anyway, but sending it invites confusion.)
    form.transform((data) => ({
        ...data,
        modifiers: data.modifiers.map((modifier) => {
            const payload = { ...modifier };
            delete payload.price_input;

            return payload;
        }),
    }));

    if (isEdit.value) {
        form.put(route('admin.modifier-groups.update', props.group.id));
    } else {
        form.post(route('admin.modifier-groups.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? $t('admin.modifier_groups.edit') : $t('admin.modifier_groups.create')" />

    <AuthenticatedLayout>
        <template #header>{{ isEdit ? $t('admin.modifier_groups.edit') : $t('admin.modifier_groups.create') }}</template>

        <div class="mx-auto max-w-2xl overflow-hidden rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <form @submit.prevent="submit" class="space-y-6">
                <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
                    <button
                        v-for="language in languages"
                        :key="language.code"
                        type="button"
                        class="border-b-2 px-3 py-2 text-sm font-medium"
                        :class="activeTab === language.code
                            ? 'border-transparent'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                        :style="activeTab === language.code ? { borderBottomColor: 'var(--brand-primary)', color: 'var(--brand-primary)' } : {}"
                        @click="activeTab = language.code"
                    >
                        {{ language.native_name }}
                    </button>
                </div>

                <div>
                    <InputLabel :value="`${$t('common.name')} (${languages.find((l) => l.code === activeTab)?.native_name})`" />
                    <TextInput
                        v-model="form.translations[activeTab].name"
                        :dir="languages.find((l) => l.code === activeTab)?.direction"
                        class="mt-1 block w-full"
                        :placeholder="$t('admin.modifier_groups.name_example')"
                    />
                    <InputError class="mt-2" :message="form.errors[`translations.${activeTab}.name`]" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="min_select" :value="$t('admin.modifier_groups.min_select')" />
                        <TextInput id="min_select" v-model.number="form.min_select" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.min_select" />
                    </div>
                    <div>
                        <InputLabel for="max_select" :value="$t('admin.modifier_groups.max_select')" />
                        <TextInput id="max_select" v-model.number="form.max_select" type="number" min="1" class="mt-1 block w-full" :placeholder="$t('admin.modifier_groups.unlimited')" />
                        <InputError class="mt-2" :message="form.errors.max_select" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center">
                        <Checkbox v-model:checked="form.is_required" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('admin.modifier_groups.required') }}</span>
                    </label>
                    <label class="flex items-center">
                        <Checkbox v-model:checked="form.is_variation" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('admin.modifier_groups.is_variation') }}</span>
                    </label>
                    <label class="flex items-center">
                        <Checkbox v-model:checked="form.is_active" />
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $t('common.active') }}</span>
                    </label>
                </div>

                <!-- Ticking this changes what the numbers below mean, so it
                     says so rather than leaving the admin to discover it on
                     the customer's screen. -->
                <p v-if="form.is_variation" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    {{ $t('admin.modifier_groups.is_variation_hint') }}
                </p>

                <div>
                    <div class="flex items-center justify-between">
                        <InputLabel :value="$t('admin.modifier_groups.options')" />
                        <button type="button" class="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100" @click="addModifier">
                            + {{ $t('admin.modifier_groups.add_option') }}
                        </button>
                    </div>

                    <div v-if="form.modifiers.length > 0" class="mt-3 flex items-center gap-3 px-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                        <span class="w-5"></span>
                        <span class="flex-1">{{ $t('common.name') }}</span>
                        <span class="w-28">{{ $t('common.price') }}</span>
                        <span class="w-9 text-center">{{ $t('common.active') }}</span>
                        <span class="w-4"></span>
                    </div>
                    <!-- Order here is the order the customer sees, and it is
                         positional: "صغير / وسط / كبير" has to read in that
                         sequence, so adding a size later means putting it
                         between two existing ones rather than at the end.
                         Saved from the array order (the controller writes
                         sort_order from the index), so a drop is the whole
                         change. -->
                    <draggable
                        v-model="form.modifiers"
                        item-key="index"
                        handle=".modifier-drag-handle"
                        class="mt-1.5 space-y-3"
                    >
                        <template #item="{ element: modifier, index }">
                        <div
                            class="flex items-start gap-3 rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <!-- A handle rather than the whole row: the row is
                                 mostly text inputs, and a draggable input
                                 cannot be selected with the mouse. -->
                            <span
                                class="modifier-drag-handle w-5 shrink-0 cursor-move select-none pt-2 text-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                title="اسحب لإعادة الترتيب"
                            >⋮⋮</span>

                            <div class="flex-1">
                                <TextInput
                                    v-model="modifier.translations[activeTab].name"
                                    :dir="languages.find((l) => l.code === activeTab)?.direction"
                                    class="block w-full"
                                    :placeholder="$t('common.name')"
                                />
                                <InputError class="mt-1" :message="form.errors[`modifiers.${index}.translations.${activeTab}.name`]" />
                            </div>
                            <div class="w-28">
                                <TextInput
                                    :model-value="modifier.price_input"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    dir="ltr"
                                    class="no-spinner block w-full"
                                    :placeholder="$t('common.price')"
                                    @update:model-value="(value) => setModifierPrice(modifier, value)"
                                    @blur="normaliseModifierPrice(modifier)"
                                />
                                <InputError class="mt-1" :message="form.errors[`modifiers.${index}.price_cents`]" />
                            </div>
                            <label class="flex w-9 items-center justify-center pt-2">
                                <Checkbox v-model:checked="modifier.is_active" />
                            </label>
                            <button
                                type="button"
                                class="w-4 pt-2 text-red-500 hover:text-red-700"
                                :disabled="form.modifiers.length === 1"
                                @click="removeModifier(index)"
                            >
                                ×
                            </button>
                        </div>
                        </template>
                    </draggable>

                    <p v-if="form.modifiers.length > 1" class="mt-2 text-xs text-gray-400">
                        اسحب ⋮⋮ لإعادة ترتيب الإضافات — هذا هو الترتيب الذي يراه الزبون.
                    </p>
                    <InputError class="mt-2" :message="form.errors.modifiers" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Link :href="route('admin.modifier-groups.index')">
                        <SecondaryButton type="button">{{ $t('common.cancel') }}</SecondaryButton>
                    </Link>
                    <PrimaryButton :disabled="form.processing">{{ $t('common.save') }}</PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
