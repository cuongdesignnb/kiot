/**
 * STEP 24.5 — Vietnamese Date/Time Utilities
 *
 * Policy:
 *   Display date     → dd/MM/yyyy          (08/05/2026)
 *   Display datetime → dd/MM/yyyy HH:mm    (08/05/2026 10:14)
 *   Submit date      → yyyy-MM-dd
 *   Submit datetime  → yyyy-MM-ddTHH:mm
 *   Timezone         → Asia/Ho_Chi_Minh (backend); frontend treats all values as local.
 *
 * NEVER depends on browser locale.  Uses manual string slicing only.
 */

/** Zero-pad to 2 digits */
export function pad2(n) {
    return String(n).padStart(2, '0');
}

export function nowDatetimeLocal() {
    const d = new Date();
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

/**
 * Internal: parse any reasonable date value into a JS Date (or null).
 * Accepts: Date object, ISO string, 'yyyy-MM-dd', 'yyyy-MM-ddTHH:mm', 'yyyy-MM-dd HH:mm:ss', number (epoch ms).
 * Does NOT accept dd/MM/yyyy — use parseVNDate for that.
 */
function toDate(value) {
    if (!value) return null;
    if (value instanceof Date) return isNaN(value.getTime()) ? null : value;
    if (typeof value === 'number') return new Date(value);
    if (typeof value !== 'string') return null;

    // Handle ISO / yyyy-MM-dd / yyyy-MM-ddTHH:mm / yyyy-MM-dd HH:mm:ss
    const s = value.trim();
    // yyyy-MM-dd (10 chars) or yyyy-MM-ddTHH:mm (16 chars) or yyyy-MM-dd HH:mm:ss (19 chars) etc.
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
        // Replace space separator with T for consistency
        const normalized = s.replace(' ', 'T');
        const d = new Date(normalized);
        return isNaN(d.getTime()) ? null : d;
    }
    return null;
}

// ─── Display formatters (locale-independent) ────────────────────────

/**
 * Format a date value as dd/MM/yyyy.
 * @param {Date|string|number|null} value
 * @returns {string} e.g. "08/05/2026" or ""
 */
export function formatDateVN(value) {
    const d = toDate(value);
    if (!d) return '';
    return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/**
 * Format a date value as dd/MM/yyyy HH:mm.
 * @param {Date|string|number|null} value
 * @returns {string} e.g. "08/05/2026 10:14" or ""
 */
export function formatDateTimeVN(value) {
    const d = toDate(value);
    if (!d) return '';
    return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

/**
 * Format a date value as HH:mm (24h).
 * @param {Date|string|number|null} value
 * @returns {string} e.g. "10:14" or ""
 */
export function formatTimeVN(value) {
    const d = toDate(value);
    if (!d) return '';
    return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

// ─── Canonical submit helpers ───────────────────────────────────────

/**
 * Convert to yyyy-MM-ddTHH:mm for <input type="datetime-local"> or backend submit.
 * @param {Date|string|number|null} value
 * @returns {string} e.g. "2026-05-08T10:14" or ""
 */
export function toDatetimeLocalValue(value) {
    const d = toDate(value);
    if (!d) return '';
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

/**
 * Convert to yyyy-MM-dd for <input type="date"> or backend submit.
 * @param {Date|string|number|null} value
 * @returns {string} e.g. "2026-05-08" or ""
 */
export function toDateInputValue(value) {
    const d = toDate(value);
    if (!d) return '';
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

// ─── VN format parsers ──────────────────────────────────────────────

/**
 * Parse dd/MM/yyyy → Date object (or null).
 * Does NOT use Date.parse to avoid locale confusion.
 * @param {string} value  e.g. "08/05/2026"
 * @returns {Date|null}
 */
export function parseVNDate(value) {
    if (!value || typeof value !== 'string') return null;
    const m = value.trim().match(/^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$/);
    if (!m) return null;
    const day = parseInt(m[1], 10);
    const month = parseInt(m[2], 10);
    const year = parseInt(m[3], 10);
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;
    const d = new Date(year, month - 1, day);
    // Validate roll-over (e.g. Feb 30 → Mar 2)
    if (d.getDate() !== day || d.getMonth() !== month - 1 || d.getFullYear() !== year) return null;
    return d;
}

/**
 * Parse dd/MM/yyyy HH:mm → Date object (or null).
 * @param {string} value  e.g. "08/05/2026 10:14"
 * @returns {Date|null}
 */
export function parseVNDateTime(value) {
    if (!value || typeof value !== 'string') return null;
    const m = value.trim().match(/^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})\s+(\d{1,2}):(\d{2})$/);
    if (!m) return null;
    const day = parseInt(m[1], 10);
    const month = parseInt(m[2], 10);
    const year = parseInt(m[3], 10);
    const hour = parseInt(m[4], 10);
    const minute = parseInt(m[5], 10);
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;
    if (hour < 0 || hour > 23 || minute < 0 || minute > 59) return null;
    const d = new Date(year, month - 1, day, hour, minute);
    if (d.getDate() !== day || d.getMonth() !== month - 1) return null;
    return d;
}

// ─── Validators ─────────────────────────────────────────────────────

/** @returns {boolean} */
export function isValidVNDate(value) {
    return parseVNDate(value) !== null;
}

/** @returns {boolean} */
export function isValidVNDateTime(value) {
    return parseVNDateTime(value) !== null;
}

// ─── Convenience: format "relative time" (e.g. "2 phút trước") ──────

/**
 * Vietnamese relative time (optional, for activity logs / notifications).
 * @param {Date|string|number|null} value
 * @returns {string}
 */
export function timeAgoVN(value) {
    const d = toDate(value);
    if (!d) return '';
    const diffMs = Date.now() - d.getTime();
    const diffSec = Math.floor(diffMs / 1000);
    if (diffSec < 60) return 'Vừa xong';
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return `${diffMin} phút trước`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `${diffHr} giờ trước`;
    const diffDay = Math.floor(diffHr / 24);
    if (diffDay < 30) return `${diffDay} ngày trước`;
    return formatDateVN(value);
}
