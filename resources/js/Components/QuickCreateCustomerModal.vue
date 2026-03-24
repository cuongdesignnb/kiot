<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    show: { type: Boolean, default: false },
    initialName: { type: String, default: '' },
    apiUrl: { type: String, default: '/api/pos/customers' },
    entityLabel: { type: String, default: 'khách hàng' },
    isSupplier: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'created']);

const creating = ref(false);

const form = ref({
    name: '',
    code: '',
    phone: '',
    phone2: '',
    birthday: '',
    gender: 'none',
    email: '',
    facebook: '',
    address: '',
    city: '',
    district: '',
    ward: '',
    customer_group: '',
    note: '',
    type: 'individual',
    invoice_name: '',
    id_card: '',
    passport: '',
    tax_code: '',
    invoice_address: '',
    invoice_city: '',
    invoice_district: '',
    invoice_ward: '',
    invoice_email: '',
    invoice_phone: '',
    bank_name: '',
    bank_account: '',
    is_supplier: false,
    is_customer: true,
});

const resetForm = () => {
    Object.assign(form.value, {
        name: '', code: '', phone: '', phone2: '', birthday: '', gender: 'none',
        email: '', facebook: '', address: '', city: '', district: '', ward: '',
        customer_group: '', note: '', type: 'individual', invoice_name: '',
        id_card: '', passport: '', tax_code: '', invoice_address: '', invoice_city: '',
        invoice_district: '', invoice_ward: '', invoice_email: '', invoice_phone: '',
        bank_name: '', bank_account: '', is_supplier: props.isSupplier, is_customer: !props.isSupplier,
    });
};

watch(() => props.show, (val) => {
    if (val) {
        resetForm();
        form.value.name = props.initialName || '';
        form.value.is_supplier = props.isSupplier;
        form.value.is_customer = !props.isSupplier;
    }
});

const submit = async () => {
    if (!form.value.name.trim()) return;
    creating.value = true;
    try {
        const res = await axios.post(props.apiUrl, form.value);
        emit('created', res.data.customer || res.data.supplier || res.data);
        emit('close');
    } catch (e) {
        alert(e.response?.data?.message || `Lỗi tạo ${props.entityLabel}.`);
    } finally {
        creating.value = false;
    }
};

