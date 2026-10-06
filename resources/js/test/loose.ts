import type { DefineComponent } from 'vue';

/** Generic SFCs (`DataTable<T>`) mount with loosely typed props in tests. */
export const loose = (component: unknown) => component as DefineComponent<Record<string, unknown>>;
