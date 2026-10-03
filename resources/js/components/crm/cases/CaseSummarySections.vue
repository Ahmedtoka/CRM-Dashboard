<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { CaseSummarySection } from '@/types/crm';
import { ClipboardCheck, FileText, Package, Paperclip, ShoppingBag, TriangleAlert, UserRound } from 'lucide-vue-next';
import type { Component } from 'vue';

/** The organised case summary (same sections as the conversation note): a lucide icon per section + bold title, lines below; alerts turn amber when there are any. */
const props = withDefaults(defineProps<{ sections: CaseSummarySection[]; compact?: boolean }>(), { compact: false });

const { t, dir } = useI18n();

/** The alerts box only turns amber when there is something to warn about: the PHP side flags the empty one. */
/** The PHP side sends no icon (spec 2026-10-01 §6: no emoji in what the system writes): the UI picks one per section. */
const icons: Record<CaseSummarySection['key'], Component> = {
    customer: UserRound,
    order: Package,
    items: ShoppingBag,
    request: FileText,
    attachments: Paperclip,
    alerts: TriangleAlert,
    team_action: ClipboardCheck,
};

function isAlert(section: CaseSummarySection): boolean {
    return section.key === 'alerts' && section.empty !== true;
}
</script>

<template>
    <div :class="props.compact ? 'space-y-2' : 'space-y-3'" :dir="dir" :aria-label="t('cases.summary')" role="group">
        <section
            v-for="section in sections"
            :key="section.key"
            class="rounded-md"
            :class="[isAlert(section) ? 'bg-warning/15 px-2 py-1.5 ring-1 ring-warning/40' : '', props.compact ? 'text-xs' : 'text-sm']"
        >
            <h4 class="flex items-center gap-1.5 font-semibold text-foreground">
                <component :is="icons[section.key]" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span>{{ section.title }}</span>
            </h4>
            <p
                v-for="(line, index) in section.lines"
                :key="index"
                dir="auto"
                class="ms-6 whitespace-pre-line leading-relaxed"
                :class="section.key === 'alerts' && !isAlert(section) ? 'text-muted-foreground' : 'text-foreground'"
            >
                {{ line }}
            </p>
        </section>
    </div>
</template>
