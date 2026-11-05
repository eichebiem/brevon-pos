import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * UI hints only. Authorisation is always enforced on the server (FR-007).
 */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(
        () => new Set(page.props.auth.permissions ?? []),
    );

    const can = (permission: string): boolean =>
        permissions.value.has(permission);

    return { can };
}
