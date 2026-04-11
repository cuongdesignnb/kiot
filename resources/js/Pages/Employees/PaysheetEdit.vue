<template>
    <Head :title="`${paysheet.name} - Bảng lương`" />
    <AppLayout>
        <div class="h-screen flex flex-col bg-gray-50 font-sans">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 px-6 py-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button @click="goBack" class="text-gray-500 hover:text-gray-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <div>
                            <h1 class="text-lg font-bold text-gray-800">{{ paysheet.name }}</h1>
                            <p class="text-xs text-gray-500">{{ paysheet.code }} &middot; {{ formatDate(paysheet.period_start) }} - {{ formatDate(paysheet.period_end) }}</p>
                        </div>
                        <span :class="statusClass(paysheet.status)" class="ml-2 px-2 py-0.5 text-xs font-medium rounded-full">
                            {{ statusLabel(paysheet.status) }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input v-model="searchQuery" type="text" placeholder="Tìm nhân viên..."
                                class="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md w-48 outline-none focus:ring-1 focus:ring-blue-500" />
                        </div>
                        <button v-if="!isLocked" @click="recalculate" :disabled="recalculating"
                            class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition disabled:opacity-50">
                            <span v-if="recalculating">Đang tính...</span>
                            <span v-else>Tính lại</span>
                        </button>
                        <button v-if="paysheet.status === 'calculated'" @click="lockPaysheet"
                            class="px-4 py-1.5 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 transition">
                            Chốt lương
                        </button>
                    </div>
                </div>
            </header>

            <!-- Auto-recalc notification -->
            <div v-if="autoRecalcMessage" class="bg-green-50 border-b border-green-200 px-6 py-2 flex items-center gap-2 text-sm text-green-700">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span>{{ autoRecalcMessage }}</span>
                <button @click="autoRecalcMessage = ''" class="ml-auto text-green-500 hover:text-green-700">&times;</button>
            </div>

            <!-- Summary Bar -->
            <div class="bg-white border-b px-6 py-2 flex items-center gap-6 text-sm">
                <div class="flex items-center gap-1.5">
                    <span class="text-gray-500">Nhân viên:</span>
                    <span class="font-semibold">{{ filteredSlips.length }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-gray-500">Tổng lương:</span>
                    <span class="font-semibold text-blue-700">{{ fmt(summaryTotals.total_salary) }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-gray-500">Đã trả:</span>
                    <span class="font-semibold text-green-700">{{ fmt(summaryTotals.total_paid) }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-gray-500">Còn trả:</span>
                    <span class="font-semibold text-red-600">{{ fmt(summaryTotals.total_remaining) }}</span>
                </div>
            </div>

            <!-- Main Table -->
            <div class="flex-1 overflow-auto px-4 py-3">
                <table class="w-full bg-white border border-gray-200 rounded-lg text-sm">
                    <thead class="bg-gray-50 sticky top-0 z-10">
                        <tr class="text-left text-xs text-gray-600 uppercase tracking-wide">
                            <th class="px-3 py-2.5 w-10 text-center">#</th>
                            <th class="px-3 py-2.5 w-44">Nhân viên</th>
                            <th class="px-3 py-2.5 text-right w-28">Ngày công</th>
                            <th class="px-3 py-2.5 text-right w-28">Lương chính</th>
                            <th class="px-3 py-2.5 text-right w-28 cursor-pointer hover:text-blue-600">Làm thêm</th>
                            <th class="px-3 py-2.5 text-right w-28">Hoa hồng</th>
                            <th class="px-3 py-2.5 text-right w-28 cursor-pointer hover:text-blue-600">Phụ cấp</th>
                            <th class="px-3 py-2.5 text-right w-28 cursor-pointer hover:text-blue-600">Thưởng</th>
                            <th class="px-3 py-2.5 text-right w-28 cursor-pointer hover:text-blue-600">Giảm trừ</th>
                            <th class="px-3 py-2.5 text-right w-32 font-bold">Tổng lương</th>
                            <th class="px-3 py-2.5 text-right w-28">Đã trả</th>
                            <th class="px-3 py-2.5 text-right w-28">Còn trả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(slip, idx) in filteredSlips" :key="slip.id"
                            class="border-t border-gray-100 hover:bg-blue-50/30 transition">
                            <td class="px-3 py-2 text-center text-gray-400">{{ idx + 1 }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-800">{{ slip.employee?.name }}</div>
                                <div class="text-xs text-gray-400">{{ slip.employee?.code }}</div>
                            </td>
                            <td class="px-3 py-2 text-right text-gray-700">
                                <button @click="openPopup('workdays', slip)" class="text-blue-600 hover:underline font-medium tabular-nums">
                                    <span v-if="slip.details?.normal_work_units && slip.details.normal_work_units !== slip.work_units">
                                        {{ slip.details.normal_work_units }}(<span class="font-semibold">{{ slip.work_units || 0 }}</span>)
                                    </span>
                                    <span v-else>{{ slip.work_units || 0 }}</span>
                                    <span class="text-gray-400">/{{ slip.details?.standard_work_units || 26 }}</span>
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('base', slip)" class="text-blue-600 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.base_salary) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('ot', slip)" class="text-blue-600 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.ot_pay) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('commission', slip)" class="text-blue-600 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.commission) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('allowance', slip)" class="text-blue-600 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.allowances) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('bonus', slip)" class="text-blue-600 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.bonus) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('deduction', slip)" class="text-red-600 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.deductions) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right font-bold text-gray-900">{{ fmt(slip.total_salary) }}</td>
                            <td class="px-3 py-2 text-right">
                                <button @click="openPopup('paid', slip)" class="text-green-700 hover:underline font-medium tabular-nums">
                                    {{ fmt(slip.paid_amount) }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-right" :class="slip.remaining > 0 ? 'text-red-600 font-semibold' : 'text-gray-500'">
                                {{ fmt(slip.remaining) }}
                            </td>
                        </tr>
                        <tr v-if="filteredSlips.length === 0">
                            <td colspan="12" class="px-3 py-8 text-center text-gray-400">Không có dữ liệu</td>
                        </tr>
                    </tbody>
                    <!-- Summary Row -->
                    <tfoot class="bg-gray-50 font-semibold border-t-2 border-gray-300">
                        <tr>
                            <td class="px-3 py-2.5" colspan="3">Tổng cộng</td>
                            <td class="px-3 py-2.5 text-right">{{ fmt(summaryTotals.base_salary) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ fmt(summaryTotals.ot_pay) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ fmt(summaryTotals.commission) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ fmt(summaryTotals.allowances) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ fmt(summaryTotals.bonus) }}</td>
                            <td class="px-3 py-2.5 text-right text-red-600">{{ fmt(summaryTotals.deductions) }}</td>
                            <td class="px-3 py-2.5 text-right text-blue-700">{{ fmt(summaryTotals.total_salary) }}</td>
                            <td class="px-3 py-2.5 text-right text-green-700">{{ fmt(summaryTotals.total_paid) }}</td>
                            <td class="px-3 py-2.5 text-right text-red-600">{{ fmt(summaryTotals.total_remaining) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ========== POPUP MODALS ========== -->

        <!-- Overlay -->
        <Teleport to="body">
            <div v-if="popup.show" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="closePopup"></div>

                <!-- OT Popup -->
                <div v-if="popup.type === 'ot'" class="relative bg-white rounded-lg shadow-xl w-[700px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Làm thêm - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Loại ngày</th>
                                    <th class="px-3 py-2 text-right">Hệ số (%)</th>
                                    <th class="px-3 py-2 text-right">Đơn giá</th>
                                    <th class="px-3 py-2 text-right">Số lượng</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ob in otBreakdown" :key="ob.type" class="border-t">
                                    <td class="px-3 py-2 font-medium">
                                        {{ ob.label }}
                                        <div v-if="ob.note" class="text-xs text-gray-400 font-normal mt-0.5">{{ ob.note }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ ob.rate_percent }}%</td>
                                    <td class="px-3 py-2 text-right">{{ ob.daily_wage ? fmt(ob.daily_wage) + '/ngày' : fmt(ob.hourly_rate) + '/giờ' }}</td>
                                    <td class="px-3 py-2 text-right">{{ ob.days ? ob.days + ' ngày' : formatHours(ob.minutes) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(ob.amount) }}</td>
                                </tr>
                                <tr v-if="otBreakdown.length === 0" class="border-t">
                                    <td colspan="5" class="px-3 py-4 text-center text-gray-400">Không có dữ liệu làm thêm</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="4">Tổng OT (tự động)</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(otBreakdown.reduce((s, o) => s + (o.amount || 0), 0)) }}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Manual OT adjustments -->
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold text-gray-600 mb-2">Điều chỉnh thủ công</h4>
                            <div v-for="adj in popupAdjustments" :key="adj.id" class="flex items-center gap-2 mb-2">
                                <span class="flex-1 text-sm text-gray-700">{{ adj.name }}</span>
                                <input v-model.number="adj.amount" :disabled="isLocked" type="number" class="w-32 text-sm border rounded px-2 py-1 text-right" />
                                <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600 text-lg">&times;</button>
                            </div>
                            <button v-if="!isLocked" @click="addAdjustmentRow('ot')"
                                class="text-sm text-blue-600 hover:underline mt-1">+ Thêm khoản OT khác</button>
                        </div>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-between items-center bg-gray-50">
                        <div class="font-semibold">Tổng: {{ fmt(popupTotal) }}</div>
                        <div class="flex gap-2">
                            <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Bỏ qua</button>
                            <button v-if="!isLocked" @click="saveAdjustments" class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">Xong</button>
                        </div>
                    </div>
                </div>

                <!-- Commission Popup -->
                <div v-if="popup.type === 'commission'" class="relative bg-white rounded-lg shadow-xl w-[600px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Hoa hồng - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Loại</th>
                                    <th class="px-3 py-2 text-right">Giá trị</th>
                                    <th class="px-3 py-2 text-center">%</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(c, ci) in commissionItems" :key="ci" class="border-t">
                                    <td class="px-3 py-2">{{ c.product_category || c.name || 'Hoa hồng' }}</td>
                                    <td class="px-3 py-2 text-right">{{ c.commission_value }}</td>
                                    <td class="px-3 py-2 text-center">{{ c.is_percentage ? '✓' : '' }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(c.calculated || 0) }}</td>
                                </tr>
                                <tr v-if="commissionItems.length === 0" class="border-t">
                                    <td colspan="4" class="px-3 py-4 text-center text-gray-400">Không có hoa hồng</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="3">Tổng hoa hồng</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(popup.slip?.commission || 0) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                        <div v-if="popup.slip?.details?.personal_revenue" class="mt-3 text-xs text-gray-500">
                            Doanh thu cá nhân: {{ fmt(popup.slip.details.personal_revenue) }}
                        </div>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-end bg-gray-50">
                        <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Đóng</button>
                    </div>
                </div>

                <!-- Allowance Popup -->
                <div v-if="popup.type === 'allowance'" class="relative bg-white rounded-lg shadow-xl w-[500px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Phụ cấp - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Tên phụ cấp</th>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[150px]">Số tiền</th>
                                    <th class="w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(adj, i) in popupAdjustments" :key="adj.id" class="border-t">
                                    <td class="px-2 py-1">
                                        <input v-model="adj.name" :disabled="isLocked" type="text"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:border-blue-500 outline-none"
                                            placeholder="Ăn trưa, đi lại..." />
                                    </td>
                                    <td class="px-2 py-1">
                                        <input v-model.number="adj.amount" :disabled="isLocked" type="number" min="0"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-1 py-1 text-center">
                                        <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600">&times;</button>
                                    </td>
                                </tr>
                                <tr v-if="popupAdjustments.length === 0" class="border-t">
                                    <td colspan="3" class="px-3 py-4 text-center text-gray-400">Chưa có phụ cấp nào</td>
                                </tr>
                            </tbody>
                        </table>
                        <button v-if="!isLocked" @click="addAdjustmentRow('allowance')"
                            class="text-sm text-blue-600 hover:underline mt-3">+ Thêm phụ cấp</button>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-between items-center bg-gray-50">
                        <div class="font-semibold">Tổng: {{ fmt(popupTotal) }}</div>
                        <div class="flex gap-2">
                            <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Bỏ qua</button>
                            <button v-if="!isLocked" @click="saveAdjustments" class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">Xong</button>
                        </div>
                    </div>
                </div>

                <!-- Bonus Popup -->
                <div v-if="popup.type === 'bonus'" class="relative bg-white rounded-lg shadow-xl w-[500px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Thưởng - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Tên thưởng</th>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[150px]">Số tiền</th>
                                    <th class="w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(adj, i) in popupAdjustments" :key="adj.id" class="border-t">
                                    <td class="px-2 py-1">
                                        <input v-model="adj.name" :disabled="isLocked" type="text"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:border-blue-500 outline-none"
                                            placeholder="Thưởng KPI, thưởng doanh số..." />
                                    </td>
                                    <td class="px-2 py-1">
                                        <input v-model.number="adj.amount" :disabled="isLocked" type="number" min="0"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-1 py-1 text-center">
                                        <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600">&times;</button>
                                    </td>
                                </tr>
                                <tr v-if="popupAdjustments.length === 0" class="border-t">
                                    <td colspan="3" class="px-3 py-4 text-center text-gray-400">Chưa có thưởng nào</td>
                                </tr>
                            </tbody>
                        </table>
                        <button v-if="!isLocked" @click="addAdjustmentRow('bonus')"
                            class="text-sm text-blue-600 hover:underline mt-3">+ Thêm thưởng</button>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-between items-center bg-gray-50">
                        <div class="font-semibold">Tổng: {{ fmt(popupTotal) }}</div>
                        <div class="flex gap-2">
                            <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Bỏ qua</button>
                            <button v-if="!isLocked" @click="saveAdjustments" class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">Xong</button>
                        </div>
                    </div>
                </div>

                <!-- Deduction Popup -->
                <div v-if="popup.type === 'deduction'" class="relative bg-white rounded-lg shadow-xl w-[550px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Giảm trừ - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <h4 class="text-sm font-semibold text-gray-600 mb-2">Giảm trừ</h4>
                        <table class="w-full text-sm mb-4">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Tên giảm trừ</th>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[140px]">Loại</th>
                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[130px]">Số tiền</th>
                                    <th class="w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(adj, i) in popupAdjustments" :key="adj.id" class="border-t">
                                    <td class="px-2 py-1">
                                        <input v-model="adj.name" :disabled="isLocked" type="text"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:border-blue-500 outline-none"
                                            placeholder="BHXH, tạm ứng..." />
                                    </td>
                                    <td class="px-2 py-1">
                                        <select v-model="adj.meta.category" :disabled="isLocked"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:border-blue-500 outline-none bg-white">
                                            <option value="">-- Chọn --</option>
                                            <option value="late">Đi muộn</option>
                                            <option value="early_leave">Về sớm</option>
                                            <option value="bhxh">BHXH</option>
                                            <option value="advance">Tạm ứng</option>
                                            <option value="fixed">Cố định</option>
                                            <option value="penalty">Phạt</option>
                                            <option value="other">Khác</option>
                                        </select>
                                    </td>
                                    <td class="px-2 py-1">
                                        <input v-model.number="adj.amount" :disabled="isLocked" type="number" min="0"
                                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-1 py-1 text-center">
                                        <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600">&times;</button>
                                    </td>
                                </tr>
                                <tr v-for="(adj, i) in popupAdjustments" :key="'note-'+adj.id" class="bg-gray-50/50">
                                    <td colspan="4" class="px-2 py-1">
                                        <input v-model="adj.notes" :disabled="isLocked" type="text"
                                            class="w-full border border-gray-200 rounded px-2 py-1 text-xs text-gray-600 focus:border-blue-400 outline-none"
                                            placeholder="Ghi chú diễn giải (tùy chọn)..." />
                                    </td>
                                </tr>
                                <tr v-if="popupAdjustments.length === 0" class="border-t">
                                    <td colspan="4" class="px-3 py-3 text-center text-gray-400">Chưa có giảm trừ nào</td>
                                </tr>
                            </tbody>
                        </table>
                        <button v-if="!isLocked" @click="addAdjustmentRow('deduction')"
                            class="text-sm text-blue-600 hover:underline mb-4">+ Thêm giảm trừ</button>

                        <!-- Late penalty (read-only, auto from attendance) -->
                        <div v-if="latePenaltyItems.length > 0">
                            <h4 class="text-sm font-semibold text-gray-600 mb-2">Phạt đi muộn (tự động)</h4>
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-xs text-gray-500 uppercase">
                                        <th class="px-3 py-2">Ngày</th>
                                        <th class="px-3 py-2 text-right">Muộn (phút)</th>
                                        <th class="px-3 py-2 text-right">Phạt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(lp, li) in latePenaltyItems" :key="'lp'+li" class="border-t">
                                        <td class="px-3 py-2">{{ formatDate(lp.date) }}</td>
                                        <td class="px-3 py-2 text-right">{{ lp.late_minutes }} phút</td>
                                        <td class="px-3 py-2 text-right font-semibold text-red-600">{{ fmt(lp.penalty || 0) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-gray-50 font-semibold border-t-2">
                                    <tr>
                                        <td class="px-3 py-2" colspan="2">Tổng phạt đi muộn</td>
                                        <td class="px-3 py-2 text-right text-red-600">{{ fmt(latePenaltyItems.reduce((s, l) => s + (l.penalty || 0), 0)) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-between items-center bg-gray-50">
                        <div class="font-semibold text-red-600">Tổng giảm trừ: {{ fmt(popupTotal) }}</div>
                        <div class="flex gap-2">
                            <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Bỏ qua</button>
                            <button v-if="!isLocked" @click="saveAdjustments" class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">Xong</button>
                        </div>
                    </div>
                </div>

                <!-- Base Salary Popup (Lương chính) — Editable like KiotViet -->
                <div v-if="popup.type === 'base'" class="relative bg-white rounded-lg shadow-xl w-[750px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-gray-800">Lương chính</h3>
                            <div class="text-sm text-gray-500 mt-0.5">
                                Nhân viên: {{ popup.slip?.employee?.name }}
                                | Loại lương: {{ salaryTypeLabel(popup.slip?.details?.salary_type) }}
                                | Ngày công chuẩn: {{ popup.slip?.details?.standard_work_units || 26 }}
                            </div>
                        </div>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <div class="text-sm text-gray-600 mb-3">
                            Mức lương: <span class="font-semibold text-gray-800">{{ fmt(popup.slip?.details?.base_salary_full || 0) }}</span>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Ngày</th>
                                    <th class="px-3 py-2 text-right">Lương mỗi ngày</th>
                                    <th class="px-3 py-2 text-right">Số ngày chấm công</th>
                                    <th class="px-3 py-2 text-right">Số ngày tính lương</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Tổng row -->
                                <tr class="border-t bg-blue-50/60 font-semibold">
                                    <td class="px-3 py-2" colspan="2"></td>
                                    <td class="px-3 py-2 text-right">{{ baseEditTotalAttendDays }}</td>
                                    <td class="px-3 py-2 text-right">{{ baseEditTotalPayDays }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(baseEditDirty ? baseEditTotalAmount : (popup.slip?.base_salary || 0)) }}</td>
                                </tr>
                                <!-- Ngày thường -->
                                <tr class="border-t">
                                    <td class="px-3 py-2 font-medium">Ngày thường</td>
                                    <td class="px-3 py-2 text-right">
                                        <input :value="fmt(baseEditRows.normal.rate)" @change="baseEditRows.normal.rate = parseNum($event.target.value); baseEditDirty = true" :disabled="isLocked" type="text"
                                            class="w-28 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-3 py-2 text-right text-gray-500">{{ baseEditRows.normal.attendDays }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <input :value="baseEditRows.normal.payDays" @change="baseEditRows.normal.payDays = parseFloat($event.target.value) || 0; baseEditDirty = true" :disabled="isLocked" type="text"
                                            class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(Math.round(baseEditRows.normal.rate * baseEditRows.normal.payDays)) }}</td>
                                </tr>
                                <!-- Ngày nghỉ -->
                                <tr class="border-t">
                                    <td class="px-3 py-2 font-medium">
                                        Ngày nghỉ
                                        <div class="text-xs text-gray-400">{{ baseEditRows.rest_day.multiplier || 200 }}%</div>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input :value="fmt(baseEditRows.rest_day.rate)" @change="baseEditRows.rest_day.rate = parseNum($event.target.value); baseEditDirty = true" :disabled="isLocked" type="text"
                                            class="w-28 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-3 py-2 text-right text-gray-500">{{ baseEditRows.rest_day.attendDays }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <input :value="baseEditRows.rest_day.payDays" @change="baseEditRows.rest_day.payDays = parseFloat($event.target.value) || 0; baseEditDirty = true" :disabled="isLocked" type="text"
                                            class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(Math.round(baseEditRows.rest_day.rate * baseEditRows.rest_day.payDays)) }}</td>
                                </tr>
                                <!-- Ngày lễ tết -->
                                <tr class="border-t">
                                    <td class="px-3 py-2 font-medium">
                                        Ngày lễ tết
                                        <div class="text-xs text-gray-400">{{ baseEditRows.holiday.multiplier || 300 }}%</div>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input :value="fmt(baseEditRows.holiday.rate)" @change="baseEditRows.holiday.rate = parseNum($event.target.value); baseEditDirty = true" :disabled="isLocked" type="text"
                                            class="w-28 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-3 py-2 text-right text-gray-500">{{ baseEditRows.holiday.attendDays }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <input :value="baseEditRows.holiday.payDays" @change="baseEditRows.holiday.payDays = parseFloat($event.target.value) || 0; baseEditDirty = true" :disabled="isLocked" type="text"
                                            class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:border-blue-500 outline-none" />
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(Math.round(baseEditRows.holiday.rate * baseEditRows.holiday.payDays)) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-between items-center bg-gray-50">
                        <div class="font-semibold">
                            Tổng: {{ fmt(baseEditDirty ? baseEditTotalAmount : (popup.slip?.base_salary || 0)) }}
                            <span v-if="baseEditDirty" class="text-xs text-orange-500 ml-2">(đã sửa)</span>
                        </div>
                        <div class="flex gap-2">
                            <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Bỏ qua</button>
                            <button v-if="!isLocked" @click="baseEditDirty ? saveBaseSalary() : closePopup()"
                                class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">Xong</button>
                        </div>
                    </div>
                </div>

                <!-- Workdays Popup (Ngày công) -->
                <div v-if="popup.type === 'workdays'" class="relative bg-white rounded-lg shadow-xl w-[650px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Ngày công - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="bg-blue-50 rounded-lg p-3">
                                <div class="text-xs text-gray-500 mb-1">Ngày công thực tế</div>
                                <div class="text-2xl font-bold text-blue-700">{{ popup.slip?.details?.normal_work_units || 0 }}</div>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <div class="text-xs text-gray-500 mb-1">Ngày công chuẩn</div>
                                <div class="text-2xl font-bold text-gray-700">{{ popup.slip?.details?.standard_work_units || 26 }}</div>
                            </div>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Loại</th>
                                    <th class="px-3 py-2 text-right">Hệ số</th>
                                    <th class="px-3 py-2 text-right">Số ngày</th>
                                    <th class="px-3 py-2 text-right">Quy đổi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-t">
                                    <td class="px-3 py-2 font-medium">Ngày thường</td>
                                    <td class="px-3 py-2 text-right">100%</td>
                                    <td class="px-3 py-2 text-right">{{ baseSalaryBreakdown.normal?.days || 0 }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ baseSalaryBreakdown.normal?.days || 0 }}</td>
                                </tr>
                                <tr v-if="baseSalaryBreakdown.rest_day?.days > 0" class="border-t">
                                    <td class="px-3 py-2 font-medium">
                                        Ngày nghỉ (CN)
                                        <span class="text-xs text-gray-400 ml-1">{{ baseSalaryBreakdown.rest_day?.multiplier || 200 }}%</span>
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ baseSalaryBreakdown.rest_day?.multiplier || 200 }}%</td>
                                    <td class="px-3 py-2 text-right">{{ baseSalaryBreakdown.rest_day?.days || 0 }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ (baseSalaryBreakdown.rest_day?.days || 0) * (baseSalaryBreakdown.rest_day?.multiplier || 200) / 100 }}</td>
                                </tr>
                                <tr v-if="baseSalaryBreakdown.holiday?.days > 0" class="border-t">
                                    <td class="px-3 py-2 font-medium">
                                        Ngày lễ tết
                                        <span class="text-xs text-gray-400 ml-1">{{ baseSalaryBreakdown.holiday?.multiplier || 300 }}%</span>
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ baseSalaryBreakdown.holiday?.multiplier || 300 }}%</td>
                                    <td class="px-3 py-2 text-right">{{ baseSalaryBreakdown.holiday?.days || 0 }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ (baseSalaryBreakdown.holiday?.days || 0) * (baseSalaryBreakdown.holiday?.multiplier || 300) / 100 }}</td>
                                </tr>
                                <tr v-if="popup.slip?.details?.paid_leave_units > 0" class="border-t">
                                    <td class="px-3 py-2 font-medium text-green-700">Nghỉ phép (có lương)</td>
                                    <td class="px-3 py-2 text-right">100%</td>
                                    <td class="px-3 py-2 text-right">{{ popup.slip?.details?.paid_leave_units || 0 }}</td>
                                    <td class="px-3 py-2 text-right font-semibold text-green-700">{{ popup.slip?.details?.paid_leave_units || 0 }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="2">Tổng quy đổi</td>
                                    <td class="px-3 py-2 text-right">{{ popup.slip?.details?.normal_work_units || 0 }}</td>
                                    <td class="px-3 py-2 text-right text-blue-700">{{ popup.slip?.work_units || 0 }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-end bg-gray-50">
                        <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Đóng</button>
                    </div>
                </div>

                <!-- Paid Amount Popup (Đã trả) -->
                <div v-if="popup.type === 'paid'" class="relative bg-white rounded-lg shadow-xl w-[450px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Thanh toán - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                    </div>
                    <div class="p-5">
                        <div class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Tổng lương:</span>
                                <span class="font-semibold">{{ fmt(popup.slip?.total_salary || 0) }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Đã trả:</span>
                                <span class="font-semibold text-green-700">{{ fmt(popup.slip?.paid_amount || 0) }}</span>
                            </div>
                            <div class="flex justify-between text-sm border-t pt-2">
                                <span class="text-gray-700 font-medium">Còn phải trả:</span>
                                <span class="font-bold" :class="popup.slip?.remaining > 0 ? 'text-red-600' : 'text-gray-500'">
                                    {{ fmt(popup.slip?.remaining || 0) }}
                                </span>
                            </div>
                            <div v-if="!isLocked && popup.slip?.remaining > 0" class="mt-4 pt-3 border-t">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền thanh toán</label>
                                <input v-model.number="paidAmountInput" type="number" min="0"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm text-right focus:border-blue-500 outline-none"
                                    :placeholder="'Tối đa ' + fmt(popup.slip?.remaining || 0)" />
                                <div class="flex gap-2 mt-2">
                                    <button @click="paidAmountInput = popup.slip?.remaining || 0"
                                        class="text-xs text-blue-600 hover:underline">Trả hết</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="px-5 py-3 border-t flex justify-between items-center bg-gray-50">
                        <div class="text-sm text-gray-500" v-if="popup.slip?.remaining <= 0">Đã thanh toán đầy đủ ✓</div>
                        <div v-else></div>
                        <div class="flex gap-2">
                            <button @click="closePopup" class="px-4 py-1.5 text-sm border rounded-md hover:bg-gray-100">Bỏ qua</button>
                            <button v-if="!isLocked && popup.slip?.remaining > 0" @click="savePaidAmount"
                                class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700"
                                :disabled="!paidAmountInput || paidAmountInput <= 0">Thanh toán</button>
                        </div>
                    </div>
                </div>

            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { ref, computed, reactive, onMounted } from "vue";
import axios from "axios";

const props = defineProps({
    paysheet: { type: Object, required: true },
    salarySettings: { type: Object, default: () => ({}) },
});

// ===== Reactive paysheet data =====
const localPaysheet = ref(JSON.parse(JSON.stringify(props.paysheet)));
const searchQuery = ref("");
const recalculating = ref(false);
const autoRecalcMessage = ref('');

const isLocked = computed(() => localPaysheet.value.status === 'locked' || localPaysheet.value.status === 'cancelled');

// Auto-recalc khi mở trang nếu có dữ liệu thay đổi
onMounted(async () => {
    if (localPaysheet.value.needs_recalc && !isLocked.value) {
        try {
            const { data } = await axios.get(`/api/paysheets/${localPaysheet.value.id}`);
            if (data.success) {
                localPaysheet.value = data.data;
                if (data.auto_recalculated) {
                    autoRecalcMessage.value = 'Bảng lương đã được tự động cập nhật do có thay đổi dữ liệu chấm công/lương.';
                    setTimeout(() => { autoRecalcMessage.value = ''; }, 8000);
                }
            }
        } catch (e) {
            console.error('Auto-recalc check failed:', e);
        }
    }
});

const filteredSlips = computed(() => {
    const q = searchQuery.value.toLowerCase().trim();
    const slips = localPaysheet.value.payslips || [];
    if (!q) return slips;
    return slips.filter(s => {
        const emp = s.employee || {};
        return (emp.name || '').toLowerCase().includes(q) || (emp.code || '').toLowerCase().includes(q);
    });
});

const summaryTotals = computed(() => {
    const slips = filteredSlips.value;
    return {
        base_salary: slips.reduce((s, sl) => s + (sl.base_salary || 0), 0),
        ot_pay: slips.reduce((s, sl) => s + (sl.ot_pay || 0), 0),
        commission: slips.reduce((s, sl) => s + (sl.commission || 0), 0),
        allowances: slips.reduce((s, sl) => s + (sl.allowances || 0), 0),
        bonus: slips.reduce((s, sl) => s + (sl.bonus || 0), 0),
        deductions: slips.reduce((s, sl) => s + (sl.deductions || 0), 0),
        total_salary: slips.reduce((s, sl) => s + (sl.total_salary || 0), 0),
        total_paid: slips.reduce((s, sl) => s + (sl.paid_amount || 0), 0),
        total_remaining: slips.reduce((s, sl) => s + (sl.remaining || 0), 0),
    };
});

// ===== Popup State =====
const popup = reactive({
    show: false,
    type: '',      // 'ot' | 'commission' | 'allowance' | 'bonus' | 'deduction' | 'base' | 'workdays' | 'paid'
    slip: null,
});

const popupAdjustments = ref([]);
const pendingDeletes = ref([]); // adjustment IDs to delete on save
const paidAmountInput = ref(0);
const baseEditRows = reactive({
    normal: { rate: 0, payDays: 0, attendDays: 0, multiplier: 100 },
    rest_day: { rate: 0, payDays: 0, attendDays: 0, multiplier: 200 },
    holiday: { rate: 0, payDays: 0, attendDays: 0, multiplier: 300 },
});
const baseEditDirty = ref(false);
let tempIdCounter = -1;

// ===== Popup computed items =====
const otBreakdown = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.ot_breakdown || [];
});

const commissionItems = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.commission || [];
});

