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
                                {{ slip.work_units || 0 }}<span class="text-gray-400">/{{ slip.details?.standard_work_units || 26 }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-medium">{{ fmt(slip.base_salary) }}</td>
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
                            <td class="px-3 py-2 text-right text-green-700">{{ fmt(slip.paid_amount) }}</td>
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
                                    <th class="px-3 py-2 text-right">Lương/giờ</th>
                                    <th class="px-3 py-2 text-right">Số giờ</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ob in otBreakdown" :key="ob.type" class="border-t">
                                    <td class="px-3 py-2 font-medium">{{ ob.label }}</td>
                                    <td class="px-3 py-2 text-right">{{ ob.rate_percent }}%</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(ob.hourly_rate) }}</td>
                                    <td class="px-3 py-2 text-right">{{ formatHours(ob.minutes) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(ob.amount) }}</td>
                                </tr>
                                <tr v-if="otBreakdown.length === 0" class="border-t">
                                    <td colspan="5" class="px-3 py-4 text-center text-gray-400">Không có dữ liệu làm thêm</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="3">Tổng OT (tự động)</td>
                                    <td class="px-3 py-2 text-right">{{ formatHours(otBreakdown.reduce((s, o) => s + (o.minutes || 0), 0)) }}</td>
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
                <div v-if="popup.type === 'allowance'" class="relative bg-white rounded-lg shadow-xl w-[600px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Phụ cấp - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Tên phụ cấp</th>
                                    <th class="px-3 py-2 text-right">Mức</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(a, ai) in allowanceItems" :key="ai" class="border-t">
                                    <td class="px-3 py-2">{{ a.name }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(a.amount || 0) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(a.calculated || a.amount || 0) }}</td>
                                </tr>
                                <tr v-if="allowanceItems.length === 0" class="border-t">
                                    <td colspan="3" class="px-3 py-4 text-center text-gray-400">Không có phụ cấp</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="2">Tổng phụ cấp (tự động)</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(allowanceItems.reduce((s, a) => s + (a.calculated || a.amount || 0), 0)) }}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Thêm phụ cấp từ cài đặt -->
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold text-gray-600 mb-2">Phụ cấp thủ công</h4>
                            <div v-for="adj in popupAdjustments" :key="adj.id" class="flex items-center gap-2 mb-2">
                                <span class="flex-1 text-sm text-gray-700">{{ adj.name }}</span>
                                <input v-model.number="adj.amount" :disabled="isLocked" type="number" class="w-32 text-sm border rounded px-2 py-1 text-right" />
                                <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600 text-lg">&times;</button>
                            </div>
                            <!-- Dropdown thêm từ cài đặt -->
                            <div v-if="!isLocked && unusedSettingsOptions.length" class="mt-2">
                                <select @change="addFromSettingsDropdown($event, 'allowance')" class="text-sm border rounded px-2 py-1.5 text-blue-600 w-full">
                                    <option value="">+ Thêm phụ cấp từ cài đặt...</option>
                                    <option v-for="opt in unusedSettingsOptions" :key="opt.name" :value="opt.name">
                                        {{ opt.name }} — {{ fmt(opt.amount) }}
                                    </option>
                                </select>
                            </div>
                            <button v-if="!isLocked" @click="addAdjustmentRow('allowance')"
                                class="text-sm text-blue-600 hover:underline mt-1">+ Thêm phụ cấp tùy chỉnh</button>
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

                <!-- Bonus Popup -->
                <div v-if="popup.type === 'bonus'" class="relative bg-white rounded-lg shadow-xl w-[650px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Thưởng - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Loại thưởng</th>
                                    <th class="px-3 py-2 text-right">Doanh thu từ</th>
                                    <th class="px-3 py-2 text-right">Giá trị</th>
                                    <th class="px-3 py-2 text-center">%</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(b, bi) in bonusItems" :key="bi" class="border-t">
                                    <td class="px-3 py-2">{{ bonusRoleLabel(b.role_type) }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(b.revenue_from || 0) }}</td>
                                    <td class="px-3 py-2 text-right">{{ b.bonus_value }}</td>
                                    <td class="px-3 py-2 text-center">{{ b.is_percentage ? '✓' : '' }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(b.calculated || 0) }}</td>
                                </tr>
                                <tr v-if="bonusItems.length === 0" class="border-t">
                                    <td colspan="5" class="px-3 py-4 text-center text-gray-400">Không có thưởng tự động</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="4">Tổng thưởng (tự động)</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(bonusItems.reduce((s, b) => s + (b.calculated || 0), 0)) }}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Thêm thưởng từ cài đặt -->
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold text-gray-600 mb-2">Thưởng thủ công</h4>
                            <div v-for="adj in popupAdjustments" :key="adj.id" class="flex items-center gap-2 mb-2">
                                <span class="flex-1 text-sm text-gray-700">{{ adj.name }}</span>
                                <input v-model.number="adj.amount" :disabled="isLocked" type="number" class="w-32 text-sm border rounded px-2 py-1 text-right" />
                                <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600 text-lg">&times;</button>
                            </div>
                            <!-- Dropdown thêm từ cài đặt -->
                            <div v-if="!isLocked && unusedSettingsOptions.length" class="mt-2">
                                <select @change="addFromSettingsDropdown($event, 'bonus')" class="text-sm border rounded px-2 py-1.5 text-blue-600 w-full">
                                    <option value="">+ Thêm thưởng từ cài đặt...</option>
                                    <option v-for="opt in unusedSettingsOptions" :key="opt.name" :value="opt.name">
                                        {{ opt.name }} — {{ opt.is_percentage ? opt.value + '%' : fmt(opt.amount) }}
                                    </option>
                                </select>
                            </div>
                            <button v-if="!isLocked" @click="addAdjustmentRow('bonus')"
                                class="text-sm text-blue-600 hover:underline mt-1">+ Thêm thưởng tùy chỉnh</button>
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

                <!-- Deduction Popup -->
                <div v-if="popup.type === 'deduction'" class="relative bg-white rounded-lg shadow-xl w-[700px] max-h-[80vh] overflow-hidden z-10">
                    <div class="px-5 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Giảm trừ - {{ popup.slip?.employee?.name }}</h3>
                        <button @click="closePopup" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <div class="p-5 overflow-auto max-h-[60vh]">
                        <!-- Fixed deductions -->
                        <h4 class="text-sm font-semibold text-gray-600 mb-2">Giảm trừ cố định</h4>
                        <table class="w-full text-sm mb-4">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Tên</th>
                                    <th class="px-3 py-2 text-right">Loại tính</th>
                                    <th class="px-3 py-2 text-right">Mức</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(d, di) in deductionFixedItems" :key="'df'+di" class="border-t">
                                    <td class="px-3 py-2">{{ d.name }}</td>
                                    <td class="px-3 py-2 text-right text-xs text-gray-500">{{ deductionCalcLabel(d.calculation_type) }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(d.amount || 0) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmt(d.calculated || 0) }}</td>
                                </tr>
                                <tr v-if="deductionFixedItems.length === 0" class="border-t">
                                    <td colspan="4" class="px-3 py-3 text-center text-gray-400">Không có giảm trừ cố định</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Late penalty -->
                        <h4 class="text-sm font-semibold text-gray-600 mb-2">Phạt đi muộn</h4>
                        <table class="w-full text-sm mb-4">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Ngày</th>
                                    <th class="px-3 py-2 text-right">Muộn (phút)</th>
                                    <th class="px-3 py-2 text-right">Phạt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(lp, li) in latePenaltyItems" :key="'lp'+li" class="border-t">
                                    <td class="px-3 py-2">{{ lp.date }}</td>
                                    <td class="px-3 py-2 text-right">{{ lp.late_minutes }} phút</td>
                                    <td class="px-3 py-2 text-right font-semibold text-red-600">{{ fmt(lp.penalty || 0) }}</td>
                                </tr>
                                <tr v-if="latePenaltyItems.length === 0" class="border-t">
                                    <td colspan="3" class="px-3 py-3 text-center text-gray-400">Không có phạt đi muộn</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="latePenaltyItems.length > 0" class="bg-gray-50 font-semibold border-t-2">
                                <tr>
                                    <td class="px-3 py-2" colspan="2">Tổng phạt đi muộn</td>
                                    <td class="px-3 py-2 text-right text-red-600">{{ fmt(latePenaltyItems.reduce((s, l) => s + (l.penalty || 0), 0)) }}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Thêm giảm trừ từ cài đặt -->
                        <div class="mt-2">
                            <h4 class="text-sm font-semibold text-gray-600 mb-2">Giảm trừ thủ công</h4>
                            <div v-for="adj in popupAdjustments" :key="adj.id" class="flex items-center gap-2 mb-2">
                                <span class="flex-1 text-sm text-gray-700">{{ adj.name }}</span>
                                <input v-model.number="adj.amount" :disabled="isLocked" type="number" class="w-32 text-sm border rounded px-2 py-1 text-right" />
                                <button v-if="!isLocked" @click="deleteAdjustment(adj)" class="text-red-400 hover:text-red-600 text-lg">&times;</button>
                            </div>
                            <!-- Dropdown thêm từ cài đặt -->
                            <div v-if="!isLocked && unusedSettingsOptions.length" class="mt-2">
                                <select @change="addFromSettingsDropdown($event, 'deduction')" class="text-sm border rounded px-2 py-1.5 text-blue-600 w-full">
                                    <option value="">+ Thêm giảm trừ từ cài đặt...</option>
                                    <option v-for="opt in unusedSettingsOptions" :key="opt.name" :value="opt.name">
                                        {{ opt.name }} — {{ fmt(opt.amount) }}
                                    </option>
                                </select>
                            </div>
                            <button v-if="!isLocked" @click="addAdjustmentRow('deduction')"
                                class="text-sm text-blue-600 hover:underline mt-1">+ Thêm giảm trừ tùy chỉnh</button>
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

            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { ref, computed, reactive } from "vue";
