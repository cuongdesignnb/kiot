<template>
  <Head title="Lịch làm việc - KiotViet Clone" />
  <AppLayout>
    <div class="h-full flex flex-col bg-gray-50 font-sans">
      <!-- Header -->
      <header class="bg-white border-b border-gray-200 px-6 py-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 class="text-xl font-bold text-gray-800">Lịch làm việc</h1>
          <p class="text-sm text-gray-500 mt-1">Phân ca cho nhân viên theo tuần</p>
        </div>

        <div class="flex items-center space-x-3">
          <button
            @click="changeWeek(-1)"
            class="p-2 bg-white border border-gray-300 rounded hover:bg-gray-50 transition"
          >
            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
          </button>

          <div class="text-sm font-semibold text-gray-700 bg-white border border-gray-300 px-4 py-2 rounded">
            Tuần {{ weekNumber }} ({{ formatDateLabel(weekStart) }} - {{ formatDateLabel(weekEnd) }})
          </div>

          <button
            @click="changeWeek(1)"
            class="p-2 bg-white border border-gray-300 rounded hover:bg-gray-50 transition"
          >
            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
          </button>
        </div>
      </header>

      <!-- Main Content -->
      <main class="flex-1 overflow-auto p-6">
        <div v-if="loading" class="flex justify-center items-center h-64">
          <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        </div>

        <div v-else class="bg-white rounded-lg shadow border border-gray-200">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50 sticky top-0 z-10">
                <tr>
                  <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sticky left-0 bg-gray-50 z-20 min-w-[200px] border-r border-gray-200">
                    Nhân viên
                  </th>
                  <th v-for="day in weekDays" :key="day.date" scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[180px] border-r border-gray-200 last:border-r-0">
                    {{ day.dayName }} <br>
                    <span class="text-gray-400 font-normal">{{ formatDateLabel(day.date) }}</span>
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-if="employees.length === 0">
                  <td :colspan="8" class="px-6 py-10 text-center text-gray-500">
                      Chưa có nhân viên nào trên hệ thống.
                  </td>
                </tr>
                <tr 
                  v-for="employee in employees" 
                  :key="employee.id" 
                  class="hover:bg-gray-50 transition-colors"
                >
                  <!-- Cột NV Cố định -->
                  <td class="px-6 py-4 whitespace-nowrap sticky left-0 bg-white border-r border-gray-200 z-10">
                    <div class="flex items-center justify-between">
                      <div class="flex items-center">
                        <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center rounded-full bg-blue-100 text-blue-600 font-bold">
                            {{ employee.name.charAt(0) }}
                        </div>
                        <div class="ml-4">
                          <div class="text-sm font-medium text-gray-900">{{ employee.name }}</div>
                          <div class="text-xs text-gray-500">{{ employee.code }}</div>
                        </div>
                      </div>
                      <button
                        v-if="getEmployeeScheduleCount(employee.id) > 0"
                        @click.stop="clearEmployeeSchedules(employee)"
                        class="ml-2 text-gray-400 hover:text-red-500 transition p-1 rounded hover:bg-red-50"
                        title="Xóa hết lịch tuần này"
                      >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                      </button>
                    </div>
                  </td>

                  <!-- Các cột Ngày -->
                  <td v-for="day in weekDays" :key="day.date" class="px-4 py-3 align-top border-r border-gray-200 last:border-r-0 hover:bg-gray-100 transition-colors cursor-pointer" @click.self="openModal(employee, day.date)">
                    <div class="flex flex-col gap-2 pointer-events-none">
                      <template v-if="getSchedules(employee.id, day.date).length > 0">
                          <div 
                              v-for="schedule in getSchedules(employee.id, day.date)" 
                              :key="schedule.id"
                              @click.stop="openModal(employee, day.date, schedule)"
                              class="rounded p-2 text-xs border cursor-pointer hover:shadow-md transition bg-blue-50 border-blue-200 text-blue-800 pointer-events-auto"
                          >
                              <div class="font-semibold truncate">{{ schedule.shift ? schedule.shift.name : 'Ca ' + schedule.shift_id }}</div>
                              <div class="mt-1 flex justify-between text-[11px] opacity-80" v-if="schedule.shift">
                                  <span>{{ schedule.shift.start_time.substring(0,5) }} - {{ schedule.shift.end_time.substring(0,5) }}</span>
                              </div>
                          </div>
                      </template>
                      <!-- Add button placeholder -->
                      <div class="text-center w-full mt-1 pointer-events-auto" v-if="getSchedules(employee.id, day.date).length === 0">
                          <button @click.stop="openModal(employee, day.date)" class="text-gray-400 hover:text-blue-600 outline-none">
                              <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                          </button>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </main>

      <!-- ===== Modal Thêm / Sửa lịch làm việc (KiotViet style) ===== -->
      <div v-if="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
          <div class="flex items-center justify-center min-h-screen px-4 py-6">
              <div class="fixed inset-0 bg-black bg-opacity-40 transition-opacity" @click="closeModal"></div>

              <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-lg z-10">
                  <!-- Header -->
                  <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                      <div>
                          <h3 class="text-lg font-bold text-gray-900">
                              {{ form.id ? 'Sửa lịch làm việc' : 'Thêm lịch làm việc' }}
                          </h3>
                          <p class="text-sm text-gray-500 mt-0.5">
                              {{ modalContext.employee?.name }}
                              <span class="mx-1">|</span>
                              {{ getDayOfWeekName(modalContext.date) }}, {{ formatDateVietnamese(modalContext.date) }}
                          </p>
                      </div>
                      <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition p-1 rounded hover:bg-gray-100">
                          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                      </button>
                  </div>

                  <!-- Body -->
                  <div class="px-6 py-5 max-h-[60vh] overflow-y-auto">

                      <!-- Chọn ca làm việc -->
                      <div class="mb-6">
                          <div class="flex items-center justify-between mb-3">
                              <label class="text-sm font-semibold text-gray-800">Chọn ca làm việc</label>
                              <button
                                  v-if="!form.id"
                                  type="button"
                                  @click="showNewShiftForm = !showNewShiftForm"
                                  class="flex items-center gap-1 text-blue-600 hover:text-blue-700 text-sm font-medium transition"
                              >
                                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                  Thêm ca mới
                              </button>
                          </div>

                          <!-- Form tạo ca nhanh -->
                          <div v-if="showNewShiftForm" class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                              <div class="grid grid-cols-3 gap-2 mb-2">
                                  <input v-model="newShift.name" type="text" placeholder="Tên ca" class="col-span-1 text-sm border border-gray-300 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                                  <input v-model="newShift.start_time" type="time" class="col-span-1 text-sm border border-gray-300 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                                  <input v-model="newShift.end_time" type="time" class="col-span-1 text-sm border border-gray-300 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                              </div>
                              <div class="flex justify-end gap-2">
                                  <button @click="showNewShiftForm = false" type="button" class="text-xs text-gray-500 hover:text-gray-700 px-3 py-1">Huỷ</button>
                                  <button @click="createShift" type="button" class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 transition" :disabled="!newShift.name || !newShift.start_time || !newShift.end_time">Tạo ca</button>
                              </div>
                          </div>

                          <!-- Danh sách ca (checkbox hoặc radio tuỳ chế độ) -->
                          <div class="space-y-2">
                              <div v-if="shifts.length === 0" class="text-sm text-gray-400 italic py-2">Chưa có ca làm việc nào. Hãy thêm ca mới.</div>
                              
                              <!-- Chế độ sửa: chỉ chọn 1 ca (radio) -->
                              <template v-if="form.id">
                                  <label
                                      v-for="shift in shifts"
                                      :key="shift.id"
                                      class="flex items-center p-3 border rounded-lg cursor-pointer transition-all"
                                      :class="form.shift_id === shift.id ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'"
                                  >
                                      <input
                                          type="radio"
                                          :value="shift.id"
                                          v-model="form.shift_id"
                                          class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                      >
                                      <div class="ml-3">
                                          <div class="text-sm font-medium text-gray-900">{{ shift.name }}</div>
                                          <div class="text-xs text-gray-500">{{ shift.start_time?.substring(0,5) }} - {{ shift.end_time?.substring(0,5) }}</div>
                                      </div>
                                  </label>
                              </template>

                              <!-- Chế độ thêm mới: chọn nhiều ca (checkbox) -->
                              <template v-else>
                                  <label
                                      v-for="shift in shifts"
                                      :key="shift.id"
                                      class="flex items-center p-3 border rounded-lg cursor-pointer transition-all"
                                      :class="form.selectedShiftIds.includes(shift.id) ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'"
                                  >
                                      <input
                                          type="checkbox"
                                          :value="shift.id"
                                          v-model="form.selectedShiftIds"
                                          class="h-4 w-4 rounded text-blue-600 border-gray-300 focus:ring-blue-500"
                                      >
                                      <div class="ml-3">
                                          <div class="text-sm font-medium text-gray-900">{{ shift.name }}</div>
                                          <div class="text-xs text-gray-500">{{ shift.start_time?.substring(0,5) }} - {{ shift.end_time?.substring(0,5) }}</div>
                                      </div>
                                  </label>
                              </template>
                          </div>
                      </div>

                      <!-- Divider -->
                      <div class="border-t border-gray-200 my-4" v-if="!form.id"></div>

                      <!-- Lặp lại hàng tuần (Toggle) -->
                      <div class="flex items-center justify-between py-3" v-if="!form.id">
                          <div>
                              <div class="text-sm font-semibold text-gray-800">Lặp lại hàng tuần</div>
                              <div class="text-xs text-gray-500 mt-0.5">Lịch làm việc sẽ được tự động lặp lại vào các ngày trong tuần</div>
                          </div>
                          <button
                              type="button"
                              @click="form.options.repeatWeekly = !form.options.repeatWeekly"
                              :class="form.options.repeatWeekly ? 'bg-blue-600' : 'bg-gray-300'"
                              class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none"
                          >
                              <span
                                  :class="form.options.repeatWeekly ? 'translate-x-5' : 'translate-x-0'"
                                  class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"
                              ></span>
                          </button>
                      </div>

                      <!-- Số tuần lặp -->
                      <div v-if="!form.id && form.options.repeatWeekly" class="ml-0 mb-2 pl-0">
                          <label class="text-xs text-gray-600">Số tuần lặp lại:</label>
                          <select v-model="form.options.repeatWeeks" class="ml-2 text-sm border border-gray-300 rounded px-2 py-1 outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                              <option :value="2">2 tuần</option>
                              <option :value="4">4 tuần</option>
                              <option :value="8">8 tuần</option>
                              <option :value="12">12 tuần</option>
                          </select>
                      </div>

                      <!-- Divider -->
                      <div class="border-t border-gray-200 my-4" v-if="!form.id"></div>

                      <!-- Thêm lịch tương tự cho nhân viên khác (Toggle) -->
                      <div class="flex items-center justify-between py-3" v-if="!form.id">
                          <div>
                              <div class="text-sm font-semibold text-gray-800">Thêm lịch tương tự cho nhân viên khác</div>
                              <div class="text-xs text-gray-500 mt-0.5">Lịch làm việc sẽ được áp dụng cho các nhân viên được chọn</div>
                          </div>
                          <button
                              type="button"
                              @click="form.options.applyToOthers = !form.options.applyToOthers"
                              :class="form.options.applyToOthers ? 'bg-blue-600' : 'bg-gray-300'"
                              class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none"
                          >
                              <span
                                  :class="form.options.applyToOthers ? 'translate-x-5' : 'translate-x-0'"
                                  class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5 ml-0.5"
                              ></span>
                          </button>
                      </div>

                      <!-- Tìm kiếm & chọn nhân viên -->
                      <div v-if="!form.id && form.options.applyToOthers" class="mt-2 mb-2">
                          <div class="relative mb-2">
                              <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                              <input
                                  v-model="employeeSearch"
                                  type="text"
                                  placeholder="Tìm kiếm nhân viên..."
                                  class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                              >
                          </div>

                          <!-- Nhân viên đã chọn (tags) -->
                          <div v-if="form.options.otherEmployeeIds.length > 0" class="flex flex-wrap gap-1.5 mb-2">
                              <span
                                  v-for="empId in form.options.otherEmployeeIds"
                                  :key="empId"
                                  class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full"
                              >
                                  {{ getEmployeeName(empId) }}
                                  <button @click="removeOtherEmployee(empId)" class="hover:text-blue-600">
                                      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                                  </button>
                              </span>
                          </div>

                          <!-- Danh sách nhân viên để chọn -->
                          <div class="border border-gray-200 rounded-lg max-h-40 overflow-y-auto">
                              <div v-if="filteredOtherEmployees.length === 0" class="px-3 py-2 text-sm text-gray-400 text-center">Không tìm thấy nhân viên</div>
                              <label
                                  v-for="emp in filteredOtherEmployees"
                                  :key="emp.id"
                                  class="flex items-center px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition"
                              >
                                  <input
                                      type="checkbox"
                                      :value="emp.id"
                                      v-model="form.options.otherEmployeeIds"
                                      class="h-4 w-4 rounded text-blue-600 border-gray-300 focus:ring-blue-500"
                                  >
                                  <div class="ml-3 flex items-center gap-2">
                                      <div class="h-7 w-7 flex items-center justify-center rounded-full bg-gray-100 text-gray-600 text-xs font-bold flex-shrink-0">
                                          {{ emp.name.charAt(0) }}
                                      </div>
                                      <div>
                                          <div class="text-sm text-gray-900">{{ emp.name }}</div>
                                          <div class="text-xs text-gray-500">{{ emp.code }}</div>
                                      </div>
                                  </div>
                              </label>
                          </div>
                      </div>

                  </div>

                  <!-- Footer -->
                  <div class="flex items-center justify-between px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                      <div>
                          <button
                              v-if="form.id"
                              @click="deleteSchedule"
                              type="button"
                              class="inline-flex items-center gap-1 px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-300 rounded-lg hover:bg-red-50 transition"
                          >
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                              Xóa ca
                          </button>
                      </div>
                      <div class="flex items-center gap-3">
                          <button
                              @click="closeModal"
                              type="button"
                              class="px-5 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                          >
                              Bỏ qua
                          </button>
                          <button
                              @click="saveSchedule"
                              type="button"
                              class="inline-flex items-center px-6 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                              :disabled="isSaving || (!form.id && form.selectedShiftIds.length === 0) || (form.id && !form.shift_id)"
                          >
                              <svg v-if="isSaving" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                              Lưu
                          </button>
                      </div>
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
import { ref, computed, onMounted, reactive } from 'vue'
import axios from 'axios'

