<template>
  <Head title="Bảng chấm công - KiotViet Clone" />
  <AppLayout>
  <div class="h-screen flex flex-col bg-gray-50 font-sans">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 px-6 py-3">
      <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
        <h1 class="text-lg font-bold text-gray-800">Bảng chấm công</h1>

        <div class="flex items-center gap-3 flex-wrap">
          <!-- Search -->
          <div class="relative">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input v-model="searchQuery" type="text" placeholder="Tìm kiếm nhân viên" class="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md w-48 outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          </div>

          <!-- View mode (shift/employee) -->
          <select v-model="viewMode" class="text-sm border border-gray-300 rounded-md px-3 py-1.5 outline-none focus:ring-1 focus:ring-blue-500">
            <option value="shift">Xem theo ca</option>
            <option value="employee">Xem theo nhân viên</option>
          </select>

          <!-- Period mode (week/month) -->
          <select v-model="periodMode" class="text-sm border border-gray-300 rounded-md px-3 py-1.5 outline-none focus:ring-1 focus:ring-blue-500">
            <option value="week">Theo tuần</option>
            <option value="month">Theo tháng</option>
          </select>

          <!-- Period nav -->
          <div class="flex items-center gap-1">
            <button @click="changePeriod(-1)" class="p-1.5 bg-white border border-gray-300 rounded hover:bg-gray-50"><svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
            <div class="text-sm font-medium text-gray-700 bg-white border border-gray-300 px-3 py-1.5 rounded whitespace-nowrap">
              <template v-if="periodMode === 'week'">Tuần {{ weekNumber }} - Th. {{ weekMonth }} {{ weekYear }}</template>
              <template v-else>Tháng {{ currentMonthDisplay }} / {{ currentYearDisplay }}</template>
            </div>
            <button @click="changePeriod(1)" class="p-1.5 bg-white border border-gray-300 rounded hover:bg-gray-50"><svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>
          </div>

          <!-- Duyệt chấm công -->
          <button @click="recalculate" class="flex items-center gap-1.5 px-4 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 transition disabled:opacity-50" :disabled="isRecalculating">
            <svg v-if="isRecalculating" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Duyệt chấm công</span>
          </button>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-auto">
      <div v-if="loading" class="flex justify-center items-center h-64">
        <svg class="animate-spin h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
      </div>

      <div v-else class="bg-white border-t border-gray-200">
        <div class="overflow-x-auto">
          <table class="min-w-full">
            <thead class="sticky top-0 z-10">
              <tr class="border-b border-gray-200 bg-gray-50">
                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 sticky left-0 bg-gray-50 z-20 min-w-[180px] border-r border-gray-200">
                  {{ viewMode === 'shift' ? 'Ca làm việc' : 'Nhân viên' }}
                </th>
                <th v-for="day in displayDays" :key="day.date"
                  class="py-2.5 text-center text-xs font-medium text-gray-500 border-r border-gray-200 last:border-r-0"
                  :class="periodMode === 'month' ? 'px-1 min-w-[48px]' : 'px-3 min-w-[200px]'"
                >
                  <div v-if="periodMode === 'week'">{{ day.dayName }} <span class="font-semibold text-gray-700">{{ day.dayNum }}</span></div>
                  <div v-else>
                    <div class="text-[10px] leading-tight" :class="day.isWeekend ? 'text-red-400' : 'text-gray-400'">{{ day.dayNameShort }}</div>
                    <div class="font-semibold text-gray-700 text-[11px]">{{ day.dayNum }}</div>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <!-- Xem theo Ca -->
              <template v-if="viewMode === 'shift'">
                <template v-if="shiftGroups.length === 0">
                  <tr><td :colspan="displayDays.length + 1" class="px-6 py-10 text-center text-gray-400">Chưa có dữ liệu chấm công.</td></tr>
                </template>
                <tr v-for="shiftGroup in filteredShiftGroups" :key="shiftGroup.shiftKey" class="border-b border-gray-200">
                  <td class="px-4 py-3 align-top sticky left-0 bg-white z-10 border-r border-gray-200">
                    <div class="font-semibold text-sm text-gray-800">{{ shiftGroup.shiftName }}</div>
                    <div class="text-xs text-gray-500">{{ shiftGroup.shiftTime }}</div>
                  </td>
                  <td v-for="day in displayDays" :key="day.date"
                    class="py-2 align-top border-r border-gray-200 last:border-r-0"
                    :class="periodMode === 'month' ? 'px-0.5' : 'px-2'"
                  >
                    <div class="flex flex-col gap-1">
                      <template v-for="item in getShiftDayItems(shiftGroup, day.date)" :key="item.schedule.id">
                        <div
                          @click="openModal(item.schedule)"
                          class="rounded cursor-pointer hover:shadow transition"
                          :class="[getCardClasses(item.record), periodMode === 'month' ? 'px-1 py-0.5 text-[9px]' : 'px-2 py-1.5 text-xs']"
                        >
                          <template v-if="periodMode === 'month'">
                            <div class="font-semibold truncate" :title="item.employeeName">{{ item.employeeName.split(' ').pop() }}</div>
                            <div class="text-[8px] mt-0.5" :class="getTimeTextClass(item.record)">
                              {{ formatCheckTimeShort(item.record?.check_in_at) }}
                            </div>
                          </template>
                          <template v-else>
                            <div class="font-semibold text-[12px]">{{ item.employeeName }}</div>
                            <div class="text-[11px] mt-0.5" :class="getTimeTextClass(item.record)">
                              {{ formatCheckTime(item.record?.check_in_at) }} - {{ formatCheckTime(item.record?.check_out_at) }}
                            </div>
                            <div v-if="getOtInfo(item.record)" class="text-[10px] mt-0.5" :class="getOtTextClass(item.record)">
                              {{ getOtInfo(item.record) }}
                            </div>
                            <div v-if="!item.record" class="text-[10px] text-gray-400 italic mt-0.5">Chưa chấm công</div>
                          </template>
                        </div>
                      </template>
                    </div>
                  </td>
                </tr>
              </template>

              <!-- Xem theo Nhân viên -->
              <template v-else>
                <template v-if="filteredEmployeeGroups.length === 0">
                  <tr><td :colspan="displayDays.length + 1" class="px-6 py-10 text-center text-gray-400">Chưa có dữ liệu chấm công.</td></tr>
                </template>
                <tr v-for="empRow in filteredEmployeeGroups" :key="empRow.employee.id" class="border-b border-gray-200 hover:bg-gray-50">
                  <td class="px-4 py-3 align-top sticky left-0 bg-white z-10 border-r border-gray-200">
                    <div class="font-semibold text-sm text-gray-800">{{ empRow.employee.name }}</div>
                    <div class="text-xs text-gray-500">{{ empRow.employee.code }}</div>
                  </td>
                  <td v-for="day in displayDays" :key="day.date"
                    class="py-2 align-top border-r border-gray-200 last:border-r-0"
                    :class="periodMode === 'month' ? 'px-0.5' : 'px-2'"
                  >
                    <div class="flex flex-col gap-1">
                      <template v-if="empRow.days[day.date]?.length">
                        <div
                          v-for="schedule in empRow.days[day.date]"
                          :key="schedule.id"
                          @click="openModal(schedule)"
                          class="rounded cursor-pointer hover:shadow transition"
                          :class="[getCardClasses(schedule.timekeeping_record), periodMode === 'month' ? 'px-1 py-0.5 text-[9px]' : 'px-2 py-1.5 text-xs']"
                        >
                          <template v-if="periodMode === 'month'">
                            <div class="font-semibold truncate" :title="schedule.shift?.name || 'Ca tự do'">{{ (schedule.shift?.name || 'Tự do').substring(0,4) }}</div>
                            <div class="text-[8px] mt-0.5" :class="getTimeTextClass(schedule.timekeeping_record)">
                              {{ formatCheckTimeShort(schedule.timekeeping_record?.check_in_at) }}
                            </div>
                          </template>
                          <template v-else>
                            <div class="font-semibold text-[12px]">{{ schedule.shift?.name || 'Ca tự do' }}</div>
                            <div class="text-[11px] mt-0.5" :class="getTimeTextClass(schedule.timekeeping_record)">
                              {{ formatCheckTime(schedule.timekeeping_record?.check_in_at) }} - {{ formatCheckTime(schedule.timekeeping_record?.check_out_at) }}
                            </div>
                            <div v-if="getOtInfo(schedule.timekeeping_record)" class="text-[10px] mt-0.5" :class="getOtTextClass(schedule.timekeeping_record)">
                              {{ getOtInfo(schedule.timekeeping_record) }}
                            </div>
                            <div v-if="!schedule.timekeeping_record" class="text-[10px] text-gray-400 italic mt-0.5">Chưa chấm công</div>
                          </template>
                        </div>
                      </template>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Legend -->
        <div class="flex items-center gap-6 px-6 py-3 border-t border-gray-200 bg-gray-50 text-xs text-gray-600">
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-blue-500 inline-block"></span> Đúng giờ</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-purple-500 inline-block"></span> Đi muộn / Về sớm</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-500 inline-block"></span> Chấm công thiếu</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-gray-300 inline-block"></span> Chưa chấm công</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> Nghỉ phép</span>
        </div>
      </div>
    </main>

    <!-- ===== Modal chấm công thủ công ===== -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto">
      <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div class="fixed inset-0 bg-black bg-opacity-40 transition-opacity" @click="closeModal"></div>
        <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-lg z-10">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <div>
              <h3 class="text-lg font-bold text-gray-900">Cập nhật chấm công</h3>
              <p class="text-sm text-gray-500 mt-0.5">
                {{ activeSchedule?.employee?.name }} ({{ activeSchedule?.employee?.code }})
              </p>
            </div>
            <button @click="closeModal" class="text-gray-400 hover:text-gray-600 p-1 rounded hover:bg-gray-100 transition">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>

          <!-- Body -->
          <div class="px-6 py-5">
            <!-- Info -->
            <div class="flex items-center gap-4 mb-5 p-3 bg-blue-50 rounded-lg border border-blue-100 text-sm">
              <div>
                <span class="text-gray-600">Ngày:</span>
                <span class="font-semibold ml-1">{{ formatDateVietnamese(activeSchedule?.work_date) }}</span>
              </div>
              <div>
                <span class="text-gray-600">Ca:</span>
                <span class="font-semibold ml-1">{{ activeSchedule?.shift?.name }} ({{ formatShiftTime(activeSchedule?.shift?.start_time) }} - {{ formatShiftTime(activeSchedule?.shift?.end_time) }})</span>
              </div>
            </div>

            <!-- Trạng thái -->
            <div class="mb-5">
              <label class="block text-sm font-semibold text-gray-800 mb-2">Trạng thái điểm danh</label>
              <div class="flex gap-2">
                <label v-for="opt in attendanceTypes" :key="opt.value"
                  class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border cursor-pointer text-sm transition"
                  :class="form.attendance_type === opt.value ? opt.activeClass : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                >
                  <input type="radio" :value="opt.value" v-model="form.attendance_type" class="hidden">
                  <span>{{ opt.icon }}</span>
                  <span>{{ opt.label }}</span>
                </label>
              </div>
            </div>

            <!-- Giờ vào / ra -->
            <div v-show="form.attendance_type === 'work'" class="grid grid-cols-2 gap-4 mb-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Giờ vào</label>
                <input type="time" v-model="form.check_in_time" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Giờ ra</label>
                <input type="time" v-model="form.check_out_time" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
              </div>
            </div>

            <!-- OT -->
            <div v-show="form.attendance_type === 'work'" class="mb-4">
              <label class="block text-sm font-medium text-gray-700 mb-1">Làm thêm (phút)</label>
              <input type="number" min="0" v-model="form.ot_minutes" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500" placeholder="0">
            </div>

            <!-- Ghi chú -->
            <div class="mb-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
              <textarea v-model="form.notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500" placeholder="Ghi chú thêm nếu cần..."></textarea>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">
            <button @click="closeModal" class="px-5 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
              Bỏ qua
            </button>
            <button @click="saveRecord" class="inline-flex items-center px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition disabled:opacity-50" :disabled="isSaving">
              <svg v-if="isSaving" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
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
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { ref, computed, onMounted, reactive, watch } from 'vue'
import axios from 'axios'

