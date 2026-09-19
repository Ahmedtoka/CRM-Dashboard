import type { StepTypeCatalog } from '@/types/flows';
import {
    Camera,
    CircleStop,
    ClipboardCheck,
    FilePlus2,
    FileText,
    IdCard,
    LayoutList,
    Link,
    ListChecks,
    MapPin,
    MessageSquareText,
    Package,
    PackageOpen,
    Phone,
    Store,
    Truck,
    UserRound,
    Workflow,
} from 'lucide-vue-next';
import type { Component } from 'vue';

/** The lucide icons named by FlowStepCatalog (explicit imports keep the bundle small). */
const ICONS: Record<string, Component> = {
    ListChecks,
    LayoutList,
    MessageSquareText,
    IdCard,
    Phone,
    Camera,
    Package,
    PackageOpen,
    Link,
    MapPin,
    Store,
    Truck,
    ClipboardCheck,
    FilePlus2,
    FileText,
    UserRound,
    CircleStop,
};

export const stepIcon = (name: string | undefined): Component => (name && ICONS[name]) || Workflow;

export interface StepColor {
    /** the header strip */
    strip: string;
    /** the icon tile */
    tile: string;
    /** a small dot (menus, legends) */
    dot: string;
}

// Literal class names so Tailwind's scanner keeps them.
const COLORS: Record<string, StepColor> = {
    violet: { strip: 'bg-violet-500', tile: 'bg-violet-500/10 text-violet-600 dark:text-violet-300', dot: 'bg-violet-500' },
    indigo: { strip: 'bg-indigo-500', tile: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300', dot: 'bg-indigo-500' },
    sky: { strip: 'bg-sky-500', tile: 'bg-sky-500/10 text-sky-600 dark:text-sky-300', dot: 'bg-sky-500' },
    teal: { strip: 'bg-teal-500', tile: 'bg-teal-500/10 text-teal-600 dark:text-teal-300', dot: 'bg-teal-500' },
    amber: { strip: 'bg-amber-500', tile: 'bg-amber-500/10 text-amber-600 dark:text-amber-300', dot: 'bg-amber-500' },
    emerald: { strip: 'bg-emerald-500', tile: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300', dot: 'bg-emerald-500' },
    slate: { strip: 'bg-slate-500', tile: 'bg-slate-500/10 text-slate-600 dark:text-slate-300', dot: 'bg-slate-500' },
    rose: { strip: 'bg-rose-500', tile: 'bg-rose-500/10 text-rose-600 dark:text-rose-300', dot: 'bg-rose-500' },
    orange: { strip: 'bg-orange-500', tile: 'bg-orange-500/10 text-orange-600 dark:text-orange-300', dot: 'bg-orange-500' },
    pink: { strip: 'bg-pink-500', tile: 'bg-pink-500/10 text-pink-600 dark:text-pink-300', dot: 'bg-pink-500' },
};

export const stepColor = (color: string | undefined): StepColor => (color && COLORS[color]) || COLORS.slate;

type Translate = (key: string) => string;

/** Arabic label from the catalog; English falls back to `flows.types.<type>`. */
export function stepTypeLabel(type: string, catalog: StepTypeCatalog, locale: string, t: Translate): string {
    if (locale === 'ar' && catalog[type]?.label_ar) return catalog[type].label_ar;
    const key = `flows.types.${type}`;
    const text = t(key);
    return text === key ? (catalog[type]?.label_ar ?? type) : text;
}
