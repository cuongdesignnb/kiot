-- HOTFIX 24.36+24.37 local audit.
-- Run only against the local Docker database imported from production.

-- 0. Schema preflight
SHOW COLUMNS FROM invoice_items;
SHOW COLUMNS FROM invoice_item_serials;
SHOW COLUMNS FROM serial_imeis;
SHOW COLUMNS FROM stock_take_items;
SHOW COLUMNS FROM damage_items;
SHOW COLUMNS FROM task_parts;
SHOW COLUMNS FROM tasks;
SHOW COLUMNS FROM products;

-- 1. Status values used by transaction sources
SELECT 'invoices' AS source_table, status, COUNT(*) AS total FROM invoices GROUP BY status ORDER BY total DESC;
SELECT 'tasks' AS source_table, status, COUNT(*) AS total FROM tasks GROUP BY status ORDER BY total DESC;
SELECT 'purchases' AS source_table, status, COUNT(*) AS total FROM purchases GROUP BY status ORDER BY total DESC;
SELECT 'purchase_returns' AS source_table, status, COUNT(*) AS total FROM purchase_returns GROUP BY status ORDER BY total DESC;
SELECT 'stock_takes' AS source_table, status, COUNT(*) AS total FROM stock_takes GROUP BY status ORDER BY total DESC;
SELECT 'damages' AS source_table, status, COUNT(*) AS total FROM damages GROUP BY status ORDER BY total DESC;
SELECT 'returns' AS source_table, status, COUNT(*) AS total FROM returns GROUP BY status ORDER BY total DESC;

-- 2. Product aggregate vs serial aggregate for all serial products
SELECT
    p.id,
    p.sku,
    p.name,
    p.has_serial,
    p.stock_quantity AS product_stock,
    p.cost_price AS product_bq,
    p.inventory_total_cost AS product_total_cost,
    COUNT(CASE WHEN s.status = 'in_stock' THEN 1 END) AS serial_in_stock_count,
    COALESCE(SUM(CASE WHEN s.status = 'in_stock' THEN s.cost_price ELSE 0 END), 0) AS serial_in_stock_total_cost,
    COALESCE(AVG(CASE WHEN s.status = 'in_stock' THEN s.cost_price END), 0) AS serial_in_stock_avg_cost
FROM products p
LEFT JOIN serial_imeis s ON s.product_id = p.id
WHERE p.has_serial = 1
GROUP BY p.id, p.sku, p.name, p.has_serial, p.stock_quantity, p.cost_price, p.inventory_total_cost
HAVING product_stock <> serial_in_stock_count
    OR ROUND(product_total_cost, 0) <> ROUND(serial_in_stock_total_cost, 0)
    OR ROUND(product_bq, 0) <> ROUND(serial_in_stock_avg_cost, 0)
ORDER BY ABS(product_total_cost - serial_in_stock_total_cost) DESC;

-- 3. Audit product SP26032722444
SELECT
    id, sku, name, has_serial,
    stock_quantity,
    cost_price,
    inventory_total_cost,
    CASE WHEN stock_quantity > 0 THEN ROUND(inventory_total_cost / stock_quantity, 2) ELSE cost_price END AS calculated_bq
FROM products
WHERE sku = 'SP26032722444';

SELECT
    id,
    serial_number,
    status,
    repair_status,
    cost_price,
    sold_cost_price,
    invoice_id,
    sold_at,
    purchase_return_id
FROM serial_imeis
WHERE product_id = 102
ORDER BY status, id;

SELECT
    status,
    repair_status,
    COUNT(*) AS total,
    SUM(cost_price) AS total_serial_cost,
    AVG(cost_price) AS avg_serial_cost
FROM serial_imeis
WHERE product_id = 102
GROUP BY status, repair_status
ORDER BY status, repair_status;

-- 4. Invoice items of product 102
SELECT
    i.id AS invoice_id,
    i.code,
    i.created_at,
    i.status,
    ii.id AS invoice_item_id,
    ii.product_id,
    ii.quantity,
    ii.price,
    ii.cost_price,
    (ii.cost_price * ii.quantity) AS total_cost_calc
