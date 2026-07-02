# HOTFIX — QuickCreateCustomerModal reactive import

## Lỗi production
- Màn hình: /orders/create
- Error: ReferenceError: reactive is not defined
- Asset lỗi: QuickCreateCustomerModal-*.js

## Root cause
- File: resources/js/Components/QuickCreateCustomerModal.vue
- Import thiếu: reactive
- Component dùng reactive ở đâu: dòng 146, khởi tạo dualRoleConfirm

## Patch
```diff
- import { ref, watch } from 'vue';
+ import { ref, watch, reactive } from 'vue';
```

## Data safety
- Migration: No
- Backfill: No
- Update dữ liệu cũ: No
- Recalculate: No

## Tests
- Static check: verified import `{ ref, watch, reactive }` exists and `dualRoleConfirm = reactive({...})` is used correctly.
- npm run build: Success, compiled to QuickCreateCustomerModal-e-RwQD7f.js
- Manual QA: OK

## Deploy note
- Commit: f80c982fdadaa3f558526b64c2b275361ffc384b
- Production đã pull chưa: No
- Production đã build lại chưa: No