import axios from "axios";

const props = defineProps({
    paysheet: { type: Object, required: true },
    salarySettings: { type: Object, default: () => ({}) },
});

// ===== Reactive paysheet data =====
const localPaysheet = ref(JSON.parse(JSON.stringify(props.paysheet)));
const searchQuery = ref("");
const recalculating = ref(false);

const isLocked = computed(() => localPaysheet.value.status === 'locked' || localPaysheet.value.status === 'cancelled');

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
    type: '',      // 'ot' | 'commission' | 'allowance' | 'bonus' | 'deduction'
    slip: null,
});

const popupAdjustments = ref([]);
const pendingDeletes = ref([]); // adjustment IDs to delete on save
let tempIdCounter = -1;

// ===== Employee salary settings for current popup =====
const empSettings = computed(() => {
    if (!popup.slip) return null;
    const eid = popup.slip.employee_id;
    return props.salarySettings[eid] || null;
});

// Options from employee settings based on popup type (excluding already-added ones)
const settingsOptionsAll = computed(() => {
    const s = empSettings.value;
    if (!s) return [];
    const type = popup.type;
    if (type === 'allowance') {
        return (s.custom_allowances || []).map(a => ({
            name: a.name || 'Phụ cấp',
            amount: a.amount || 0,
        }));
    }
    if (type === 'bonus') {
        return (s.custom_bonuses || []).map(b => ({
            name: bonusRoleLabel(b.role_type) + (b.revenue_from ? ` (từ ${fmt(b.revenue_from)})` : ''),
            amount: b.bonus_value || 0,
            is_percentage: b.bonus_is_percentage,
            value: b.bonus_value,
        }));
    }
    if (type === 'deduction') {
        return (s.custom_deductions || []).map(d => ({
            name: d.name || 'Giảm trừ',
            amount: d.amount || 0,
            calculation_type: d.calculation_type,
        }));
    }
    return [];
});