const latePenaltyItems = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.late_penalty || [];
});

const baseSalaryBreakdown = computed(() => {
    if (!popup.slip) return { normal: {}, rest_day: {}, holiday: {} };
    return popup.slip.details?.base_salary_breakdown || { normal: {}, rest_day: {}, holiday: {} };
});

const baseEditTotalAttendDays = computed(() => {
    return (baseEditRows.normal.attendDays || 0)
         + (baseEditRows.rest_day.attendDays || 0)
         + (baseEditRows.holiday.attendDays || 0);
});

const baseEditTotalPayDays = computed(() => {
    return (baseEditRows.normal.payDays || 0)
         + (baseEditRows.rest_day.payDays || 0)
         + (baseEditRows.holiday.payDays || 0);
});

const baseEditTotalAmount = computed(() => {
    return Math.round((baseEditRows.normal.rate * baseEditRows.normal.payDays)
         + (baseEditRows.rest_day.rate * baseEditRows.rest_day.payDays)
         + (baseEditRows.holiday.rate * baseEditRows.holiday.payDays));
});

const popupTotal = computed(() => {
    if (!popup.slip) return 0;
    const type = popup.type;

    if (type === 'ot') {
        // OT: auto breakdown + additive manual
        const autoTotal = otBreakdown.value.reduce((s, o) => s + (o.amount || 0), 0);
        const adjTotal = popupAdjustments.value.reduce((s, a) => s + (a.amount || 0), 0);
        return autoTotal + adjTotal;
    } else if (type === 'commission') {
        return popup.slip.commission || 0;
    } else if (type === 'deduction') {
        // Deduction: editable items + auto late penalty
        const adjTotal = popupAdjustments.value.reduce((s, a) => s + (a.amount || 0), 0);
        const lateTotal = latePenaltyItems.value.reduce((s, l) => s + (l.penalty || 0), 0);
        return adjTotal + lateTotal;
    } else {
        // Bonus/Allowance: sum of editable items (they replace auto)
        return popupAdjustments.value.reduce((s, a) => s + (a.amount || 0), 0);
    }
});