const schedules = ref([])
const loading = ref(false)
const currentDate = ref(new Date())
const searchQuery = ref('')
const viewMode = ref('shift')
const periodMode = ref('week')

const attendanceTypes = [
    { value: 'work', label: 'Đi làm', icon: '🟢', activeClass: 'border-blue-500 bg-blue-50 text-blue-700 ring-1 ring-blue-500' },
    { value: 'leave_paid', label: 'Nghỉ phép', icon: '🟡', activeClass: 'border-green-500 bg-green-50 text-green-700 ring-1 ring-green-500' },
    { value: 'leave_unpaid', label: 'Nghỉ không lương', icon: '🔴', activeClass: 'border-red-500 bg-red-50 text-red-700 ring-1 ring-red-500' },
]

// === Helper: format date to YYYY-MM-DD ===
const toDateStr = (d) => {
    const y = d.getFullYear()
    const m = (d.getMonth() + 1).toString().padStart(2, '0')
    const day = d.getDate().toString().padStart(2, '0')
    return `${y}-${m}-${day}`
}

// === Week calculations ===
const currentWeekStart = computed(() => {
    const d = new Date(currentDate.value)
    const day = d.getDay()
    const diff = d.getDate() - day + (day === 0 ? -6 : 1)
    return new Date(d.setDate(diff))
})
const weekStart = computed(() => toDateStr(new Date(currentWeekStart.value)))
const weekEnd = computed(() => {
    const d = new Date(currentWeekStart.value); d.setDate(d.getDate() + 6)
    return toDateStr(d)
})
const weekNumber = computed(() => {
    const d = new Date(currentDate.value)
    const yearStart = new Date(d.getFullYear(),0,1)
    return Math.ceil((((d - yearStart) / 86400000) + yearStart.getDay()+1)/7)
})
const weekMonth = computed(() => new Date(currentWeekStart.value).getMonth() + 1)
const weekYear = computed(() => new Date(currentWeekStart.value).getFullYear())

