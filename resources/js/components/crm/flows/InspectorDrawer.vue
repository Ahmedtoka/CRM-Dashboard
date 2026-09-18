<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { FlaskConical, History, SquarePen, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted } from 'vue';
import type { DrawerTab } from './FlowToolbar.vue';

/**
 * The step / تجربة / النسخ inspector. On wide screens it slides over the canvas from the end side
 * (the canvas keeps its size); on phones it is a bottom sheet. Closed = `tab` is null.
 * The body stays mounted while closed, so the test conversation survives closing and reopening.
 */
defineProps<{
    mobile: boolean;
    /** the last tab opened, so the closing slide keeps its content */
    shown: DrawerTab;
}>();
const tab = defineModel<DrawerTab | null>('tab', { required: true });

const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

const tabs = [
    { id: 'step', key: 'flows.step', icon: SquarePen },
    { id: 'test', key: 'flows.test', icon: FlaskConical },
    { id: 'versions', key: 'flows.versions', icon: History },
] as const;

/** Esc closes the drawer, unless a dialog or menu (which handle Esc themselves) is open on top. */
function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || event.defaultPrevented || tab.value === null) return;
    if (document.querySelector('[role="dialog"][data-state="open"], [role="menu"][data-state="open"]')) return;
    event.preventDefault();
    emit('close');
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Transition
        enter-active-class="transition-transform duration-200 ease-out motion-reduce:transition-none"
        leave-active-class="transition-transform duration-150 ease-in motion-reduce:transition-none"
        :enter-from-class="mobile ? 'translate-y-full' : 'ltr:translate-x-full rtl:-translate-x-full'"
        :leave-to-class="mobile ? 'translate-y-full' : 'ltr:translate-x-full rtl:-translate-x-full'"
    >
        <aside
            v-show="tab !== null"
            class="z-30 flex flex-col bg-card"
            :class="
                mobile
                    ? 'fixed inset-x-0 bottom-0 h-[70svh] rounded-t-2xl border-t border-border shadow-[0_-8px_30px_rgb(0_0_0/0.18)]'
                    : 'absolute inset-y-0 end-0 w-[400px] max-w-[calc(100%-4rem)] border-s border-border shadow-[0_0_32px_rgb(0_0_0/0.12)] dark:shadow-[0_0_32px_rgb(0_0_0/0.5)] xl:w-[420px]'
            "
            :aria-label="t('flows.workspace.drawer_label')"
        >
            <div class="flex items-center gap-1 border-b border-border px-2 pt-1.5" role="tablist">
                <button
                    v-for="item in tabs"
                    :key="item.id"
                    type="button"
                    role="tab"
                    :aria-selected="shown === item.id"
                    class="-mb-px inline-flex items-center gap-1.5 rounded-t-md border-b-2 px-3 py-2.5 text-xs font-semibold outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                    :class="shown === item.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="tab = item.id"
                >
                    <component :is="item.icon" class="size-3.5" aria-hidden="true" />{{ t(item.key) }}
                </button>
                <button
                    type="button"
                    class="ms-auto inline-flex size-9 items-center justify-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="t('flows.close_panel')"
                    :title="`${t('flows.close_panel')} (Esc)`"
                    @click="emit('close')"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>

            <div
                class="scrollbar-thin min-h-0 flex-1"
                :class="shown === 'test' ? 'flex flex-col overflow-hidden' : 'overflow-y-auto'"
                role="tabpanel"
            >
                <slot :tab="shown" />
            </div>
        </aside>
    </Transition>
</template>