// ===== Pre-populate from employee salary settings =====
function getSettingsItems(type, slip) {
    const s = props.salarySettings[slip.employee_id];
    if (!s) return [];
    const calcDetails = slip.details?.details || {};

    if (type === 'allowance') {
        const calcItems = calcDetails.allowances || [];
        return (s.custom_allowances || []).map(a => {
            const calc = calcItems.find(c => c.name === a.name);
            return {
                id: tempIdCounter--,
                type: 'allowance',
                name: a.name || 'Phụ cấp',
                amount: calc ? (calc.calculated || 0) : (a.amount || 0),
                notes: '',
                meta: {},
                _existing: false,
                _fromSettings: true,
            };
        });
    }

    if (type === 'bonus') {
        const calcItems = calcDetails.bonus || [];
        return (s.custom_bonuses || []).map(b => {
            const calc = calcItems.find(c => c.role_type === b.role_type);
            return {
                id: tempIdCounter--,
                type: 'bonus',
                name: b.name || 'Thưởng',
                amount: calc ? (calc.calculated || 0) : (b.bonus_value || 0),
                notes: '',
                meta: {},
                _existing: false,
                _fromSettings: true,
            };
        });
    }

    if (type === 'deduction') {
        const calcItems = calcDetails.deductions || [];
        return (s.custom_deductions || []).map(d => {
            const calc = calcItems.find(c => c.name === d.name);
            return {
                id: tempIdCounter--,
                type: 'deduction',
                name: d.name || 'Giảm trừ',
                amount: calc ? (calc.calculated || 0) : (d.amount || 0),
                notes: '',
                meta: {},
                _existing: false,
                _fromSettings: true,
            };
        });
    }

    return [];
}

