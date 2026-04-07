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
                                                            @click="
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
                                                            @click="
                                                                lockPaysheet(ps)
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
                                                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                />
                                                            </svg>
                                                            Xem bảng lương
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
                                                    class="w-full text-sm"
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
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Lương chính</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Làm thêm</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Hoa hồng</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Phụ cấp</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Thưởng</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Giảm trừ</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Tổng lương</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Đã trả NV</th>
                                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500">Còn cần trả</th>
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
                                                                    class="w-28 text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.ot_pay)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'ot_pay', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-24 text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.commission)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'commission', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-24 text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.allowances)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'allowances', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-24 text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.bonus)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'bonus', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-24 text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <input
                                                                    type="text"
                                                                    :value="formatNumber(slip.deductions)"
                                                                    @focus="$event.target.select()"
                                                                    @blur="updateSlipField(slip, 'deductions', $event)"
                                                                    :disabled="ps.status === 'locked'"
                                                                    class="w-24 text-right border border-gray-200 rounded px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                                />
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
                                                            <td colspan="6" class="bg-gray-50 px-6 py-4">
                                                                <div class="grid grid-cols-2 gap-6 text-sm">
                                                                    <!-- Cột trái: Thông tin cơ bản -->
                                                                    <div class="space-y-2">
                                                                        <h4 class="font-semibold text-gray-700 mb-2">Thông tin lương</h4>
                                                                        <div class="flex justify-between">
                                                                            <span class="text-gray-500">Lương cơ bản:</span>
                                                                            <span class="font-medium">{{ formatMoney(slip.base_salary) }}</span>
                                                                        </div>
                                                                        <div class="flex justify-between" v-if="slip.details?.work_units !== undefined">
                                                                            <span class="text-gray-500">Ngày công:</span>
                                                                            <span class="font-medium">{{ slip.details?.work_units ?? slip.work_units }} / {{ slip.details?.standard_work_units ?? '-' }}</span>
                                                                        </div>
                                                                        <div class="flex justify-between" v-if="slip.bonus > 0">
                                                                            <span class="text-gray-500">Thưởng:</span>
                                                                            <span class="font-medium text-green-600">+{{ formatMoney(slip.bonus) }}</span>
                                                                        </div>
                                                                        <div class="flex justify-between" v-if="slip.commission > 0">
                                                                            <span class="text-gray-500">Hoa hồng:</span>
                                                                            <span class="font-medium text-green-600">+{{ formatMoney(slip.commission) }}</span>
                                                                        </div>
                                                                        <div class="flex justify-between" v-if="slip.allowances > 0">
                                                                            <span class="text-gray-500">Phụ cấp:</span>
                                                                            <span class="font-medium text-green-600">+{{ formatMoney(slip.allowances) }}</span>
                                                                        </div>
                                                                        <div class="flex justify-between border-t pt-2 mt-2">
                                                                            <span class="text-gray-700 font-semibold">Tổng giảm trừ:</span>
                                                                            <span class="font-semibold text-red-600">-{{ formatMoney(slip.deductions) }}</span>
                                                                        </div>
                                                                        <div class="flex justify-between border-t pt-2">
                                                                            <span class="text-gray-800 font-bold">Thực lãnh:</span>
                                                                            <span class="font-bold text-blue-600">{{ formatMoney(slip.total_salary) }}</span>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Cột phải: Chi tiết giảm trừ -->
                                                                    <div class="space-y-2" v-if="slip.details?.details?.deductions?.length || slip.details?.details?.late_penalty?.length">
                                                                        <h4 class="font-semibold text-gray-700 mb-2">Chi tiết giảm trừ</h4>

                                                                        <!-- Template deductions -->
                                                                        <div v-for="(ded, i) in (slip.details?.details?.deductions || [])" :key="'ded-'+i"
                                                                            class="bg-white rounded-md border border-gray-200 px-3 py-2">
                                                                            <div class="flex justify-between items-start">
                                                                                <div>
                                                                                    <span class="font-medium text-gray-800">{{ ded.name }}</span>
                                                                                    <span class="ml-2 text-xs text-gray-400">({{ categoryLabel(ded.category) }})</span>
                                                                                </div>
                                                                                <span class="font-semibold text-red-600">-{{ formatMoney(ded.calculated) }}</span>
                                                                            </div>
                                                                            <div class="text-xs text-gray-500 mt-1">
                                                                                <template v-if="ded.calc_type === 'per_minute'">
                                                                                    {{ calcTypeLabel(ded.calc_type) }}: <span class="font-medium text-gray-700">{{ ded.total_minutes || (ded.config_amount > 0 ? Math.round(ded.calculated / ded.config_amount) : 0) }} phút</span>
                                                                                    × {{ formatMoney(ded.config_amount) }}/phút
                                                                                </template>
                                                                                <template v-else-if="ded.calc_type === 'per_occurrence'">
                                                                                    {{ calcTypeLabel(ded.calc_type) }}: <span class="font-medium text-gray-700">{{ ded.occurrences }} lần</span>
                                                                                    × {{ formatMoney(ded.config_amount) }}/lần
                                                                                </template>
                                                                                <template v-else>
                                                                                    {{ calcTypeLabel(ded.calc_type) }}: {{ formatMoney(ded.config_amount) }}
                                                                                </template>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Tier-based late penalties -->
                                                                        <template v-if="slip.details?.details?.late_penalty?.length">
                                                                            <h5 class="font-medium text-gray-600 mt-3 mb-1">Phạt đi muộn theo mức</h5>
                                                                            <div class="bg-white rounded-md border border-gray-200 overflow-hidden">
                                                                                <table class="w-full text-xs">
                                                                                    <thead class="bg-gray-100">
                                                                                        <tr>
                                                                                            <th class="px-2 py-1.5 text-left text-gray-500">Ngày</th>
                                                                                            <th class="px-2 py-1.5 text-right text-gray-500">Muộn (phút)</th>
                                                                                            <th class="px-2 py-1.5 text-right text-gray-500">Mức phạt</th>
                                                                                        </tr>
                                                                                    </thead>
                                                                                    <tbody>
                                                                                        <tr v-for="(lp, j) in slip.details.details.late_penalty" :key="'lp-'+j" class="border-t border-gray-100">
                                                                                            <td class="px-2 py-1.5 text-gray-700">{{ lp.date }}</td>
                                                                                            <td class="px-2 py-1.5 text-right text-gray-700">{{ lp.late_minutes }}</td>
                                                                                            <td class="px-2 py-1.5 text-right text-red-600 font-medium">-{{ formatMoney(lp.penalty) }}</td>
                                                                                        </tr>
                                                                                    </tbody>
                                                                                    <tfoot class="bg-gray-50 font-medium">
                                                                                        <tr>
                                                                                            <td class="px-2 py-1.5">Tổng</td>
                                                                                            <td class="px-2 py-1.5 text-right">{{ slip.details?.late_minutes || slip.details?.details?.late_penalty?.reduce((s, lp) => s + lp.late_minutes, 0) }} phút</td>
                                                                                            <td class="px-2 py-1.5 text-right text-red-600">-{{ formatMoney(slip.details?.late_penalty || slip.details?.details?.late_penalty?.reduce((s, lp) => s + lp.penalty, 0)) }}</td>
                                                                                        </tr>
                                                                                    </tfoot>
                                                                                </table>
                                                                            </div>
                                                                        </template>

                                                                        <!-- Tổng hợp đi muộn -->
                                                                        <div v-if="slip.details?.late_count > 0 && !slip.details?.details?.late_penalty?.length"
                                                                            class="text-xs text-gray-500 mt-1">
                                                                            Đi muộn: {{ slip.details.late_count }} lần, tổng {{ slip.details.late_minutes }} phút
                                                                        </div>
                                                                    </div>
                                                                    <div v-else class="space-y-2">
                                                                        <h4 class="font-semibold text-gray-700 mb-2">Chi tiết giảm trừ</h4>
                                                                        <p class="text-gray-400 text-xs">Không có giảm trừ.</p>
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
</template>

<script setup>
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import { ref, computed, reactive, onMounted, watch } from "vue";
import axios from "axios";

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
const formatMoney = (v) => {
    if (!v && v !== 0) return "0";
    return Number(v).toLocaleString("vi-VN");
};

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
