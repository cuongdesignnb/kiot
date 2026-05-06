<template>
    <Head title="Bảng lương - KiotViet Clone" />
    <AppLayout>
        <div class="h-screen flex flex-col bg-gray-50 font-sans">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 px-6 py-3">
                <div class="flex items-center justify-between">
                    <h1 class="text-lg font-bold text-gray-800">Bảng lương</h1>
                    <div class="flex items-center gap-3">
                        <!-- Search -->
                        <div class="relative">
                            <svg
                                class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                />
                            </svg>
                            <input
                                v-model="searchQuery"
                                @input="debouncedFetch"
                                type="text"
                                placeholder="Theo mã, tên bảng lương"
                                class="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md w-56 outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            />
                        </div>
                        <button
                            @click="openCreateModal"
                            class="flex items-center gap-1.5 px-4 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition"
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
                                    d="M12 4v16m8-8H4"
                                />
                            </svg>
                            Bảng tính lương
                        </button>
                        <ExcelButtons export-url="/paysheets/export" />
                    </div>
                </div>
            </header>

            <div class="flex flex-1 overflow-hidden">
                <!-- Sidebar Filters -->
                <aside
                    class="w-56 bg-white border-r border-gray-200 p-4 space-y-5 overflow-y-auto flex-shrink-0"
                >
                    <!-- Chi nhánh -->
                    <div>
                        <label
                            class="block text-sm font-semibold text-gray-700 mb-2"
                            >Chi nhánh</label
                        >
                        <div
                            v-if="selectedBranch"
                            class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 text-xs px-2.5 py-1 rounded-full font-medium"
                        >
                            {{ selectedBranch.name }}
                            <button
                                @click="
                                    selectedBranch = null;
                                    fetchPaysheets();
                                "
                                class="hover:text-blue-900"
                            >
                                &times;
                            </button>
                        </div>
                        <select
                            v-else
                            v-model="selectedBranchId"
                            @change="onBranchChange"
                            class="w-full text-sm border border-gray-300 rounded-md px-2 py-1.5 outline-none focus:ring-1 focus:ring-blue-500"
                        >
                            <option :value="null">Tất cả chi nhánh</option>
                            <option
                                v-for="b in branches"
                                :key="b.id"
                                :value="b.id"
                            >
                                {{ b.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Kỳ hạn trả lương -->
                    <div>
                        <label
                            class="block text-sm font-semibold text-gray-700 mb-2"
                            >Kỳ hạn trả lương</label
                        >
                        <select
                            v-model="filterPeriod"
                            @change="fetchPaysheets()"
                            class="w-full text-sm border border-gray-300 rounded-md px-2 py-1.5 outline-none focus:ring-1 focus:ring-blue-500"
                        >
                            <option value="">Chọn kỳ hạn trả lương</option>
                            <option value="monthly">Hàng tháng</option>
                            <option value="biweekly">Hai tuần</option>
                        </select>
                    </div>

                    <!-- Trạng thái -->
                    <div>
                        <label
                            class="block text-sm font-semibold text-gray-700 mb-2"
                            >Trạng thái</label
                        >
                        <div class="space-y-2">
                            <label
                                v-for="st in statusOptions"
                                :key="st.value"
                                class="flex items-center gap-2 cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    v-model="st.checked"
                                    @change="fetchPaysheets()"
                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 h-4 w-4"
                                />
                                <span class="text-sm" :class="st.color">{{
                                    st.label
                                }}</span>
                            </label>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1 overflow-auto">
                    <div
                        v-if="loading"
                        class="flex justify-center items-center h-64"
                    >
                        <svg
                            class="animate-spin h-8 w-8 text-blue-600"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            />
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            />
                        </svg>
                    </div>

                    <div v-else>
                        <table class="w-full">
                            <thead
                                class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10"
                            >
                                <tr>
                                    <th class="w-10 px-4 py-2.5">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-blue-600 h-4 w-4"
                                        />
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-medium text-gray-500"
                                    >
                                        Mã
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-medium text-gray-500"
                                    >
                                        Tên
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-medium text-gray-500"
                                    >
                                        Kỳ hạn trả
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-medium text-gray-500"
                                    >
                                        Kỳ làm việc
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-medium text-gray-500"
                                    >
                                        Chi nhánh
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right text-xs font-medium text-gray-500"
                                    >
                                        Tổng lương
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right text-xs font-medium text-gray-500"
                                    >
                                        Đã trả NV
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right text-xs font-medium text-gray-500"
                                    >
                                        Còn cần trả
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-medium text-gray-500"
                                    >
                                        Trạng thái
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Summary row -->
                                <tr
                                    class="bg-gray-50 border-b border-gray-200 font-semibold text-sm"
                                >
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td
                                        class="px-4 py-2 text-right text-gray-700"
                                    >
                                        {{ formatMoney(summary.total_salary) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right text-gray-700"
                                    >
                                        {{ formatMoney(summary.total_paid) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right text-gray-700"
                                    >
                                        {{
                                            formatMoney(summary.total_remaining)
                                        }}
                                    </td>
                                    <td></td>
                                </tr>

                                <template v-if="paysheets.length === 0">
                                    <tr>
                                        <td
                                            colspan="10"
                                            class="px-6 py-10 text-center text-gray-400"
                                        >
                                            Chưa có bảng lương nào.
                                        </td>
                                    </tr>
                                </template>

                                <template v-for="ps in paysheets" :key="ps.id">
                                    <!-- Row -->
                                    <tr
                                        @click="toggleExpand(ps.id)"
                                        class="border-b border-gray-200 cursor-pointer text-sm hover:bg-blue-50 transition"
                                        :class="
                                            expandedId === ps.id
                                                ? 'bg-blue-50'
                                                : 'bg-white'
                                        "
                                    >
                                        <td class="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                @click.stop
                                                class="rounded border-gray-300 text-blue-600 h-4 w-4"
                                            />
                                        </td>
                                        <td
                                            class="px-4 py-3 font-medium text-gray-800"
                                        >
                                            {{ ps.code }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            {{ ps.name }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            {{
                                                ps.pay_period === "monthly"
                                                    ? "Hàng tháng"
                                                    : "Hai tuần"
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            {{ formatDate(ps.period_start) }} -
                                            {{ formatDate(ps.period_end) }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            {{ ps.branch?.name || "-" }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right font-medium text-gray-800"
                                        >
                                            {{ formatMoney(ps.total_salary) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-gray-600"
                                        >
                                            {{ formatMoney(ps.total_paid) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-gray-600"
                                        >
                                            {{
                                                formatMoney(ps.total_remaining)
                                            }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                                :class="
                                                    getStatusClass(ps.status)
                                                "
                                                >{{
                                                    getStatusLabel(ps.status)
                                                }}</span
                                            >
                                        </td>
                                    </tr>

                                    <!-- Expandable detail panel -->
                                    <tr v-if="expandedId === ps.id">
                                        <td
                                            colspan="10"
                                            class="bg-white border-b-2 border-blue-200"
                                        >
                                            <!-- Tabs -->
                                            <div
                                                class="border-b border-gray-200 px-6"
                                            >
                                                <nav class="flex gap-6">
                                                    <button
                                                        v-for="tab in detailTabs"
                                                        :key="tab.key"
                                                        @click="
                                                            activeTab = tab.key
                                                        "
                                                        class="py-3 text-sm font-medium border-b-2 transition"
                                                        :class="
                                                            activeTab ===
                                                            tab.key
                                                                ? 'border-blue-600 text-blue-600'
                                                                : 'border-transparent text-gray-500 hover:text-gray-700'
                                                        "
                                                    >
                                                        {{ tab.label }}
                                                    </button>
                                                </nav>
                                            </div>

                                            <!-- Tab: Thông tin -->
                                            <div
                                                v-show="activeTab === 'info'"
                                                class="px-6 py-5"
                                            >
                                                <div
                                                    class="grid grid-cols-4 gap-x-8 gap-y-4 text-sm"
                                                >
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Mã:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{ ps.code }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Tên:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{ ps.name }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Kỳ hạn trả:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.pay_period ===
                                                                "monthly"
                                                                    ? "Hàng tháng"
                                                                    : "Hai tuần"
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Kỳ làm việc:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                formatDate(
                                                                    ps.period_start,
                                                                )
                                                            }}
                                                            -
                                                            {{
                                                                formatDate(
                                                                    ps.period_end,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Ngày tạo:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                formatDateTime(
                                                                    ps.created_at,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Người tạo:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.created_by ||
                                                                "Admin"
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Người lập
                                                            bảng:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.created_by ||
                                                                "Admin"
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Trạng thái:</span
                                                        >
                                                        <div class="mt-0.5">
                                                            <span
                                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                                                :class="
                                                                    getStatusClass(
                                                                        ps.status,
                                                                    )
                                                                "
                                                                >{{
                                                                    getStatusLabel(
                                                                        ps.status,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Tổng số nhân
                                                            viên:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.employee_count
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Tổng lương:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                formatMoney(
                                                                    ps.total_salary,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Đã trả nhân
                                                            viên:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                formatMoney(
                                                                    ps.total_paid,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Còn cần trả:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                formatMoney(
                                                                    ps.total_remaining,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Chi nhánh:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.branch
                                                                    ?.name ||
                                                                "-"
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Phạm vi áp
                                                            dụng:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.scope ===
                                                                "all"
                                                                    ? "Tất cả nhân viên"
                                                                    : "Tùy chọn"
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Người chốt
                                                            lương:</span
                                                        >
                                                        <div
                                                            class="font-semibold text-gray-800 mt-0.5"
                                                        >
                                                            {{
                                                                ps.locked_by ||
                                                                "-"
                                                            }}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500"
                                                            >Ghi chú:</span
                                                        >
                                                        <textarea
                                                            v-model="editNotes"
                                                            @blur="
                                                                saveNotes(ps)
                                                            "
                                                            rows="2"
                                                            class="mt-0.5 w-full text-sm border border-gray-200 rounded px-2 py-1 outline-none focus:ring-1 focus:ring-blue-500"
                                                            placeholder="Ghi chú..."
                                                        ></textarea>
                                                    </div>
                                                </div>

                                                <!-- Actions -->
                                                <div
                                                    class="flex items-center justify-between mt-6 pt-4 border-t border-gray-200"
                                                >
                                                    <div
                                                        class="flex items-center gap-4"
                                                    >
                                                        <button
                                                            v-if="
                                                                ps.status !==
                                                                    'locked' &&
                                                                ps.status !==
                                                                    'cancelled'
                                                            "
                                                            @click="
                                                                cancelPaysheet(
                                                                    ps,
                                                                )
                                                            "
                                                            class="flex items-center gap-1.5 text-sm text-red-600 hover:text-red-700"
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
                                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                                />
                                                            </svg>
                                                            Hủy bỏ
                                                        </button>
                                                        <span
                                                            class="text-xs text-gray-400"
                                                            >Dữ liệu được cập
                                                            nhật vào:
                                                            {{
                                                                formatDateTime(
                                                                    ps.updated_at,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="flex items-center gap-3"
                                                    >
                                                        <button
                                                            v-if="
                                                                ps.status !==
                                                                    'locked' &&
                                                                ps.status !==
                                                                    'cancelled'
                                                            "
                                                            @click.stop="
                                                                recalculatePaysheet(
                                                                    ps,
                                                                )
                                                            "
                                                            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition"
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
                                                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                                                />
                                                            </svg>
                                                            Tải lại dữ liệu
                                                        </button>
                                                        <button
                                                            v-if="
                                                                ps.status ===
                                                                'calculated'
                                                            "
                                                            @click.stop="
                                                                router.visit(`/employees/paysheets/${ps.id}/edit`)
                                                            "
                                                            class="flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700 transition"
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
                                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                                                />
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                                                />
                                                            </svg>
                                                            Xem bảng lương
                                                        </button>
                                                        <button
                                                            v-if="
                                                                ps.status ===
                                                                'calculated'
                                                            "
                                                            @click.stop="
                                                                lockPaysheet(ps)
                                                            "
                                                            class="flex items-center gap-1.5 px-4 py-2 bg-green-600 text-white rounded-md text-sm hover:bg-green-700 transition"
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
                                                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                                                                />
                                                            </svg>
                                                            Chốt lương
                                                        </button>
                                                        <button
                                                            @click.stop="
                                                                printPaysheet(
                                                                    ps,
                                                                )
                                                            "
                                                            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition"
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
                                                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                                                                />
                                                            </svg>
                                                            In
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Tab: Phiếu lương -->
                                            <div
                                                v-show="
                                                    activeTab === 'payslips'
                                                "
                                                class="px-0"
                                            >
                                                <div
                                                    v-if="detailLoading"
                                                    class="flex justify-center py-10"
                                                >
                                                    <svg
                                                        class="animate-spin h-6 w-6 text-blue-600"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <circle
                                                            class="opacity-25"
                                                            cx="12"
                                                            cy="12"
                                                            r="10"
                                                            stroke="currentColor"
                                                            stroke-width="4"
                                                        />
                                                        <path
                                                            class="opacity-75"
                                                            fill="currentColor"
                                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                                        />
                                                    </svg>
                                                </div>
                                                <table
                                                    v-else
                                                    class="w-full text-sm table-fixed"
                                                >
                                                    <thead
                                                        class="bg-gray-50 border-b border-gray-200 sticky top-0"
                                                    >
                                                        <tr>
                                                            <th class="w-10 px-3 py-2">
                                                                <input
                                                                    type="checkbox"
                                                                    v-model="selectAllSlips"
                                                                    @change="toggleSelectAllSlips"
                                                                    class="rounded border-gray-300 text-blue-600 h-4 w-4"
                                                                />
                                                            </th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 w-10">STT</th>
                                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 min-w-[140px]">Tên nhân viên</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[120px]">Lương chính</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[110px]">Làm thêm</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[110px]">Hoa hồng</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[110px]">Phụ cấp</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[110px]">Thưởng</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[110px]">Giảm trừ</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[120px]">Tổng lương</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[100px]">Đã trả NV</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 w-[110px]">Còn cần trả</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <!-- Summary row -->
                                                        <tr class="bg-blue-50 border-b font-semibold text-sm">
                                                            <td></td>
                                                            <td></td>
                                                            <td></td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.base_salary) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.ot_pay) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.commission) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.allowances) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.bonus) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.deductions) }}</td>
                                                            <td class="px-3 py-2 text-right text-blue-700">{{ formatMoney(editSummary.total_salary) }}</td>
                                                            <td class="px-3 py-2 text-right">{{ formatMoney(editSummary.paid_amount) }}</td>
                                                            <td class="px-3 py-2 text-right text-orange-600">{{ formatMoney(editSummary.remaining) }}</td>
                                                        </tr>

                                                        <template
                                                            v-for="(slip, idx) in detailPayslips"
                                                            :key="slip.id"
                                                        >
                                                        <tr
                                                            class="border-b border-gray-200 hover:bg-gray-50"
                                                            :class="{ 'bg-yellow-50': slip._dirty }"
                                                        >
                                                            <td class="px-3 py-1.5">
                                                                <input
                                                                    type="checkbox"
                                                                    v-model="selectedSlipIds"
                                                                    :value="slip.id"
                                                                    class="rounded border-gray-300 text-blue-600 h-4 w-4"
                                                                />
                                                            </td>
                                                            <td class="px-3 py-1.5 text-gray-400 text-xs">{{ idx + 1 }}</td>
                                                            <td class="px-3 py-1.5">
                                                                <div class="font-medium text-blue-600 cursor-pointer" @click="toggleSlipDetail(slip.id)">
                                                                    {{ slip.employee?.name }}
                                                                </div>
                                                                <div class="text-xs text-gray-400">{{ slip.employee?.code || slip.code }}</div>
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.base_salary)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'base_salary', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-full text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.ot_pay)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'ot_pay', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-full text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.commission)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'commission', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-full text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.allowances)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'allowances', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-full text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.bonus)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'bonus', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-full text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <div
                                                                    @click="ps.status !== 'locked' && openDeductionModal(slip)"
                                                                    class="w-full text-right border border-gray-200 rounded px-2 py-1 text-sm cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition"
                                                                    :class="ps.status === 'locked' ? 'bg-gray-100 cursor-not-allowed' : ''"
                                                                >{{ formatNumber(slip.deductions) }}</div>
                                                            </td>
                                                            <td class="px-3 py-1.5 text-right font-semibold text-blue-700">
                                                                {{ formatMoney(slip.total_salary) }}
                                                            </td>
                                                            <td class="px-3 py-1.5 text-right text-gray-600">
                                                                {{ formatMoney(slip.paid_amount) }}
                                                            </td>
                                                            <td class="px-3 py-1.5 text-right font-medium" :class="slip.remaining > 0 ? 'text-orange-600' : 'text-green-600'">
                                                                {{ formatMoney(slip.remaining) }}
                                                            </td>
                                                        </tr>

                                                        <!-- Chi tiết phiếu lương (expandable) -->
                                                        <tr v-if="expandedSlipId === slip.id">
                                                            <td colspan="12" class="bg-gray-50 px-6 py-4">
                                                                <div class="grid grid-cols-2 gap-6 text-sm">
                                                                    <!-- Cột trái: Thông tin lương -->
                                                                    <div>
                                                                        <h4 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                                                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                                            Thông tin lương
                                                                        </h4>
                                                                        <div class="bg-white rounded-lg border border-gray-200 divide-y divide-gray-100">
                                                                            <!-- Lương chính -->
                                                                            <div class="flex justify-between px-4 py-2.5">
                                                                                <span class="text-gray-600">Lương chính</span>
                                                                                <span class="font-medium">{{ formatMoney(slip.base_salary) }}</span>
                                                                            </div>
                                                                            <div class="px-4 py-1.5 text-xs text-gray-400 bg-gray-50" v-if="slip.details?.work_units !== undefined">
                                                                                Ngày công: {{ slip.details?.work_units ?? slip.work_units }}/{{ slip.details?.standard_work_units ?? '-' }}
                                                                                <template v-if="slip.details?.paid_leave_units > 0"> (nghỉ phép: {{ slip.details.paid_leave_units }})</template>
                                                                            </div>
                                                                            <!-- Làm thêm -->
                                                                            <div class="flex justify-between px-4 py-2.5">
                                                                                <span class="text-gray-600">Làm thêm</span>
                                                                                <span class="font-medium" :class="slip.ot_pay > 0 ? 'text-green-600' : ''">{{ slip.ot_pay > 0 ? '+' : '' }}{{ formatMoney(slip.ot_pay) }}</span>
                                                                            </div>
                                                                            <div class="px-4 py-1.5 text-xs text-gray-400 bg-gray-50" v-if="slip.details?.ot_minutes > 0 || slip.details?.holiday_pay > 0">
                                                                                <span v-if="slip.details?.ot_minutes > 0">OT: {{ Math.round(slip.details.ot_minutes / 60 * 10) / 10 }}h</span>
                                                                                <span v-if="slip.details?.ot_minutes > 0 && slip.details?.holiday_pay > 0"> · </span>
                                                                                <span v-if="slip.details?.holiday_pay > 0">Ngày lễ/nghỉ: +{{ formatMoney(slip.details.holiday_pay) }}</span>
                                                                            </div>
                                                                            <!-- Hoa hồng -->
                                                                            <div class="flex justify-between px-4 py-2.5">
                                                                                <span class="text-gray-600">Hoa hồng</span>
                                                                                <span class="font-medium" :class="slip.commission > 0 ? 'text-green-600' : ''">{{ slip.commission > 0 ? '+' : '' }}{{ formatMoney(slip.commission) }}</span>
                                                                            </div>
                                                                            <!-- Phụ cấp -->
                                                                            <div class="flex justify-between px-4 py-2.5">
                                                                                <span class="text-gray-600">Phụ cấp</span>
                                                                                <span class="font-medium" :class="slip.allowances > 0 ? 'text-green-600' : ''">{{ slip.allowances > 0 ? '+' : '' }}{{ formatMoney(slip.allowances) }}</span>
                                                                            </div>
                                                                            <!-- Thưởng -->
                                                                            <div class="flex justify-between px-4 py-2.5">
                                                                                <span class="text-gray-600">Thưởng</span>
                                                                                <span class="font-medium" :class="slip.bonus > 0 ? 'text-green-600' : ''">{{ slip.bonus > 0 ? '+' : '' }}{{ formatMoney(slip.bonus) }}</span>
                                                                            </div>
                                                                            <!-- Giảm trừ -->
                                                                            <div class="flex justify-between px-4 py-2.5">
                                                                                <span class="text-gray-600">Giảm trừ</span>
                                                                                <span class="font-medium text-red-600">{{ slip.deductions > 0 ? '-' : '' }}{{ formatMoney(slip.deductions) }}</span>
                                                                            </div>
                                                                            <!-- Thực lĩnh -->
                                                                            <div class="flex justify-between px-4 py-3 bg-blue-50">
                                                                                <span class="text-gray-800 font-bold">Thực lĩnh</span>
                                                                                <span class="font-bold text-blue-600 text-base">{{ formatMoney(slip.total_salary) }}</span>
                                                                            </div>
                                                                        </div>
                                                                        <!-- Attendance summary -->
                                                                        <div class="mt-3 text-xs text-gray-400 space-y-0.5" v-if="slip.details">
                                                                            <div v-if="slip.details.late_count > 0">Đi muộn: {{ slip.details.late_count }} lần ({{ slip.details.late_minutes }} phút)</div>
                                                                            <div v-if="slip.details.early_leave_count > 0">Về sớm: {{ slip.details.early_leave_count }} lần ({{ slip.details.early_minutes }} phút)</div>
                                                                            <div v-if="slip.details.personal_revenue > 0">Doanh thu cá nhân: {{ formatMoney(slip.details.personal_revenue) }}</div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Cột phải: Chi tiết giảm trừ -->
                                                                    <div>
                                                                        <h4 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                                                                            <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                            Chi tiết giảm trừ
                                                                        </h4>
                                                                        <template v-if="slip.details?.details?.deductions?.length || slip.details?.details?.late_penalty?.length">
                                                                            <!-- Custom/template deductions -->
                                                                            <div class="space-y-2">
                                                                                <div v-for="(ded, i) in (slip.details?.details?.deductions || [])" :key="'ded-'+i"
                                                                                    class="bg-white rounded-lg border border-gray-200 px-4 py-2.5">
                                                                                    <div class="flex justify-between items-center">
                                                                                        <div>
                                                                                            <span class="font-medium text-gray-800">{{ ded.name }}</span>
                                                                                            <span class="ml-2 text-xs px-1.5 py-0.5 rounded-full"
                                                                                                :class="ded.category === 'late' ? 'bg-orange-100 text-orange-600' : ded.category === 'early_leave' ? 'bg-yellow-100 text-yellow-600' : 'bg-gray-100 text-gray-500'">
                                                                                                {{ categoryLabel(ded.category) }}
                                                                                            </span>
                                                                                        </div>
                                                                                        <span class="font-semibold text-red-600">-{{ formatMoney(ded.calculated) }}</span>
                                                                                    </div>
                                                                                    <div class="text-xs text-gray-400 mt-1">
                                                                                        <template v-if="ded.calc_type === 'per_minute'">
                                                                                            {{ ded.total_minutes || 0 }} phút × {{ formatMoney(ded.config_amount) }}/phút
                                                                                        </template>
                                                                                        <template v-else-if="ded.calc_type === 'per_occurrence'">
                                                                                            {{ ded.occurrences }} lần × {{ formatMoney(ded.config_amount) }}/lần
                                                                                        </template>
                                                                                        <template v-else>
                                                                                            {{ calcTypeLabel(ded.calc_type) }}
                                                                                        </template>
                                                                                    </div>
                                                                                </div>
                                                                            </div>

                                                                            <!-- Tier-based late penalties -->
                                                                            <template v-if="slip.details?.details?.late_penalty?.length">
                                                                                <h5 class="font-medium text-gray-600 mt-4 mb-2 text-xs uppercase tracking-wide">Phạt đi muộn theo mức</h5>
                                                                                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                                                                                    <table class="w-full text-xs">
                                                                                        <thead class="bg-gray-50">
                                                                                            <tr>
                                                                                                <th class="px-3 py-2 text-left text-gray-500 font-medium">Ngày</th>
                                                                                                <th class="px-3 py-2 text-right text-gray-500 font-medium">Muộn (phút)</th>
                                                                                                <th class="px-3 py-2 text-right text-gray-500 font-medium">Mức phạt</th>
                                                                                            </tr>
                                                                                        </thead>
                                                                                        <tbody>
                                                                                            <tr v-for="(lp, j) in slip.details.details.late_penalty" :key="'lp-'+j" class="border-t border-gray-100">
                                                                                                <td class="px-3 py-2 text-gray-700">{{ lp.date }}</td>
                                                                                                <td class="px-3 py-2 text-right text-gray-700">{{ lp.late_minutes }}</td>
                                                                                                <td class="px-3 py-2 text-right text-red-600 font-medium">-{{ formatMoney(lp.penalty) }}</td>
                                                                                            </tr>
                                                                                        </tbody>
                                                                                        <tfoot class="bg-gray-50 font-medium border-t">
                                                                                            <tr>
                                                                                                <td class="px-3 py-2">Tổng</td>
                                                                                                <td class="px-3 py-2 text-right">{{ slip.details?.details?.late_penalty?.reduce((s, lp) => s + lp.late_minutes, 0) }} phút</td>
                                                                                                <td class="px-3 py-2 text-right text-red-600">-{{ formatMoney(slip.details?.late_penalty || slip.details?.details?.late_penalty?.reduce((s, lp) => s + lp.penalty, 0)) }}</td>
                                                                                            </tr>
                                                                                        </tfoot>
                                                                                    </table>
                                                                                </div>
                                                                            </template>
                                                                        </template>
                                                                        <div v-else class="bg-white rounded-lg border border-gray-200 px-4 py-6 text-center">
                                                                            <p class="text-gray-400 text-xs">Không có giảm trừ</p>
                                                                        </div>

                                                                        <!-- Chi tiết phụ cấp -->
                                                                        <template v-if="slip.details?.details?.allowances?.length">
                                                                            <h5 class="font-medium text-gray-600 mt-4 mb-2 text-xs uppercase tracking-wide">Chi tiết phụ cấp</h5>
                                                                            <div class="space-y-1">
                                                                                <div v-for="(alw, i) in slip.details.details.allowances" :key="'alw-'+i"
                                                                                    class="flex justify-between bg-white rounded-lg border border-gray-200 px-4 py-2">
                                                                                    <span class="text-gray-700">{{ alw.name }}</span>
                                                                                    <span class="font-medium text-green-600">+{{ formatMoney(alw.calculated || alw.amount) }}</span>
                                                                                </div>
                                                                            </div>
                                                                        </template>

                                                                        <!-- Chi tiết thưởng -->
                                                                        <template v-if="slip.details?.details?.bonus?.length">
                                                                            <h5 class="font-medium text-gray-600 mt-4 mb-2 text-xs uppercase tracking-wide">Chi tiết thưởng</h5>
                                                                            <div class="space-y-1">
                                                                                <div v-for="(bon, i) in slip.details.details.bonus" :key="'bon-'+i"
                                                                                    class="flex justify-between bg-white rounded-lg border border-gray-200 px-4 py-2">
                                                                                    <span class="text-gray-700">{{ bon.role_type || 'Thưởng' }}: {{ formatMoney(bon.revenue_from) }}+</span>
                                                                                    <span class="font-medium text-green-600">+{{ formatMoney(bon.calculated) }}</span>
                                                                                </div>
                                                                            </div>
                                                                        </template>

                                                                        <!-- Chi tiết ngày lễ/nghỉ -->
                                                                        <template v-if="slip.details?.details?.holiday_pay?.length">
                                                                            <h5 class="font-medium text-gray-600 mt-4 mb-2 text-xs uppercase tracking-wide">Ngày lễ / ngày nghỉ</h5>
                                                                            <div class="space-y-1">
                                                                                <div v-for="(hp, i) in slip.details.details.holiday_pay" :key="'hp-'+i"
                                                                                    class="flex justify-between bg-white rounded-lg border border-gray-200 px-4 py-2 text-xs">
                                                                                    <span class="text-gray-700">{{ hp.date }} <span class="text-gray-400">({{ hp.type === 'holiday/tet' ? 'Lễ' : 'Ngày nghỉ' }} ×{{ hp.multiplier }})</span></span>
                                                                                    <span class="font-medium text-green-600">+{{ formatMoney(hp.extra_pay) }}</span>
                                                                                </div>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        </template>
                                                    </tbody>
                                                </table>

                                                <!-- Pay button -->
                                                <div
                                                    v-if="
                                                        detailPayslips.length >
                                                            0 &&
                                                        ps.status !==
                                                            'cancelled'
                                                    "
                                                    class="flex justify-end px-6 py-3 border-t border-gray-200"
                                                >
                                                    <button
                                                        @click="paySelected(ps)"
                                                        :disabled="
                                                            selectedSlipIds.length ===
                                                                0 || isPaying
                                                        "
                                                        class="flex items-center gap-1.5 px-5 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 transition disabled:opacity-50"
                                                    >
                                                        <svg
                                                            v-if="isPaying"
                                                            class="animate-spin h-4 w-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <circle
                                                                class="opacity-25"
                                                                cx="12"
                                                                cy="12"
                                                                r="10"
                                                                stroke="currentColor"
                                                                stroke-width="4"
                                                            />
                                                            <path
                                                                class="opacity-75"
                                                                fill="currentColor"
                                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                                            />
                                                        </svg>
                                                        Thanh toán
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Tab: Lịch sử thanh toán -->
                                            <div
                                                v-show="
                                                    activeTab === 'payments'
                                                "
                                                class="px-0"
                                            >
                                                <table class="w-full text-sm">
                                                    <thead
                                                        class="bg-gray-50 border-b border-gray-200"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500"
                                                            >
                                                                Thời gian
                                                            </th>
                                                            <th
                                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500"
                                                            >
                                                                Nhân viên
                                                            </th>
                                                            <th
                                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500"
                                                            >
                                                                Mã phiếu
                                                            </th>
                                                            <th
                                                                class="px-4 py-2 text-right text-xs font-medium text-gray-500"
                                                            >
                                                                Số tiền
                                                            </th>
                                                            <th
                                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500"
                                                            >
                                                                Phương thức
                                                            </th>
                                                            <th
                                                                class="px-4 py-2 text-left text-xs font-medium text-gray-500"
                                                            >
                                                                Ghi chú
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr
                                                            v-if="
                                                                detailPayments.length ===
                                                                0
                                                            "
                                                        >
                                                            <td
                                                                colspan="6"
                                                                class="px-6 py-8 text-center text-gray-400"
                                                            >
                                                                Chưa có lịch sử
                                                                thanh toán.
                                                            </td>
                                                        </tr>
                                                        <tr
                                                            v-for="p in detailPayments"
                                                            :key="p.id"
                                                            class="border-b border-gray-200 hover:bg-gray-50"
                                                        >
                                                            <td
                                                                class="px-4 py-2.5 text-gray-600"
                                                            >
                                                                {{
                                                                    formatDateTime(
                                                                        p.paid_at,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-4 py-2.5 text-gray-800"
                                                            >
                                                                {{
                                                                    p.employee
                                                                        ?.name
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-4 py-2.5 font-medium text-blue-600"
                                                            >
                                                                {{
                                                                    p.payslip
                                                                        ?.code
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-4 py-2.5 text-right font-medium text-gray-800"
                                                            >
                                                                {{
                                                                    formatMoney(
                                                                        p.amount,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-4 py-2.5 text-gray-600"
                                                            >
                                                                {{
                                                                    p.method ===
                                                                    "cash"
                                                                        ? "Tiền mặt"
                                                                        : "Chuyển khoản"
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-4 py-2.5 text-gray-500"
                                                            >
                                                                {{
                                                                    p.notes ||
                                                                    "-"
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </main>
            </div>

            <!-- ===== Modal: Thêm bảng tính lương ===== -->
            <div
                v-if="showCreateModal"
                class="fixed inset-0 z-50 overflow-y-auto"
            >
                <div class="flex items-center justify-center min-h-screen px-4">
                    <div
                        class="fixed inset-0 bg-black bg-opacity-40"
                        @click="showCreateModal = false"
                    ></div>
                    <div
                        class="relative bg-white rounded-lg shadow-2xl w-full max-w-md z-10"
                    >
                        <div
                            class="flex items-center justify-between px-6 py-4 border-b border-gray-200"
                        >
                            <h3 class="text-lg font-bold text-gray-900">
                                Thêm bảng tính lương
                            </h3>
                            <button
                                @click="showCreateModal = false"
                                class="text-gray-400 hover:text-gray-600"
                            >
                                <svg
                                    class="h-5 w-5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>

                        <div class="px-6 py-5 space-y-5">
                            <!-- Kỳ hạn trả lương -->
                            <div class="flex items-center gap-4">
                                <label
                                    class="text-sm text-gray-600 w-36 flex-shrink-0"
                                    >Kỳ hạn trả lương</label
                                >
                                <select
                                    v-model="createForm.pay_period"
                                    class="flex-1 text-sm border border-gray-300 rounded-md px-3 py-2 outline-none focus:ring-1 focus:ring-blue-500"
                                >
                                    <option value="monthly">Hàng tháng</option>
                                    <option value="biweekly">Hai tuần</option>
                                </select>
                            </div>

                            <!-- Kỳ làm việc -->
                            <div class="flex items-center gap-4">
                                <label
                                    class="text-sm text-gray-600 w-36 flex-shrink-0"
                                    >Kỳ làm việc</label
                                >
                                <select
                                    v-model="createForm.periodKey"
                                    class="flex-1 text-sm border border-gray-300 rounded-md px-3 py-2 outline-none focus:ring-1 focus:ring-blue-500"
                                >
                                    <option
                                        v-for="p in periodOptions"
                                        :key="p.key"
                                        :value="p.key"
                                    >
                                        {{ p.label }}
                                    </option>
                                </select>
                            </div>

                            <!-- Phạm vi áp dụng -->
                            <div class="flex items-center gap-4">
                                <label
                                    class="text-sm text-gray-600 w-36 flex-shrink-0"
                                    >Phạm vi áp dụng</label
                                >
                                <div class="flex items-center gap-4">
                                    <label
                                        class="flex items-center gap-1.5 cursor-pointer"
                                    >
                                        <input
                                            type="radio"
                                            value="all"
                                            v-model="createForm.scope"
                                            class="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="text-sm"
                                            >Tất cả nhân viên</span
                                        >
                                    </label>
                                    <label
                                        class="flex items-center gap-1.5 cursor-pointer"
                                    >
                                        <input
                                            type="radio"
                                            value="custom"
                                            v-model="createForm.scope"
                                            class="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="text-sm">Tùy chọn</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Employee selection (when custom) -->
                            <div
                                v-if="createForm.scope === 'custom'"
                                class="pl-40"
                            >
                                <div
                                    class="border border-gray-300 rounded-md max-h-40 overflow-y-auto p-2"
                                >
                                    <label
                                        v-for="emp in employees"
                                        :key="emp.id"
                                        class="flex items-center gap-2 py-1 text-sm cursor-pointer hover:bg-gray-50 rounded px-1"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="emp.id"
                                            v-model="createForm.employee_ids"
                                            class="rounded border-gray-300 text-blue-600 h-3.5 w-3.5"
                                        />
                                        <span
                                            >{{ emp.name }} ({{
                                                emp.code
                                            }})</span
                                        >
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg"
                        >
                            <button
                                @click="showCreateModal = false"
                                class="px-5 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                            >
                                Bỏ qua
                            </button>
                            <button
                                @click="createPaysheet"
                                :disabled="isCreating"
                                class="inline-flex items-center px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition disabled:opacity-50"
                            >
                                <svg
                                    v-if="isCreating"
                                    class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    />
                                </svg>
                                Lưu
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Modal Các khoản giảm trừ -->
    <Teleport to="body">
        <div v-if="showDeductionModal" class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="fixed inset-0 bg-black/40" @click="closeDeductionModal"></div>
            <div class="relative bg-white rounded-lg shadow-xl w-[560px] max-h-[80vh] overflow-hidden">
                <!-- Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Các khoản giảm trừ</h3>
                        <p class="text-sm text-gray-500">Nhân viên: {{ deductionModalSlip?.employee?.name }}</p>
                    </div>
                    <button @click="closeDeductionModal" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>

                <!-- Body -->
                <div class="px-6 py-4 overflow-y-auto max-h-[60vh]">
                    <!-- Header row -->
                    <div class="flex justify-between text-xs font-medium text-gray-500 uppercase tracking-wide pb-2 border-b border-gray-200 mb-3">
                        <span>Loại giảm trừ</span>
                        <span>Tiền giảm trừ</span>
                    </div>

                    <!-- Tổng giảm trừ -->
                    <div class="flex justify-between py-2 mb-2">
                        <span class="font-semibold text-gray-700"></span>
                        <span class="font-bold text-blue-600 text-lg">{{ formatMoney(deductionModalSlip?.deductions || 0) }}</span>
                    </div>

                    <!-- Giảm trừ đi muộn, về sớm, cố định -->
                    <div class="border-t border-gray-100 py-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Giảm trừ đi muộn, về sớm, cố định</span>
                            <span class="font-medium" :class="fixedDeductionTotal > 0 ? 'text-red-600' : 'text-blue-600'">{{ formatMoney(fixedDeductionTotal) }}</span>
                        </div>
                        <div v-if="fixedDeductionItems.length" class="mt-2 ml-4 space-y-1">
                            <div v-for="(ded, i) in fixedDeductionItems" :key="'fd-'+i" class="flex justify-between text-sm text-gray-500">
                                <span>{{ ded.name }} <span class="text-xs">({{ calcTypeLabel(ded.calc_type) }})</span></span>
                                <span class="text-red-500">-{{ formatMoney(ded.calculated) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Phạt vi phạm theo ngày -->
                    <div class="border-t border-gray-100 py-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Phạt vi phạm theo ngày</span>
                            <div class="flex items-center gap-2">
                                <span class="font-medium" :class="latePenaltyTotal > 0 ? 'text-red-600' : 'text-blue-600'">{{ formatMoney(latePenaltyTotal) }}</span>
                            </div>
                        </div>
                        <div v-if="latePenaltyItems.length" class="mt-2 ml-4 space-y-1">
                            <div v-for="(lp, j) in latePenaltyItems" :key="'lp-'+j" class="flex justify-between text-sm text-gray-500">
                                <span>{{ lp.date }} (muộn {{ lp.late_minutes }} phút)</span>
                                <span class="text-red-500">-{{ formatMoney(lp.penalty) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Giảm trừ khác (manual) -->
                    <div class="border-t border-gray-100 py-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Giảm trừ khác</span>
                            <div class="flex items-center gap-2">
                                <input
                                    type="text"
                                    :value="formatNumber(manualDeduction)"
                                    @blur="updateManualDeduction($event)"
                                    @focus="$event.target.select()"
                                    class="w-28 text-right border border-gray-300 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex justify-end px-6 py-3 border-t border-gray-200 bg-gray-50">
                    <button @click="closeDeductionModal" class="px-5 py-2 text-sm font-medium text-gray-600 border border-gray-300 rounded-md hover:bg-gray-100 transition">Bỏ qua</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import { ref, computed, reactive, onMounted, watch } from "vue";
import axios from "axios";
import { formatVND as formatMoney } from '@/utils/money';

const props = defineProps({
    branches: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
});

// ===== State =====
const paysheets = ref([]);
const loading = ref(false);
const searchQuery = ref("");
const filterPeriod = ref("");
const selectedBranch = ref(null);
const selectedBranchId = ref(null);
const summary = ref({ total_salary: 0, total_paid: 0, total_remaining: 0 });

const statusOptions = ref([
    {
        value: "draft",
        label: "Đang tạo",
        color: "text-gray-600",
        checked: true,
    },
    {
        value: "calculated",
        label: "Tạm tính",
        color: "text-blue-600",
        checked: true,
    },
    {
        value: "locked",
        label: "Đã chốt lương",
        color: "text-green-600",
        checked: true,
    },
    {
        value: "cancelled",
        label: "Đã hủy",
        color: "text-red-500",
        checked: false,
    },
]);

const branches = computed(() => props.branches);
const employees = computed(() => props.employees);
const defaultPayrollSettings = {
    pay_cycle: "monthly",
    start_day: 26,
    end_day: 25,
    start_in_prev_month: true,
    pay_day: 5,
    default_recalculate_timekeeping: true,
    auto_generate_enabled: false,
};
const payrollSettings = ref({ ...defaultPayrollSettings });

// ===== Expanded row =====
const expandedId = ref(null);
const activeTab = ref("info");
const detailPayslips = ref([]);
const detailPayments = ref([]);
const detailLoading = ref(false);
const editNotes = ref("");
const selectedSlipIds = ref([]);
const selectAllSlips = ref(false);
const isPaying = ref(false);
const expandedSlipId = ref(null);

// ===== Deduction modal =====
const showDeductionModal = ref(false);
const deductionModalSlip = ref(null);
const manualDeduction = ref(0);

const fixedDeductionItems = computed(() => {
    const deds = deductionModalSlip.value?.details?.details?.deductions || [];
    return deds.filter(d => d.category !== 'violation');
});
const fixedDeductionTotal = computed(() => fixedDeductionItems.value.reduce((s, d) => s + (d.calculated || 0), 0));

const latePenaltyItems = computed(() => deductionModalSlip.value?.details?.details?.late_penalty || []);
const latePenaltyTotal = computed(() => latePenaltyItems.value.reduce((s, lp) => s + (lp.penalty || 0), 0));

const openDeductionModal = (slip) => {
    deductionModalSlip.value = slip;
    const autoTotal = fixedDeductionTotal.value + latePenaltyTotal.value;
    manualDeduction.value = Math.max(0, (slip.deductions || 0) - autoTotal);
    showDeductionModal.value = true;
};

const closeDeductionModal = () => {
    showDeductionModal.value = false;
    deductionModalSlip.value = null;
};

const updateManualDeduction = async (event) => {
    const newVal = parseNumber(event.target.value);
    manualDeduction.value = newVal;
    const slip = deductionModalSlip.value;
    if (!slip) return;
    const autoTotal = fixedDeductionTotal.value + latePenaltyTotal.value;
    const totalDed = autoTotal + newVal;
    slip.deductions = totalDed;
    slip.total_salary = Math.max(0, (slip.base_salary || 0) + (slip.bonus || 0) + (slip.commission || 0)
        + (slip.allowances || 0) + (slip.ot_pay || 0) - totalDed);
    slip.remaining = Math.max(0, slip.total_salary - (slip.paid_amount || 0));
    slip._dirty = true;
    try {
        const psId = paysheets.value.find(p => p.id === expandedId.value)?.id;
        await axios.put(`/api/paysheets/${psId}/payslips/${slip.id}`, { deductions: totalDed });
        slip._dirty = false;
    } catch (e) {
        console.error('Update deduction failed:', e);
    }
};

const toggleSlipDetail = (slipId) => {
    expandedSlipId.value = expandedSlipId.value === slipId ? null : slipId;
};

const calcTypeLabel = (type) => {
    const map = { per_occurrence: 'Theo lần', per_minute: 'Theo số phút', fixed_per_month: 'Cố định/tháng' };
    return map[type] || type;
};

const categoryLabel = (cat) => {
    const map = { late: 'Đi muộn', early_leave: 'Về sớm', absence: 'Vắng mặt', violation: 'Vi phạm', fixed: 'Cố định' };
    return map[cat] || cat;
};

const detailTabs = [
    { key: "info", label: "Thông tin" },
    { key: "payslips", label: "Phiếu lương" },
    { key: "payments", label: "Lịch sử thanh toán" },
];

const payslipSummary = computed(() => ({
    total: detailPayslips.value.reduce((s, p) => s + (p.total_salary || 0), 0),
    paid: detailPayslips.value.reduce((s, p) => s + (p.paid_amount || 0), 0),
    remaining: detailPayslips.value.reduce((s, p) => s + (p.remaining || 0), 0),
}));

// ===== Inline edit summary =====
const editSummary = computed(() => {
    const slips = detailPayslips.value;
    return {
        base_salary: slips.reduce((s, p) => s + (p.base_salary || 0), 0),
        ot_pay: slips.reduce((s, p) => s + (p.ot_pay || 0), 0),
        commission: slips.reduce((s, p) => s + (p.commission || 0), 0),
        allowances: slips.reduce((s, p) => s + (p.allowances || 0), 0),
        bonus: slips.reduce((s, p) => s + (p.bonus || 0), 0),
        deductions: slips.reduce((s, p) => s + (p.deductions || 0), 0),
        total_salary: slips.reduce((s, p) => s + (p.total_salary || 0), 0),
        paid_amount: slips.reduce((s, p) => s + (p.paid_amount || 0), 0),
        remaining: slips.reduce((s, p) => s + (p.remaining || 0), 0),
    };
});

const formatNumber = (v) => {
    if (!v && v !== 0) return '0';
    return new Intl.NumberFormat('vi-VN').format(v);
};

const parseNumber = (str) => {
    if (!str) return 0;
    return parseInt(String(str).replace(/[^\d-]/g, '')) || 0;
};

const updateSlipField = async (slip, field, event) => {
    const newVal = parseNumber(event.target.value);
    if (newVal === slip[field]) return; // Không thay đổi

    const oldVal = slip[field];
    slip[field] = newVal;

    // Tính lại total local
    slip.total_salary = Math.max(0, (slip.base_salary || 0) + (slip.bonus || 0) + (slip.commission || 0)
        + (slip.allowances || 0) + (slip.ot_pay || 0) - (slip.deductions || 0));
    slip.remaining = Math.max(0, slip.total_salary - (slip.paid_amount || 0));
    slip._dirty = true;

    // Lưu vào DB
    try {
        const psId = expandedPaysheet.value?.id;
        await axios.put(`/api/paysheets/${psId}/payslips/${slip.id}`, {
            [field]: newVal,
        });
        slip._dirty = false;
    } catch (e) {
        console.error('Update payslip failed:', e);
        slip[field] = oldVal; // Rollback
        slip.total_salary = Math.max(0, (slip.base_salary || 0) + (slip.bonus || 0) + (slip.commission || 0)
            + (slip.allowances || 0) + (slip.ot_pay || 0) - (slip.deductions || 0));
        slip.remaining = Math.max(0, slip.total_salary - (slip.paid_amount || 0));
    }
};

// ===== Create modal =====
const showCreateModal = ref(false);
const isCreating = ref(false);
const createForm = reactive({
    pay_period: "monthly",
    periodKey: "",
    scope: "all",
    employee_ids: [],
});

// Chu kỳ lương được tính tự động từ backend (xử lý đúng 28/29/30/31 ngày)
const payrollCycles = ref([]);

const fetchPayrollCycles = async () => {
    try {
        const params = {};
        if (selectedBranch.value?.id) params.branch_id = selectedBranch.value.id;
        const res = await axios.get("/api/payroll-cycles", { params });
        if (res.data?.success && res.data?.data) {
            payrollCycles.value = res.data.data;
        }
    } catch (e) {
        console.error("Fetch payroll cycles error:", e);
    }
};

const periodOptions = computed(() => {
    return payrollCycles.value.map((c) => ({
        key: `${c.period_start}|${c.period_end}`,
        label: c.label,
    }));
});

const setDefaultPeriodKey = () => {
    if (periodOptions.value.length > 0) {
        createForm.periodKey = periodOptions.value[0].key;
        return;
    }

    createForm.periodKey = "";
};

const fetchPayrollSettings = async () => {
    try {
        const res = await axios.get("/api/payroll-settings");
        if (res.data?.success && res.data?.data) {
            const nextSettings = {
                ...defaultPayrollSettings,
                ...res.data.data,
            };
            payrollSettings.value = nextSettings;
        }
    } catch (e) {
        console.error("Fetch payroll settings error:", e);
    }
};

// Set default period
onMounted(async () => {
    await fetchPayrollSettings();
    createForm.pay_period =
        payrollSettings.value.pay_cycle === "biweekly" ? "biweekly" : "monthly";
    await fetchPayrollCycles();
    setDefaultPeriodKey();
    fetchPaysheets();
});

watch(
    () => createForm.pay_period,
    async () => {
        await fetchPayrollCycles();
        setDefaultPeriodKey();
    },
);

// ===== API calls =====
let fetchTimer = null;
const debouncedFetch = () => {
    clearTimeout(fetchTimer);
    fetchTimer = setTimeout(fetchPaysheets, 300);
};

const fetchPaysheets = async () => {
    loading.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedBranch.value) params.branch_id = selectedBranch.value.id;
        else if (selectedBranchId.value)
            params.branch_id = selectedBranchId.value;
        if (filterPeriod.value) params.pay_period = filterPeriod.value;
        const checkedStatuses = statusOptions.value
            .filter((s) => s.checked)
            .map((s) => s.value);
        if (
            checkedStatuses.length > 0 &&
            checkedStatuses.length < statusOptions.value.length
        ) {
            params.status = checkedStatuses.join(",");
        }

        const res = await axios.get("/api/paysheets", { params });
        if (res.data?.success) {
            paysheets.value = res.data.data;
            summary.value = res.data.summary || {
                total_salary: 0,
                total_paid: 0,
                total_remaining: 0,
            };
        }
    } catch (e) {
        console.error("Fetch paysheets error:", e);
    } finally {
        loading.value = false;
    }
};

const onBranchChange = () => {
    const b = branches.value.find((x) => x.id === selectedBranchId.value);
    selectedBranch.value = b || null;
    fetchPaysheets();
};

const toggleExpand = async (id) => {
    if (expandedId.value === id) {
        expandedId.value = null;
        return;
    }
    expandedId.value = id;
    activeTab.value = "info";
    selectedSlipIds.value = [];
    selectAllSlips.value = false;

    const ps = paysheets.value.find((p) => p.id === id);
    editNotes.value = ps?.notes || "";

    await fetchDetail(id);
};

const fetchDetail = async (id) => {
    detailLoading.value = true;
    try {
        const res = await axios.get(`/api/paysheets/${id}`);
        if (res.data?.success) {
            detailPayslips.value = res.data.data.payslips || [];
            detailPayments.value = res.data.data.payments || [];
        }
    } catch (e) {
        console.error(e);
    } finally {
        detailLoading.value = false;
    }
};

// ===== Create =====
const openCreateModal = async () => {
    await fetchPayrollSettings();
    createForm.pay_period =
        payrollSettings.value.pay_cycle === "biweekly" ? "biweekly" : "monthly";
    createForm.scope = "all";
    createForm.employee_ids = [];
    setDefaultPeriodKey();
    showCreateModal.value = true;
};

const createPaysheet = async () => {
    if (!createForm.periodKey) return alert("Vui lòng chọn kỳ làm việc");
    isCreating.value = true;
    try {
        const [start, end] = createForm.periodKey.split("|");
        const payload = {
            pay_period: createForm.pay_period,
            period_start: start,
            period_end: end,
            branch_id:
                selectedBranch.value?.id || selectedBranchId.value || null,
            scope: createForm.scope,
            employee_ids:
                createForm.scope === "custom" ? createForm.employee_ids : [],
        };
        await axios.post("/api/paysheets", payload);
        showCreateModal.value = false;
        await fetchPaysheets();
    } catch (e) {
        alert("Có lỗi xảy ra khi tạo bảng lương!");
        console.error(e);
    } finally {
        isCreating.value = false;
    }
};

// ===== Actions =====
const recalculatePaysheet = async (ps) => {
    if (!confirm("Tải lại dữ liệu sẽ tính lại toàn bộ lương. Tiếp tục?"))
        return;
    try {
        await axios.post(`/api/paysheets/${ps.id}/recalculate`);
        await fetchPaysheets();
        if (expandedId.value === ps.id) await fetchDetail(ps.id);
        alert("Đã tải lại dữ liệu thành công!");
    } catch (e) {
        alert("Có lỗi!");
        console.error(e);
    }
};

const lockPaysheet = async (ps) => {
    if (!confirm("Chốt bảng lương? Sau khi chốt sẽ không thể sửa.")) return;
    try {
        await axios.put(`/api/paysheets/${ps.id}/lock`);
        await fetchPaysheets();
        if (expandedId.value === ps.id) await fetchDetail(ps.id);
    } catch (e) {
        alert("Có lỗi!");
        console.error(e);
    }
};

const cancelPaysheet = async (ps) => {
    if (!confirm("Hủy bỏ bảng lương này?")) return;
    try {
        await axios.put(`/api/paysheets/${ps.id}/cancel`);
        await fetchPaysheets();
        expandedId.value = null;
    } catch (e) {
        alert("Có lỗi!");
        console.error(e);
    }
};

const saveNotes = async (ps) => {
    try {
        await axios.put(`/api/paysheets/${ps.id}/notes`, {
            notes: editNotes.value,
        });
    } catch (e) {
        console.error(e);
    }
};

const toggleSelectAllSlips = () => {
    selectedSlipIds.value = selectAllSlips.value
        ? detailPayslips.value.map((s) => s.id)
        : [];
};

const paySelected = async (ps) => {
    if (
        !confirm(
            `Thanh toán ${selectedSlipIds.value.length} phiếu lương đã chọn?`,
        )
    )
        return;
    isPaying.value = true;
    try {
        await axios.post(`/api/paysheets/${ps.id}/pay`, {
            payslip_ids: selectedSlipIds.value,
        });
        await fetchPaysheets();
        await fetchDetail(ps.id);
        selectedSlipIds.value = [];
        selectAllSlips.value = false;
    } catch (e) {
        alert("Có lỗi!");
        console.error(e);
    } finally {
        isPaying.value = false;
    }
};

// ===== Helpers =====

const formatDate = (d) => {
    if (!d) return "";
    const parts = d.split("T")[0].split("-");
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
};

const formatDateTime = (d) => {
    if (!d) return "";
    const dt = new Date(d);
    return `${dt.getDate().toString().padStart(2, "0")}/${(dt.getMonth() + 1).toString().padStart(2, "0")}/${dt.getFullYear()} ${dt.getHours().toString().padStart(2, "0")}:${dt.getMinutes().toString().padStart(2, "0")}:${dt.getSeconds().toString().padStart(2, "0")}`;
};

const getStatusLabel = (s) =>
    ({
        draft: "Đang tạo",
        calculating: "Đang tính",
        calculated: "Tạm tính",
        locked: "Đã chốt lương",
        cancelled: "Đã hủy",
    })[s] || s;

const getStatusClass = (s) =>
    ({
        draft: "bg-gray-100 text-gray-700",
        calculating: "bg-yellow-100 text-yellow-700",
        calculated: "bg-blue-100 text-blue-700",
        locked: "bg-green-100 text-green-700",
        cancelled: "bg-red-100 text-red-700",
    })[s] || "bg-gray-100 text-gray-600";

const printPaysheet = (ps) => {
    window.open(`/paysheets/${ps.id}/print`, "_blank", "width=400,height=600");
};
</script>