// ===== Popup Actions =====
function openPopup(type, slip) {
    popup.type = type;
    popup.slip = slip;
    popup.show = true;
    pendingDeletes.value = [];

    // For base salary popup → init editable rows from breakdown data or compute from existing
    if (type === 'base') {
        const bd = slip.details?.base_salary_breakdown || {};
        const baseFull = slip.details?.base_salary_full || 0;
        const stdUnits = slip.details?.standard_work_units || 26;
        // KHÔNG round dailyRate → giữ chính xác để tổng khớp backend: baseFull * totalUnits / stdUnits
        const dailyRate = stdUnits > 0 ? baseFull / stdUnits : 0;
        const normalWU = slip.details?.normal_work_units || slip.work_units || 0;
        const totalWU = slip.work_units || 0;

        // Read multipliers from employee salary settings
        const empSetting = props.salarySettings[slip.employee_id] || {};
        const restMult = empSetting.holiday_rate || 200; // % ngày nghỉ
        const holMult = empSetting.tet_rate || 300;      // % ngày lễ tết

        // Nếu có breakdown data từ backend → dùng trực tiếp
        if (bd.normal?.rate) {
            baseEditRows.normal.rate = bd.normal.rate;
            baseEditRows.normal.payDays = bd.normal.days || 0;
            baseEditRows.normal.attendDays = bd.normal.days || 0;
            baseEditRows.normal.multiplier = 100;
            baseEditRows.rest_day.rate = bd.rest_day?.rate || dailyRate * restMult / 100;
            baseEditRows.rest_day.payDays = bd.rest_day?.days || 0;
            baseEditRows.rest_day.attendDays = bd.rest_day?.days || 0;
            baseEditRows.rest_day.multiplier = bd.rest_day?.multiplier || restMult;
            baseEditRows.holiday.rate = bd.holiday?.rate || dailyRate * holMult / 100;
            baseEditRows.holiday.payDays = bd.holiday?.days || 0;
            baseEditRows.holiday.attendDays = bd.holiday?.days || 0;
            baseEditRows.holiday.multiplier = bd.holiday?.multiplier || holMult;
        } else {
            // Fallback: tính từ dữ liệu có sẵn
            const restMultiplier = restMult / 100;
            const extraUnits = totalWU - normalWU;
            const restDays = restMultiplier > 1 ? Math.round(extraUnits / (restMultiplier - 1)) : 0;
            const normalDays = normalWU - restDays;

            baseEditRows.normal.rate = dailyRate;
            baseEditRows.normal.payDays = normalDays;
            baseEditRows.normal.attendDays = normalDays;
            baseEditRows.normal.multiplier = 100;
            baseEditRows.rest_day.rate = dailyRate * restMultiplier;
            baseEditRows.rest_day.payDays = restDays;
            baseEditRows.rest_day.attendDays = restDays;
            baseEditRows.rest_day.multiplier = restMult;
            baseEditRows.holiday.rate = dailyRate * holMult / 100;
            baseEditRows.holiday.payDays = 0;
            baseEditRows.holiday.attendDays = 0;
            baseEditRows.holiday.multiplier = holMult;
        }
        baseEditDirty.value = false;
        popupAdjustments.value = [];
        return;
    }

    // For read-only popups
    if (type === 'workdays' || type === 'commission') {
        popupAdjustments.value = [];
        return;
    }

    // For paid popup, init input
    if (type === 'paid') {
        paidAmountInput.value = slip.remaining || 0;
        popupAdjustments.value = [];
        return;
    }

    // Load existing adjustments for this type
    const existingAdj = (slip.adjustments || []).filter(a => a.type === type);

    if (existingAdj.length > 0) {
        // Already saved adjustments — use them, ensuring meta is an object
        popupAdjustments.value = existingAdj.map(a => ({
            ...a,
            meta: a.meta || {},
            _existing: true,
        }));
    } else if (type === 'allowance' || type === 'bonus' || type === 'deduction') {
        // Pre-populate from employee salary settings with calculated amounts
        popupAdjustments.value = getSettingsItems(type, slip);
    } else {
        popupAdjustments.value = [];
    }
}

