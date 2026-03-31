<script setup>
import { Link, usePage, router } from "@inertiajs/vue3";
import { useSlots, ref, watch, onMounted, computed } from "vue";
import { usePermission } from "@/composables/usePermission";
import NotificationBell from "@/Components/NotificationBell.vue";

const slots = useSlots();
const page = usePage();
const { can, canAny, isAdmin } = usePermission();

const user = computed(() => page.props.auth?.user);
const userInitial = computed(() =>
    (user.value?.name || "U").charAt(0).toUpperCase(),
);

const logout = () => {
    router.post("/logout");
};

const showToast = ref(false);
const toastMessage = ref("");
const toastType = ref("success");

const triggerToast = () => {
    if (page.props.flash?.success) {
        toastMessage.value = page.props.flash.success;
        toastType.value = "success";
        showToast.value = true;
        setTimeout(() => (showToast.value = false), 3000);
        page.props.flash.success = null;
    } else if (page.props.flash?.error) {
        toastMessage.value = page.props.flash.error;
        toastType.value = "error";
        showToast.value = true;
        setTimeout(() => (showToast.value = false), 3000);
        page.props.flash.error = null;
    }
};

onMounted(triggerToast);
watch(() => page.props.flash, triggerToast, { deep: true });
</script>

<template>
    <div class="min-h-screen flex flex-col bg-[#f0f2f5]">
        <!-- Navbar -->
        <header
            class="bg-[#0070f4] text-white flex items-center justify-between px-4 h-14 shadow-sm z-50"
        >
            <div class="flex items-center gap-6">
                <div class="font-bold text-xl tracking-tight">
                    KiotViet Clone
                </div>
                <nav
                    class="hidden md:flex items-center space-x-1 text-sm font-medium"
                >
                    <Link
                        v-if="canAny(['dashboard.view'])"
                        href="/"
                        class="px-3 py-2 rounded hover:bg-[#005bb5]"
                        :class="{ 'bg-[#005bb5]': $page.url === '/' }"
                        >Tổng quan</Link
                    >
                    <div v-if="canAny(['products.view', 'stock.transfer', 'stock.take', 'purchases.view'])" class="relative group cursor-pointer">
                        <div
                            class="px-3 py-2 flex items-center gap-1 hover:bg-[#005bb5] rounded"
                            :class="{
                                'bg-[#005bb5]':
                                    $page.url.startsWith('/products') ||
                                    $page.url.startsWith('/suppliers'),
                            }"
                        >
                            Hàng hóa
                            <svg
                                class="w-4 h-4"
                                transform="rotate(180)"
                                v-if="
                                    $page.url.startsWith('/products') ||
                                    $page.url.startsWith('/suppliers')
                                "
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 9l-7 7-7-7"
                                ></path></svg
                            ><svg
                                class="w-4 h-4"
                                v-else
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
                        </div>

                        <!-- Mega Menu Dropdown -->
                        <div
                            class="absolute left-0 top-full mt-1 w-[550px] bg-white border border-gray-200 rounded shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 text-gray-800 text-[13.5px] flex font-normal overflow-hidden leading-normal"
                        >
                            <!-- Col 1: Hàng hóa -->
                            <div class="flex-1 py-1">
                                <h3
                                    class="px-4 py-3 font-bold text-gray-500 text-[12px] mb-1"
                                >
                                    Hàng hóa
                                </h3>
                                <Link
                                    href="/products"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Danh sách hàng hóa</Link
                                >
                                <Link
                                    href="/price-settings"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Thiết lập giá</Link
                                >
                                <Link
                                    v-if="
                                        $page.props.app_settings
                                            ?.warranty_enabled ?? true
                                    "
                                    href="/warranties"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Bảo hành, bảo trì</Link
                                >
                            </div>

                            <!-- Col 2: Kho hàng -->
                            <div class="flex-1 py-1 border-l border-gray-200">
                                <h3
                                    class="px-4 py-3 font-bold text-gray-500 text-[12px] mb-1"
                                >
                                    Kho hàng
                                </h3>
                                <Link
                                    href="/stock-transfers"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Chuyển hàng</Link
                                >
                                <Link
                                    v-if="
                                        $page.props.app_settings
                                            ?.production_enabled
                                    "
                                    href="#"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Sản xuất</Link
                                >
                                <Link
                                    href="/stock-takes"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Kiểm kho</Link
                                >
                                <Link
                                    href="/damages"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Xuất hủy</Link
                                >
                                <Link
                                    href="#"
                                    class="block px-4 py-3 hover:bg-gray-100 flex justify-between items-center group/item"
                                >
                                    Xuất dùng nội bộ
                                    <span
                                        class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full font-bold leading-none translate-y-[-1px]"
                                        >Sắp ra mắt</span
                                    >
                                </Link>
                            </div>

                            <!-- Col 3: Nhập hàng -->
                            <div
                                class="flex-1 py-1 border-l border-gray-200 bg-gray-50/30"
                            >
                                <h3
                                    class="px-4 py-3 font-bold text-gray-500 text-[12px] mb-1"
                                >
                                    Nhập hàng
                                </h3>
                                <Link
                                    href="#"
                                    class="block px-4 py-3 hover:bg-gray-100 flex justify-between items-center"
                                >
                                    Hóa đơn đầu vào
                                    <span
                                        class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full font-bold leading-none translate-y-[-1px]"
                                        >Mới</span
                                    >
                                </Link>
                                <Link
                                    href="/suppliers"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Nhà cung cấp</Link
                                >
                                <Link
                                    v-if="
                                        $page.props.app_settings
                                            ?.purchase_order_enabled ?? true
                                    "
                                    href="/purchase-orders"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Đặt hàng nhập</Link
                                >
                                <Link
                                    href="/purchases"
                                    class="block px-4 py-3 hover:bg-gray-100"
                                    >Nhập hàng</Link
                                >
                                <Link
                                    href="/purchases"
                                    class="block px-4 py-3 hover:bg-gray-100 bg-gray-100 border-t border-gray-200"
                                    >Trả hàng nhập</Link
                                >
                            </div>
                        </div>
                    </div>
                    <div v-if="canAny(['orders.view', 'invoices.view'])" class="relative group">
                        <button
                            class="px-3 py-2 hover:bg-[#005bb5] rounded flex items-center gap-1"
                            :class="{
                                'bg-[#005bb5]':
                                    $page.url.startsWith('/orders') ||
                                    $page.url.startsWith('/invoices') ||
                                    $page.url.startsWith('/returns'),
                            }"
                        >
                            Đơn hàng
                        </button>
                        <div
                            class="absolute left-0 mt-0 w-48 bg-white rounded shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 pt-1 border border-gray-100"
                        >
                            <div class="bg-white rounded py-1">
                                <Link
                                    href="/orders"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Đặt hàng</Link
                                >
                                <Link
                                    href="/invoices"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Hóa đơn</Link
                                >
                                <Link
                                    href="/returns"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Trả hàng</Link
                                >
                                <Link
                                    href="#"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Yêu cầu sửa chữa</Link
                                >
                                <Link
                                    href="#"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Đối tác giao hàng</Link
                                >
                                <Link
                                    href="#"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Vận đơn</Link
                                >
                            </div>
                        </div>
                    </div>
                    <Link
                        v-if="canAny(['customers.view'])"
                        href="/customers"
                        class="px-3 py-2 hover:bg-[#005bb5] rounded"
                        :class="{
                            'bg-[#005bb5]': $page.url.startsWith('/customers'),
                        }"
                        >Khách hàng</Link
                    >
                    <div v-if="canAny(['employees.view'])" class="relative group">
                        <button
                            class="px-3 py-2 hover:bg-[#005bb5] rounded flex items-center gap-1"
                            :class="{
                                'bg-[#005bb5]':
                                    $page.url.startsWith('/employees'),
                            }"
                        >
                            Nhân viên
                        </button>
                        <div
                            class="absolute left-0 mt-0 w-48 bg-white rounded shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 pt-1 border border-gray-100"
                        >
                            <div class="bg-white rounded py-1">
                                <Link
                                    href="/employees"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Danh sách nhân viên</Link
                                >
                                <Link
                                    href="/employees/schedules"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Lịch làm việc</Link
                                >
                                <Link
                                    href="/employees/attendance"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Bảng chấm công</Link
                                >
                                <Link
                                    href="/employees/paysheets"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Bảng lương</Link
                                >
                                <Link
                                    href="#"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Bảng hoa hồng</Link
                                >
                                <Link
                                    href="/employees/settings"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Thiết lập nhân viên</Link
                                >
                            </div>
                        </div>
                    </div>
                    <Link
                        v-if="canAny(['cashbook.view'])"
                        href="/cash-flows"
                        class="px-3 py-2 hover:bg-[#005bb5] rounded"
                        :class="{
                            'bg-[#005bb5]': $page.url.startsWith('/cash-flows'),
                        }"
                        >Sổ quỹ</Link
                    >
                    <!-- Công việc — chỉ hiện khi module bật + có quyền -->
                    <div v-if="($page.props.app_settings?.task_module_enabled || $page.props.app_settings?.repair_tracking_enabled) && canAny(['tasks.view', 'repairs.view'])" class="relative group">
                        <button
                            class="px-3 py-2 hover:bg-[#005bb5] rounded flex items-center gap-1"
                            :class="{
                                'bg-[#005bb5]':
                                    $page.url.startsWith('/tasks') || $page.url.startsWith('/my-tasks'),
                            }"
                        >
                            Công việc
                        </button>
                        <div
                            class="absolute left-0 mt-0 w-48 bg-white rounded shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 pt-1 border border-gray-100"
                        >
                            <div class="bg-white rounded py-1">
                                <Link
                                    href="/tasks"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Danh sách công việc</Link
                                >
                                <Link
                                    href="/my-tasks"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Việc của tôi</Link
                                >
                                <Link
                                    href="/tasks/performance"
                                    class="block px-4 py-2 text-[14px] text-gray-700 hover:bg-gray-100"
                                    >Báo cáo năng suất</Link
                                >
                            </div>
                        </div>
                    </div>
                    <Link href="#" class="px-3 py-2 hover:bg-[#005bb5] rounded"
                        >Phân tích</Link
                    >
                </nav>
            </div>

            <div class="flex items-center gap-4 text-sm font-medium">
                <Link
                    href="/pos"
                    class="bg-white text-blue-600 px-4 py-1.5 rounded-full font-bold flex items-center gap-2 hover:bg-gray-100 transition-colors"
                >
                    <svg
                        class="w-4 h-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"
                        ></path>
                    </svg>
                    Bán hàng
                </Link>
                <NotificationBell />
                <div class="relative group ml-4 cursor-pointer">
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 bg-blue-800 rounded-full flex items-center justify-center text-white font-bold"
                        >
                            {{ userInitial }}
                        </div>
                        <span>{{ user?.name || "User" }}</span>
                        <svg
                            class="w-4 h-4"
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
                    </div>

                    <!-- User Dropdown -->
                    <div
                        class="absolute right-0 top-full mt-0 w-56 bg-white border border-gray-200 rounded shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50 text-gray-800 text-[13.5px] font-normal py-1"
                    >
                        <div
                            class="px-4 py-3 border-b border-gray-100 bg-gray-50/50"
                        >
                            <div class="font-bold">
                                {{ user?.name || "User" }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ user?.email || "" }}
                            </div>
                        </div>
                        <Link
                            href="/settings"
                            class="block px-4 py-2.5 hover:bg-gray-100 flex items-center gap-2"
                        >
                            <svg
                                class="w-4 h-4 text-gray-500"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                                ></path>
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                ></path>
                            </svg>
                            Thiết lập
                        </Link>
                        <Link
                            href="#"
                            class="block px-4 py-2.5 hover:bg-gray-100 flex items-center gap-2"
                        >
                            <svg
                                class="w-4 h-4 text-gray-500"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                                ></path>
                            </svg>
                            Đổi mật khẩu
                        </Link>
                        <div class="border-t border-gray-100 mt-1 pt-1">
                            <button
                                @click="logout"
                                class="w-full text-left block px-4 py-2.5 hover:bg-gray-100 text-red-600 flex items-center gap-2"
                            >
                                <svg
                                    class="w-4 h-4"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                                    ></path>
                                </svg>
                                Đăng xuất
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content wrapper -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Sidebar slot (if provided) -->
            <aside
                v-if="slots.sidebar"
                class="w-64 bg-white border-r border-gray-200 overflow-y-auto flex-shrink-0 z-10 shadow-[2px_0_5px_-2px_rgba(0,0,0,0.1)] h-[calc(100vh-3.5rem)] sticky top-0"
            >
                <slot name="sidebar"></slot>
            </aside>

            <!-- Main working area -->
            <main
                class="flex-1 overflow-x-hidden overflow-y-auto bg-[#eff3f6] p-4 h-[calc(100vh-3.5rem)] relative"
            >
                <slot></slot>
            </main>
        </div>
    </div>

    <!-- Global Toast Flash Message -->
    <transition
        enter-active-class="transform transition ease-out duration-300"
        enter-from-class="translate-x-full opacity-0"
        enter-to-class="translate-x-0 opacity-100"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="translate-x-0 opacity-100"
        leave-to-class="translate-x-full opacity-0"
    >
        <div
            v-if="showToast"
            class="fixed top-20 right-4 z-[9999] max-w-sm w-full bg-white shadow-lg rounded pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden"
        >
            <div class="p-4 flex items-start">
                <div class="flex-shrink-0">
                    <svg
                        v-if="toastType === 'success'"
                        class="h-6 w-6 text-green-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                        ></path>
                    </svg>
                    <svg
                        v-else
                        class="h-6 w-6 text-red-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                        ></path>
                    </svg>
                </div>
                <div class="ml-3 w-0 flex-1 pt-0.5">
                    <p class="text-sm font-medium text-gray-900">
                        {{
                            toastType === "success"
                                ? "Thành công"
                                : "Có lỗi xảy ra"
                        }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500">{{ toastMessage }}</p>
                </div>
                <div class="ml-4 flex-shrink-0 flex">
                    <button
                        @click="showToast = false"
                        class="bg-white rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        <span class="sr-only">Đóng</span>
                        <svg
                            class="h-5 w-5"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>