const props = defineProps({
    employees: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
})

const serverSchedules = ref([])
const loading = ref(false)
const currentDate = ref(new Date())

// Biến cho shift list (có thể thêm mới inline)
const shifts = ref([...props.shifts])
const showNewShiftForm = ref(false)
const newShift = reactive({ name: '', start_time: '', end_time: '' })
const employeeSearch = ref('')

// Tính toán ngày đầu/cuối tuần
const currentWeekStart = computed(() => {
    const d = new Date(currentDate.value)
    const day = d.getDay()
    const diff = d.getDate() - day + (day === 0 ? -6 : 1)
    return new Date(d.setDate(diff))
})

const weekStart = computed(() => {
    const d = new Date(currentWeekStart.value)
    return d.toISOString().split('T')[0]
})

const weekEnd = computed(() => {
    const d = new Date(currentWeekStart.value)
    d.setDate(d.getDate() + 6)
    return d.toISOString().split('T')[0]
})

const weekNumber = computed(() => {
    const d = new Date(currentDate.value)
    const yearStart = new Date(d.getFullYear(),0,1)
    return Math.ceil((((d - yearStart) / 86400000) + yearStart.getDay()+1)/7)
})

const weekDays = computed(() => {
    const days = []
    const start = new Date(currentWeekStart.value)
    const names = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'CN']
    for (let i = 0; i < 7; i++) {
        const d = new Date(start)
        d.setDate(start.getDate() + i)
        days.push({
            date: d.toISOString().split('T')[0],
            dayName: names[i]
        })
    }
    return days
})

