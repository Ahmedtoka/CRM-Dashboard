<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import type { TemplatePayload } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { FileText, SendHorizontal } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/** Shape of `config('crm.whatsapp_templates')`, shared as the `whatsappTemplates` Inertia prop. */
interface WhatsappTemplateOption {
    name: string;
    language: string;
    params: number;
    label_ar: string;
    label_en: string;
}

// Approved WhatsApp templates (spec §5.6). Falls back to this constant only if the
// `whatsappTemplates` shared prop is missing (e.g. an older backend build) — see task-12 fix,
// which added the shared prop from `config('crm.whatsapp_templates')` so the name/param-count
// list lives in one place instead of being duplicated here (see task-9 report, concerns).
const FALLBACK_TEMPLATES: readonly WhatsappTemplateOption[] = [
    { name: 'order_update', language: 'ar', params: 2, label_ar: 'تحديث الطلب', label_en: 'Order update' },
    { name: 'follow_up', language: 'ar', params: 1, label_ar: 'متابعة', label_en: 'Follow up' },
    { name: 'back_in_stock', language: 'ar', params: 1, label_ar: 'عاد للمخزون', label_en: 'Back in stock' },
];

defineProps<{ disabled?: boolean }>();
const emit = defineEmits<{ send: [template: TemplatePayload] }>();

const { t, locale } = useI18n();

const page = usePage<{ whatsappTemplates?: WhatsappTemplateOption[] }>();
const TEMPLATES = computed<readonly WhatsappTemplateOption[]>(() =>
    page.props.whatsappTemplates && page.props.whatsappTemplates.length > 0 ? page.props.whatsappTemplates : FALLBACK_TEMPLATES,
);

const selected = ref<string>(FALLBACK_TEMPLATES[0].name);
const params = ref<string[]>(Array.from({ length: FALLBACK_TEMPLATES[0].params }, () => ''));

const template = computed(() => TEMPLATES.value.find((tpl) => tpl.name === selected.value) ?? TEMPLATES.value[0]);
const ready = computed(() => params.value.every((p) => p.trim() !== ''));

function templateLabel(tpl: WhatsappTemplateOption): string {
    return locale.value === 'en' ? tpl.label_en : tpl.label_ar;
}

watch(template, (tpl) => {
    params.value = Array.from({ length: tpl.params }, () => '');
});

function submit(): void {
    if (!ready.value) return;
    emit('send', { name: template.value.name, language: template.value.language, params: params.value.map((p) => p.trim()) });
    params.value = params.value.map(() => '');
}

const field = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';
</script>

<template>
    <div class="border-t bg-card px-3 pb-3 pt-2">
        <p class="mb-2 flex items-center gap-1.5 text-xs font-medium text-foreground">
            <FileText class="size-3.5 text-muted-foreground" aria-hidden="true" />{{ t('templates.title') }}
        </p>
        <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="t('templates.choose')">
            <button
                v-for="tpl in TEMPLATES"
                :key="tpl.name"
                type="button"
                role="radio"
                :aria-checked="selected === tpl.name"
                class="rounded-md border px-2.5 py-1 text-xs transition-colors"
                :class="selected === tpl.name ? 'border-primary bg-primary/10 text-primary' : 'bg-background text-muted-foreground hover:text-foreground'"
                @click="selected = tpl.name"
            >
                {{ templateLabel(tpl) }}
                <code class="ms-1 text-2xs opacity-70" dir="ltr">{{ tpl.name }}</code>
            </button>
        </div>
        <form class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end" @submit.prevent="submit">
            <div class="grid flex-1 gap-2 sm:grid-cols-2">
                <input
                    v-for="(_, index) in params"
                    :key="`${selected}-${index}`"
                    v-model="params[index]"
                    dir="auto"
                    :class="field"
                    :placeholder="t('templates.param', { n: index + 1 })"
                    :aria-label="t('templates.param', { n: index + 1 })"
                    :disabled="disabled"
                />
            </div>
            <button type="submit" :class="cn(buttonVariants({ size: 'sm' }), 'h-8')" :disabled="disabled || !ready">
                <SendHorizontal class="rtl-flip" />{{ t('templates.send') }}
            </button>
        </form>
    </div>
</template>