function closePopup() {
    popup.show = false;
    popup.type = '';
    popup.slip = null;
    popupAdjustments.value = [];
    pendingDeletes.value = [];
}

function addAdjustmentRow(type) {
    if (type === 'ot') {
        const name = prompt('Nhập tên khoản OT:');
        if (!name) return;
        popupAdjustments.value.push({
            id: tempIdCounter--,
            type: 'ot',
            name: name,
            amount: 0,
            notes: '',
            meta: {},
            _existing: false,
        });
    } else {
        popupAdjustments.value.push({
            id: tempIdCounter--,
            type: type,
            name: '',
            amount: 0,
            notes: '',
            meta: {},
            _existing: false,
        });
    }
}

function deleteAdjustment(adj) {
    if (adj._existing && adj.id > 0) {
        pendingDeletes.value.push(adj.id);
    }
    popupAdjustments.value = popupAdjustments.value.filter(a => a !== adj);
}

async function saveAdjustments() {
    if (!popup.slip) return;
    const psId = localPaysheet.value.id;
    const slipId = popup.slip.id;
    const type = popup.type;

    try {
        // Delete removed adjustments
        for (const adjId of pendingDeletes.value) {
            await axios.delete(`/api/paysheets/${psId}/payslips/${slipId}/adjustments/${adjId}`);
        }

        // Update existing or create new
        for (const adj of popupAdjustments.value) {
            if (!adj.amount && adj.amount !== 0) continue;
            if (!adj.name) adj.name = type === 'allowance' ? 'Phụ cấp' : type === 'bonus' ? 'Thưởng' : type === 'deduction' ? 'Giảm trừ' : 'OT';
            if (adj._existing && adj.id > 0) {
                await axios.put(`/api/paysheets/${psId}/payslips/${slipId}/adjustments/${adj.id}`, {
                    name: adj.name,
                    amount: adj.amount,
                    notes: adj.notes || '',
                    meta: adj.meta || {},
                });
            } else {
                await axios.post(`/api/paysheets/${psId}/payslips/${slipId}/adjustments`, {
                    type: type,
                    name: adj.name,
                    amount: adj.amount,
                    notes: adj.notes || '',
                    meta: adj.meta || {},
                });
            }
        }

        // Refresh data
        const { data } = await axios.get(`/api/paysheets/${psId}`);
        if (data.success) {
            localPaysheet.value = data.data;
        }

        closePopup();
    } catch (e) {
        console.error('Save adjustments error:', e);
        alert('Lỗi khi lưu điều chỉnh.');
    }
}