const fetchSchedules = async () => {
    loading.value = true
    try {
        const res = await axios.get('/api/employee-schedules', {
            params: {
                from: weekStart.value,
                to: weekEnd.value
            }
        })
        if (res.data?.success) {
            serverSchedules.value = res.data.data
        }
    } catch (e) {
        console.error('Lỗi khi tải lịch làm việc:', e)
    } finally {
        loading.value = false
    }
}

const getSchedules = (employeeId, dateStr) => {
    return serverSchedules.value.filter(s => s.employee_id === employeeId && (s.work_date || '').substring(0, 10) === dateStr)
}

const changeWeek = (offset) => {
    const d = new Date(currentDate.value)
    d.setDate(d.getDate() + offset * 7)
    currentDate.value = d
    fetchSchedules()
}

// Helpers
const formatDateLabel = (dateStr) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}`
}
const formatDateVietnamese = (dateStr) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getFullYear()}`
}
const getDayOfWeekName = (dateStr) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    const dayIndex = d.getDay()
    const names = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7']
    return names[dayIndex]
}

const getEmployeeName = (empId) => {
    const emp = props.employees.find(e => e.id === empId)
    return emp ? emp.name : `NV #${empId}`
}

// Modal Form
const isModalOpen = ref(false)
const modalContext = ref({ employee: null, date: null, schedule: null })
const isSaving = ref(false)

