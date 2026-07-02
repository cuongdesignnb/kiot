# HOTFIX 24.36+24.37 Local SQL Result

Database: kiot_db
Container: sales_mysql_test
Generated: 2026-05-18T16:14:46

`	ext
+-------------+-----------------+------+-----+---------+----------------+
| Field       | Type            | Null | Key | Default | Extra          |
+-------------+-----------------+------+-----+---------+----------------+
| id          | bigint unsigned | NO   | PRI | NULL    | auto_increment |
| invoice_id  | bigint unsigned | NO   | MUL | NULL    |                |
| product_id  | bigint unsigned | YES  | MUL | NULL    |                |
| quantity    | int             | NO   |     | NULL    |                |
| price       | decimal(15,2)   | NO   |     | NULL    |                |
| cost_price  | decimal(15,2)   | NO   |     | 0.00    |                |
| serial      | text            | YES  |     | NULL    |                |
| created_at  | timestamp       | YES  |     | NULL    |                |
| updated_at  | timestamp       | YES  |     | NULL    |                |
| discount    | decimal(15,2)   | NO   |     | 0.00    |                |
| subtotal    | decimal(15,2)   | NO   |     | 0.00    |                |
| description | varchar(255)    | YES  |     | NULL    |                |
| note        | varchar(255)    | YES  |     | NULL    |                |
+-------------+-----------------+------+-----+---------+----------------+
+-----------------+-----------------+------+-----+---------+----------------+
| Field           | Type            | Null | Key | Default | Extra          |
+-----------------+-----------------+------+-----+---------+----------------+
| id              | bigint unsigned | NO   | PRI | NULL    | auto_increment |
| invoice_item_id | bigint unsigned | NO   | MUL | NULL    |                |
| serial_imei_id  | bigint unsigned | YES  | MUL | NULL    |                |
| serial_number   | varchar(255)    | NO   | MUL | NULL    |                |
| cost_price      | decimal(18,0)   | NO   |     | 0       |                |
| created_at      | timestamp       | YES  |     | NULL    |                |
| updated_at      | timestamp       | YES  |     | NULL    |                |
+-----------------+-----------------+------+-----+---------+----------------+
+---------------------+-------------------------------------------------------------------------------------------------------------------+------+-----+----------+----------------+
| Field               | Type                                                                                                              | Null | Key | Default  | Extra          |
+---------------------+-------------------------------------------------------------------------------------------------------------------+------+-----+----------+----------------+
| id                  | bigint unsigned                                                                                                   | NO   | PRI | NULL     | auto_increment |
| product_id          | bigint unsigned                                                                                                   | NO   | MUL | NULL     |                |
| variant_id          | bigint unsigned                                                                                                   | YES  | MUL | NULL     |                |
| serial_number       | varchar(255)                                                                                                      | NO   | UNI | NULL     |                |
| status              | enum('in_stock','sold','returning','warranty','defective','returned','used_for_repair','dismantled','in_transit') | NO   |     | in_stock |                |
| sold_at             | timestamp                                                                                                         | YES  |     | NULL     |                |
| invoice_id          | bigint unsigned                                                                                                   | YES  |     | NULL     |                |
| warranty_expires_at | timestamp                                                                                                         | YES  |     | NULL     |                |
| repair_status       | enum('not_started','repairing','ready')                                                                           | YES  |     | NULL     |                |
| cost_price          | decimal(18,0)                                                                                                     | NO   |     | 0        |                |
| original_cost       | decimal(18,0)                                                                                                     | NO   |     | 0        |                |
| sold_cost_price     | decimal(18,0)                                                                                                     | YES  |     | NULL     |                |
| purchase_id         | bigint unsigned                                                                                                   | YES  | MUL | NULL     |                |
| purchase_return_id  | bigint unsigned                                                                                                   | YES  | MUL | NULL     |                |
| created_at          | timestamp                                                                                                         | YES  |     | NULL     |                |
| updated_at          | timestamp                                                                                                         | YES  |     | NULL     |                |
+---------------------+-------------------------------------------------------------------------------------------------------------------+------+-----+----------+----------------+
+---------------+-----------------+------+-----+---------+----------------+
| Field         | Type            | Null | Key | Default | Extra          |
+---------------+-----------------+------+-----+---------+----------------+
| id            | bigint unsigned | NO   | PRI | NULL    | auto_increment |
| stock_take_id | bigint unsigned | NO   | MUL | NULL    |                |
| product_id    | bigint unsigned | NO   | MUL | NULL    |                |
| system_stock  | int             | NO   |     | 0       |                |
| actual_stock  | int             | NO   |     | 0       |                |
| diff_qty      | int             | NO   |     | 0       |                |
| diff_value    | decimal(15,2)   | NO   |     | 0.00    |                |
| created_at    | timestamp       | YES  |     | NULL    |                |
| updated_at    | timestamp       | YES  |     | NULL    |                |
| deleted_at    | timestamp       | YES  |     | NULL    |                |
+---------------+-----------------+------+-----+---------+----------------+
+-------------+-----------------+------+-----+---------+----------------+
| Field       | Type            | Null | Key | Default | Extra          |
+-------------+-----------------+------+-----+---------+----------------+
| id          | bigint unsigned | NO   | PRI | NULL    | auto_increment |
| damage_id   | bigint unsigned | NO   | MUL | NULL    |                |
| product_id  | bigint unsigned | NO   | MUL | NULL    |                |
| serial_ids  | longtext        | YES  |     | NULL    |                |
| qty         | int             | NO   |     | 0       |                |
| cost_price  | decimal(15,2)   | NO   |     | 0.00    |                |
| total_value | decimal(15,2)   | NO   |     | 0.00    |                |
| note        | varchar(255)    | YES  |     | NULL    |                |
| created_at  | timestamp       | YES  |     | NULL    |                |
| updated_at  | timestamp       | YES  |     | NULL    |                |
| deleted_at  | timestamp       | YES  |     | NULL    |                |
+-------------+-----------------+------+-----+---------+----------------+
+-------------+-----------------+------+-----+---------+----------------+
| Field       | Type            | Null | Key | Default | Extra          |
+-------------+-----------------+------+-----+---------+----------------+
| id          | bigint unsigned | NO   | PRI | NULL    | auto_increment |
| task_id     | bigint unsigned | NO   | MUL | NULL    |                |
| product_id  | bigint unsigned | NO   | MUL | NULL    |                |
| quantity    | int             | NO   |     | 1       |                |
| unit_cost   | decimal(18,0)   | NO   |     | 0       |                |
| sale_price  | decimal(15,2)   | YES  |     | 0.00    |                |
| total_cost  | decimal(18,0)   | NO   |     | 0       |                |
| exported_by | bigint unsigned | YES  | MUL | NULL    |                |
| notes       | text            | YES  |     | NULL    |                |
| direction   | varchar(10)     | NO   |     | export  |                |
| serial_ids  | longtext        | YES  |     | NULL    |                |
| created_at  | timestamp       | YES  |     | NULL    |                |
| updated_at  | timestamp       | YES  |     | NULL    |                |
+-------------+-----------------+------+-----+---------+----------------+
+-------------------------+------------------+------+-----+---------+----------------+
| Field                   | Type             | Null | Key | Default | Extra          |
+-------------------------+------------------+------+-----+---------+----------------+
| id                      | bigint unsigned  | NO   | PRI | NULL    | auto_increment |
| code                    | varchar(255)     | NO   | UNI | NULL    |                |
| type                    | varchar(20)      | NO   | MUL | general |                |
| title                   | varchar(255)     | YES  |     | NULL    |                |
| category_id             | bigint unsigned  | YES  | MUL | NULL    |                |
| product_id              | bigint unsigned  | YES  | MUL | NULL    |                |
| serial_imei_id          | bigint unsigned  | YES  | MUL | NULL    |                |
| original_cost           | decimal(18,0)    | NO   |     | 0       |                |
| parts_cost              | decimal(18,0)    | NO   |     | 0       |                |
| total_cost              | decimal(18,0)    | NO   |     | 0       |                |
| issue_description       | text             | YES  |     | NULL    |                |
| priority                | varchar(10)      | NO   | MUL | normal  |                |
| progress                | tinyint unsigned | NO   |     | 0       |                |
| status                  | varchar(20)      | NO   | MUL | pending |                |
| external                | tinyint(1)       | NO   |     | 0       |                |
| sub_status              | varchar(30)      | YES  |     | NULL    |                |
| customer_id             | bigint unsigned  | YES  | MUL | NULL    |                |
| customer_name           | varchar(255)     | YES  |     | NULL    |                |
| customer_phone          | varchar(30)      | YES  |     | NULL    |                |
| warranty_id             | bigint unsigned  | YES  | MUL | NULL    |                |
| warranty_policy         | varchar(20)      | YES  |     | NULL    |                |
| invoice_id              | bigint unsigned  | YES  | MUL | NULL    |                |
| received_at             | timestamp        | YES  |     | NULL    |                |
| returned_at             | timestamp        | YES  |     | NULL    |                |
| labor_fee               | decimal(15,2)    | NO   |     | 0.00    |                |
| parts_total             | decimal(15,2)    | NO   |     | 0.00    |                |
| total_amount            | decimal(15,2)    | NO   |     | 0.00    |                |
| paid_amount             | decimal(15,2)    | NO   |     | 0.00    |                |
| debt_amount             | decimal(15,2)    | NO   |     | 0.00    |                |
| warranty_covered_amount | decimal(15,2)    | NO   |     | 0.00    |                |
| customer_payable_amount | decimal(15,2)    | NO   |     | 0.00    |                |
| assigned_employee_id    | bigint unsigned  | YES  | MUL | NULL    |                |
| assigned_at             | timestamp        | YES  |     | NULL    |                |
| completed_at            | timestamp        | YES  |     | NULL    |                |
| cancelled_at            | timestamp        | YES  |     | NULL    |                |
| branch_id               | bigint unsigned  | YES  | MUL | NULL    |                |
| notes                   | text             | YES  |     | NULL    |                |
| deadline                | date             | YES  |     | NULL    |                |
| created_by              | bigint unsigned  | YES  | MUL | NULL    |                |
| created_at              | timestamp        | YES  |     | NULL    |                |
| updated_at              | timestamp        | YES  |     | NULL    |                |
+-------------------------+------------------+------+-----+---------+----------------+
+--------------------------+---------------------------------------------------+------+-----+----------+----------------+
| Field                    | Type                                              | Null | Key | Default  | Extra          |
+--------------------------+---------------------------------------------------+------+-----+----------+----------------+
| id                       | bigint unsigned                                   | NO   | PRI | NULL     | auto_increment |
| sku                      | varchar(255)                                      | NO   | UNI | NULL     |                |
| barcode                  | varchar(255)                                      | YES  | UNI | NULL     |                |
| name                     | varchar(255)                                      | NO   |     | NULL     |                |
| type                     | enum('standard','service','combo','manufactured') | NO   |     | standard |                |
| category_id              | bigint unsigned                                   | YES  | MUL | NULL     |                |
| brand_id                 | bigint unsigned                                   | YES  | MUL | NULL     |                |
| cost_price               | decimal(15,2)                                     | NO   |     | 0.00     |                |
| inventory_total_cost     | decimal(20,2)                                     | NO   |     | 0.00     |                |
| last_purchase_price      | decimal(15,2)                                     | NO   |     | 0.00     |                |
| retail_price             | decimal(15,2)                                     | NO   |     | 0.00     |                |
| technician_price         | decimal(15,2)                                     | NO   |     | 0.00     |                |
| stock_quantity           | int                                               | NO   |     | 0        |                |
| min_stock                | int                                               | NO   |     | 0        |                |
| max_stock                | int                                               | YES  |     | NULL     |                |
| has_serial               | tinyint(1)                                        | NO   |     | 0        |                |
| has_variants             | tinyint(1)                                        | NO   |     | 0        |                |
| is_active                | tinyint(1)                                        | NO   |     | 1        |                |
| allow_point_accumulation | tinyint(1)                                        | NO   |     | 1        |                |
| sell_directly            | tinyint(1)                                        | NO   |     | 1        |                |
| image                    | varchar(255)                                      | YES  |     | NULL     |                |
| description              | text                                              | YES  |     | NULL     |                |
| weight                   | varchar(255)                                      | YES  |     | NULL     |                |
| warranty_months          | int unsigned                                      | YES  |     | NULL     |                |
| warranty_policies        | longtext                                          | YES  |     | NULL     |                |
| maintenance_policies     | longtext                                          | YES  |     | NULL     |                |
| location                 | varchar(255)                                      | YES  |     | NULL     |                |
| created_at               | timestamp                                         | YES  |     | NULL     |                |
| updated_at               | timestamp                                         | YES  |     | NULL     |                |
| deleted_at               | timestamp                                         | YES  |     | NULL     |                |
+--------------------------+---------------------------------------------------+------+-----+----------+----------------+
+--------------+------------+-------+
| source_table | status     | total |
+--------------+------------+-------+
| invoices     | Ho�n th�nh |   159 |
| invoices     | ?� h?y     |     4 |
+--------------+------------+-------+
+--------------+-------------+-------+
| source_table | status      | total |
+--------------+-------------+-------+
| tasks        | completed   |   230 |
| tasks        | in_progress |   135 |
| tasks        | pending     |    24 |
| tasks        | cancelled   |     6 |
+--------------+-------------+-------+
+--------------+-----------+-------+
| source_table | status    | total |
+--------------+-----------+-------+
| purchases    | completed |   263 |
| purchases    | cancelled |     8 |
| purchases    | returned  |     1 |
+--------------+-----------+-------+
+------------------+-----------+-------+
| source_table     | status    | total |
+------------------+-----------+-------+
| purchase_returns | cancelled |     3 |
| purchase_returns | completed |     1 |
+------------------+-----------+-------+
+--------------+--------+-------+
| source_table | status | total |
+--------------+--------+-------+
| stock_takes  | draft  |     1 |
+--------------+--------+-------+
+--------------+--------+-------+
| source_table | status | total |
+--------------+--------+-------+
| returns      | ?� tr? |     2 |
| returns      | ?� h?y |     1 |
+--------------+--------+-------+
+-----+---------------+-----------------------------------------------------------------------------------------------------+------------+---------------+-------------+--------------------+-----------------------+----------------------------+--------------------------+
| id  | sku           | name                                                                                                | has_serial | product_stock | product_bq  | product_total_cost | serial_in_stock_count | serial_in_stock_total_cost | serial_in_stock_avg_cost |
+-----+---------------+-----------------------------------------------------------------------------------------------------+------------+---------------+-------------+--------------------+-----------------------+----------------------------+--------------------------+
|  15 | SP26031726235 | Dell Latitude 5310 Core i5-10310u/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3"                               |          1 |             6 | 10990356.77 |        65942140.63 |                     6 |                   35135909 |             5855984.8333 |
| 123 | SP26032734927 | Macbook air M1 Ram 8GB/SSD 256GB/13.3"                                                              |          1 |             0 |  7333333.33 |        22000000.00 |                     0 |                          0 |                   0.0000 |
| 102 | SP26032722444 | HP Probook 450G7 core i5-10210U/Ram 8/SSD 256/15.6" FHD                                             |          1 |             1 | 17395000.00 |        17395000.00 |                     1 |                    4400000 |             4400000.0000 |
|  13 | SP26031717682 | Asus FA506 Ryzen 7 7445HS/Ram 16GB/SSD 512GB/RTX 3050/15.6" 144Hz                                   |          1 |             0 | 12000000.00 |        12000000.00 |                     0 |                          0 |                   0.0000 |
| 110 | SP26032720839 | Acer Nitro ANV15-51 Core I7-13620H/Ram 16GB/SSD 256GB/Card RTX2050/15.6"FHD 144Hz                   |          1 |             0 | 12000000.00 |        12000000.00 |                     0 |                          0 |                   0.0000 |
| 125 | SP26032712735 | Macbook Pro M1 Ram 8/SSD 256/13.3inch Touch Bar                                                     |          1 |             0 | 11000000.00 |        11000000.00 |                     0 |                          0 |                   0.0000 |
| 323 | SP26050627868 | Dell Latitude E7390 Core i5-8250U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3"                               |          1 |            12 |  4176294.56 |        45939240.14 |                    12 |                   35356160 |             2946346.6667 |
| 127 | SP26032753278 | Lenovo Slim 15 Core i7-1362uHQ/Ram 16GB/SSD 512GB/15.6"FHD                                          |          1 |             0 |  8500000.00 |         8500000.00 |                     0 |                          0 |                   0.0000 |
|   3 | SP260315720   | Dell 7210 2-in 1 core i7-10610u/Ram16GB/SSD 256GB/14inch c?m ?ng xoay g?p 360                       |          1 |             1 |  8000000.00 |               0.00 |                     1 |                    8000000 |             8000000.0000 |
| 287 | SP26042116982 | HP Victus 16-D0298TX core I5-11400H/Ram 8GB/SSD 512GB/Card 1650/m�n 15.6"                           |          1 |             0 |  8000000.00 |         8000000.00 |                     0 |                          0 |                   0.0000 |
| 231 | SP26041066020 | Hp EliteBook 830 G6 Core i5-8265U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3"                               |          1 |             2 |  4044470.50 |        16177882.00 |                     2 |                    9177882 |             4588941.0000 |
| 258 | SP26041290851 | Dell Precision 3530 Xeon E-2176M/ Ram 8GB/ SSD 240Gb/ VGA P600/ M�n h�nh 15.6" FHD                  |          1 |             0 |  3300000.00 |         6600000.00 |                     0 |                          0 |                   0.0000 |
| 198 | SP26040479023 | Dell Latitude 7420 core i5-1145G7/Ram 8GB/SSD 256GB/m�n h�nh 14inch                                 |          1 |             0 |  5750000.00 |         5750000.00 |                     0 |                          0 |                   0.0000 |
| 153 | SP26033029916 | Thinkpad X1 Extreme Gen 2 Core i7 9750H, Ram 16GB, SSD 512GB, RTX 1650Ti, 15.6? 4K                  |          1 |             0 |  5700000.00 |         5700000.00 |                     0 |                          0 |                   0.0000 |
|  21 | SP26031825268 | Acer Swift SF314-512 core i5-1240P/Ram 16GB/SSD 512GB/14"FHD                                        |          1 |             0 |  5500000.00 |         5500000.00 |                     0 |                          0 |                   0.0000 |
| 283 | SP26042163708 | HP 15-FD0306TU Core I3-1315U/Ram 8GB/SSD 256GB/m�n h�nh 15.6"                                       |          1 |             0 |  5300000.00 |         5300000.00 |                     0 |                          0 |                   0.0000 |
| 130 | SP26032743449 | Lenovo ideapd 5 Core i7-1165G7/Ram 8GB/SSD 256GB/14"FHD                                             |          1 |             0 |  5200000.00 |         5200000.00 |                     0 |                          0 |                   0.0000 |
| 259 | SP26041259255 | Dell Latitude E3490 Core i5-8250U/ Ram 8GB/ SSD 240Gb/ M�n h�nh 14.0" HD                            |          1 |             0 |  2600000.00 |         5200000.00 |                     0 |                          0 |                   0.0000 |
| 194 | SP26040356785 | HP 250G8 Core I5-1135G5/Ram 8/SSD 256/m�n h�nh 15.6"                                                |          1 |             0 |  5000000.00 |         5000000.00 |                     0 |                          0 |                   0.0000 |
| 289 | SP26042179413 | Hp Pavilion x360 core i3-1125G4/Ram 4GB/SSD 256/m�n h�nh 14"                                        |          1 |             0 |  4500000.00 |         4500000.00 |                     0 |                          0 |                   0.0000 |
| 284 | SP26042126814 | Dell Precision 5530 core i7-8706G/Ram 16GB/SSD 512GB/VGA MD Radeon Pro WX/m�n h�nh 15.6"            |          1 |             0 |  4500000.00 |         4500000.00 |                     0 |                          0 |                   0.0000 |
| 203 | SP26040467075 | Surface Pro 6 i5-8350u/Ram 8GB/SSD 128GB/m�n h�nh 12.5" c?m ?ng                                     |          1 |             4 |  5500000.00 |        22000000.00 |                     4 |                   18100000 |             4525000.0000 |
|  24 | SP26031868136 | HP Pavilion 14-dv0520TU Core i3-1125G4/Ram 8/SSD 128GB/14"FHD                                       |          1 |             0 |  3800000.00 |         3800000.00 |                     0 |                          0 |                   0.0000 |
|  22 | SP26031809611 | HP 15S-FQ2XX Core i3-1115G4/Ram 8/SSD 256/15.6"FHD                                                  |          1 |             0 |  3700000.00 |         3700000.00 |                     0 |                          0 |                   0.0000 |
| 111 | SP26032705225 | Asus vivobook X412DA RYZEN 5 3500/Ram 8GB/SSD 128GB/14"FHD                                          |          1 |             0 |  3550000.00 |         3550000.00 |                     0 |                          0 |                   0.0000 |
|  20 | SP26031895317 | DELL Latitude 7490 i5-8250u/Ram 8GB/SSD 256GB/14"FHD                                                |          1 |             0 |  3500000.00 |         3500000.00 |                     0 |                          0 |                   0.0000 |
| 199 | SP26040489821 | HP Probook 450G5 Core i5-8250U/Ram 8GB/SSD 128GB/m�n h�nh 15.6"                                     |          1 |             1 |  4162500.00 |         8325000.00 |                     1 |                    4925000 |             4925000.0000 |
| 254 | SP26041223982 | Hp Elitebook 840 G6 Core i5-8365U/ Ram 8GB/ SSD 256GB/ M�n h�nh 14.0" FHD                           |          1 |             2 |  5122565.64 |        10245131.28 |                     2 |                    6865132 |             3432566.0000 |
| 200 | SP26040434055 | Surface Laptop i5-7300u/Ram 8GB/SSD 128GB/m�n h�nh 13.5"2k c?m ?ng                                  |          1 |             5 |  3200000.00 |        19200000.00 |                     5 |                   16000000 |             3200000.0000 |
| 272 | SP26041829540 | SAMSUNG BOOK3 GO Snapdragon 7 gen 3/ram 4/ssd 128/14"HD                                             |          1 |             0 |  3000000.00 |         3000000.00 |                     0 |                          0 |                   0.0000 |
| 248 | SP26041098485 | Hp Elitebook 830 G5 Core i5-8250U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3"                               |          1 |             3 |  3948674.25 |        15794697.00 |                     3 |                   12794697 |             4264899.0000 |
| 117 | SP26032745415 | DELL Latitude 3400 Core i3-1115G4/Ram 4GB/SSD 128GB/14"FHD                                          |          1 |             0 |  2950000.00 |         2950000.00 |                     0 |                          0 |                   0.0000 |
| 131 | SP26032780329 | Dell Latitude 7300 Core i5-8265u/Ram 8GB/SSD 256GB/13.3"HD                                          |          1 |             0 |  2900000.00 |         2900000.00 |                     0 |                          0 |                   0.0000 |
| 213 | SP26040834731 | Thinkpad X1 Yoga Gen 1 I5-6200U/Ram 8GB/SSD 128GB/m�n 14"FHD C?M ?NG xoay g?p 360                   |          1 |            32 |  3230250.00 |       103368000.00 |                    32 |                  100568000 |             3142750.0000 |
| 121 | SP26032760131 | Dell Latitude Core I7-6600U/Ram 4GB/SSD 128GB/14"FHD                                                |          1 |             0 |  2800000.00 |         2800000.00 |                     0 |                          0 |                   0.0000 |
| 120 | SP26032785830 | Acer Aspire 514-54 I3-11145G4/Ram 4GB/SSD 128GB/14"FHD                                              |          1 |             0 |  2670000.00 |         2670000.00 |                     0 |                          0 |                   0.0000 |
| 260 | SP26041209175 | Dell Latitude E3400 Core i5-8265U/ Ram 8GB/ SSD 240GB/ M�n h�nh 14.0" HD                            |          1 |             0 |  2600000.00 |         2600000.00 |                     0 |                          0 |                   0.0000 |
| 195 | SP26040368754 | HP 15-DY Core I5-1135G7/Ram 8GB/SSD 256GB/m�n h�nh 15.6"                                            |          1 |             0 |  2550000.00 |         2550000.00 |                     0 |                          0 |                   0.0000 |
| 116 | SP26032707586 | LENOVO ideapad S340 I3-1005G1/Ram 4GB/SSD 128GB/14"FHD                                              |          1 |             0 |  2530000.00 |         2530000.00 |                     0 |                          0 |                   0.0000 |
| 119 | SP26032730831 | DELL Latitude 5570 Core I7-6820HQ/4/128/15.6"FHD                                                    |          1 |             0 |  2500000.00 |         2500000.00 |                     0 |                          0 |                   0.0000 |
| 278 | SP26042175059 | MSI Morden 14 B10MW core I3-10110U/Ram 8GB/SSD 128/14"FHD                                           |          1 |             0 |  2500000.00 |         2500000.00 |                     0 |                          0 |                   0.0000 |
| 211 | SP26040865882 | HP 430G6 core i3-8145u/Ram 4GB/SSD 128GB/m�n 13.3 inch                                              |          1 |             0 |  2500000.00 |         2500000.00 |                     0 |                          0 |                   0.0000 |
| 261 | SP26041242972 | Hp Probook 440 G5 Core i5-8250U/ Ram 8GB/ SSD 240GB/ M�n h�nh 14.0" HD                              |          1 |             4 |  2318121.20 |        11590606.00 |                     4 |                    9140606 |             2285151.5000 |
| 126 | SP26032798015 | Asus Vivobook X505 i5-4210/Ram 4GB/SSD 128GB/15.6"HD                                                |          1 |             0 |  2000000.00 |         2000000.00 |                     0 |                          0 |                   0.0000 |
| 204 | SP26040619288 | HP MT44 Ryzen 3 Pro 2300u/ Ram 8GB/ SSD 256GB/ M�n h�nh 14.0"                                       |          1 |             0 |  1600000.00 |         1600000.00 |                     0 |                          0 |                   0.0000 |
| 129 | SP26032775474 | Asus S451LA Ram 4/SSD 128/14"HD                                                                     |          1 |             0 |  1500000.00 |         1500000.00 |                     0 |                          0 |                   0.0000 |
| 115 | SP26032736639 | HP 240G8 Core i3-1005G1/Ram 4GB/SSD 256GB/14"                                                       |          1 |             1 |  3364649.50 |         3364649.50 |                     1 |                    2000000 |             2000000.0000 |
| 300 | SP26042584534 | HP Probook 430 G5 Core i3-7020U/ Ram 8GB/ SSD 128GB/ M�n h�nh 13.3"                                 |          1 |             1 |  3150000.00 |         3150000.00 |                     1 |                    1800000 |             1800000.0000 |
| 346 | SP26051226388 | Macbook Air 2017 core i5 Ram 8GB SSD 256GB/M�n h�nh 13.3"                                           |          1 |             1 |  2300000.00 |         2300000.00 |                     1 |                    3350000 |             3350000.0000 |
| 128 | SP26032788989 | Macbook air 2013 Ram 4/SSD 128/11 inch                                                              |          1 |             0 |  1000000.00 |         1000000.00 |                     0 |                          0 |                   0.0000 |
| 193 | SP26040316054 | Hp Probook 430 G6 Core i5-8250U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3"                                 |          1 |             2 |  3375018.32 |         6750036.63 |                     2 |                    6282727 |             3141363.5000 |
| 321 | SP26050642223 | Dell Precision 3560 Core i7-1185G7/ Ram 16GB/ SSD 256GB/ VGA Quadro T500 Mobile/ M�n h�nh 15.6" FHD |          1 |            10 |  6724694.35 |        67246943.48 |                    10 |                   67606943 |             6760694.3000 |
| 104 | SP26032793307 | HDD LAPTOP 2.5 1TB (B�c m�y)                                                                        |          1 |             1 |   300000.00 |          300000.00 |                     0 |                          0 |                   0.0000 |
|  61 | SP26032104842 | Macbook Pro 2017 core i5/Ram 8GB/SSD 256GB/m�n 13.3inch                                             |          1 |             1 |  3250000.00 |         3250000.00 |                     1 |                    3000000 |             3000000.0000 |
| 230 | SP26041027709 | Hp Elitebook 830 G5 Core i7-8550u/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3"                               |          1 |            17 |  4877948.37 |        82925122.31 |                    17 |                   82763967 |             4868468.6471 |
| 184 | SP26040184465 | Hp Probook 450 G7 Core i5-10210u/ Ram 8GB/ SSD 256GB/ M�n h�nh 15.6"                                |          1 |             4 |  5233553.85 |        20934215.40 |                     4 |                   20811168 |             5202792.0000 |
| 255 | SP26041228294 | Hp Elitebook 840 G5 Core i5-8350U/ Ram 8GB/ SSD 256GB/ M�n h�nh 14.0" FHD                           |          1 |             1 |  2980000.00 |         2980000.00 |                     1 |                    2900000 |             2900000.0000 |
|  48 | SP26032084612 | Thinkpad L13 Yoga gen 2 Core i5-1135G7/Ram 8GB/SSD 256GB/13.3"FHD C?M ?NG xoay g?p 360              |          1 |             9 |  5264260.00 |        47378340.00 |                     9 |                   47302600 |             5255844.4444 |
|   9 | SP260316662   | Asus FA506 Ryzen 7 7445HS/Ram16/SSD 512GB/RTX 3050/15.6" 144Hz                                      |          1 |             0 | 12000000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|   2 | SP260312830   | Dell Latitude 7210 2-in 1 Core i7-10610u/Ram 16GB/SSD 256GB/14"c?m ?ng xoay g?p 360                 |          1 |             0 |  8000000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 341 | SP26051238396 | Asus Vivobook X515FA core i3-1115G4/Ram 8GB/SSD 256GB/M�n h�nh 15.6"                                |          1 |             0 |  3400000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 330 | SP26050943905 | Dell Latitude 7320 detachable 2in1 core i5-1140G7/Ram 8Gb/SSD 256Gb/M�n h�nh 13.3"                  |          1 |             0 |  3914380.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 329 | SP26050935325 | HP Laptop 14s pentium N5000/Ram 4gb/SSD 128gb/M�n h�nh 14"                                          |          1 |             0 |   500000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|   7 | SP260316190   | Lenovo Thinkpad T480s Core i5-8250u, Ram 8GB, SSD 256GB, m�n 14.0" FHD IPS                          |          1 |             0 |  3900000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 190 | SP26040218810 | Jack Type C L13 Yoga Gen 2                                                                          |          1 |             0 |    40000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 319 | SP26050551718 | HP 430G5 core i3-7020U/Ram 8Gb/SSD 128Gb/M�n 13.3"                                                  |          1 |             0 |  1800000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 316 | SP26050402780 | MSI GF63 core i7-9750H,Ram 16GB,SSD 512GB,Card 1650Ti, m�n h�nh 15.6inch                            |          1 |             0 |  3000000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 314 | SP26050479428 | Laptop MCC AP56 core i3-1215u/Ram 12GB/SSD 512GB/14inch FHD                                         |          1 |             0 |  3500000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 313 | SP26050498388 | Surface Laptop Go core i5-1035G1/Ram 8GB/SSD 256GB/12,3"touch                                       |          1 |             0 |  4500000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|  17 | SP26031829970 | Microsoft Surface Pro 7 Core i5-1035G4/Ram 8GB/SSD 128GB                                            |          1 |             0 |  6700000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|  83 | SP26032699293 | Asus Vivobook X512FAC Core i5-10210U/ Ram 8GB/ SSD256/ M�n h�nh 15.6" FHD                           |          1 |             0 |  3300584.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|  90 | SP26032620834 | MSI GL62 7QF Core i5-7300HQ/ Ram 8GB/ Ssd 256GB/ GTX 1050/ M�n h�nh 15.6" Full HD                   |          1 |             0 |  2640000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|  96 | SP26032728477 | M�n 14.0 HD c� tai 30p C? spa                                                                       |          1 |             0 |   400000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
|  97 | SP26032762822 | M�n 14.0 HD c� tai 30p C? spa                                                                       |          1 |             0 |   400000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
| 253 | SP26041290828 | Test                                                                                                |          1 |            -2 | 14000000.00 |        14000000.00 |                     1 |                   14000000 |            14000000.0000 |
| 138 | SP26032870063 | S?n v? m?t D Hp probook 450G5                                                                       |          1 |             0 |   120000.00 |               0.00 |                     0 |                          0 |                   0.0000 |
+-----+---------------+-----------------------------------------------------------------------------------------------------+------------+---------------+-------------+--------------------+-----------------------+----------------------------+--------------------------+
+-----+---------------+---------------------------------------------------------+------------+----------------+-------------+----------------------+---------------+
| id  | sku           | name                                                    | has_serial | stock_quantity | cost_price  | inventory_total_cost | calculated_bq |
+-----+---------------+---------------------------------------------------------+------------+----------------+-------------+----------------------+---------------+
| 102 | SP26032722444 | HP Probook 450G7 core i5-10210U/Ram 8/SSD 256/15.6" FHD |          1 |              1 | 17395000.00 |          17395000.00 |   17395000.00 |
+-----+---------------+---------------------------------------------------------+------------+----------------+-------------+----------------------+---------------+
+-----+---------------+----------+---------------+------------+-----------------+------------+---------------------+--------------------+
| id  | serial_number | status   | repair_status | cost_price | sold_cost_price | invoice_id | sold_at             | purchase_return_id |
+-----+---------------+----------+---------------+------------+-----------------+------------+---------------------+--------------------+
|  59 | 5CD024K671    | in_stock | NULL          |    4400000 |            NULL |       NULL | NULL                |               NULL |
|  55 | 5CD0361JTP    | sold     | NULL          |    4400000 |               0 |         14 | 2026-03-27 08:28:01 |               NULL |
|  56 | 5CD030GMX1    | sold     | NULL          |    4400000 |               0 |         15 | 2026-03-27 08:29:14 |               NULL |
|  57 | 5CD0361JVJ    | sold     | NULL          |    4400000 |               0 |         13 | 2026-03-27 07:19:35 |               NULL |
|  58 | 5CD0361JRR    | sold     | NULL          |    4400000 |               0 |         16 | 2026-03-27 08:30:09 |               NULL |
|  60 | 5CD0361JRF    | sold     | NULL          |    4400000 |               0 |         11 | 2026-03-27 07:17:00 |               NULL |
|  61 | 5CD0361JLW    | sold     | NULL          |    4400000 |               0 |         12 | 2026-03-27 07:18:50 |               NULL |
| 279 | 5CD0166WMD    | sold     | NULL          |    3990000 |        17395000 |        172 | 2026-05-16 07:14:59 |               NULL |
+-----+---------------+----------+---------------+------------+-----------------+------------+---------------------+--------------------+
+----------+---------------+-------+-------------------+-----------------+
| status   | repair_status | total | total_serial_cost | avg_serial_cost |
+----------+---------------+-------+-------------------+-----------------+
| in_stock | NULL          |     1 |           4400000 |    4400000.0000 |
| sold     | NULL          |     7 |          30390000 |    4341428.5714 |
+----------+---------------+-------+-------------------+-----------------+
+------------+----------------+---------------------+------------+-----------------+------------+----------+------------+-------------+-----------------+
| invoice_id | code           | created_at          | status     | invoice_item_id | product_id | quantity | price      | cost_price  | total_cost_calc |
+------------+----------------+---------------------+------------+-----------------+------------+----------+------------+-------------+-----------------+
|         11 | HD177459582033 | 2026-03-09 09:15:00 | Ho�n th�nh |              17 |        102 |        1 | 4684000.00 |        0.00 |            0.00 |
|         14 | HD177460008188 | 2026-03-10 09:15:00 | Ho�n th�nh |              20 |        102 |        1 | 5650000.00 |        0.00 |            0.00 |
|         15 | HD177460015338 | 2026-03-10 09:15:00 | Ho�n th�nh |              21 |        102 |        1 | 5500000.00 |        0.00 |            0.00 |
|         12 | HD177459593057 | 2026-03-19 09:15:00 | Ho�n th�nh |              18 |        102 |        1 | 5500000.00 |        0.00 |            0.00 |
|         16 | HD177460020949 | 2026-03-20 09:15:00 | Ho�n th�nh |              22 |        102 |        1 | 5500000.00 |        0.00 |            0.00 |
|         13 | HD177459597581 | 2026-03-26 09:15:00 | Ho�n th�nh |              19 |        102 |        1 | 5500000.00 |        0.00 |            0.00 |
|        172 | HD177891569817 | 2026-05-08 07:13:00 | Ho�n th�nh |             362 |        102 |        1 | 5500000.00 | 17395000.00 |     17395000.00 |
|        161 | HD177831464785 | 2026-05-08 08:12:00 | ?� h?y     |             337 |        102 |        1 | 5500000.00 |  4348750.00 |      4348750.00 |
+------------+----------------+---------------------+------------+-----------------+------------+----------+------------+-------------+-----------------+
+----------------+---------------+------------+------------+----------+------------------+-------------+---------------------------------+---------------------+
| serial_imei_id | serial_number | product_id | link_count | link_ids | invoice_item_ids | invoice_ids | invoice_codes                   | invoice_statuses    |
+----------------+---------------+------------+------------+----------+------------------+-------------+---------------------------------+---------------------+
|            279 | 5CD0166WMD    |        102 |          2 | 35,48    | 337,362          | 161,172     | HD177831464785 | HD177891569817 | ?� h?y | Ho�n th�nh |
|            394 | BB499Y2       |        323 |          2 | 34,47    | 336,360          | 161,172     | HD177831464785 | HD177891569817 | ?� h?y | Ho�n th�nh |
+----------------+---------------+------------+------------+----------+------------------+-------------+---------------------------------+---------------------+
+---------+----------------+---------------+---------------+-------------------+-----------------+-----------------+------------+----------+-------------------------+----------------+----------------+---------------------+
| link_id | serial_imei_id | serial_number | serial_status | serial_invoice_id | invoice_item_id | item_invoice_id | product_id | quantity | invoice_item_cost_price | invoice_code   | invoice_status | invoice_created_at  |
+---------+----------------+---------------+---------------+-------------------+-----------------+-----------------+------------+----------+-------------------------+----------------+----------------+---------------------+
|      48 |            279 | 5CD0166WMD    | sold          |               172 |             362 |             172 |        102 |        1 |             17395000.00 | HD177891569817 | Ho�n th�nh     | 2026-05-08 07:13:00 |
|      35 |            279 | 5CD0166WMD    | sold          |               172 |             337 |             161 |        102 |        1 |              4348750.00 | HD177831464785 | ?� h?y         | 2026-05-08 08:12:00 |
|      47 |            394 | BB499Y2       | sold          |               172 |             360 |             172 |        323 |        1 |             15893080.01 | HD177891569817 | Ho�n th�nh     | 2026-05-08 07:13:00 |
|      34 |            394 | BB499Y2       | sold          |               172 |             336 |             161 |        323 |        1 |              3319441.91 | HD177831464785 | ?� h?y         | 2026-05-08 08:12:00 |
+---------+----------------+---------------+---------------+-------------------+-----------------+-----------------+------------+----------+-------------------------+----------------+----------------+---------------------+
+-----------------+------------+----------------+------------+------------+----------+-------------------+----------------+
| invoice_item_id | invoice_id | code           | status     | product_id | item_qty | linked_serial_qty | linked_serials |
+-----------------+------------+----------------+------------+------------+----------+-------------------+----------------+
|             257 |        127 | HD177727497421 | Ho�n th�nh |        151 |        1 |                 0 | NULL           |
|             263 |        131 | HD177734325221 | Ho�n th�nh |        152 |        1 |                 0 | NULL           |
|             260 |        129 | HD177727627723 | Ho�n th�nh |        300 |        3 |                 0 | NULL           |
|             261 |        130 | HD177727642495 | Ho�n th�nh |        300 |        3 |                 0 | NULL           |
|             249 |        124 | HD177710844363 | Ho�n th�nh |        230 |        1 |                 0 | NULL           |
|             246 |        123 | HD177699888910 | Ho�n th�nh |        281 |        1 |                 0 | NULL           |
|             251 |        125 | HD177710878780 | Ho�n th�nh |        121 |        1 |                 0 | NULL           |
|             252 |        125 | HD177710878780 | Ho�n th�nh |         15 |        1 |                 0 | NULL           |
|             244 |        122 | HD177699869750 | Ho�n th�nh |        284 |        1 |                 0 | NULL           |
|             236 |        119 | HD177682588276 | Ho�n th�nh |        184 |        1 |                 0 | NULL           |
|             193 |        101 | HD177667230322 | Ho�n th�nh |        270 |        1 |                 0 | NULL           |
|             223 |        114 | HD177676868461 | Ho�n th�nh |        283 |        1 |                 0 | NULL           |
|             225 |        115 | HD177676873563 | Ho�n th�nh |        289 |        1 |                 0 | NULL           |
|             231 |        118 | HD177682353283 | Ho�n th�nh |        230 |        1 |                 0 | NULL           |
|             233 |        118 | HD177682353283 | Ho�n th�nh |        278 |        1 |                 0 | NULL           |
|             241 |        121 | HD177684273830 | Ho�n th�nh |        287 |        1 |                 0 | NULL           |
|             242 |        121 | HD177684273830 | Ho�n th�nh |         84 |        1 |                 0 | NULL           |
|             202 |        104 | HD177667558375 | Ho�n th�nh |        265 |        1 |                 0 | NULL           |
|             205 |        105 | HD177667565647 | Ho�n th�nh |        265 |        1 |                 0 | NULL           |
|             178 |         95 | HD177650791433 | Ho�n th�nh |        272 |        1 |                 0 | NULL           |
|             198 |        103 | HD177667548258 | Ho�n th�nh |        271 |        1 |                 0 | NULL           |
|             221 |        113 | HD177676829715 | Ho�n th�nh |        265 |        1 |                 0 | NULL           |
|             196 |        102 | HD177667244265 | Ho�n th�nh |        256 |        1 |                 0 | NULL           |
|             197 |        102 | HD177667244265 | Ho�n th�nh |        193 |        1 |                 0 | NULL           |
|             160 |         87 | HD177647885862 | Ho�n th�nh |        152 |        1 |                 0 | NULL           |
|             181 |         96 | HD177650870050 | Ho�n th�nh |        193 |        2 |                 0 | NULL           |
|             183 |         97 | HD177650887183 | Ho�n th�nh |        199 |        6 |                 0 | NULL           |
|             190 |         99 | HD177650903560 | Ho�n th�nh |        265 |        1 |                 0 | NULL           |
|             154 |         85 | HD177639897745 | Ho�n th�nh |        184 |        1 |                 0 | NULL           |
|             158 |         86 | HD177639975416 | Ho�n th�nh |        193 |        1 |                 0 | NULL           |
|             161 |         88 | HD177647908160 | Ho�n th�nh |        255 |        2 |                 0 | NULL           |
|             164 |         89 | HD177647953332 | Ho�n th�nh |        265 |        1 |                 0 | NULL           |
|             167 |         90 | HD177647962883 | Ho�n th�nh |        203 |        1 |                 0 | NULL           |
|             168 |         91 | HD177647978033 | Ho�n th�nh |        266 |        1 |                 0 | NULL           |
|             186 |         98 | HD177650893986 | ?� h?y     |        265 |        1 |                 0 | NULL           |
|             219 |        112 | HD177676816993 | Ho�n th�nh |        200 |        1 |                 0 | NULL           |
|             138 |         80 | HD177639794967 | Ho�n th�nh |        255 |        2 |                 0 | NULL           |
|             140 |         81 | HD177639824785 | Ho�n th�nh |        256 |        2 |                 0 | NULL           |
|             141 |         81 | HD177639824785 | Ho�n th�nh |        257 |        2 |                 0 | NULL           |
|             144 |         82 | HD177639842146 | Ho�n th�nh |        255 |        1 |                 0 | NULL           |
|             145 |         82 | HD177639842146 | Ho�n th�nh |        254 |        1 |                 0 | NULL           |
|             148 |         83 | HD177639850680 | Ho�n th�nh |        213 |        1 |                 0 | NULL           |
|             152 |         84 | HD177639859643 | Ho�n th�nh |        255 |        1 |                 0 | NULL           |
|             170 |         92 | HD177648018551 | Ho�n th�nh |        254 |        1 |                 0 | NULL           |
|             171 |         92 | HD177648018551 | Ho�n th�nh |        255 |        2 |                 0 | NULL           |
|             210 |        109 | HD177676679013 | Ho�n th�nh |        265 |        2 |                 0 | NULL           |
|             132 |         78 | HD177639754744 | Ho�n th�nh |        193 |        1 |                 0 | NULL           |
|             133 |         78 | HD177639754744 | Ho�n th�nh |        254 |        1 |                 0 | NULL           |
|             136 |         79 | HD177639769177 | Ho�n th�nh |        255 |        1 |                 0 | NULL           |
|             176 |         94 | HD177650786513 | Ho�n th�nh |         85 |        1 |                 0 | NULL           |
|             207 |        107 | HD177676608124 | Ho�n th�nh |        262 |        1 |                 0 | NULL           |
|             208 |        107 | HD177676608124 | Ho�n th�nh |        259 |        1 |                 0 | NULL           |
|             255 |        126 | HD177710901555 | Ho�n th�nh |         66 |        1 |                 0 | NULL           |
|             256 |        126 | HD177710901555 | Ho�n th�nh |        193 |        1 |                 0 | NULL           |
|             130 |         77 | HD177639721971 | Ho�n th�nh |         63 |        1 |                 0 | NULL           |
|             209 |        108 | HD177676623441 | Ho�n th�nh |        261 |        5 |                 0 | NULL           |
|              89 |         59 | HD177601111594 | Ho�n th�nh |        253 |        1 |                 0 | NULL           |
|             125 |         75 | HD177639659670 | Ho�n th�nh |        260 |        1 |                 0 | NULL           |
|             126 |         75 | HD177639659670 | Ho�n th�nh |        259 |        2 |                 0 | NULL           |
|             127 |         76 | HD177639666367 | Ho�n th�nh |        258 |        2 |                 0 | NULL           |
|             238 |        120 | HD177684230361 | Ho�n th�nh |        254 |        1 |                 0 | NULL           |
|             124 |         74 | HD177639650122 | Ho�n th�nh |        200 |        1 |                 0 | NULL           |
|             173 |         93 | HD177648063267 | Ho�n th�nh |        248 |        1 |                 0 | NULL           |
|             174 |         93 | HD177648063267 | Ho�n th�nh |        231 |        2 |                 0 | NULL           |
|             120 |         72 | HD177639630782 | Ho�n th�nh |        193 |        1 |                 0 | NULL           |
|             122 |         73 | HD177639640677 | Ho�n th�nh |        199 |        1 |                 0 | NULL           |
|             110 |         68 | HD177639500175 | Ho�n th�nh |        193 |        1 |                 0 | NULL           |
|             111 |         69 | HD177639516888 | Ho�n th�nh |        199 |        2 |                 0 | NULL           |
|             116 |         71 | HD177639573098 | Ho�n th�nh |        201 |        1 |                 0 | NULL           |
|              83 |         55 | HD177561737527 | Ho�n th�nh |         48 |        1 |                 0 | NULL           |
|              97 |         62 | HD177639308452 | Ho�n th�nh |          7 |        1 |                 0 | NULL           |
|             102 |         65 | HD177639422435 | Ho�n th�nh |        193 |        2 |                 0 | NULL           |
|             103 |         65 | HD177639422435 | Ho�n th�nh |        184 |        3 |                 0 | NULL           |
|             108 |         67 | HD177639446418 | Ho�n th�nh |        211 |        1 |                 0 | NULL           |
|             211 |        110 | HD177676775869 | Ho�n th�nh |         15 |        1 |                 0 | NULL           |
|             214 |        111 | HD177676792874 | Ho�n th�nh |        152 |        1 |                 0 | NULL           |
|             215 |        111 | HD177676792874 | Ho�n th�nh |        193 |        2 |                 0 | NULL           |
|              79 |         53 | HD177561670290 | Ho�n th�nh |         87 |        1 |                 0 | NULL           |
|              86 |         56 | HD177561756788 | Ho�n th�nh |        204 |        1 |                 0 | NULL           |
|              94 |         61 | HD177639202162 | Ho�n th�nh |        213 |        1 |                 0 | NULL           |
|              91 |         60 | HD177639176457 | Ho�n th�nh |        198 |        1 |                 0 | NULL           |
|              69 |         50 | HD177528903341 | Ho�n th�nh |         89 |        1 |                 0 | NULL           |
|             100 |         64 | HD177639382183 | Ho�n th�nh |        203 |        1 |                 0 | NULL           |
|              65 |         48 | HD177519022978 | Ho�n th�nh |          7 |        1 |                 0 | NULL           |
|              66 |         49 | HD177519113396 | Ho�n th�nh |        195 |        1 |                 0 | NULL           |
|              67 |         49 | HD177519113396 | Ho�n th�nh |        194 |        1 |                 0 | NULL           |
|             114 |         70 | HD177639557110 | Ho�n th�nh |        200 |        1 |                 0 | NULL           |
|              57 |         42 | HD177512274157 | Ho�n th�nh |         91 |        1 |                 0 | NULL           |
|              73 |         52 | HD177529117196 | Ho�n th�nh |        199 |        1 |                 0 | NULL           |
|              75 |         52 | HD177529117196 | Ho�n th�nh |         79 |        1 |                 0 | NULL           |
|             105 |         66 | HD177639436274 | Ho�n th�nh |         95 |        1 |                 0 | NULL           |
|              60 |         45 | HD177518434586 | Ho�n th�nh |         67 |        1 |                 0 | NULL           |
|              61 |         45 | HD177518434586 | Ho�n th�nh |         50 |        1 |                 0 | NULL           |
|              71 |         51 | HD177528993031 | Ho�n th�nh |        153 |        1 |                 0 | NULL           |
|              87 |         57 | HD177598589311 | Ho�n th�nh |        253 |        1 |                 0 | NULL           |
|              88 |         58 | HD177600497523 | Ho�n th�nh |        253 |        1 |                 0 | NULL           |
|               9 |          5 | HD177458315279 | Ho�n th�nh |          7 |        1 |                 0 | NULL           |
|              19 |         13 | HD177459597581 | Ho�n th�nh |        102 |        1 |                 0 | NULL           |
|              36 |         24 | HD177460459486 | Ho�n th�nh |        115 |        1 |                 0 | NULL           |
|              37 |         24 | HD177460459486 | Ho�n th�nh |        120 |        1 |                 0 | NULL           |
|              24 |         17 | HD177460073796 | Ho�n th�nh |         64 |        1 |                 0 | NULL           |
|              59 |         44 | HD177518363548 | Ho�n th�nh |         15 |        2 |                 0 | NULL           |
|              25 |         18 | HD177460085526 | Ho�n th�nh |         65 |        1 |                 0 | NULL           |
|              46 |         29 | HD177460759091 | Ho�n th�nh |        128 |        1 |                 0 | NULL           |
|              11 |          7 | HD177458406224 | Ho�n th�nh |         15 |        1 |                 0 | NULL           |
|              12 |          8 | HD177458415217 | Ho�n th�nh |         15 |        1 |                 0 | NULL           |
|              22 |         16 | HD177460020949 | Ho�n th�nh |        102 |        1 |                 0 | NULL           |
|              27 |         19 | HD177460324483 | Ho�n th�nh |        117 |        1 |                 0 | NULL           |
|              18 |         12 | HD177459593057 | Ho�n th�nh |        102 |        1 |                 0 | NULL           |
|              33 |         23 | HD177460448869 | Ho�n th�nh |        121 |        1 |                 0 | NULL           |
|              30 |         21 | HD177460378735 | Ho�n th�nh |        116 |        1 |                 0 | NULL           |
|              51 |         32 | HD177460879243 | Ho�n th�nh |        131 |        1 |                 0 | NULL           |
|              42 |         26 | HD177460703778 | Ho�n th�nh |        126 |        1 |                 0 | NULL           |
|              20 |         14 | HD177460008188 | Ho�n th�nh |        102 |        1 |                 0 | NULL           |
|              21 |         15 | HD177460015338 | Ho�n th�nh |        102 |        1 |                 0 | NULL           |
|              44 |         27 | HD177460719584 | Ho�n th�nh |        129 |        1 |                 0 | NULL           |
|              17 |         11 | HD177459582033 | Ho�n th�nh |        102 |        1 |                 0 | NULL           |
|              31 |         22 | HD177460406262 | Ho�n th�nh |        119 |        1 |                 0 | NULL           |
|              45 |         28 | HD177460725057 | Ho�n th�nh |        127 |        1 |                 0 | NULL           |
|              10 |          6 | HD177458388371 | Ho�n th�nh |         15 |        3 |                 0 | NULL           |
|              49 |         31 | HD177460796213 | Ho�n th�nh |        111 |        1 |                 0 | NULL           |
|              16 |         10 | HD177458507793 | Ho�n th�nh |         24 |        1 |                 0 | NULL           |
|               5 |          3 | HD177452516914 | Ho�n th�nh |         22 |        1 |                 0 | NULL           |
|               7 |          4 | HD177458303396 | Ho�n th�nh |         21 |        1 |                 0 | NULL           |
|              29 |         20 | HD177460367629 | Ho�n th�nh |        110 |        1 |                 0 | NULL           |
|              39 |         25 | HD177460593178 | Ho�n th�nh |        125 |        1 |                 0 | NULL           |
|              40 |         25 | HD177460593178 | Ho�n th�nh |        123 |        3 |                 0 | NULL           |
|              58 |         43 | HD177518348764 | Ho�n th�nh |         15 |        4 |                 0 | NULL           |
|               2 |          1 | HD177451659653 | Ho�n th�nh |         13 |        1 |                 0 | NULL           |
|              14 |          9 | HD177458491822 | Ho�n th�nh |         20 |        1 |                 0 | NULL           |
|              47 |         30 | HD177460783178 | Ho�n th�nh |        130 |        1 |                 0 | NULL           |
+-----------------+------------+----------------+------------+------------+----------+-------------------+----------------+
+-----------+---------------+------------+---------------+-----------------------------------------------------------------------+------------+---------------+------------+---------+--------------------+----------------+------------------+--------------------+---------------------+---------------------+---------------------+
| serial_id | serial_number | product_id | sku           | product_name                                                          | status     | repair_status | invoice_id | sold_at | purchase_return_id | latest_task_id | latest_task_code | latest_task_status | completed_at        | created_at          | updated_at          |
+-----------+---------------+------------+---------------+-----------------------------------------------------------------------+------------+---------------+------------+---------+--------------------+----------------+------------------+--------------------+---------------------+---------------------+---------------------+
|       377 | 1GKH0Z2       |        323 | SP26050627868 | Dell Latitude E7390 Core i5-8250U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3" | dismantled | ready         |       NULL | NULL    |               NULL |            339 | SC-0332          | completed          | 2026-05-16 07:31:06 | 2026-05-09 09:50:19 | 2026-05-16 07:31:06 |
|       384 | 7HKH0Z2       |        323 | SP26050627868 | Dell Latitude E7390 Core i5-8250U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3" | dismantled | ready         |       NULL | NULL    |               NULL |            340 | SC-0333          | completed          | 2026-05-16 07:29:51 | 2026-05-09 09:50:19 | 2026-05-16 07:29:51 |
+-----------+---------------+------------+---------------+-----------------------------------------------------------------------+------------+---------------+------------+---------+--------------------+----------------+------------------+--------------------+---------------------+---------------------+---------------------+
+-----------+---------------+------------+---------------+-----------------------------------------------------------------------+------------+---------------+------------+---------+--------------------+------------+
| serial_id | serial_number | product_id | sku           | product_name                                                          | status     | repair_status | invoice_id | sold_at | purchase_return_id | cost_price |
+-----------+---------------+------------+---------------+-----------------------------------------------------------------------+------------+---------------+------------+---------+--------------------+------------+
|       384 | 7HKH0Z2       |        323 | SP26050627868 | Dell Latitude E7390 Core i5-8250U/ Ram 8GB/ SSD 256GB/ M�n h�nh 13.3" | dismantled | ready         |       NULL | NULL    |               NULL |    3029680 |
+-----------+---------------+------------+---------------+-----------------------------------------------------------------------+------------+---------------+------------+---------+--------------------+------------+
+-----+---------+--------+-----------+----------+----------------+------------+---------------------+---------------------+---------------------+
| id  | code    | type   | status    | progress | serial_imei_id | product_id | created_at          | completed_at        | updated_at          |
+-----+---------+--------+-----------+----------+----------------+------------+---------------------+---------------------+---------------------+
| 340 | SC-0333 | repair | completed |      100 |            384 |        323 | 2026-05-09 09:50:19 | 2026-05-16 07:29:51 | 2026-05-16 07:29:51 |
| 321 | SC-0314 | repair | completed |      100 |            384 |        323 | 2026-05-08 07:54:35 | 2026-05-09 04:04:17 | 2026-05-09 04:04:17 |
+-----+---------+--------+-----------+----------+----------------+------------+---------------------+---------------------+---------------------+
```
