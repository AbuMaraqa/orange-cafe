<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    group: { type: Object, required: true },
    products: { type: Array, required: true },
    assigned: { type: Array, required: true },
});

const page = usePage();
const { t } = useI18n();

const form = useForm({ product_ids: [...props.assigned] });

const search = ref('');
const activeCategory = ref('all');
const onlyAssigned = ref(false);

function formatPrice(cents) {
    return `${page.props.branding.currency_symbol}${(cents / 100).toFixed(2)}`;
}

/** Every section any product sits in, for the filter row. */
const categories = computed(() => {
    const names = new Set();
    props.products.forEach((product) => product.categories.forEach((name) => names.add(name)));

    return [...names].sort();
});

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.products.filter((product) => {
        if (onlyAssigned.value && !form.product_ids.includes(product.id)) return false;
        if (activeCategory.value !== 'all' && !product.categories.includes(activeCategory.value)) return false;
        if (term && !product.name.toLowerCase().includes(term)) return false;

        return true;
    });
});

const selectedCount = computed(() => form.product_ids.length);

/**
 * Acts on what is on screen, not on the whole catalog — the point of the
 * filters is to make "every drink" a single click, and a select-all that
 * quietly included the food would be a trap.
 */
const allVisibleSelected = computed(
    () => visible.value.length > 0 && visible.value.every((p) => form.product_ids.includes(p.id))
);

function toggleAllVisible() {
    const ids = visible.value.map((p) => p.id);

    form.product_ids = allVisibleSelected.value
        ? form.product_ids.filter((id) => !ids.includes(id))
        : [...new Set([...form.product_ids, ...ids])];
}

function toggle(product, checked) {
    form.product_ids = checked
        ? [...form.product_ids, product.id]
        : form.product_ids.filter((id) => id !== product.id);
}

function save() {
    form.put(route('admin.modifier-groups.products.sync', props.group.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="group.name" />

    <AuthenticatedLayout>
        <template #header>{{ group.name }}</template>

        <div class="mx-auto max-w-5xl space-y-4">
            <!-- What is being applied: the add-ons and their prices, so the
                 decision is made with the prices in view. -->
            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ group.name }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ group.is_required ? 'إجبارية — لازم الزبون يختار' : 'اختيارية' }}
                            · اختيار من {{ group.min_select }} إلى {{ group.max_select || '∞' }}
                        </p>
                    </div>
                    <Link :href="route('admin.modifier-groups.edit', group.id)" class="text-sm underline" style="color: var(--brand-primary)">
                        تعديل المجموعة
                    </Link>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-for="modifier in group.modifiers"
                        :key="modifier.id"
                        class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs dark:bg-gray-800"
                    >
                        <span class="text-gray-700 dark:text-gray-300">{{ modifier.name }}</span>
                        <span class="tabular-nums" :class="modifier.price_cents > 0 ? 'text-gray-500' : 'text-green-600'">
                            {{ modifier.price_cents > 0 ? `+${formatPrice(modifier.price_cents)}` : 'مجاناً' }}
                        </span>
                    </span>
                </div>
            </section>

            <!-- Filters -->
            <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center gap-3">
                    <TextInput v-model="search" class="w-full sm:w-64" placeholder="ابحث عن صنف…" />

                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <Checkbox :checked="onlyAssigned" @update:checked="(v) => (onlyAssigned = v)" />
                        المضافة فقط
                    </label>

                    <span class="ms-auto text-sm text-gray-500">
                        محدد <span class="font-semibold text-gray-900 dark:text-gray-100">{{ selectedCount }}</span>
                        من {{ products.length }}
                    </span>
                </div>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs"
                        :class="activeCategory === 'all' ? 'text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'"
                        :style="activeCategory === 'all' ? { backgroundColor: 'var(--brand-primary)' } : {}"
                        @click="activeCategory = 'all'"
                    >
                        الكل
                    </button>
                    <button
                        v-for="category in categories"
                        :key="category"
                        type="button"
                        class="rounded-full px-3 py-1 text-xs"
                        :class="activeCategory === category ? 'text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'"
                        :style="activeCategory === category ? { backgroundColor: 'var(--brand-primary)' } : {}"
                        @click="activeCategory = category"
                    >
                        {{ category }}
                    </button>
                </div>
            </section>

            <!-- The list -->
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        <Checkbox :checked="allVisibleSelected" @update:checked="toggleAllVisible" />
                        تحديد كل الظاهر ({{ visible.length }})
                    </label>
                </div>

                <p v-if="visible.length === 0" class="px-4 py-10 text-center text-sm text-gray-400">ما في أصناف مطابقة.</p>

                <label
                    v-for="product in visible"
                    :key="product.id"
                    class="flex cursor-pointer items-center gap-3 border-b border-gray-50 px-4 py-2.5 last:border-0 hover:bg-gray-50 dark:border-gray-800/60 dark:hover:bg-gray-800/40"
                >
                    <Checkbox
                        :checked="form.product_ids.includes(product.id)"
                        @update:checked="(checked) => toggle(product, checked)"
                    />

                    <span class="min-w-0 flex-1">
                        <span class="text-sm text-gray-900 dark:text-gray-100">{{ product.name }}</span>
                        <span v-if="!product.is_active" class="ms-2 text-xs text-amber-600">معطّل</span>
                        <span v-if="product.categories.length" class="ms-2 text-xs text-gray-400">
                            {{ product.categories.join('، ') }}
                        </span>
                    </span>

                    <span class="shrink-0 text-sm text-gray-500 tabular-nums">{{ formatPrice(product.price_cents) }}</span>
                </label>
            </section>

            <!-- Sticky, because the list is long and the decision is made at
                 the bottom of it as often as the top. -->
            <div class="sticky bottom-4 flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-800 dark:bg-gray-900">
                <span class="text-sm text-gray-500">
                    {{ selectedCount }} صنف رح تظهر عليه هذه الإضافات
                </span>
                <PrimaryButton :disabled="form.processing" @click="save">
                    {{ form.processing ? '…' : t('common.save') }}
                </PrimaryButton>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