const weekDays = computed(() => {
    const days = []
    const start = new Date(currentWeekStart.value)
    const names = ['Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy', 'Chủ nhật']
    const shortNames = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']
    for (let i = 0; i < 7; i++) {
        const d = new Date(start); d.setDate(start.getDate() + i)
        days.push({
            date: toDateStr(d),
            dayName: names[i],
            dayNameShort: shortNames[i],
            dayNum: d.getDate().toString().padStart(2, '0'),
            isWeekend: i >= 5
        })
    }
    return days
})

// === Month calculations ===
const currentMonthDisplay = computed(() => {
    const d = new Date(currentDate.value)
    return d.getMonth() + 1
})
const currentYearDisplay = computed(() => new Date(currentDate.value).getFullYear())

const monthStart = computed(() => {
    const d = new Date(currentDate.value)
    return toDateStr(new Date(d.getFullYear(), d.getMonth(), 1))
})
const monthEnd = computed(() => {
    const d = new Date(currentDate.value)
    return toDateStr(new Date(d.getFullYear(), d.getMonth() + 1, 0))
})

const monthDays = computed(() => {
    const days = []
    const d = new Date(currentDate.value)
    const year = d.getFullYear()
    const month = d.getMonth()
    const daysInMonth = new Date(year, month + 1, 0).getDate()
    const shortNames = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7']
    for (let i = 1; i <= daysInMonth; i++) {
        const dt = new Date(year, month, i)
        const dow = dt.getDay()
        days.push({
            date: toDateStr(dt),
            dayName: shortNames[dow],
            dayNameShort: shortNames[dow],
            dayNum: i.toString().padStart(2, '0'),
            isWeekend: dow === 0 || dow === 6
        })
    }
    return days
})

