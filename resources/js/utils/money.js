/**
 * Tiện ích format tiền VNĐ — dùng chung toàn hệ thống.
 *
 * Quy chuẩn:
 *   1000000  → "1.000.000đ"
 *   0        → "0đ"
 *   -500000  → "-500.000đ"
 *   null/NaN → "0đ"
 *
 * KHÔNG dùng cho: số lượng, số điện thoại, mã hàng, Serial/IMEI, phần trăm.
 */

/**
 * Format số thành chuỗi tiền VNĐ hiển thị (có đ).
 * Dùng cho text display, KHÔNG dùng trong input value.
 * @param {number|string|null|undefined} value
 * @returns {string} Ví dụ: "1.000.000đ"
 */
export function formatVND(value) {
    const number = Number(value || 0);
    if (!Number.isFinite(number)) return '0đ';
    return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Math.round(number))}đ`;
}

/**
 * Format số thành chuỗi tách hàng nghìn KHÔNG có đ.
 * Dùng cho input tiền (khi UI có suffix đ riêng hoặc không cần đ).
 *
 * formatMoneyInput(1500000) → "1.500.000"
 * formatMoneyInput(0)       → "0"
 * formatMoneyInput(null)    → "0"
 *
 * @param {number|string|null|undefined} value
 * @returns {string}
 */
export function formatMoneyInput(value) {
    const number = Number(value || 0);
    if (!Number.isFinite(number)) return '0';
    return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Math.round(number));
}

/**
 * Parse chuỗi tiền VNĐ (hoặc bất kỳ format) về number.
 * Dùng khi cần convert giá trị hiển thị thành số gửi API.
 *
 * parseVND("1.000.000đ") → 1000000
 * parseVND("1,000,000")  → 1000000
 * parseVND("1.500.000")  → 1500000
 * parseVND("1500000")    → 1500000
 * parseVND("")           → 0
 * parseVND(null)         → 0
 *
 * @param {string|number|null|undefined} value
 * @returns {number}
 */
export function parseVND(value) {
    if (value === null || value === undefined) return 0;
    const cleaned = String(value).replace(/[^\d-]/g, '');
    const number = Number(cleaned);
    return Number.isFinite(number) ? number : 0;
}

/**
 * Alias ngắn — dùng cho template cần gọn.
 * Cùng output với formatVND.
 */
export const fmtVND = formatVND;
