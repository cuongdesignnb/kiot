<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const form = useForm({
    email: "",
    password: "",
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post("/login", {
        onFinish: () => form.reset("password"),
    });
};
</script>

<template>
    <Head title="Đăng nhập - KiotViet" />
    <div
        class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-white to-blue-100"
    >
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <div
                    class="inline-flex items-center justify-center w-16 h-16 bg-[#0070f4] rounded-2xl shadow-lg mb-4"
                >
                    <svg
                        class="w-9 h-9 text-white"
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
                </div>
                <h1 class="text-2xl font-bold text-gray-900">KiotViet Clone</h1>
                <p class="text-gray-500 mt-1">Đăng nhập để quản lý cửa hàng</p>
            </div>

            <!-- Login Card -->
            <div
                class="bg-white rounded-xl shadow-lg border border-gray-200 p-8"
            >
                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Email -->
                    <div>
                        <label
                            for="email"
                            class="block text-sm font-medium text-gray-700 mb-1.5"
                            >Email</label
                        >
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                            >
                                <svg
                                    class="w-5 h-5 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"
                                    ></path>
                                </svg>
                            </div>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                required
                                autofocus
                                class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                :class="{
                                    'border-red-500 focus:ring-red-500 focus:border-red-500':
                                        form.errors.email,
                                }"
                                placeholder="admin@kiotviet.vn"
                            />
                        </div>
                        <p
                            v-if="form.errors.email"
                            class="mt-1.5 text-sm text-red-600"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label
                            for="password"
                            class="block text-sm font-medium text-gray-700 mb-1.5"
                            >Mật khẩu</label
                        >
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                            >
                                <svg
                                    class="w-5 h-5 text-gray-400"
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
                            </div>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                class="block w-full pl-10 pr-12 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                :class="{
                                    'border-red-500 focus:ring-red-500 focus:border-red-500':
                                        form.errors.password,
                                }"
                                placeholder="••••••••"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                            >
                                <svg
                                    v-if="!showPassword"
                                    class="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                    ></path>
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                    ></path>
                                </svg>
                                <svg
                                    v-else
                                    class="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                                    ></path>
                                </svg>
                            </button>
                        </div>
                        <p
                            v-if="form.errors.password"
                            class="mt-1.5 text-sm text-red-600"
                        >
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- Remember me -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            />
                            <span class="text-sm text-gray-600"
                                >Ghi nhớ đăng nhập</span
                            >
                        </label>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full bg-[#0070f4] text-white py-2.5 px-4 rounded-lg font-semibold hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                    >
                        <svg
                            v-if="form.processing"
                            class="animate-spin w-5 h-5"
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
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            ></path>
                        </svg>
                        <span v-if="!form.processing">Đăng nhập</span>
                        <span v-else>Đang đăng nhập...</span>
                    </button>
                </form>
            </div>

            <!-- Footer -->
            <p class="text-center text-xs text-gray-400 mt-6">
                &copy; {{ new Date().getFullYear() }} KiotViet Clone. Phần mềm
                quản lý bán hàng.
            </p>
        </div>
    </div>
</template>