// === Unified display days (week or month) ===
const displayDays = computed(() => periodMode.value === 'month' ? monthDays.value : weekDays.value)

// === Period range for API ===
const periodFrom = computed(() => periodMode.value === 'month' ? monthStart.value : weekStart.value)
const periodTo = computed(() => periodMode.value === 'month' ? monthEnd.value : weekEnd.value)

// === Data fetching ===
const fetchSchedules = async () => {
    loading.value = true
    try {
        const res = await axios.get('/api/employee-schedules', { params: { from: periodFrom.value, to: periodTo.value } })
        if (res.data?.success) schedules.value = res.data.data
    } catch (e) { console.error('Lỗi khi tải dữ liệu:', e) }
    finally { loading.value = false }
}

const changePeriod = (offset) => {
    const d = new Date(currentDate.value)
    if (periodMode.value === 'month') {
        d.setMonth(d.getMonth() + offset)
    } else {
        d.setDate(d.getDate() + offset * 7)
    }
    currentDate.value = d
    fetchSchedules()
}

// Re-fetch when switching period mode
watch(periodMode, () => { fetchSchedules() })

// === Grouping by Shift (KiotViet style) ===
const shiftGroups = computed(() => {
    const map = {}
    schedules.value.forEach(s => {
        const shiftKey = s.shift_id || 'none'
        if (!map[shiftKey]) {
            map[shiftKey] = {
                shiftKey,
                shiftName: s.shift?.name || 'Ca tự do',
                shiftTime: s.shift ? `${s.shift.start_time?.substring(0,5)} - ${s.shift.end_time?.substring(0,5)}` : '',
                schedules: []
            }
        }
        map[shiftKey].schedules.push(s)
    })
    return Object.values(map).sort((a, b) => a.shiftName.localeCompare(b.shiftName))
})

