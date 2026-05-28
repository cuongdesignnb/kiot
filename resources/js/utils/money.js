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

/* ─────────────────────────────────────────────────────────────────────
 * HOTFIX 24.20 — realtime money-input helpers.
 *
 * Khác với `formatMoneyInput` / `parseVND` ở trên (vẫn giữ cho backward
 * compatibility với screens đang xài):
 *
 *   - Empty / null / undefined / "" → KHÔNG fall về "0". Trả về "" hoặc 0
 *     đúng theo intent người dùng. Tránh chuyện ô tiền mặc định hiện "0"
 *     làm user phải xoá trước khi gõ.
 *   - `formatVndInput("1.000.000")` chấp nhận chuỗi đã format và normalise
 *     dấu chấm — dùng được trong `@input` handler để format realtime.
 *   - `parseVndInput` strip mọi non-digit (kể cả `,`, ` `, `.`) → number.
 * ─────────────────────────────────────────────────────────────────────
 */

/**
 * Lấy chỉ digits từ chuỗi nhập vào. Strip dot/comma/space/đ/non-digit.
 *
 * onlyDigits('1.000.000')   → '1000000'
 * onlyDigits('1,000,000')   → '1000000'
 * onlyDigits('1 000 000 đ') → '1000000'
 * onlyDigits(null)          → ''
 *
 * @param {string|number|null|undefined} value
 * @returns {string}
 */
export const onlyDigits = (value) => {
    return String(value ?? '').replace(/[^\d]/g, '');
};

/**
 * Format chuỗi/ số tiền VNĐ với dấu chấm phân tách hàng nghìn.
 * Realtime-safe — nhận cả string đã format và normalise lại.
 *
 * formatVndInput('1000')       → '1.000'
 * formatVndInput('1000000')    → '1.000.000'
 * formatVndInput('1.000.000')  → '1.000.000'
 * formatVndInput('1,000,000')  → '1.000.000'
 * formatVndInput('1 000 000')  → '1.000.000'
 * formatVndInput('')           → ''
 * formatVndInput(null)         → ''
 *
 * @param {string|number|null|undefined} value
 * @returns {string}
 */
export const formatVndInput = (value) => {
    const digits = onlyDigits(value);
    if (!digits) return '';
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

/**
 * Parse chuỗi đã format về number. Đảm bảo payload gửi backend luôn
 * là number — không phải "1.000.000".
 *
 * parseVndInput('1.000.000') → 1000000
 * parseVndInput('1,000,000') → 1000000
 * parseVndInput('1 000 000') → 1000000
 * parseVndInput('')          → 0
 * parseVndInput(null)        → 0
 *
 * @param {string|number|null|undefined} value
 * @returns {number}
 */
export const parseVndInput = (value) => {
    const digits = onlyDigits(value);
    if (!digits) return 0;
    return Number(digits);
};

/**
 * Phân biệt input "trống" với input "= 0". Dùng để giữ placeholder
 * khi field rỗng (thay vì lúc nào cũng hiện "0").
 *
 * @param {string|number|null|undefined} value
 * @returns {boolean}
 */
export const isMoneyInputEmpty = (value) => {
    return value === null || value === undefined || String(value).trim() === '';
};

/**
 * Parses modelValue from backend/app state to integer.
 * Handles decimal strings ("6580000.00"), raw numbers, or formatted inputs.
 */
export const parseMoneyModelValue = (value) => {
    if (value === null || value === undefined || value === '') return 0;
    if (typeof value === 'number') {
        return Number.isFinite(value) ? Math.round(value) : 0;
    }

    const s = String(value).trim();

    // Backend/Laravel decimal string: "6580000.00"
    if (/^-?\d+\.\d+$/.test(s)) {
        const n = Number(s);
        return Number.isFinite(n) ? Math.round(n) : 0;
    }

    // Raw numeric string: "6580000"
    if (/^-?\d+$/.test(s)) {
        const n = Number(s);
        return Number.isFinite(n) ? n : 0;
    }

    // VN formatted string: "6.580.000đ", "6 580 000"
    return parseVndInput(s);
};

