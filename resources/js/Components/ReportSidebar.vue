<script setup>
import { Link } from "@inertiajs/vue3";
import { ref, computed } from "vue";

const props = defineProps({
    currentPage: { type: String, default: "" },
});

const sections = ref([
    {
        key: "business",
        label: "Kinh doanh",
        icon: "chart",
        open: true,
        items: [
            { label: "Tổng quan", href: "/reports/business", key: "business" },
            {
                label: "Chi phí - Lợi nhuận",
                href: "/reports/cost-profit",
                key: "cost-profit",
            },
        ],
    },
    {
        key: "products",
        label: "Hàng hóa",
        icon: "box",
        open: true,
        items: [
            { label: "Tổng quan", href: "/reports/products", key: "products" },
            { label: "Tồn kho", href: "/reports/inventory", key: "inventory" },
            {
                label: "Phân loại hàng hóa",
                href: "/reports/product-categories",
                key: "product-categories",
            },
        ],
    },
    {
        key: "customers",
        label: "Khách hàng",
        icon: "users",
        open: true,
        items: [
            {
                label: "Tổng quan",
                href: "/reports/customers",
                key: "customers",
            },
            {
                label: "Phân loại khách hàng",
                href: "/reports/customer-categories",
                key: "customer-categories",
            },
        ],
    },
    {
        key: "efficiency",
        label: "Hiệu quả",
        icon: "target",
        open: true,
        items: [
            {
                label: "Công nợ khách hàng",
                href: "/reports/customer-debt",
                key: "customer-debt",
            },
        ],
    },
]);

const toggleSection = (section) => {
    section.open = !section.open;
};

const isActive = (key) => props.currentPage === key;
</script>

<template>
    <div class="py-4">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 mb-3">
            <h2 class="text-[15px] font-semibold text-gray-700">Phân tích</h2>
            <button class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg
                    class="w-5 h-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M15 19l-7-7 7-7"
                    ></path>
                </svg>
            </button>
        </div>

        <!-- Sections -->
        <div v-for="section in sections" :key="section.key" class="mb-1">
            <!-- Section header -->
            <button
                @click="toggleSection(section)"
                class="w-full flex items-center gap-2 px-4 py-2 text-[13px] font-semibold text-blue-600 hover:bg-gray-50 transition-colors"
            >
                <!-- Icons -->
                <svg
                    v-if="section.icon === 'chart'"
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                    ></path>
                </svg>
                <svg
                    v-else-if="section.icon === 'box'"
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                    ></path>
                </svg>
                <svg
                    v-else-if="section.icon === 'users'"
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
                    ></path>
                </svg>
                <svg
                    v-else-if="section.icon === 'target'"
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                    ></path>
                </svg>

                <span>{{ section.label }}</span>

                <svg
                    class="w-3.5 h-3.5 ml-auto transition-transform"
                    :class="{ 'rotate-180': !section.open }"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M19 9l-7 7-7-7"
                    ></path>
                </svg>
            </button>

            <!-- Items -->
            <transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="max-h-0 opacity-0"
                enter-to-class="max-h-40 opacity-100"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="max-h-40 opacity-100"
                leave-to-class="max-h-0 opacity-0"
            >
                <div v-show="section.open" class="overflow-hidden">
                    <Link
                        v-for="item in section.items"
                        :key="item.key"
                        :href="item.href"
                        class="block pl-10 pr-4 py-2 text-[13px] transition-colors"
                        :class="
                            isActive(item.key)
                                ? 'bg-blue-50 text-blue-600 font-semibold border-r-2 border-blue-500'
                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-800'
                        "
                    >
                        {{ item.label }}
                    </Link>
                </div>
            </transition>
        </div>
    </div>
</template>
