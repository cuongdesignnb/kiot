const nonNegativeMoney = (value) => {
    const parsed = Number(value);

    if (!Number.isFinite(parsed)) return 0;

    return Math.max(0, Math.round(parsed));
};

const wholeQuantity = (value) => {
    const parsed = Number(value);

    if (!Number.isFinite(parsed)) return 0;

    return Math.max(0, Math.trunc(parsed));
};

export const purchaseLineQuantity = (item, quantityKey = 'quantity') => {
    if (item?.has_serial) {
        return Array.isArray(item.serials) ? item.serials.length : 0;
    }

    return wholeQuantity(item?.[quantityKey]);
};

export const purchaseLineTotal = (item, quantityKey = 'quantity') => {
    const quantity = purchaseLineQuantity(item, quantityKey);
    const price = nonNegativeMoney(item?.price);
    const discount = nonNegativeMoney(item?.discount);

    return Math.max(0, (quantity * price) - discount);
};

export const purchaseLinePricingError = (item, quantityKey = 'quantity') => {
    const quantity = purchaseLineQuantity(item, quantityKey);

    if (quantity <= 0) return 'Số lượng phải lớn hơn 0.';

    const gross = quantity * nonNegativeMoney(item?.price);
    if (nonNegativeMoney(item?.discount) > gross) {
        return 'Giảm giá không được vượt thành tiền trước giảm giá.';
    }

    return '';
};

/**
 * Adds UI-only state to an item. The persisted accounting contract remains
 * quantity × price − discount, so no line-total value is trusted by backend.
 */
export const preparePurchaseLinePricing = (item, quantityKey = 'quantity') => {
    const line = item;

    line.line_total_mode = line.line_total_mode === 'line_total' ? 'line_total' : 'unit_price';
    line.line_total_rounding_discount = nonNegativeMoney(line.line_total_rounding_discount);
    line.line_total = purchaseLineTotal(line, quantityKey);
    line.line_total_error = purchaseLinePricingError(line, quantityKey);

    return line;
};

/** Unit-price or discount is authoritative; refresh the displayed line total. */
export const syncPurchaseLineFromUnitPrice = (
    item,
    quantityKey = 'quantity',
    { discountWasEdited = false } = {},
) => {
    const previousRounding = nonNegativeMoney(item.line_total_rounding_discount);

    item.price = nonNegativeMoney(item.price);
    item.discount = nonNegativeMoney(item.discount);
    if (!discountWasEdited) {
        item.discount = Math.max(0, item.discount - previousRounding);
    }
    item.line_total_mode = 'unit_price';
    item.line_total_rounding_discount = 0;
    item.line_total = purchaseLineTotal(item, quantityKey);
    item.line_total_error = purchaseLinePricingError(item, quantityKey);

    return item;
};

/**
 * Line total is authoritative; derive an integer VND unit price.
 *
 * If total is not divisible by quantity, the smallest possible rounding
 * remainder is folded into the existing line discount. This keeps the exact
 * amount entered by the operator while preserving the canonical persisted
 * equation: quantity × price − discount = line total.
 */
export const syncPurchaseLineFromTotal = (item, total, quantityKey = 'quantity') => {
    const target = nonNegativeMoney(total);
    const quantity = purchaseLineQuantity(item, quantityKey);

    item.line_total_mode = 'line_total';
    item.line_total = target;

    if (quantity <= 0) {
        item.line_total_error = 'Nhập số lượng hoặc Serial/IMEI trước khi nhập thành tiền.';

        return item;
    }

    const previousRounding = nonNegativeMoney(item.line_total_rounding_discount);
    const currentDiscount = nonNegativeMoney(item.discount);
    const manualDiscount = Math.max(0, currentDiscount - previousRounding);
    const grossTarget = target + manualDiscount;
    const price = Math.ceil(grossTarget / quantity);
    const roundingDiscount = Math.max(0, (quantity * price) - grossTarget);

    item.price = price;
    item.discount = manualDiscount + roundingDiscount;
    item.line_total_rounding_discount = roundingDiscount;
    item.line_total = purchaseLineTotal(item, quantityKey);
    item.line_total_error = purchaseLinePricingError(item, quantityKey);

    return item;
};

/** Keep whichever value the operator edited most recently authoritative. */
export const syncPurchaseLineAfterQuantityChange = (item, quantityKey = 'quantity') => {
    if (item.line_total_mode === 'line_total') {
        return syncPurchaseLineFromTotal(item, item.line_total, quantityKey);
    }

    return syncPurchaseLineFromUnitPrice(item, quantityKey);
};
