<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'KiotViet Clone ERP' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-800 text-sm antialiased font-sans">

    {{-- Top Navigation, Header màu xanh đặc trưng --}}
    <nav class="bg-[#0070f3] text-white flex items-center justify-between px-4 py-2 sticky top-0 z-50 shadow-md">
        <div class="flex items-center gap-6">
            <a href="/" class="text-xl font-bold tracking-tight">KiotViet Clone</a>
            
            {{-- Main Menu --}}
            <div class="hidden md:flex space-x-1">
                <a href="#" class="px-3 py-2 rounded relative hover:bg-blue-600 transition-colors bg-blue-700 font-medium">Tổng quan</a>
                
                {{-- Dropdown Hàng Hóa --}}
                <div class="relative group" x-data="{ open: false }" @click.away="open = false">
                    <button @click="open = !open" class="px-3 py-2 rounded hover:bg-blue-600 transition-colors flex items-center gap-1 font-medium">
                        Hàng hóa
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div x-show="open" x-transition class="absolute left-0 mt-1 w-[600px] bg-white text-gray-800 shadow-xl rounded-md border border-gray-100 flex p-4 shadow-lg z-50" style="display: none;">
                        <div class="flex-1 border-r border-gray-100 pr-4">
                            <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Hàng hóa</h4>
                            <ul class="space-y-2">
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Danh sách hàng hóa</a></li>
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Thiết lập giá</a></li>
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Bảo hành, bảo trì</a></li>
                            </ul>
                        </div>
                        <div class="flex-1 px-4 border-r border-gray-100">
                            <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Kho hàng</h4>
                            <ul class="space-y-2">
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Kiểm kho</a></li>
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Chuyển hàng</a></li>
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Sản xuất (BOM)</a></li>
                            </ul>
                        </div>
                        <div class="flex-1 pl-4">
                            <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Nhập hàng</h4>
                            <ul class="space-y-2">
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm text-red-600 font-medium">Nhập hàng mới</a></li>
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Đặt hàng nhập</a></li>
                                <li><a href="#" class="block hover:bg-blue-50 hover:text-blue-600 p-2 rounded transition-colors text-sm">Nhà cung cấp</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <a href="#" class="px-3 py-2 rounded hover:bg-blue-600 transition-colors flex font-medium">Đơn hàng</a>
                <a href="#" class="px-3 py-2 rounded hover:bg-blue-600 transition-colors flex font-medium">Khách hàng</a>
                <a href="#" class="px-3 py-2 rounded hover:bg-blue-600 transition-colors flex font-medium">Nhân viên</a>
                <a href="#" class="px-3 py-2 rounded hover:bg-blue-600 transition-colors flex font-medium">Sổ quỹ</a>
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <button class="bg-white text-blue-600 font-bold px-4 py-1.5 rounded-full hover:bg-blue-50 transition-colors border border-transparent flex items-center gap-2 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                Bán hàng
            </button>
            <div class="h-6 w-px bg-blue-500 mx-1"></div>
            <div class="flex items-center gap-2 cursor-pointer hover:bg-blue-600 px-2 py-1 rounded transition-colors">
                <div class="w-7 h-7 bg-blue-800 rounded-full flex items-center justify-center font-bold border border-blue-400">A</div>
                <span class="font-medium text-sm hidden sm:block">Admin</span>
            </div>
        </div>
    </nav>

    {{-- Layout Container --}}
    <div class="flex h-[calc(100vh-56px)] overflow-hidden">
        {{-- Left Sidebar --}}
        <aside class="w-64 bg-white border-r border-gray-200 overflow-y-auto flex-shrink-0 relative hidden lg:block shadow-sm z-10">
            @yield('sidebar')
        </aside>

        {{-- Main Content --}}
        <main class="flex-1 bg-[#f0f2f5] overflow-y-auto p-4 md:p-6 w-full relative">
            <div class="max-w-7xl mx-auto">
                {{ $slot }}
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>