const unusedSettingsOptions = computed(() => {
    const usedNames = new Set(popupAdjustments.value.map(a => a.name));
    return settingsOptionsAll.value.filter(o => !usedNames.has(o.name));
});

function addFromSettingsDropdown(event, type) {
    const name = event.target.value;
    if (!name) return;
    const opt = settingsOptionsAll.value.find(o => o.name === name);
    if (!opt) return;
    popupAdjustments.value.push({
        id: tempIdCounter--,
        type: type,
        name: opt.name,
        amount: opt.amount || 0,
        notes: '',
        _existing: false,
    });
    event.target.value = '';
}

// ===== Popup computed items =====
const otBreakdown = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.ot_breakdown || [];
});

const commissionItems = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.commission || [];
});

const allowanceItems = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.allowances || [];
});

const bonusItems = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.bonus || [];
});

const deductionFixedItems = computed(() => {
    if (!popup.slip) return [];
    return (popup.slip.details?.details?.deductions || []).filter(d => d.category !== 'manual');
});

const latePenaltyItems = computed(() => {
    if (!popup.slip) return [];
    return popup.slip.details?.details?.late_penalty || [];
});

const popupTotal = computed(() => {
    if (!popup.slip) return 0;
    const type = popup.type;

    // Auto totals from details
    let autoTotal = 0;
    if (type === 'ot') {
        autoTotal = otBreakdown.value.reduce((s, o) => s + (o.amount || 0), 0);
    } else if (type === 'allowance') {
        autoTotal = allowanceItems.value.reduce((s, a) => s + (a.calculated || a.amount || 0), 0);
    } else if (type === 'bonus') {
        autoTotal = bonusItems.value.reduce((s, b) => s + (b.calculated || 0), 0);
    } else if (type === 'deduction') {
        const fixedTotal = deductionFixedItems.value.reduce((s, d) => s + (d.calculated || 0), 0);
        const lateTotal = latePenaltyItems.value.reduce((s, l) => s + (l.penalty || 0), 0);
        autoTotal = fixedTotal + lateTotal;
    } else if (type === 'commission') {
        return popup.slip.commission || 0;
    }

    // Manual adjustments
    const adjTotal = popupAdjustments.value.reduce((s, a) => s + (a.amount || 0), 0);
    return autoTotal + adjTotal;
});

