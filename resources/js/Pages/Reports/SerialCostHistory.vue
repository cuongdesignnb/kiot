<script setup>
import { formatVND as fmt } from '@/utils/money';
import { ref } from "vue";
import { router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    logs: Object, // paginator
    stats: Object,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const dateFrom = ref(props.filters?.date_from || "");
const dateTo = ref(props.filters?.date_to || "");

const apply = () => {
    router.get(
        "/reports/serial-cost-history",
        {
            search: search.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        },
        { preserveState: true, preserveScroll: true }
    );
};

let timer = null;
const onSearchInput = () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 400);
};


const fmtDate = (s) => {
    if (!s) return "";
    const d = new Date(s);
    return d.toLocaleString("vi-VN");
};

const goPage = (url) => {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="serial-cost-history" />
        </template>

        <div class="max-w-[1400px] mx-auto">
            <div class="mb-5">
                <h1 class="text-xl font-bold text-gray-800">
                    Lịch sử thay đổi giá vốn Serial
                </h1>
                <p class="text-xs text-gray-500 mt-1">
                    Ghi nhận các lần admin sửa giá vốn từng serial qua màn quản lý
                    sản phẩm.
                </p>
            </div>

            <!-- Filters -->
            <div
                class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap items-end gap-3"
            >
                <div class="flex-1 min-w-[240px]">
                    <label class="block text-xs text-gray-500 mb-1">Tìm kiếm</label>
                    <input
                        v-model="search"
                        @input="onSearchInput"
                        type="text"
                        placeholder="Tên SP, số serial..."
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-full"
                    />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Từ ngày</label>
                    <input
                        type="date"
                        v-model="dateFrom"
                        @change="apply"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-40"
                    />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Đến ngày</label>
                    <input
                        type="date"
                        v-model="dateTo"
                        @change="apply"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-40"
                    />
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-5">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng lượt sửa</div>
                    <div class="text-xl font-bold text-gray-800">
                        {{ stats?.total ?? 0 }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tháng này</div>
                    <div class="text-xl font-bold text-blue-700">
                        {{ stats?.this_month ?? 0 }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Hôm nay</div>
                    <div class="text-xl font-bold text-green-700">
                        {{ stats?.today ?? 0 }}
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div
                class="bg-white rounded-lg border border-gray-200 overflow-hidden"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr class="text-xs text-gray-600 uppercase">
                                <th class="px-3 py-2 text-left">Thời điểm</th>
                                <th class="px-3 py-2 text-left">Người thực hiện</th>
                                <th class="px-3 py-2 text-left">Mô tả</th>
                                <th class="px-3 py-2 text-right">Giá vốn cũ</th>
                                <th class="px-3 py-2 text-right">Giá vốn mới</th>
                                <th class="px-3 py-2 text-right">Chênh</th>
                                <th class="px-3 py-2 text-center">Sản phẩm</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!logs?.data?.length">
                                <td
                                    colspan="7"
                                    class="px-3 py-8 text-center text-gray-400"
                                >
                                    Chưa có lịch sử thay đổi
                                </td>
                            </tr>
                            <tr
                                v-for="log in logs?.data || []"
                                :key="log.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">
                                    {{ fmtDate(log.created_at) }}
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-sm text-gray-800">
                                        {{
                                            log.employee?.name ||
                                            log.user?.name ||
                                            "—"
                                        }}
                                    </div>
                                    <div
                                        v-if="log.employee?.code"
                                        class="text-xs text-gray-500"
                                    >
                                        {{ log.employee.code }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-gray-700">
                                    {{ log.description }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(log.properties?.old_cost) }}
                                </td>
                                <td class="px-3 py-2 text-right font-semibold">
                                    {{ fmt(log.properties?.new_cost) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right"
                                    :class="
                                        (log.properties?.new_cost ?? 0) -
                                            (log.properties?.old_cost ?? 0) >
                                        0
                                            ? 'text-green-700'
                                            : 'text-red-700'
                                    "
                                >
                                    {{
                                        fmt(
                                            (log.properties?.new_cost ?? 0) -
                                                (log.properties?.old_cost ?? 0)
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <Link
                                        v-if="log.properties?.product_id"
                                        :href="`/products/${log.properties.product_id}/edit`"
                                        class="text-blue-600 hover:underline text-xs"
                                        >Mở SP</Link
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="logs?.last_page > 1"
                    class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-gray-50"
                >
                    <div class="text-xs text-gray-600">
                        Trang {{ logs.current_page }} / {{ logs.last_page }} —
                        Tổng {{ logs.total }} bản ghi
                    </div>
                    <div class="flex gap-1">
                        <button
                            v-for="link in logs.links"
                            :key="link.label"
                            :disabled="!link.url"
                            @click="goPage(link.url)"
                            class="px-3 py-1 text-sm rounded border"
                            :class="
                                link.active
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100'
                            "
                            v-html="link.label"
                        ></button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
