import { reactive, watch } from "vue";
import { router } from "@inertiajs/vue3";

/**
 * useFilters — standardized sidebar filter state manager.
 *
 * Usage:
 *   const { filters, update, reset, setSort } = useFilters({
 *     initial: props.filters,
 *     route: '/purchases',
 *     defaults: { date_filter: 'this_month' },
 *     debounce: 400,
 *   });
 *
 * - `filters` is a reactive object you bind in UI (v-model).
 * - Any change triggers a debounced `router.get(route, cleanedParams,
 *   { preserveState: true, preserveScroll: true, replace: true })`.
 * - Empty strings, null, and empty arrays are stripped from the URL.
 * - For date ranges, `date_from`/`date_to` are dropped unless
 *   `date_filter === 'custom'`.
 */
export function useFilters(options) {
    const {
        initial = {},
        defaults = {},
        route: routeUrl,
        debounce = 400,
        only = null, // optional array of partial reload keys
        transform = null, // hook (params) => params
    } = options;

    const base = { ...defaults, ...initial };
    const filters = reactive({
        search: "",
        status: [],
        date_filter: "all",
        date_from: "",
        date_to: "",
        sort_by: "",
        sort_direction: "",
        ...base,
    });

    let timer = null;

    const buildParams = () => {
        const out = {};
        for (const [k, v] of Object.entries(filters)) {
            if (v === null || v === undefined) continue;
            if (typeof v === "string" && v === "") continue;
            if (Array.isArray(v) && v.length === 0) continue;
            out[k] = v;
        }
        // Drop custom bounds unless preset is 'custom'.
        if (out.date_filter !== "custom") {
            delete out.date_from;
            delete out.date_to;
        }
        return transform ? transform(out) : out;
    };

    const update = ({ immediate = false } = {}) => {
        clearTimeout(timer);
        const fire = () => {
            const params = buildParams();
            const opts = {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            };
            if (only) opts.only = only;
            router.get(routeUrl, params, opts);
        };
        if (immediate) fire();
        else timer = setTimeout(fire, debounce);
    };

    const reset = () => {
        for (const k of Object.keys(filters)) {
            if (k in defaults) filters[k] = defaults[k];
            else if (Array.isArray(filters[k])) filters[k] = [];
            else filters[k] = "";
        }
        update({ immediate: true });
    };

    const setSort = (field, direction) => {
        filters.sort_by = field || "";
        filters.sort_direction = direction || "";
        update({ immediate: true });
    };

    // Auto-watch every top-level key.
    watch(filters, () => update(), { deep: true });

    return { filters, update, reset, setSort, buildParams };
}

export default useFilters;
