<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCart } from '@/composables/useCart';

const props = defineProps({
    currencySymbol: {
        type: String,
        required: true,
    },
    tableId: {
        type: [String, Number],
        default: null,
    },
    tableLabel: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['close']);
const { t } = useI18n();
const cart = useCart();

const form = useForm({
    table_id: props.tableId ?? null,
    guest_count: null,
    customer_name: '',
    notes: '',
    items: [],
});

function formatPrice(cents) {
    return `${props.currencySymbol}${(cents / 100).toFixed(2)}`;
}

function submit() {
    form.items = cart.toOrderPayload();
    form.post(route('menu.orders.store'), {
        onSuccess: () => cart.clear(),
    });
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex justify-end bg-gray-900/50" @click.self="emit('close')">
        <div class="flex h-full w-full max-w-md flex-col bg-white dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-800">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ t('menu.your_order') }}</h2>
                <button type="button" class="text-gray-400 hover:text-gray-600" @click="emit('close')">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto p-4">
                <p v-if="cart.lines.length === 0" class="mt-10 text-center text-sm text-gray-400">{{ t('menu.empty_cart') }}</p>

                <div v-for="line in cart.lines" :key="line.id" class="mb-3 flex items-start gap-3 border-b border-gray-100 pb-3 dark:border-gray-800">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ line.name }}</p>
                        <p v-if="line.modifiers.length" class="text-xs text-gray-500">
                            {{ line.modifiers.map((m) => m.name).join(', ') }}
                        </p>
                        <p v-if="line.notes" class="text-xs italic text-gray-400">{{ line.notes }}</p>
                        <div class="mt-1.5 flex items-center gap-2">
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-300 text-xs dark:border-gray-600" @click="cart.updateQuantity(line.id, line.quantity - 1)">−</button>
                            <span class="w-4 text-center text-sm">{{ line.quantity }}</span>
                            <button type="button" class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-300 text-xs dark:border-gray-600" @click="cart.updateQuantity(line.id, line.quantity + 1)">+</button>
                        </div>
                    </div>
                    <p class="shrink-0 text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatPrice(line.unit_price_cents * line.quantity) }}</p>
                </div>

                <div v-if="cart.lines.length > 0" class="mt-4 space-y-3">
                    <div v-if="tableId">
                        <label class="text-sm text-gray-600 dark:text-gray-400">{{ t('tables.title') }}</label>
                        <div class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            {{ tableLabel ?? tableId }}
                        </div>
                    </div>
                    <p v-else class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                        {{ t('menu.scan_qr_required') }}
                    </p>

                    <template v-if="tableId">
                        <div>
                            <label class="text-sm text-gray-600 dark:text-gray-400">{{ t('menu.guest_count') }}</label>
                            <input v-model.number="form.guest_count" type="number" min="1" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300" />
                        </div>
                        <div>
                            <label class="text-sm text-gray-600 dark:text-gray-400">{{ t('menu.customer_name') }}</label>
                            <input v-model="form.customer_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300" />
                        </div>
                        <div>
                            <label class="text-sm text-gray-600 dark:text-gray-400">{{ t('menu.order_notes') }}</label>
                            <textarea v-model="form.notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300"></textarea>
                        </div>
                        <p v-if="form.errors.items" class="text-sm text-red-600">{{ form.errors.items }}</p>
                    </template>
                </div>
            </div>

            <div v-if="cart.lines.length > 0" class="border-t border-gray-200 p-4 dark:border-gray-800">
                <div class="mb-3 flex items-center justify-between font-semibold text-gray-900 dark:text-gray-100">
                    <span>{{ t('common.price') }}</span>
                    <span>{{ formatPrice(cart.totalCents.value) }}</span>
                </div>
                <button
                    type="button"
                    class="w-full rounded-md py-3 text-sm font-semibold text-white disabled:opacity-40"
                    style="background-color: var(--brand-primary)"
                    :disabled="form.processing || !tableId"
                    @click="submit"
                >
                    {{ t('menu.place_order') }}
                </button>
            </div>
        </div>
    </div>
</template>