async function saveBaseSalary() {
    if (!popup.slip) return;
    const psId = localPaysheet.value.id;
    const slipId = popup.slip.id;
    const newBase = baseEditTotalAmount.value;

    try {
        const { data } = await axios.put(`/api/paysheets/${psId}/payslips/${slipId}`, {
            base_salary: newBase,
        });

        if (data.success) {
            // Reload full data to refresh all computed fields
            const { data: full } = await axios.get(`/api/paysheets/${psId}`);
            if (full.success) {
                localPaysheet.value = full.data;
            }
        }

        closePopup();
    } catch (e) {
        console.error('Save base salary error:', e);
        alert('Lỗi khi lưu lương chính.');
    }
}

async function savePaidAmount() {
    if (!popup.slip || !paidAmountInput.value || paidAmountInput.value <= 0) return;
    const psId = localPaysheet.value.id;
    const slipId = popup.slip.id;

    try {
        const { data } = await axios.post(`/api/paysheets/${psId}/pay`, {
            payslip_ids: [slipId],
            method: 'cash',
            notes: '',
        });

        if (data.success) {
            localPaysheet.value = data.data;
            // Reload full data to get adjustments
            const { data: full } = await axios.get(`/api/paysheets/${psId}`);
            if (full.success) {
                localPaysheet.value = full.data;
            }
        }

        closePopup();
    } catch (e) {
        console.error('Save paid amount error:', e);
        alert('Lỗi khi thanh toán.');
    }
}

