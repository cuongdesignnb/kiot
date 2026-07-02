import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const page = usePage();

    const permissions = () => page.props.auth?.permissions || [];

    const can = (permission) => {
        const perms = permissions();
        if (perms.includes('*')) return true;
        return perms.includes(permission);
    };

    const canAny = (permissionList) => {
        const perms = permissions();
        if (perms.includes('*')) return true;
        return permissionList.some(p => perms.includes(p));
    };

    const isAdmin = () => {
        const perms = permissions();
        return perms.includes('*');
    };

    return { can, canAny, isAdmin };
}
