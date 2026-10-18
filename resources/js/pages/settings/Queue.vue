<script setup lang="ts">
import IconAction from '@/components/crm/IconAction.vue';
import StickySaveBar from '@/components/crm/StickySaveBar.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import AppLayout from '@/layouts/AppLayout.vue';
import type { QueueSettings, QueueShiftTemplate } from '@/types/admin';
import { Head, useForm } from '@inertiajs/vue3';
import { ChevronDown, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    settings: QueueSettings;
    supervisors: { id: number; name: string; role: string }[];
    canEditAdmin: boolean;
}>();

const { t } = useI18n();

const title = computed(() => t('settings_hub.items.queue'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);

const pointKeys = [
    'inquiry',
    'problem',
    'case_open',
    'case_resolved_in_time',
    'case_resolved_late',
    'speed_fast',
    'speed_ok',
    'review_per_star',
    'qa_per_point',
    'auto_close',
    'escalation',
    'no_reply',
    'daily_cap',
] as const;

function emptyShift(): QueueShiftTemplate {
    return { key: '', name: '', from: '09:00', to: '17:00', location: 'office', leader_user_id: null };
}

// Type aliases, not interfaces: Inertia's form data constraint needs an index signature, which
// an interface never has, and without an explicit shape every field degrades to `FormDataConvertible`.
type ShiftRow = { [K in keyof QueueShiftTemplate]: QueueShiftTemplate[K] };
type QueueForm = {
    enabled: boolean;
    night_message_enabled: boolean;
    case_follow_owner: boolean;
    points: Record<string, number>;
    shifts: ShiftRow[];
} & Record<(typeof NUMERIC_KEYS)[number], number>;

const NUMERIC_KEYS = [
    'windows_per_moderator',
    'silence_warn_seconds',
    'silence_close_seconds',
    'return_priority_minutes',
    'close_confirm_minutes',
    'sla_first_reply_seconds',
    'sla_target_pct',
    'occupancy_cap_pct',
    'break_minutes',
    'break_after_minutes',
    'review_sample_pct',
    'review_delay_seconds',
    'case_sla_hours',
    'speed_fast_seconds',
    'speed_ok_seconds',
    'eta_default_handle_seconds',
    'waiting_update_seconds',
    'agent_apology_seconds',
    'agent_reassign_first_seconds',
    'agent_reassign_seconds',
] as const;

const form = useForm<QueueForm>({
    enabled: props.settings.enabled,
    windows_per_moderator: props.settings.windows_per_moderator,
    silence_warn_seconds: props.settings.silence_warn_seconds,
    silence_close_seconds: props.settings.silence_close_seconds,
    return_priority_minutes: props.settings.return_priority_minutes,
    close_confirm_minutes: props.settings.close_confirm_minutes,
    sla_first_reply_seconds: props.settings.sla_first_reply_seconds,
    sla_target_pct: props.settings.sla_target_pct,
    occupancy_cap_pct: props.settings.occupancy_cap_pct,
    break_minutes: props.settings.break_minutes,
    break_after_minutes: props.settings.break_after_minutes,
    review_sample_pct: props.settings.review_sample_pct,
    review_delay_seconds: props.settings.review_delay_seconds,
    case_sla_hours: props.settings.case_sla_hours,
    night_message_enabled: props.settings.night_message_enabled,
    speed_fast_seconds: props.settings.speed_fast_seconds,
    speed_ok_seconds: props.settings.speed_ok_seconds,
    eta_default_handle_seconds: props.settings.eta_default_handle_seconds,
    waiting_update_seconds: props.settings.waiting_update_seconds,
    agent_apology_seconds: props.settings.agent_apology_seconds,
    agent_reassign_first_seconds: props.settings.agent_reassign_first_seconds,
    agent_reassign_seconds: props.settings.agent_reassign_seconds,
    case_follow_owner: props.settings.case_follow_owner,
    points: { ...(props.settings.points ?? {}) },
    shifts: (props.settings.shifts ?? []).map((s) => ({ ...s })),
});

// Each shift reads as one row (name, hours, place, leader); «عدّلي» opens its fields under it. A new shift, or one
// the server rejected, opens by itself.
const openShifts = ref<Set<number>>(new Set());

function shiftOpen(index: number): boolean {
    return openShifts.value.has(index) || Object.keys(form.errors).some((key) => key.startsWith(`shifts.${index}.`));
}

function toggleShift(index: number): void {
    const next = new Set(openShifts.value);
    if (next.has(index)) next.delete(index);
    else next.add(index);
    openShifts.value = next;
}

function addShift(): void {
    form.shifts.push(emptyShift());
    openShifts.value = new Set([...openShifts.value, form.shifts.length - 1]);
}

function removeShift(index: number): void {
    form.shifts.splice(index, 1);
    openShifts.value = new Set([...openShifts.value].filter((i) => i !== index).map((i) => (i > index ? i - 1 : i)));
}

function leaderName(id: number | null): string {
    return props.supervisors.find((s) => s.id === id)?.name ?? t('settings.queue.no_leader');
}

// The wrapped <Input> component's own v-model never sees a `.number` modifier applied at the call site
// (that only works on a native element's v-model), so every numeric field arrives here as a string typed
// into the DOM. Laravel's `integer` rule tolerates a numeric string, but we coerce anyway so the payload —
// and the settings read back afterwards — are genuinely numeric. A supervisor also cannot touch
// points/shifts (controller: abort_if hasAny(ADMIN_FIELDS)); useForm always serializes every field it
// holds, so those two are dropped for a non-admin via transform() rather than sent unchanged.
function submit(): void {
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };
        for (const key of NUMERIC_KEYS) {
            payload[key] = Number(payload[key]);
        }
        if (props.canEditAdmin) {
            payload.points = Object.fromEntries(Object.entries(data.points).map(([key, value]) => [key, Number(value)]));
        } else {
            delete payload.points;
            delete payload.shifts;
        }

        return payload;
    }).put('/settings/queue', { preserveScroll: true });
}