const form = reactive({
    id: null,
    employee_id: '',
    work_date: '',
    slot: 1,
    shift_id: '',            // Dùng khi sửa (chọn 1 ca)
    selectedShiftIds: [],    // Dùng khi thêm mới (chọn nhiều ca)
    options: {
        repeatWeekly: false,
        repeatWeeks: 4,
        applyToOthers: false,
        otherEmployeeIds: []
    }
})

const otherEmployees = computed(() => {
    if (!modalContext.value.employee) return []
    return props.employees.filter(e => e.id !== modalContext.value.employee.id)
})

const filteredOtherEmployees = computed(() => {
    const search = employeeSearch.value.toLowerCase().trim()
    if (!search) return otherEmployees.value
    return otherEmployees.value.filter(e =>
        e.name.toLowerCase().includes(search) || (e.code && e.code.toLowerCase().includes(search))
    )
})

const removeOtherEmployee = (empId) => {
    const idx = form.options.otherEmployeeIds.indexOf(empId)
    if (idx >= 0) form.options.otherEmployeeIds.splice(idx, 1)
}

const openModal = (employee, dateStr, existingSchedule = null) => {
    modalContext.value = { employee, date: dateStr, schedule: existingSchedule }
    employeeSearch.value = ''
    showNewShiftForm.value = false
    newShift.name = ''
    newShift.start_time = ''
    newShift.end_time = ''

    if (existingSchedule) {
        form.id = existingSchedule.id
        form.shift_id = existingSchedule.shift_id
        form.slot = existingSchedule.slot
        form.selectedShiftIds = []
    } else {
        form.id = null
        form.shift_id = ''
        form.selectedShiftIds = []
        const usedSlots = getSchedules(employee.id, dateStr).map(s => s.slot)
        form.slot = usedSlots.length > 0 ? Math.max(...usedSlots) + 1 : 1
        form.options.repeatWeekly = false
        form.options.repeatWeeks = 4
        form.options.applyToOthers = false
        form.options.otherEmployeeIds = []
    }

    form.employee_id = employee.id
    form.work_date = dateStr
    isModalOpen.value = true
}

