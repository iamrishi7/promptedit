import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** The product name: APP_NAME from the server's config, shared with every page. */
export function useAppName() {
    const page = usePage();

    return computed(() => page.props.name);
}