type NumericKey = (typeof NUMERIC_KEYS)[number];
type NumericField = { key: NumericKey; min: number; max: number };

/** The timers and targets as SettingsRow runs: one label, the number at the far end, the same bounds as before. */
const replyFields: NumericField[] = [
    { key: 'agent_apology_seconds', min: 30, max: 3600 },
    { key: 'agent_reassign_first_seconds', min: 120, max: 3600 },
    { key: 'agent_reassign_seconds', min: 120, max: 3600 },
];
const timerFields: NumericField[] = [
    { key: 'silence_warn_seconds', min: 30, max: 3600 },
    { key: 'silence_close_seconds', min: 60, max: 7200 },
    { key: 'return_priority_minutes', min: 0, max: 1440 },
    { key: 'close_confirm_minutes', min: 0, max: 1440 },
    { key: 'review_delay_seconds', min: 0, max: 3600 },
    { key: 'case_sla_hours', min: 1, max: 168 },
    { key: 'waiting_update_seconds', min: 30, max: 900 },
];
const slaFields: NumericField[] = [
    { key: 'sla_first_reply_seconds', min: 30, max: 7200 },
    { key: 'sla_target_pct', min: 1, max: 100 },
    { key: 'occupancy_cap_pct', min: 10, max: 100 },
    { key: 'break_minutes', min: 1, max: 120 },
    { key: 'review_sample_pct', min: 0, max: 100 },
    { key: 'speed_fast_seconds', min: 10, max: 3600 },
    { key: 'speed_ok_seconds', min: 10, max: 3600 },
    { key: 'eta_default_handle_seconds', min: 30, max: 3600 },
];

const num = 'h-10 w-28 tabular-nums';
const field =
    'h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 disabled:cursor-not-allowed disabled:opacity-50';