const closeModal = () => {
    isModalOpen.value = false
    modalContext.value = { employee: null, date: null, schedule: null }
}

// Tạo ca mới nhanh
const createShift = async () => {
    if (!newShift.name || !newShift.start_time || !newShift.end_time) return
    try {
        const res = await axios.post('/api/shifts', {
            name: newShift.name,
            start_time: newShift.start_time,
            end_time: newShift.end_time,
            status: 'active',
        })
        if (res.data?.data || res.data?.id) {
            const created = res.data.data || res.data
            shifts.value.push(created)
            // Tự động chọn ca vừa tạo
            if (!form.id) {
                form.selectedShiftIds.push(created.id)
            } else {
                form.shift_id = created.id
            }
        }
        showNewShiftForm.value = false
        newShift.name = ''
        newShift.start_time = ''
        newShift.end_time = ''
    } catch (e) {
        alert('Không thể tạo ca làm việc!')
        console.error(e)
    }
}

const saveSchedule = async () => {
    // Validate
    if (form.id && !form.shift_id) return
    if (!form.id && form.selectedShiftIds.length === 0) return

    isSaving.value = true
    try {
        const employeeIds = [form.employee_id]
        if (!form.id && form.options.applyToOthers) {
            employeeIds.push(...form.options.otherEmployeeIds)
        }

        const dates = [form.work_date]
        if (!form.id && form.options.repeatWeekly) {
            const weeks = form.options.repeatWeeks || 4
            for (let i = 1; i <= weeks; i++) {
                const d = new Date(form.work_date)
                d.setDate(d.getDate() + i * 7)
                dates.push(d.toISOString().split('T')[0])
            }
        }

        const shiftIds = form.id ? [form.shift_id] : form.selectedShiftIds

        const promises = []
        for (const empId of employeeIds) {
            for (const d of dates) {
                let slotCounter = form.id ? form.slot : 1
                if (!form.id) {
                    const existing = serverSchedules.value.filter(s => s.employee_id === empId && (s.work_date || '').substring(0, 10) === d)
                    slotCounter = existing.length > 0 ? Math.max(...existing.map(s => s.slot || 0)) + 1 : 1
                }

                for (const shiftId of shiftIds) {
                    promises.push(axios.post('/api/employee-schedules', {
                        employee_id: empId,
                        work_date: d,
                        slot: slotCounter,
                        shift_id: shiftId,
                    }))
                    slotCounter++
                }
            }
        }

        const results = await Promise.allSettled(promises)
        const failed = results.filter(r => r.status === 'rejected')
        if (failed.length > 0) {
            const msg = failed[0].reason?.response?.data?.message || failed[0].reason?.message || 'Lỗi không xác định'
            alert(`Lỗi lưu ca: ${msg} (${failed.length}/${results.length} thất bại)`)
            console.error('Failed requests:', failed)
        }
        await fetchSchedules()
        closeModal()
    } catch(e) {
        const msg = e.response?.data?.message || e.message || 'Lỗi không xác định'
        alert(`Không thể lưu ca làm việc: ${msg}`)
        console.error(e)
    } finally {
        isSaving.value = false
    }
}

const getEmployeeScheduleCount = (employeeId) => {
    return serverSchedules.value.filter(s => s.employee_id === employeeId).length
}

const clearEmployeeSchedules = async (employee) => {
    if (!confirm(`Xóa hết lịch làm việc tuần này của ${employee.name}?`)) return
    try {
        await axios.post('/api/employee-schedules/bulk-destroy', {
            employee_id: employee.id,
            from: weekStart.value,
            to: weekEnd.value
        })
        await fetchSchedules()
    } catch (e) {
        alert('Lỗi khi xóa lịch: ' + (e.response?.data?.message || e.message))
    }
}

const deleteSchedule = async () => {
    if (!form.id || !confirm('Bạn có chắc chắn muốn xóa ca làm việc này?')) return
    isSaving.value = true
    try {
        await axios.delete(`/api/employee-schedules/${form.id}`)
        await fetchSchedules()
        closeModal()
    } catch(e) {
        alert("Có lỗi xảy ra khi xoá ca.")
    } finally {
        isSaving.value = false
    }
}

onMounted(() => {
    fetchSchedules()
})
</script>