const filteredShiftGroups = computed(() => {
    if (!searchQuery.value.trim()) return shiftGroups.value
    const q = searchQuery.value.toLowerCase()
    return shiftGroups.value.map(g => ({
        ...g,
        schedules: g.schedules.filter(s => {
            const emp = s.employee
            return emp?.name?.toLowerCase().includes(q) || emp?.code?.toLowerCase().includes(q)
        })
    })).filter(g => g.schedules.length > 0)
})

const getShiftDayItems = (shiftGroup, dateStr) => {
    return shiftGroup.schedules
        .filter(s => s.work_date === dateStr)
        .map(s => ({
            schedule: s,
            record: s.timekeeping_record,
            employeeName: s.employee?.name || 'N/A'
        }))
        .sort((a, b) => a.employeeName.localeCompare(b.employeeName))
}

// === Grouping by Employee ===
const employeeGroups = computed(() => {
    const map = {}
    schedules.value.forEach(s => {
        const empId = s.employee_id
        if (!map[empId]) { map[empId] = { employee: s.employee, days: {} } }
        if (!map[empId].days[s.work_date]) map[empId].days[s.work_date] = []
        map[empId].days[s.work_date].push(s)
    })
    return Object.values(map).sort((a,b) => a.employee.name.localeCompare(b.employee.name))
})

const filteredEmployeeGroups = computed(() => {
    if (!searchQuery.value.trim()) return employeeGroups.value
    const q = searchQuery.value.toLowerCase()
    return employeeGroups.value.filter(e =>
        e.employee?.name?.toLowerCase().includes(q) || e.employee?.code?.toLowerCase().includes(q)
    )
})

// === Card Styling ===
const getCardClasses = (record) => {
    if (!record) return 'bg-gray-50 border border-gray-200 text-gray-600'
    const type = record.attendance_type || 'work'
    if (type === 'leave_paid') return 'bg-green-50 border border-green-200 text-green-800'
    if (type === 'leave_unpaid') return 'bg-red-50 border border-red-200 text-red-800'
    if (!record.check_in_at && !record.check_out_at) return 'bg-gray-50 border border-gray-200 text-gray-500'
    if (Boolean(record.check_in_at) !== Boolean(record.check_out_at)) return 'bg-red-50 border border-red-300 text-red-800'
    if (record.late_minutes > 0 || record.early_minutes > 0) return 'bg-purple-50 border border-purple-200 text-purple-800'
    return 'bg-blue-50 border border-blue-200 text-blue-800'
}

