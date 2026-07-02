<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import axios from "axios";

const open = ref(false);
const unreadCount = ref(0);
const notifications = ref([]);
const loading = ref(false);

let pollTimer = null;

const fetchCount = async () => {
    try {
        const res = await axios.get("/api/notifications/unread-count");
        unreadCount.value = res.data.count || 0;
    } catch (e) { /* silent */ }
};

const fetchList = async () => {
    loading.value = true;
    try {
        const res = await axios.get("/api/notifications", { params: { per_page: 20 } });
        notifications.value = res.data.data || [];
    } catch (e) { /* silent */ }
    loading.value = false;
};

const toggle = () => {
    open.value = !open.value;
    if (open.value) fetchList();
};

const markRead = async (id) => {
    try {
        await axios.post(`/api/notifications/${id}/read`);
        const n = notifications.value.find(x => x.id === id);
        if (n) n.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);
    } catch (e) { /* silent */ }
};

const markAllRead = async () => {
    try {
        await axios.post("/api/notifications/read-all");
        notifications.value.forEach(n => n.read_at = n.read_at || new Date().toISOString());
        unreadCount.value = 0;
    } catch (e) { /* silent */ }
};

const goToTask = (n) => {
    if (!n.read_at) markRead(n.id);
    const taskId = n.data?.task_id;
    if (taskId) window.location.href = `/tasks/${taskId}`;
    open.value = false;
};

const closeOnOutside = (e) => {
    if (open.value && !e.target.closest('.notif-bell-wrapper')) open.value = false;
};

onMounted(() => {
    fetchCount();
    pollTimer = setInterval(fetchCount, 30000);
    document.addEventListener("click", closeOnOutside);
});

onUnmounted(() => {
    clearInterval(pollTimer);
    document.removeEventListener("click", closeOnOutside);
});
</script>

<template>
    <div class="relative notif-bell-wrapper">
        <button @click="toggle" class="relative p-2 text-gray-500 hover:text-gray-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span v-if="unreadCount > 0" class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </button>

        <!-- Dropdown -->
        <div v-if="open" class="absolute right-0 mt-2 w-80 bg-white border rounded-xl shadow-xl z-50 max-h-96 flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b">
                <span class="font-bold text-sm text-gray-800">Thông báo</span>
                <button v-if="unreadCount > 0" @click="markAllRead" class="text-xs text-blue-600 hover:underline font-semibold">Đọc tất cả</button>
            </div>
            <div class="overflow-y-auto flex-1">
                <div v-if="loading" class="text-center py-8 text-gray-400 text-sm">Đang tải...</div>
                <div v-else-if="!notifications.length" class="text-center py-8 text-gray-400 text-sm">Không có thông báo.</div>
                <div v-for="n in notifications" :key="n.id" @click="goToTask(n)"
                    :class="n.read_at ? 'bg-white' : 'bg-blue-50'"
                    class="px-4 py-3 border-b last:border-0 hover:bg-gray-50 cursor-pointer transition">
                    <p class="text-sm text-gray-800" :class="!n.read_at ? 'font-semibold' : ''">{{ n.data?.message || 'Thông báo mới' }}</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs text-gray-400">{{ new Date(n.created_at).toLocaleString('vi-VN') }}</span>
                        <span v-if="!n.read_at" class="w-2 h-2 bg-blue-500 rounded-full"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