const fieldLabel = 'text-13 font-semibold text-fg-2';
const error = 'mt-1 text-xs text-danger';
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('settings.queue.description')" width="form">
            <form class="space-y-5" @submit.prevent="submit">
                <!-- Running -->
                <SettingsPanel>
                    <SettingsRow for="queue-enabled" :title="t('settings.queue.enabled')" :description="t('settings.queue.enabled_hint')">
                        <ToggleSwitch id="queue-enabled" v-model="form.enabled" :label="t('settings.queue.enabled')" />
                    </SettingsRow>
                    <SettingsRow
                        for="queue-windows"
                        :title="t('settings.queue.windows_per_moderator')"
                        :description="t('settings.queue.windows_hint')"
                    >
                        <template v-if="form.errors.windows_per_moderator" #below>
                            <p :class="error">{{ form.errors.windows_per_moderator }}</p>
                        </template>
                        <Input id="queue-windows" v-model="form.windows_per_moderator" type="number" min="1" max="10" dir="ltr" :class="num" />
                    </SettingsRow>
                    <SettingsRow for="queue-night-message" :title="t('settings.queue.night_message_enabled')">
                        <ToggleSwitch
                            id="queue-night-message"
                            v-model="form.night_message_enabled"
                            :label="t('settings.queue.night_message_enabled')"
                        />
                    </SettingsRow>
                    <SettingsRow for="queue-case-follow" :title="t('settings.queue.case_follow_owner')">
                        <ToggleSwitch id="queue-case-follow" v-model="form.case_follow_owner" :label="t('settings.queue.case_follow_owner')" />
                    </SettingsRow>
                </SettingsPanel>

                <!-- Moderator reply (flow revision §4): the re-route timeouts -->
                <SettingsPanel :title="t('settings.queue.reply_section')" :description="t('settings.queue.reply_section_hint')">
                    <SettingsRow v-for="f in replyFields" :key="f.key" :for="`queue-${f.key}`" :title="t(`settings.queue.${f.key}`)">
                        <template v-if="form.errors[f.key]" #below>
                            <p :class="error">{{ form.errors[f.key] }}</p>
                        </template>
                        <Input :id="`queue-${f.key}`" v-model="form[f.key]" type="number" :min="f.min" :max="f.max" dir="ltr" :class="num" />
                    </SettingsRow>
                </SettingsPanel>

                <!-- Shifts -->
                <SettingsPanel
                    :title="t('settings.queue.shifts')"
                    :description="canEditAdmin ? undefined : t('settings.queue.points_admin_only')"
                    data-queue-shifts
                >
                    <template #actions>
                        <Button type="button" variant="outline" size="sm" :disabled="!canEditAdmin" @click="addShift">
                            <Plus aria-hidden="true" />{{ t('settings.queue.add_shift') }}
                        </Button>
                    </template>

                    <p v-if="form.errors.shifts" :class="error" class="pt-3">{{ form.errors.shifts }}</p>
                    <p v-if="!form.shifts.length" class="py-4 text-13 text-fg-2">{{ t('settings.queue.no_shifts') }}</p>

                    <SettingsRow v-for="(shift, index) in form.shifts" :key="index" :title="shift.name || t('settings.queue.shift_new')">
                        <template #below>
                            <p class="mt-0.5 text-13 text-fg-2">
                                <bdi class="tabular-nums" dir="ltr">{{ shift.from }}–{{ shift.to }}</bdi>
                                · {{ t(`settings.queue.${shift.location}`) }} · {{ t('settings.queue.shift_leader') }}:
                                {{ leaderName(shift.leader_user_id) }}
                            </p>
                            <div
                                v-if="shiftOpen(index)"
                                class="mt-3 grid gap-3 rounded-md bg-surface-2 p-3 sm:grid-cols-2 lg:grid-cols-3"
                                :data-shift-editor="index"
                            >
                                <label class="grid content-start gap-1">
                                    <span :class="fieldLabel">{{ t('settings.queue.shift_key') }}</span>
                                    <Input v-model="shift.key" type="text" maxlength="40" dir="ltr" :disabled="!canEditAdmin" class="h-10" />
                                </label>
                                <label class="grid content-start gap-1">
                                    <span :class="fieldLabel">{{ t('settings.queue.shift_name') }}</span>
                                    <Input v-model="shift.name" type="text" maxlength="60" dir="auto" :disabled="!canEditAdmin" class="h-10" />
                                </label>
                                <label class="grid content-start gap-1">
                                    <span :class="fieldLabel">{{ t('settings.queue.shift_location') }}</span>
                                    <select v-model="shift.location" :disabled="!canEditAdmin" :class="field">
                                        <option value="office">{{ t('settings.queue.office') }}</option>
                                        <option value="home">{{ t('settings.queue.home') }}</option>
                                    </select>
                                </label>
                                <label class="grid content-start gap-1">
                                    <span :class="fieldLabel">{{ t('settings.queue.shift_from') }}</span>
                                    <Input v-model="shift.from" type="time" dir="ltr" :disabled="!canEditAdmin" class="h-10 tabular-nums" />
                                </label>
                                <label class="grid content-start gap-1">
                                    <span :class="fieldLabel">{{ t('settings.queue.shift_to') }}</span>
                                    <Input v-model="shift.to" type="time" dir="ltr" :disabled="!canEditAdmin" class="h-10 tabular-nums" />
                                </label>
                                <label class="grid content-start gap-1">
                                    <span :class="fieldLabel">{{ t('settings.queue.shift_leader') }}</span>
                                    <select v-model="shift.leader_user_id" :disabled="!canEditAdmin" :class="field">
                                        <option :value="null">—</option>
                                        <option v-for="sup in supervisors" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
                                    </select>
                                </label>
                            </div>
                        </template>
                        <Button type="button" variant="secondary" size="sm" :aria-expanded="shiftOpen(index)" @click="toggleShift(index)">
                            {{
                                shiftOpen(index)
                                    ? t('settings.queue.close_shift')
                                    : t(canEditAdmin ? 'settings.queue.edit_shift' : 'settings.queue.view_shift')
                            }}
                            <ChevronDown
                                class="transition-transform motion-reduce:transition-none"
                                :class="shiftOpen(index) ? 'rotate-180' : ''"
                                aria-hidden="true"
                            />
                        </Button>
                        <IconAction
                            v-if="canEditAdmin"
                            :icon="Trash2"
                            size="sm"
                            variant="destructive"
                            :label="`${t('settings.queue.remove')} ${shift.name}`"
                            @click="removeShift(index)"
                        />
                    </SettingsRow>
                </SettingsPanel>

                <!-- Timers -->
                <SettingsPanel :title="t('settings.queue.timers_section')">
                    <SettingsRow v-for="f in timerFields" :key="f.key" :for="`queue-${f.key}`" :title="t(`settings.queue.${f.key}`)">
                        <template v-if="form.errors[f.key]" #below>
                            <p :class="error">{{ form.errors[f.key] }}</p>
                        </template>
                        <Input :id="`queue-${f.key}`" v-model="form[f.key]" type="number" :min="f.min" :max="f.max" dir="ltr" :class="num" />
                    </SettingsRow>
                </SettingsPanel>

                <!-- SLA & occupancy -->
                <SettingsPanel :title="t('settings.queue.sla_section')">
                    <SettingsRow v-for="f in slaFields" :key="f.key" :for="`queue-${f.key}`" :title="t(`settings.queue.${f.key}`)">
                        <template v-if="form.errors[f.key]" #below>
                            <p :class="error">{{ form.errors[f.key] }}</p>
                        </template>
                        <Input :id="`queue-${f.key}`" v-model="form[f.key]" type="number" :min="f.min" :max="f.max" dir="ltr" :class="num" />
                    </SettingsRow>
                </SettingsPanel>

                <!-- Points -->
                <SettingsPanel :title="t('settings.queue.points')" :description="canEditAdmin ? undefined : t('settings.queue.points_admin_only')">
                    <div class="grid gap-x-6 sm:grid-cols-2">
                        <label v-for="key in pointKeys" :key="key" class="flex min-h-14 items-center justify-between gap-3 border-b border-line py-2">
                            <span class="text-sm font-semibold">{{ t(`settings.queue.point_keys.${key}`) }}</span>
                            <Input
                                v-model="form.points[key]"
                                type="number"
                                min="-100"
                                max="1000"
                                dir="ltr"
                                :disabled="!canEditAdmin"
                                class="h-10 w-24 shrink-0 tabular-nums"
                            />
                        </label>
                    </div>
                </SettingsPanel>

                <StickySaveBar :bleed="false" :busy="form.processing" :dirty="form.isDirty" :label="t('settings.queue.save')" submit />
            </form>
        </SettingsShell>
    </AppLayout>
</template>
