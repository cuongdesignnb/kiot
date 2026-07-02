# Hotfix Report — POS Process Order Payload 404 Resolution

This hotfix resolves HTTP 404 errors when attempting to load order payload details in the POS workspace.

## 1. Source Audited & Root Cause
- **Audited Files:**
  - [routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php)
  - [OrderController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/OrderController.php)
  - [POS/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/POS/Index.vue)
  - [Orders/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Orders/Index.vue)
- **Root Cause:**
  - The payload route `GET /orders/{order}/pos-payload` originally utilized implicit Route Model Binding. If the frontend requested an order key that was not a direct primary key database ID (e.g. an order code like `DHM000016` or an ID that did not match any record), Laravel automatically aborted the request and returned a generic HTML 404 page.
  - The frontend POS client immediately stripped query parameters before verifying response status, making url-based debugging difficult.
  - The "Xử lý đơn hàng" button handler redirected using a hardcoded `order.id` without fallback or URL encoding.

## 2. Changes Made
- **Backend:**
  - **Route Signature Change:** Modified `orders.pos-payload` to accept a generic `{orderKey}` parameter:
    `Route::get('/orders/{orderKey}/pos-payload', [OrderController::class, 'posPayload'])->name('orders.pos-payload');`
    This bypasses implicit binding and prevents raw HTML 404 responses.
  - **Payload Resolving Logic:** Updated `posPayload(Request $request, string $orderKey)` in `OrderController.php` to query the order manually. It attempts to find the order by numeric `id` (if `orderKey` is digital) or by `code` (e.g., `DHM...`).
  - **Descriptive JSON Errors:** If the order is not found, it responds with a clear JSON object carrying a `404` status code:
    `{"success": false, "message": "Không tìm thấy đơn đặt hàng...", "error_code": "ORDER_NOT_FOUND"}`
    Also, if the order is cancelled, completed, or ended, it returns descriptive `422` error codes (e.g., `ORDER_ALREADY_COMPLETED`, `ORDER_CANCELLED`, `ORDER_ENDED`).
- **Frontend POS (`POS/Index.vue`):**
  - **Encoding Parameter:** Encodes the `order_id` query parameter using `encodeURIComponent(orderId)` before sending.
  - **Deferred URL Cleaning:** Refactored `checkAndHydrateOrderFromUrl` to clear search parameters *only* after a successful hydration. If a 404 occurs, the query parameters remain in the address bar for developer inspection.
  - **Exposes Server Messages:** Improved alert notifications to parse and display backend JSON response messages verbatim.
- **Frontend Orders (`Orders/Index.vue`):**
  - **Fallback Resolution:** Enhanced `openProcessOrderInPos` to prioritize `order.id` but fallback to `order.code` if necessary, using URL component encoding to guarantee safe navigation.

---

## 3. Core Safety Confirmations
- **No Database Migrations:** Confirmed.
- **No Backfill/Data Modification:** Confirmed.
- **Read-Only Loading:** Confirmed. Loading order payload is strictly read-only.
- **No Side Effects:** Opening the POS page does not mutate order status, deduct warehouse inventory, or record cash flows.

---

## 4. Verification & Build Results
- **Automated Tests:**
  - Updated `ProcessOrderViaPosTest.php` with 4 new cases verifying payload loads via ID, payload loads via Code, 404 JSON for non-existent orders, and status code 422 JSON objects for invalid orders.
  - Run tests: `php artisan test tests/Feature/Orders/ProcessOrderViaPosTest.php` -> **8/8 tests passed successfully**.
- **Frontend Compilation:**
  - Vite asset compilation built cleanly in **7.64s** (`npm run build`).