const getTimeTextClass = (record) => {
    if (!record) return 'text-gray-400'
    if (record.late_minutes > 0 || record.early_minutes > 0) return 'text-purple-600'
    if (!record.check_in_at || !record.check_out_at) return 'text-red-500'
    return 'text-gray-600'
}

const getOtTextClass = (record) => {
    if (!record) return ''
    if (record.late_minutes > 0 || record.early_minutes > 0) return 'text-purple-600'
    return 'text-blue-600'
}

// === Format helpers ===
const formatCheckTime = (dateTimeStr) => {
    if (!dateTimeStr) return '--'
    const d = new Date(dateTimeStr)
    return `${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}`
}

const formatCheckTimeShort = (dateTimeStr) => {
    if (!dateTimeStr) return '--'
    const d = new Date(dateTimeStr)
    return `${d.getHours()}:${d.getMinutes().toString().padStart(2,'0')}`
}

const formatDateVietnamese = (dateStr) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return `${d.getDate().toString().padStart(2,'0')}/${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getFullYear()}`
}

const formatShiftTime = (timeStr) => timeStr ? timeStr.substring(0, 5) : ''

const getOtInfo = (record) => {
    if (!record) return ''
    const parts = []
    if (record.late_minutes > 0) parts.push(`Đi muộn ${record.late_minutes}p`)
    if (record.early_minutes > 0) parts.push(`Về sớm ${record.early_minutes}p`)
    if (record.ot_minutes > 0) {
        const h = Math.floor(record.ot_minutes / 60)
        const m = record.ot_minutes % 60
        let otText = 'Làm thêm '
        if (record.ot_minutes >= 60) {
            otText += `${h}h ${m > 0 ? m + 'p' : ''}`
        } else {
            otText += `${record.ot_minutes}p`
        }
        parts.push(otText)
    }
    return parts.join(', ')
}

// === Recalculate ===
const isRecalculating = ref(false)
const recalculate = async () => {
    const label = periodMode.value === 'month' ? 'tháng này' : 'tuần này'
    if (!confirm(`Duyệt chấm công sẽ tự động tính toán lại dữ liệu chấm công cho ${label}. Bạn chắc chắn?`)) return
    isRecalculating.value = true
    try {
        await axios.post('/api/timekeeping-records/recalculate', { from: periodFrom.value, to: periodTo.value })
        await fetchSchedules()
        alert('Duyệt chấm công hoàn tất!')
    } catch(e) { alert('Có lỗi xảy ra khi duyệt công!'); console.error(e) }
    finally { isRecalculating.value = false }
}

// === Modal ===
const isModalOpen = ref(false)
const activeSchedule = ref(null)
const isSaving = ref(false)

const form = reactive({
    employee_work_schedule_id: '',
    attendance_type: 'work',
    check_in_time: '',
    check_out_time: '',
    ot_minutes: 0,
    notes: ''
})

const openModal = (schedule) => {
    activeSchedule.value = schedule
    const rec = schedule.timekeeping_record
    form.employee_work_schedule_id = schedule.id
    form.attendance_type = rec?.attendance_type || 'work'
    form.check_in_time = rec?.check_in_at ? formatCheckTime(rec.check_in_at) : ''
    form.check_out_time = rec?.check_out_at ? formatCheckTime(rec.check_out_at) : ''
    form.ot_minutes = rec?.ot_minutes || 0
    form.notes = rec?.notes || ''
    isModalOpen.value = true
}
const closeModal = () => { isModalOpen.value = false; activeSchedule.value = null }

const saveRecord = async () => {
    isSaving.value = true
    try {
        await axios.post('/api/timekeeping-records', form)
        await fetchSchedules()
        closeModal()
    } catch(e) {
        const msg = e.response?.data?.message || e.response?.data?.errors
            ? Object.values(e.response.data.errors).flat().join('\n')
            : 'Không thể lưu thay đổi!'
        alert(msg)
        console.error(e)
    }
    finally { isSaving.value = false }
}

onMounted(() => { fetchSchedules() })
</script>