// ===== Paysheet Actions =====
async function recalculate() {
    recalculating.value = true;
    try {
        const { data } = await axios.post(`/api/paysheets/${localPaysheet.value.id}/recalculate`);
        if (data.success) {
            localPaysheet.value = data.data;
        }
    } catch (e) {
        console.error('Recalculate error:', e);
        alert('Lỗi khi tính lại.');
    } finally {
        recalculating.value = false;
    }
}

async function lockPaysheet() {
    if (!confirm('Chốt bảng lương? Sau khi chốt sẽ không thể chỉnh sửa.')) return;
    try {
        const { data } = await axios.put(`/api/paysheets/${localPaysheet.value.id}/lock`);
        if (data.success) {
            localPaysheet.value = data.data;
        }
    } catch (e) {
        console.error('Lock error:', e);
        alert('Lỗi khi chốt lương.');
    }
}

function goBack() {
    router.visit('/employees/paysheets');
}

// ===== Formatters =====
function fmt(v) {
    if (!v && v !== 0) return '0';
    return Number(v).toLocaleString('vi-VN');
}

function parseNum(str) {
    if (!str) return 0;
    // Remove dots, commas, spaces → parse as int
    return parseInt(String(str).replace(/[.\s,]/g, ''), 10) || 0;
}

function formatDate(d) {
    if (!d) return '';
    const dt = new Date(d);
    return dt.toLocaleDateString('vi-VN');
}

function formatHours(minutes) {
    if (!minutes) return '0h';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h${m}p` : `${h}h`;
}

function statusLabel(s) {
    const map = { draft: 'Đang tạo', calculating: 'Đang tính', calculated: 'Tạm tính', locked: 'Đã chốt', cancelled: 'Đã hủy' };
    return map[s] || s;
}

function salaryTypeLabel(t) {
    const map = { by_workday: 'Theo ngày công chuẩn', fixed: 'Cố định', hourly: 'Theo giờ' };
    return map[t] || 'Theo ngày công chuẩn';
}

function statusClass(s) {
    const map = {
        draft: 'bg-gray-100 text-gray-600',
        calculating: 'bg-yellow-100 text-yellow-700',
        calculated: 'bg-blue-100 text-blue-700',
        locked: 'bg-green-100 text-green-700',
        cancelled: 'bg-red-100 text-red-600',
    };
    return map[s] || 'bg-gray-100 text-gray-600';
}
</script>
