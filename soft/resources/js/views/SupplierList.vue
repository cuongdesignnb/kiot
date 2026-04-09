<template>
  <div class="bg-white">
    <!-- Header -->
    <div class="p-6 border-b">
      <h1 class="text-2xl font-semibold text-gray-900">Nhà cung cấp</h1>
    </div>

    <!-- Action Buttons -->
    <div class="p-6 border-b bg-gray-50">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
          <button 
            class="flex items-center space-x-2 text-gray-600 hover:text-gray-800"
            @click="exportSuppliers"
            :disabled="loading"
          >
            <span>⬇</span>
            <span>Xuất file</span>
          </button>
          <button 
            class="flex items-center space-x-2 text-gray-600 hover:text-gray-800"
            @click="showImportModal = true"
          >
            <span>⬆</span>
            <span>Nhập file</span>
          </button>
          <button 
            v-if="selectedIds.length > 0"
            class="flex items-center space-x-2 text-red-600 hover:text-red-800"
            @click="bulkDelete"
            :disabled="deleting"
          >
            <span>🗑️</span>
            <span>Xóa đã chọn ({{ selectedIds.length }})</span>
          </button>
        </div>
        <button 
          class="bg-blue-500 text-white px-4 py-2 rounded flex items-center space-x-2 hover:bg-blue-600"
          @click="createSupplier"
        >
          <span>+</span>
          <span>Thêm nhà cung cấp</span>
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="p-6 border-b">
      <div class="mb-4">
        <button class="text-blue-500 border-b-2 border-blue-500 pb-2">
          Tất cả nhà cung cấp ({{ pagination.total || 0 }})
        </button>
      </div>
      
      <div class="grid grid-cols-12 gap-4 items-center">
        <!-- Search -->
        <div class="col-span-4">
          <div class="relative">
            <input
              type="text"
              placeholder="Tìm kiếm theo mã, tên, SĐT..."
              class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md"
              v-model="searchQuery"
              @input="debouncedSearch"
            />
            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">🔍</span>
          </div>
        </div>
        
        <!-- Group Filter -->
        <div class="col-span-2">
          <select 
            class="w-full px-3 py-2 border border-gray-300 rounded-md"
            v-model="filters.group_id"
            @change="applyFilters"
          >
            <option value="">Tất cả nhóm</option>
            <option v-for="group in groups" :key="group.value" :value="group.value">
              {{ group.label }}
            </option>
          </select>
        </div>
        
        <!-- Status Filter -->
        <div class="col-span-2">
          <select 
            class="w-full px-3 py-2 border border-gray-300 rounded-md"
            v-model="filters.status"
            @change="applyFilters"
          >
            <option value="">Tất cả trạng thái</option>
            <option value="active">Đang giao dịch</option>
            <option value="inactive">Tạm ngưng</option>
            <option value="suspended">Đình chỉ</option>
          </select>
        </div>
        
        <!-- Sort -->
        <div class="col-span-2">
          <select 
            class="w-full px-3 py-2 border border-gray-300 rounded-md"
            v-model="filters.sort_field"
            @change="applyFilters"
          >
            <option value="created_at">Ngày tạo</option>
            <option value="name">Tên nhà cung cấp</option>
            <option value="total_debt">Công nợ</option>
          </select>
        </div>
        
        <!-- Reset -->
        <div class="col-span-2">
          <button 
            class="w-full px-3 py-2 border border-gray-300 rounded-md text-gray-600 hover:bg-gray-50"
            @click="resetFilters"
          >
            🔄 Reset bộ lọc
          </button>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading && suppliers.length === 0" class="flex justify-center items-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
      <span class="ml-2 text-gray-600">Đang tải dữ liệu...</span>
    </div>

    <!-- Table -->
    <div v-else class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50">
          <tr>
            <th class="w-12 p-4">
              <input 
                type="checkbox" 
                class="rounded"
                :checked="isAllSelected"
                @change="toggleSelectAll"
              />
            </th>
            <th class="text-left p-4 text-sm font-medium text-gray-600 cursor-pointer" @click="sortBy('code')">
              Mã nhà cung cấp
              <span class="ml-1">↕</span>
            </th>
            <th class="text-left p-4 text-sm font-medium text-gray-600 cursor-pointer" @click="sortBy('name')">
              Tên nhà cung cấp
              <span class="ml-1">↕</span>
            </th>
            <th class="text-left p-4 text-sm font-medium text-gray-600">Nhóm nhà cung cấp</th>
            <th class="text-left p-4 text-sm font-medium text-gray-600">Số điện thoại</th>
            <th class="text-left p-4 text-sm font-medium text-gray-600">Trạng thái</th>
            <th class="text-left p-4 text-sm font-medium text-gray-600 cursor-pointer" @click="sortBy('total_debt')">
              Nợ hiện tại
              <span class="ml-1">↕</span>
            </th>
            <th class="text-left p-4 text-sm font-medium text-gray-600">Thao tác</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr 
            v-for="supplier in suppliers" 
            :key="supplier.id"
            class="hover:bg-gray-50"
          >
            <td class="p-4">
              <input 
                type="checkbox" 
                class="rounded" 
                :checked="selectedIds.includes(supplier.id)"
                @change="toggleSelection(supplier.id)"
              />
            </td>
            <td class="p-4">
              <div class="text-blue-500 hover:text-blue-700 font-medium cursor-pointer" @click="viewSupplier(supplier)">
                {{ supplier.code }}
              </div>
            </td>
            <td class="p-4 font-medium">
              {{ supplier.name }}
            </td>
            <td class="p-4">
              <span v-if="supplier.group" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                {{ supplier.group.name }}
              </span>
              <span v-else class="text-gray-400">-</span>
            </td>
            <td class="p-4 text-sm text-gray-600">
              {{ supplier.formatted_phone || supplier.phone || '-' }}
            </td>
            <td class="p-4">
              <span 
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                :class="getStatusClass(supplier.status)"
              >
                {{ supplier.status_text }}
              </span>
            </td>
            <td class="p-4">
              <span 
                class="font-medium"
                :class="Math.abs(supplier.total_debt) > 0 ? 'text-red-600' : 'text-gray-400'"
              >
                {{ formatCurrency(Math.abs(supplier.total_debt)) }}
              </span>
              <div v-if="supplier.linked_customer_id && supplier.partner_debt_summary" class="text-xs text-indigo-600 mt-0.5">
                🔗 ròng: {{ formatCurrency(Math.abs(supplier.partner_debt_summary.net_debt)) }}
              </div>
            </td>
            <td class="p-4">
              <div class="flex items-center space-x-2">
                <button
                  @click="viewSupplier(supplier)"
                  class="text-blue-600 hover:text-blue-800 text-sm"
                  title="Xem chi tiết"
                >
                  👁️
                </button>
                <button
                  @click="editSupplier(supplier)"
                  class="text-blue-600 hover:text-blue-800 text-sm"
                  title="Sửa"
                >
                  ✏️
                </button>
                <button
                  @click="deleteSupplier(supplier.id)"
                  class="text-red-600 hover:text-red-800 text-sm"
                  title="Xóa"
                >
                  🗑️
                </button>
              </div>
            </td>
          </tr>
          
          <!-- Empty State -->
          <tr v-if="suppliers.length === 0 && !loading">
            <td colspan="8" class="text-center py-12 text-gray-500">
              <div class="text-4xl mb-4">🏢</div>
              <div>Không có nhà cung cấp nào</div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="pagination.total > 0" class="px-6 py-4 border-t flex justify-between items-center">
      <div class="text-sm text-gray-700">
        Hiển thị {{ pagination.from }}-{{ pagination.to }} của {{ pagination.total }} nhà cung cấp
      </div>
      <div class="flex space-x-2">
        <button 
          class="px-3 py-1 border rounded text-sm"
          :disabled="pagination.current_page <= 1"
          @click="changePage(pagination.current_page - 1)"
        >
          Trước
        </button>
        
        <template v-for="page in visiblePages" :key="page">
          <button 
            class="px-3 py-1 border rounded text-sm"
            :class="page === pagination.current_page ? 'bg-blue-500 text-white' : 'bg-white'"
            @click="changePage(page)"
          >
            {{ page }}
          </button>
        </template>
        
        <button 
          class="px-3 py-1 border rounded text-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="changePage(pagination.current_page + 1)"
        >
          Tiếp
        </button>
      </div>
    </div>

    <!-- Supplier Detail Modal -->
    <SupplierDetail 
      v-if="showDetail"
      :supplier="selectedSupplier"
      :supplier-id="selectedSupplier?.id"
      @close="closeDetail"
      @edit="editFromDetail"
      @delete="deleteSupplier"
    />

    <!-- Supplier Form Modal -->
    <SupplierForm
      v-if="showForm"
      :supplier="editingSupplier"
      @close="closeForm"
      @save="saveSupplier"
    />

    <!-- Import Modal -->
    <div v-if="showImportModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900">Nhập file nhà cung cấp</h3>
          <button @click="closeImportModal" class="text-gray-400 hover:text-gray-600">×</button>
        </div>

        <div class="space-y-4">
          <div class="text-center">
            <button @click="downloadTemplate" class="text-blue-500 hover:text-blue-700 underline text-sm">
              📥 Tải file mẫu (.csv)
            </button>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Chọn file CSV</label>
            <input type="file" accept=".csv" @change="handleFileChange" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" />
          </div>
        </div>

        <div class="flex justify-end space-x-3 mt-6">
          <button @click="closeImportModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">Hủy</button>
          <button @click="importSuppliers" :disabled="!importFile || importing" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50">
            <span v-if="importing">⏳ Đang nhập...</span>
            <span v-else>📤 Nhập file</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Notification -->
    <div v-if="notification.show" class="fixed top-4 right-4 z-50">
      <div 
        class="p-4 rounded-lg shadow-lg max-w-sm"
        :class="{
          'bg-green-100 border border-green-400 text-green-700': notification.type === 'success',
          'bg-red-100 border border-red-400 text-red-700': notification.type === 'error'
        }"
      >
        <div class="flex items-center">
          <span class="mr-2">{{ notification.type === 'success' ? '✅' : '❌' }}</span>
          <span>{{ notification.message }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import SupplierDetail from '../components/SupplierDetail.vue'
import SupplierForm from '../components/SupplierForm.vue'
import supplierApi from '../api/supplierApi.js'
import axios from 'axios'

export default {
  name: 'SupplierList',
  components: {
    SupplierDetail,
    SupplierForm
  },
  setup() {
    // Reactive data
    const loading = ref(false)
    const deleting = ref(false)
    const importing = ref(false)
    const suppliers = ref([])
    const groups = ref([])
    const selectedIds = ref([])
    const searchQuery = ref('')
    const showDetail = ref(false)
    const showForm = ref(false)
    const showImportModal = ref(false)
    const selectedSupplier = ref(null)
    const editingSupplier = ref(null)
    const importFile = ref(null)

    // Notification
    const notification = ref({
      show: false,
      type: 'success',
      message: ''
    })

    // Filters
    const filters = ref({
      group_id: '',
      status: '',
      sort_field: 'created_at',
      sort_direction: 'desc'
    })

    // Pagination
    const pagination = ref({
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 0,
      from: 0,
      to: 0
    })

    // Computed
    const isAllSelected = computed(() => {
      return suppliers.value.length > 0 && 
             selectedIds.value.length === suppliers.value.length
    })

    const visiblePages = computed(() => {
      const current = pagination.value.current_page
      const last = pagination.value.last_page
      const pages = []
      
      for (let i = Math.max(1, current - 2); i <= Math.min(last, current + 2); i++) {
        pages.push(i)
      }
      
      return pages
    })

    // Notification helper
    const showNotification = (message, type = 'success') => {
      notification.value = {
        show: true,
        type,
        message
      }
      
      setTimeout(() => {
        notification.value.show = false
      }, 3000)
    }

    // Methods
    const fetchSuppliers = async () => {
      loading.value = true
      
      try {
        const params = {
          ...filters.value,
          search: searchQuery.value,
          page: pagination.value.current_page,
          per_page: pagination.value.per_page
        }

        const response = await supplierApi.getSuppliers(params)

        if (response.data.success) {
          suppliers.value = response.data.data || []
          pagination.value = response.data.pagination || {}
        }
      } catch (error) {
        console.error('Error fetching suppliers:', error)
        showNotification('Lỗi khi tải dữ liệu', 'error')
      } finally {
        loading.value = false
      }
    }

    const fetchGroups = async () => {
      try {
        const response = await supplierApi.getSupplierGroups()
        if (response.data.success) {
          groups.value = response.data.data || []
        }
      } catch (error) {
        console.error('Error fetching groups:', error)
      }
    }

    const applyFilters = async () => {
      pagination.value.current_page = 1
      await fetchSuppliers()
    }

    const debouncedSearch = debounce(() => {
      applyFilters()
    }, 300)

    const resetFilters = () => {
      searchQuery.value = ''
      filters.value = {
        group_id: '',
        status: '',
        sort_field: 'created_at',
        sort_direction: 'desc'
      }
      applyFilters()
    }

    const sortBy = (field) => {
      if (filters.value.sort_field === field) {
        filters.value.sort_direction = filters.value.sort_direction === 'asc' ? 'desc' : 'asc'
      } else {
        filters.value.sort_field = field
        filters.value.sort_direction = 'desc'
      }
      applyFilters()
    }

    const changePage = (page) => {
      pagination.value.current_page = page
      fetchSuppliers()
    }

    const toggleSelection = (id) => {
      const index = selectedIds.value.indexOf(id)
      if (index > -1) {
        selectedIds.value.splice(index, 1)
      } else {
        selectedIds.value.push(id)
      }
    }

    const toggleSelectAll = () => {
      if (isAllSelected.value) {
        selectedIds.value = []
      } else {
        selectedIds.value = suppliers.value.map(s => s.id)
      }
    }

    const createSupplier = () => {
      editingSupplier.value = null
      showForm.value = true
    }

    const viewSupplier = (supplier) => {
      selectedSupplier.value = supplier
      showDetail.value = true
    }

    const editSupplier = (supplier) => {
      editingSupplier.value = supplier
      showForm.value = true
    }

    const editFromDetail = (supplier) => {
      editingSupplier.value = supplier
      showDetail.value = false
      showForm.value = true
    }

    const closeDetail = () => {
      showDetail.value = false
      selectedSupplier.value = null
    }

    const closeForm = () => {
      showForm.value = false
      editingSupplier.value = null
    }

    const saveSupplier = async (data) => {
      try {
        if (editingSupplier.value?.id) {
          await supplierApi.updateSupplier(editingSupplier.value.id, data)
          showNotification('Cập nhật nhà cung cấp thành công')
        } else {
          await supplierApi.createSupplier(data)
          showNotification('Tạo nhà cung cấp thành công')
        }
        
        await fetchSuppliers()
        closeForm()
        closeDetail()
      } catch (error) {
        console.error('Error saving supplier:', error)
        showNotification(error.message || 'Lỗi khi lưu nhà cung cấp', 'error')
      }
    }

    const deleteSupplier = async (id) => {
      if (confirm('Bạn có chắc muốn xóa nhà cung cấp này?')) {
        try {
          await supplierApi.deleteSupplier(id)
          showNotification('Xóa nhà cung cấp thành công')
          await fetchSuppliers()
          closeDetail()
        } catch (error) {
          console.error('Error deleting supplier:', error)
          showNotification(error.message || 'Lỗi khi xóa nhà cung cấp', 'error')
        }
      }
    }

    const bulkDelete = async () => {
      if (confirm(`Bạn có chắc muốn xóa ${selectedIds.value.length} nhà cung cấp đã chọn?`)) {
        deleting.value = true
        try {
          await supplierApi.bulkDelete(selectedIds.value)
          showNotification(`Đã xóa ${selectedIds.value.length} nhà cung cấp`)
          selectedIds.value = []
          await fetchSuppliers()
        } catch (error) {
          console.error('Error bulk deleting:', error)
          showNotification(error.message || 'Lỗi khi xóa hàng loạt', 'error')
        } finally {
          deleting.value = false
        }
      }
    }

    const exportSuppliers = async () => {
      try {
        loading.value = true
        showNotification('Đang xuất file...', 'info')
        
        const exportParams = {
          search: searchQuery.value,
          group_id: filters.value.group_id,
          status: filters.value.status
        }

        if (selectedIds.value.length > 0) {
          exportParams.selected_ids = selectedIds.value
        }

        const response = await axios({
          method: 'GET',
          url: '/api/suppliers/export',
          params: exportParams,
          responseType: 'blob'
        })
        
        const blob = new Blob([response.data], { type: 'text/csv; charset=utf-8' })
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `suppliers_${Date.now()}.csv`
        
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)
        
        showNotification('Xuất file thành công!', 'success')
        
      } catch (error) {
        console.error('Export error:', error)
        showNotification('Lỗi khi xuất file', 'error')
      } finally {
        loading.value = false
      }
    }

    const handleFileChange = (event) => {
      importFile.value = event.target.files[0]
    }

    const importSuppliers = async () => {
      if (!importFile.value) {
        showNotification('Vui lòng chọn file để nhập', 'error')
        return
      }

      try {
        importing.value = true
        showNotification('Đang nhập dữ liệu...', 'info')

        const response = await supplierApi.importSuppliers(importFile.value)

        if (response.data.success) {
          showNotification(response.data.message, 'success')
          await fetchSuppliers()
          closeImportModal()
        }
      } catch (error) {
        console.error('Import error:', error)
        showNotification('Lỗi khi nhập file', 'error')
      } finally {
        importing.value = false
      }
    }

    const downloadTemplate = async () => {
      try {
        const response = await axios({
          method: 'GET',
          url: '/api/suppliers/template',
          responseType: 'blob'
        })

        const blob = new Blob([response.data], { type: 'text/csv; charset=utf-8' })
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = 'suppliers_import_template.csv'
        
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)

        showNotification('Tải template thành công!', 'success')
      } catch (error) {
        console.error('Download template error:', error)
        showNotification('Lỗi khi tải template', 'error')
      }
    }

    const closeImportModal = () => {
      showImportModal.value = false
      importFile.value = null
    }

    const formatCurrency = (amount) => {
      return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND'
      }).format(amount || 0)
    }

    const formatDate = (dateString) => {
      return new Date(dateString).toLocaleDateString('vi-VN')
    }

    const getStatusClass = (status) => {
      const classes = {
        'active': 'bg-green-100 text-green-800',
        'inactive': 'bg-gray-100 text-gray-800',
        'suspended': 'bg-red-100 text-red-800'
      }
      return classes[status] || 'bg-gray-100 text-gray-800'
    }

    // Debounce helper
    function debounce(func, wait) {
      let timeout
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout)
          func(...args)
        }
        clearTimeout(timeout)
        timeout = setTimeout(later, wait)
      }
    }

    // Lifecycle
    onMounted(async () => {
      await Promise.all([
        fetchSuppliers(),
        fetchGroups()
      ])
    })

    return {
      loading,
      deleting,
      importing,
      suppliers,
      groups,
      selectedIds,
      searchQuery,
      showDetail,
      showForm,
      showImportModal,
      selectedSupplier,
      editingSupplier,
      importFile,
      notification,
      filters,
      pagination,
      isAllSelected,
      visiblePages,
      fetchSuppliers,
      applyFilters,
      debouncedSearch,
      resetFilters,
      sortBy,
      changePage,
      toggleSelection,
      toggleSelectAll,
      createSupplier,
      viewSupplier,
      editSupplier,
      editFromDetail,
      closeDetail,
      closeForm,
      saveSupplier,
      deleteSupplier,
      bulkDelete,
      exportSuppliers,
      handleFileChange,
      importSuppliers,
      downloadTemplate,
      closeImportModal,
      formatCurrency,
      formatDate,
      getStatusClass
    }
  }
}
</script>