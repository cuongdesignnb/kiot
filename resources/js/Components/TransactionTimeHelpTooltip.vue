<template>
    <span class="group relative ml-1 inline-flex align-middle" tabindex="0" aria-label="Giải thích thời gian giao dịch">
        <svg class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span role="tooltip" class="pointer-events-none absolute left-0 top-5 z-30 hidden w-72 rounded bg-gray-800 px-3 py-2 text-left text-xs font-normal normal-case leading-5 text-white shadow-lg group-hover:block group-focus:block">
            Thời gian giao dịch của chứng từ. Timeline và công nợ được sắp xếp theo thời gian này. Thời điểm chứng từ được ghi nhận vào hệ thống có thể muộn hơn.
        </span>
    </span>
</template>