FROM invoice_items ii
JOIN invoices i ON i.id = ii.invoice_id
WHERE ii.product_id = 102
ORDER BY i.created_at ASC, i.id ASC;

-- 5. Duplicate serial invoice links
SELECT
    iis.serial_imei_id,
    s.serial_number,
    s.product_id,
    COUNT(*) AS link_count,
    GROUP_CONCAT(iis.id ORDER BY iis.id) AS link_ids,
    GROUP_CONCAT(iis.invoice_item_id ORDER BY iis.id) AS invoice_item_ids,
    GROUP_CONCAT(i.id ORDER BY iis.id) AS invoice_ids,
    GROUP_CONCAT(i.code ORDER BY iis.id SEPARATOR ' | ') AS invoice_codes,
    GROUP_CONCAT(i.status ORDER BY iis.id SEPARATOR ' | ') AS invoice_statuses
FROM invoice_item_serials iis
JOIN serial_imeis s ON s.id = iis.serial_imei_id
JOIN invoice_items ii ON ii.id = iis.invoice_item_id
JOIN invoices i ON i.id = ii.invoice_id
GROUP BY iis.serial_imei_id, s.serial_number, s.product_id
HAVING COUNT(*) > 1
ORDER BY link_count DESC, s.product_id, s.serial_number;

-- 6. Duplicate detail for known suspicious serials
SELECT
    iis.id AS link_id,
    iis.serial_imei_id,
    s.serial_number,
    s.status AS serial_status,
    s.invoice_id AS serial_invoice_id,
    iis.invoice_item_id,
    ii.invoice_id AS item_invoice_id,
    ii.product_id,
    ii.quantity,
    ii.cost_price AS invoice_item_cost_price,
    i.code AS invoice_code,
    i.status AS invoice_status,
    i.created_at AS invoice_created_at
FROM invoice_item_serials iis
JOIN serial_imeis s ON s.id = iis.serial_imei_id
JOIN invoice_items ii ON ii.id = iis.invoice_item_id
JOIN invoices i ON i.id = ii.invoice_id
WHERE iis.serial_imei_id IN (279, 394)
ORDER BY iis.serial_imei_id, i.created_at, iis.id;

-- 7. Sold serials without a completed invoice item/fallback
SELECT
    s.id AS serial_id,
    s.serial_number,
    s.product_id,
    s.status AS serial_status,
    s.invoice_id AS serial_invoice_id,
    i.code AS invoice_code,
    i.status AS invoice_status,
    ii.id AS invoice_item_id,
    iis.id AS invoice_item_serial_id
FROM serial_imeis s
LEFT JOIN invoices i ON i.id = s.invoice_id
LEFT JOIN invoice_items ii
    ON ii.invoice_id = s.invoice_id
   AND ii.product_id = s.product_id
LEFT JOIN invoice_item_serials iis
    ON iis.serial_imei_id = s.id
   AND iis.invoice_item_id = ii.id
WHERE s.status = 'sold'
  AND (
      s.invoice_id IS NULL
      OR i.id IS NULL
      OR i.status IN ('Đã hủy', 'cancelled', 'canceled', 'da_huy', 'void')
      OR ii.id IS NULL
  )
ORDER BY s.product_id, s.id;

-- 8. Invoice item serial quantity mismatch for non-cancelled invoices
SELECT
    ii.id AS invoice_item_id,
    i.id AS invoice_id,
    i.code,
    i.status,
    ii.product_id,
    ii.quantity AS item_qty,
    COUNT(DISTINCT iis.serial_imei_id) AS linked_serial_qty,
    GROUP_CONCAT(DISTINCT s.serial_number ORDER BY s.serial_number SEPARATOR ', ') AS linked_serials