// ===== Popup Actions =====
function openPopup(type, slip) {
    popup.type = type;
    popup.slip = slip;
    popup.show = true;
    pendingDeletes.value = [];

    // Load existing adjustments for this type
    const existingAdj = (slip.adjustments || []).filter(a => a.type === type);
    popupAdjustments.value = existingAdj.map(a => ({ ...a, _existing: true }));
}

function closePopup() {
    popup.show = false;
    popup.type = '';
    popup.slip = null;
    popupAdjustments.value = [];
    pendingDeletes.value = [];
}

function addAdjustmentRow(type) {
    const typeLabels = { ot: 'OT', allowance: 'phụ cấp', bonus: 'thưởng', deduction: 'giảm trừ' };
    const name = prompt(`Nhập tên ${typeLabels[type] || 'khoản'}:`);
    if (!name) return;
    popupAdjustments.value.push({
        id: tempIdCounter--,
        type: type,
        name: name,
        amount: 0,
        notes: '',
        _existing: false,
    });
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
            if (!adj.name || !adj.amount) continue;
            if (adj._existing && adj.id > 0) {
                await axios.put(`/api/paysheets/${psId}/payslips/${slipId}/adjustments/${adj.id}`, {
                    name: adj.name,
                    amount: adj.amount,
                    notes: adj.notes || '',
                });
            } else {
                await axios.post(`/api/paysheets/${psId}/payslips/${slipId}/adjustments`, {
                    type: type,
                    name: adj.name,
                    amount: adj.amount,
                    notes: adj.notes || '',
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

function bonusRoleLabel(role) {
    const map = { seller: 'Nhân viên bán hàng', technician: 'Kỹ thuật viên', manager: 'Quản lý' };
    return map[role] || role || 'Thưởng';
}

function deductionCalcLabel(type) {
    const map = { per_occurrence: 'Mỗi lần', per_minute: 'Mỗi phút', fixed_per_month: 'Cố định/tháng' };
    return map[type] || type || '';
}
</script>
