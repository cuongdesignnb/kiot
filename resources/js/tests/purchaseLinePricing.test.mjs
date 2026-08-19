import test from 'node:test';
import assert from 'node:assert/strict';

import {
    preparePurchaseLinePricing,
    purchaseLinePricingError,
    purchaseLineTotal,
    syncPurchaseLineAfterQuantityChange,
    syncPurchaseLineFromTotal,
    syncPurchaseLineFromUnitPrice,
} from '../utils/purchaseLinePricing.js';

test('unit price, quantity and discount calculate the canonical line total', () => {
    const item = preparePurchaseLinePricing({ quantity: 18, price: 500_000, discount: 50_000 });

    assert.equal(purchaseLineTotal(item), 8_950_000);
    assert.equal(item.line_total, 8_950_000);
    assert.equal(item.line_total_mode, 'unit_price');
});

test('typing a divisible line total derives the unit price without a rounding discount', () => {
    const item = preparePurchaseLinePricing({ quantity: 18, price: 0, discount: 0 });

    syncPurchaseLineFromTotal(item, 9_000_000);

    assert.equal(item.price, 500_000);
    assert.equal(item.discount, 0);
    assert.equal(item.line_total, 9_000_000);
});

test('non-divisible VND total remains exact through a transparent rounding discount', () => {
    const item = preparePurchaseLinePricing({ quantity: 3, price: 0, discount: 0 });

    syncPurchaseLineFromTotal(item, 100_000);

    assert.equal(item.price, 33_334);
    assert.equal(item.line_total_rounding_discount, 2);
    assert.equal(item.discount, 2);
    assert.equal((item.quantity * item.price) - item.discount, 100_000);
});

test('existing manual discount is preserved when total drives unit price', () => {
    const item = preparePurchaseLinePricing({ quantity: 3, price: 0, discount: 1_000 });

    syncPurchaseLineFromTotal(item, 100_000);

    assert.equal(item.price, 33_667);
    assert.equal(item.line_total_rounding_discount, 1);
    assert.equal(item.discount, 1_001);
    assert.equal((item.quantity * item.price) - item.discount, 100_000);
});

test('quantity changes preserve a total that the operator entered', () => {
    const item = preparePurchaseLinePricing({ quantity: 4, price: 0, discount: 0 });
    syncPurchaseLineFromTotal(item, 100_000);

    item.quantity = 5;
    syncPurchaseLineAfterQuantityChange(item);

    assert.equal(item.price, 20_000);
    assert.equal(item.discount, 0);
    assert.equal(item.line_total, 100_000);
});

test('editing unit price switches authority back and recalculates total', () => {
    const item = preparePurchaseLinePricing({ quantity: 3, price: 0, discount: 0 });
    syncPurchaseLineFromTotal(item, 100_000);

    item.price = 30_000;
    syncPurchaseLineFromUnitPrice(item);

    assert.equal(item.line_total_mode, 'unit_price');
    assert.equal(item.discount, 0, 'automatic rounding discount must not become a manual discount');
    assert.equal(item.line_total, 90_000);
    assert.equal(item.line_total_rounding_discount, 0);
});

test('editing discount treats the entered discount as authoritative', () => {
    const item = preparePurchaseLinePricing({ quantity: 3, price: 0, discount: 0 });
    syncPurchaseLineFromTotal(item, 100_000);

    item.discount = 5_000;
    syncPurchaseLineFromUnitPrice(item, 'quantity', { discountWasEdited: true });

    assert.equal(item.discount, 5_000);
    assert.equal(item.line_total, 95_002);
    assert.equal(item.line_total_rounding_discount, 0);
});

test('serial quantity is resolved from selected serials', () => {
    const item = preparePurchaseLinePricing({
        has_serial: true,
        serials: ['A', 'B'],
        quantity: 99,
        price: 500_000,
        discount: 0,
    });

    assert.equal(item.line_total, 1_000_000);

    syncPurchaseLineFromTotal(item, 1_200_000);
    assert.equal(item.price, 600_000);
});

test('total input reports a clear error until quantity exists', () => {
    const item = preparePurchaseLinePricing({ quantity: 0, price: 0, discount: 0 });

    syncPurchaseLineFromTotal(item, 100_000);

    assert.equal(item.price, 0);
    assert.match(item.line_total_error, /Nhập số lượng/);
});

test('discount greater than gross is rejected by the pricing contract', () => {
    const item = { quantity: 1, price: 100_000, discount: 100_001 };

    assert.match(purchaseLinePricingError(item), /Giảm giá/);
});
