<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { CaseSummarySection } from '@/types/crm';

/** The organised case summary (same sections as the conversation note): icon + bold title, lines below; alerts turn amber when there are any. */
const props = withDefaults(defineProps<{ sections: CaseSummarySection[]; compact?: boolean }>(), { compact: false });

const { t } = useI18n();

const EMPTY_ALERT = 'مفيش';

function isAlert(section: CaseSummarySection): boolean {
    return section.key === 'alerts' && !(section.lines.length === 1 && section.lines[0] === EMPTY_ALERT);
}
</script>

<template>
    <div :class="props.compact ? 'space-y-2' : 'space-y-3'" dir="rtl" :aria-label="t('cases.summary')" role="group">
        <section
            v-for="section in sections"
            :key="section.key"
            class="rounded-md"
            :class="[isAlert(section) ? 'bg-warning/15 px-2 py-1.5 ring-1 ring-warning/40' : '', props.compact ? 'text-xs' : 'text-sm']"
        >
            <h4 class="flex items-center gap-1.5 font-semibold text-foreground">
                <span aria-hidden="true">{{ section.icon }}</span>
                <span>{{ section.title }}</span>
            </h4>
            <p
                v-for="(line, index) in section.lines"
                :key="index"
                class="ms-6 whitespace-pre-line leading-relaxed"
                :class="section.key === 'alerts' && !isAlert(section) ? 'text-muted-foreground' : 'text-foreground'"
            >
                {{ line }}
            </p>
        </section>
    </div>
</template>
