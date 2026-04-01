<?php
$db = new PDO('sqlite:database/database.sqlite');

// Fix broken records: suppliers that got is_customer=1 by default
$stmt = $db->prepare("UPDATE customers SET is_customer = 0, is_supplier = 1 WHERE code LIKE 'NCC%' AND is_customer = 1 AND is_supplier = 0");
$stmt->execute();
echo "Fixed suppliers with wrong flags: " . $stmt->rowCount() . " records\n";

// Also fix: customers that somehow got is_supplier=1 when they shouldn't
$stmt2 = $db->prepare("UPDATE customers SET is_supplier = 0 WHERE code LIKE 'KH%' AND is_supplier = 1 AND is_customer = 1 AND supplier_debt_amount = 0 AND total_bought = 0");
$stmt2->execute();
echo "Fixed customers with wrong supplier flag: " . $stmt2->rowCount() . " records\n";

// Show current state
echo "\n--- All customers/suppliers ---\n";
$r2 = $db->query("SELECT id, code, name, is_customer, is_supplier, debt_amount, supplier_debt_amount FROM customers ORDER BY id DESC LIMIT 15");
foreach ($r2->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['id']} | {$row['code']} | {$row['name']} | cust={$row['is_customer']} | supp={$row['is_supplier']} | debt={$row['debt_amount']} | s_debt={$row['supplier_debt_amount']}\n";
}
