import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

type VisitOptions = NonNullable<Parameters<typeof router.get>[2]>;

/** The list-reload pattern every page repeated: a `loading` ref driven by one Inertia visit's start/finish. */
export function useVisitLoading() {
    const loading = ref(false);

    function track(options: VisitOptions = {}): VisitOptions {
        return {
            ...options,
            onStart: (visit) => {
                loading.value = true;
                options.onStart?.(visit);
            },
            onFinish: (visit) => {
                loading.value = false;
                options.onFinish?.(visit);
            },
        };
    }

    return { loading, track };
}