FROM invoice_items ii
JOIN invoices i ON i.id = ii.invoice_id
LEFT JOIN invoice_item_serials iis ON iis.invoice_item_id = ii.id
LEFT JOIN serial_imeis s ON s.id = iis.serial_imei_id
WHERE EXISTS (
    SELECT 1 FROM products p WHERE p.id = ii.product_id AND p.has_serial = 1
)
  AND i.status NOT IN ('Đã hủy', 'cancelled', 'canceled', 'da_huy', 'void')
GROUP BY ii.id, i.id, i.code, i.status, ii.product_id, ii.quantity
HAVING item_qty <> linked_serial_qty
ORDER BY i.created_at DESC;

-- 9. Dismantled serials whose latest repair task is completed
SELECT
    s.id AS serial_id,
    s.serial_number,
    s.product_id,
    p.sku,
    p.name AS product_name,
    s.status,
    s.repair_status,
    s.invoice_id,
    s.sold_at,
    s.purchase_return_id,
    t.id AS latest_task_id,
    t.code AS latest_task_code,
    t.status AS latest_task_status,
    t.completed_at,
    t.created_at,
    t.updated_at
FROM serial_imeis s
JOIN tasks t
    ON t.id = (
        SELECT t2.id
        FROM tasks t2
        WHERE t2.serial_imei_id = s.id
          AND t2.type = 'repair'
        ORDER BY t2.id DESC
        LIMIT 1
    )
LEFT JOIN products p ON p.id = s.product_id
WHERE s.status = 'dismantled'
  AND s.invoice_id IS NULL
  AND s.sold_at IS NULL
  AND s.purchase_return_id IS NULL
  AND t.status IN ('completed', 'Hoàn thành')
ORDER BY t.completed_at DESC, s.id DESC;

-- 10. Audit serial 7HKH0Z2
SELECT
    s.id AS serial_id,
    s.serial_number,
    s.product_id,
    p.sku,
    p.name AS product_name,
    s.status,
    s.repair_status,
    s.invoice_id,
    s.sold_at,
    s.purchase_return_id,
    s.cost_price
FROM serial_imeis s
LEFT JOIN products p ON p.id = s.product_id
WHERE s.serial_number = '7HKH0Z2';

SELECT
    t.id,
    t.code,
    t.type,
    t.status,
    t.progress,
    t.serial_imei_id,
    t.product_id,
    t.created_at,
    t.completed_at,
    t.updated_at
FROM tasks t
JOIN serial_imeis s ON s.id = t.serial_imei_id
WHERE s.serial_number = '7HKH0Z2'
ORDER BY t.id DESC;

-- 11. Other possible sources affecting product 102
SELECT
    st.id AS stock_take_id,
    st.code,
    st.status,
    st.created_at,
    st.balanced_date,
    sti.id AS stock_take_item_id,
    sti.product_id,
    sti.system_stock,
    sti.actual_stock,
    sti.diff_qty,
    sti.diff_value
FROM stock_take_items sti
JOIN stock_takes st ON st.id = sti.stock_take_id
WHERE sti.product_id = 102
ORDER BY COALESCE(st.balanced_date, st.created_at), st.id;

SELECT
    d.id AS damage_id,
    d.code,
    d.status,
    d.created_at,
    di.*
FROM damage_items di
JOIN damages d ON d.id = di.damage_id
WHERE di.product_id = 102
ORDER BY d.created_at, d.id;

SELECT
    pr.id AS purchase_return_id,
    pr.code,
    pr.status,
    pr.created_at,
    pri.*
FROM purchase_return_items pri
JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
WHERE pri.product_id = 102
ORDER BY pr.created_at, pr.id;

SELECT
    t.id AS task_id,
    t.code AS task_code,
    t.status AS task_status,
    t.created_at AS task_created_at,
    t.serial_imei_id,
    tp.*
FROM task_parts tp
JOIN tasks t ON t.id = tp.task_id
WHERE tp.product_id = 102
   OR t.product_id = 102
   OR t.serial_imei_id IN (
        SELECT id FROM serial_imeis WHERE product_id = 102
   )
ORDER BY t.created_at, tp.id;