const close = () => emit('close');
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 pt-10 pb-10 font-sans" @click.self="close">
        <div class="bg-white rounded shadow-xl w-full max-w-4xl max-h-full overflow-hidden flex flex-col relative text-[13px] text-gray-800">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-xl font-bold text-gray-800">
                    Tạo {{ entityLabel }}
                </h2>
                <button @click="close" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-6 custom-scrollbar text-[13.5px]">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Basic Info -->
                    <div class="flex gap-8 items-start pb-4 border-b border-gray-100">
                        <div class="flex-1 grid grid-cols-2 gap-x-6 gap-y-4">
                            <!-- Row 1 -->
                            <div>
                                <label class="block font-semibold mb-1">Tên {{ entityLabel }} <span class="text-red-500">*</span></label>
                                <input v-model="form.name" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none" placeholder="Bắt buộc" required autofocus />
                            </div>
                            <div>
                                <label class="block font-semibold mb-1">Mã {{ entityLabel }}</label>
                                <input v-model="form.code" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none" placeholder="Tự động" />
                            </div>

                            <!-- Row 2: Phone -->
                            <div class="flex gap-2">
                                <div class="w-1/2">
                                    <label class="block font-semibold mb-1">Điện thoại</label>
                                    <input v-model="form.phone" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div class="w-1/2">
                                    <label class="block font-semibold mb-1">Điện thoại 2</label>
                                    <input v-model="form.phone2" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <div class="w-1/2">
                                    <label class="block font-semibold mb-1">Sinh nhật</label>
                                    <input v-model="form.birthday" type="date" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div class="w-1/2">
                                    <label class="block font-semibold mb-1">Giới tính</label>
                                    <select v-model="form.gender" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none">
                                        <option value="none">Chọn giới tính</option>
                                        <option value="male">Nam</option>
                                        <option value="female">Nữ</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Row 3: Email / Facebook -->
                            <div>
                                <label class="block font-semibold mb-1">Email</label>
                                <input v-model="form.email" type="email" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="email@gmail.com" />
                            </div>
                            <div>
                                <label class="block font-semibold mb-1">Facebook</label>
                                <input v-model="form.facebook" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="facebook.com/username" />
                            </div>
                        </div>
                    </div>

                    <!-- Accordion 1: Địa chỉ -->
                    <div class="border border-gray-200 rounded">
                        <div class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer">
                            <h3 class="font-bold text-gray-800">Địa chỉ</h3>
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path></svg>
                        </div>
                        <div class="p-4 grid grid-cols-2 gap-x-6 gap-y-4">
                            <div class="col-span-2">
                                <label class="block font-semibold mb-1">Địa chỉ</label>
                                <input v-model="form.address" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Nhập địa chỉ" />
                            </div>
                            <div>
                                <label class="block font-semibold mb-1">Khu vực</label>
                                <input v-model="form.city" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Chọn Tỉnh/Thành phố" />
                            </div>
                            <div>
                                <label class="block font-semibold mb-1">Phường/Xã</label>
                                <input v-model="form.ward" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Chọn Phường/Xã" />
                            </div>
                        </div>
                    </div>

                    <!-- Accordion 2: Nhóm, ghi chú -->
                    <div class="border border-gray-200 rounded">
                        <div class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer">
                            <h3 class="font-bold text-gray-800">Nhóm {{ entityLabel }}, ghi chú</h3>
                        </div>
                        <div class="p-4 space-y-4">
                            <div>
                                <label class="block font-semibold mb-1">Nhóm {{ entityLabel }}</label>
                                <input v-model="form.customer_group" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Chọn nhóm" />
                            </div>
                            <div>
                                <label class="block font-semibold mb-1">Ghi chú</label>
                                <textarea v-model="form.note" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none resize-none h-16" placeholder="Nhập ghi chú"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Accordion 3: Thông tin xuất hóa đơn -->
                    <div class="border border-gray-200 rounded">
                        <div class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer">
                            <h3 class="font-bold text-gray-800">Thông tin xuất hóa đơn</h3>
                        </div>
                        <div class="p-4">
                            <div class="flex items-center gap-6 mb-4">
                                <label class="font-semibold text-gray-800">Loại</label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="form.type" value="individual" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                    Cá nhân
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="form.type" value="company" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                    Tổ chức/ Hộ kinh doanh
                                </label>
                            </div>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                                <div>
                                    <label class="block font-semibold mb-1">Tên người mua</label>
                                    <input v-model="form.invoice_name" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Nhập tên người mua" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Mã số thuế</label>
                                    <input v-model="form.tax_code" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Nhập mã số thuế" />
                                </div>
                                <div class="col-span-2">
                                    <label class="block font-semibold mb-1">Địa chỉ</label>
                                    <input v-model="form.invoice_address" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" placeholder="Nhập địa chỉ" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Tỉnh/Thành phố</label>
                                    <input v-model="form.invoice_city" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Phường/Xã</label>
                                    <input v-model="form.invoice_ward" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Số CCCD/CMND</label>
                                    <input v-model="form.id_card" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Số hộ chiếu</label>
                                    <input v-model="form.passport" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Email</label>
                                    <input v-model="form.invoice_email" type="email" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1">Số điện thoại</label>
                                    <input v-model="form.invoice_phone" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                </div>
                                <div class="flex gap-2 col-span-2">
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1">Ngân hàng</label>
                                        <select v-model="form.bank_name" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none">
                                            <option value="">Chọn ngân hàng</option>
                                            <option value="vcb">Vietcombank</option>
                                            <option value="tcb">Techcombank</option>
                                        </select>
                                    </div>
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1">Số tài khoản ngân hàng</label>
                                        <input v-model="form.bank_account" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Switch Supplier -->
                    <div v-if="!isSupplier" class="bg-gray-50 border border-gray-200 rounded px-4 py-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-[14px] text-gray-800">Khách hàng là nhà cung cấp</h3>
                            <p class="text-[12px] text-gray-500 mt-0.5">Công nợ của khách hàng và nhà cung cấp sẽ được gộp với nhau</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" v-model="form.is_supplier" class="sr-only peer" />
                            <div class="w-11 h-6 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                    </div>

                    <!-- Switch Customer (for supplier form) -->
                    <div v-if="isSupplier" class="bg-gray-50 border border-gray-200 rounded px-4 py-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-[14px] text-gray-800">Nhà cung cấp đồng thời là khách hàng</h3>
                            <p class="text-[12px] text-gray-500 mt-0.5">Công nợ của nhà cung cấp và khách hàng sẽ được gộp với nhau</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" v-model="form.is_customer" class="sr-only peer" />
                            <div class="w-11 h-6 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                    </div>
                </form>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end gap-3 rounded-b">
                <button @click="close" class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm">
                    Bỏ qua
                </button>
                <button @click="submit" :disabled="creating || !form.name.trim()" class="px-8 py-2 border border-transparent rounded text-white bg-blue-600 font-bold hover:bg-blue-700 transition shadow-sm" :class="{ 'opacity-50 cursor-not-allowed': creating || !form.name.trim() }">
                    {{ creating ? 'Đang tạo...' : 'Lưu' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #d1d5db; border-radius: 10px; }
</style>
