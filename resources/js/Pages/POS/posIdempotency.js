export const POS_DRAFT_SCHEMA_VERSION = 2;

export const normalizeSaleTabType = (type) => (
    ['sale', 'order', 'return'].includes(type) ? type : 'sale'
);

const stableValue = (value) => {
    if (Array.isArray(value)) {
        return value.map(stableValue);
    }

    if (value !== null && typeof value === 'object') {
        return Object.keys(value)
            .sort()
            .reduce((result, key) => {
                if (value[key] !== undefined) {
                    result[key] = stableValue(value[key]);
                }
                return result;
            }, {});
    }

    return value;
};

export const canonicalRequestFingerprint = ({ endpoint, payload }) => JSON.stringify(stableValue({
    endpoint,
    payload,
}));

export const getCheckoutAttemptKey = (tab, endpoint, payload, options = {}) => {
    const {
        createKey = () => crypto.randomUUID(),
        usesIdempotency = true,
    } = options;

    if (!usesIdempotency) {
        return null;
    }

    const fingerprint = canonicalRequestFingerprint({ endpoint, payload });
    const currentAttempt = tab.checkoutAttempt;

    if (
        !currentAttempt
        || typeof currentAttempt.key !== 'string'
        || currentAttempt.key.trim() === ''
        || currentAttempt.fingerprint !== fingerprint
    ) {
        tab.checkoutAttempt = {
            key: createKey(),
            fingerprint,
        };
    }

    return tab.checkoutAttempt.key;
};

export const clearCheckoutAttempt = (tab) => {
    tab.checkoutAttempt = null;
    delete tab.idempotencyKey;
};

export const removeCompletedSaleTab = (tabs, activeTabIndex, completedTab) => {
    const completedTabIndex = tabs.indexOf(completedTab);

    if (completedTabIndex === -1 || tabs.length <= 1) {
        return activeTabIndex;
    }

    const activeTab = tabs[activeTabIndex] ?? null;
    tabs.splice(completedTabIndex, 1);

    if (activeTab && activeTab !== completedTab) {
        const preservedActiveIndex = tabs.indexOf(activeTab);
        if (preservedActiveIndex !== -1) {
            return preservedActiveIndex;
        }
    }

    return Math.min(completedTabIndex, tabs.length - 1);
};

export const sanitizeSaleTabDraft = (tab, schemaVersion) => {
    const sanitized = { ...tab };
    sanitized.type = normalizeSaleTabType(sanitized.type);
    const attempt = sanitized.checkoutAttempt;
    const canRestoreAttempt = schemaVersion === POS_DRAFT_SCHEMA_VERSION
        && attempt
        && typeof attempt.key === 'string'
        && attempt.key.trim() !== ''
        && typeof attempt.fingerprint === 'string'
        && attempt.fingerprint !== '';

    sanitized.checkoutAttempt = canRestoreAttempt
        ? { key: attempt.key, fingerprint: attempt.fingerprint }
        : null;
    delete sanitized.idempotencyKey;

    return sanitized;
};

export const emptyDeliveryState = () => ({
    is_delivery: false,
    delivery_mode: 'none',
    delivery_partner: '',
    tracking_code: '',
    delivery_fee: 0,
    cod_amount: 0,
    receiver_name: '',
    receiver_phone: '',
    receiver_address: '',
    receiver_ward: '',
    receiver_district: '',
    receiver_city: '',
    weight: 0,
    delivery_note: '',
});

export const resetSaleTabAfterSuccess = (tab) => {
    tab.cart = [];
    tab.discount = 0;
    tab.customerPaid = 0;
    tab.paymentMethod = 'cash';
    tab.bankAccountInfo = '';
    tab.selectedCustomer = null;
    tab.customerQuery = '';
    tab.note = '';
    tab.mode = 'normal';
    tab.source_order_id = null;
    tab.source_order_code = '';
    tab.orderDepositAmount = 0;
    tab.orderPaymentSummary = null;
    tab.delivery = emptyDeliveryState();
    tab.saleMode = tab.type === 'order' ? 'quick_order' : 'normal';
    clearCheckoutAttempt(tab);

    return tab;
};
