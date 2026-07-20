import test from 'node:test';
import assert from 'node:assert/strict';

import {
    POS_DRAFT_SCHEMA_VERSION,
    canonicalRequestFingerprint,
    getCheckoutAttemptKey,
    resetSaleTabAfterSuccess,
    sanitizeSaleTabDraft,
} from '../Pages/POS/posIdempotency.js';

const keyFactory = (...keys) => () => keys.shift();

test('canonical fingerprint ignores object key insertion order', () => {
    const first = canonicalRequestFingerprint({
        endpoint: '/api/pos/checkout',
        payload: { total: 200000, items: [{ quantity: 1, product_id: 10 }] },
    });
    const second = canonicalRequestFingerprint({
        payload: { items: [{ product_id: 10, quantity: 1 }], total: 200000 },
        endpoint: '/api/pos/checkout',
    });

    assert.equal(first, second);
});

test('same endpoint and normalized payload reuse the same key', () => {
    const tab = { checkoutAttempt: null };
    const nextKey = keyFactory('key-a', 'key-b');
    const payload = { total: 200000, items: [{ product_id: 10, quantity: 1 }] };

    assert.equal(getCheckoutAttemptKey(tab, '/api/pos/checkout', payload, { createKey: nextKey }), 'key-a');
    assert.equal(getCheckoutAttemptKey(tab, '/api/pos/checkout', payload, { createKey: nextKey }), 'key-a');
});

test('changed payload rotates the key', () => {
    const tab = { checkoutAttempt: null };
    const nextKey = keyFactory('key-a', 'key-b');

    assert.equal(getCheckoutAttemptKey(tab, '/api/pos/checkout', { total: 1 }, { createKey: nextKey }), 'key-a');
    assert.equal(getCheckoutAttemptKey(tab, '/api/pos/checkout', { total: 2 }, { createKey: nextKey }), 'key-b');
});

test('changed endpoint rotates the key', () => {
    const tab = { checkoutAttempt: null };
    const nextKey = keyFactory('key-a', 'key-b');
    const payload = { total: 1 };

    assert.equal(getCheckoutAttemptKey(tab, '/api/pos/checkout', payload, { createKey: nextKey }), 'key-a');
    assert.equal(getCheckoutAttemptKey(tab, '/orders/10/process', payload, { createKey: nextKey }), 'key-b');
});

test('separate tabs never share an attempt', () => {
    const firstTab = { checkoutAttempt: null };
    const secondTab = { checkoutAttempt: null };
    const nextKey = keyFactory('key-a', 'key-b');

    assert.equal(getCheckoutAttemptKey(firstTab, '/api/pos/checkout', { total: 1 }, { createKey: nextKey }), 'key-a');
    assert.equal(getCheckoutAttemptKey(secondTab, '/api/pos/checkout', { total: 1 }, { createKey: nextKey }), 'key-b');
});

test('success reset clears attempt and transaction-specific state', () => {
    const tab = {
        type: 'sale',
        cart: [{ product_id: 10 }],
        discount: 100,
        customerPaid: 900,
        paymentMethod: 'transfer',
        bankAccountInfo: 'VCB',
        selectedCustomer: { id: 20 },
        customerQuery: 'customer',
        note: 'note',
        checkoutAttempt: { key: 'key-a', fingerprint: 'fingerprint-a' },
        idempotencyKey: 'legacy-key',
        mode: 'process_order',
        source_order_id: 30,
        source_order_code: 'DH30',
        orderDepositAmount: 500,
        orderPaymentSummary: { remaining: 500 },
        delivery: { is_delivery: true },
        saleMode: 'delivery',
    };

    resetSaleTabAfterSuccess(tab);

    assert.deepEqual(tab.cart, []);
    assert.equal(tab.checkoutAttempt, null);
    assert.equal('idempotencyKey' in tab, false);
    assert.equal(tab.mode, 'normal');
    assert.equal(tab.source_order_id, null);
    assert.equal(tab.orderPaymentSummary, null);
    assert.equal(tab.delivery.is_delivery, false);
    assert.equal(tab.saleMode, 'normal');
});

test('legacy draft key is discarded without deleting user draft data', () => {
    const sanitized = sanitizeSaleTabDraft({
        cart: [{ product_id: 10 }],
        selectedCustomer: { id: 20 },
        idempotencyKey: 'stale-key',
    }, 1);

    assert.deepEqual(sanitized.cart, [{ product_id: 10 }]);
    assert.deepEqual(sanitized.selectedCustomer, { id: 20 });
    assert.equal(sanitized.checkoutAttempt, null);
    assert.equal('idempotencyKey' in sanitized, false);
});

test('current draft restores a complete pending attempt', () => {
    const attempt = { key: 'key-a', fingerprint: 'fingerprint-a' };
    const sanitized = sanitizeSaleTabDraft({ checkoutAttempt: attempt }, POS_DRAFT_SCHEMA_VERSION);

    assert.deepEqual(sanitized.checkoutAttempt, attempt);
    assert.notEqual(sanitized.checkoutAttempt, attempt);
});

test('quick order path explicitly skips idempotency and never calls the key factory', () => {
    const tab = { saleMode: 'quick_order', checkoutAttempt: null };
    const orderPayload = { total: 1000 };
    let keyFactoryCalls = 0;

    const key = getCheckoutAttemptKey(tab, '/api/pos/quick-order', orderPayload, {
        usesIdempotency: false,
        createKey: () => {
            keyFactoryCalls += 1;
            return 'unused-key';
        },
    });

    assert.equal(key, null);
    assert.equal(keyFactoryCalls, 0);
    assert.equal(tab.checkoutAttempt, null);
});
