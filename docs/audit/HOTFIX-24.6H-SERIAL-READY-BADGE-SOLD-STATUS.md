# HOTFIX 24.6H - Serial Ready Badge Sold Status

## Pham vi audit
- Module: products, serial/IMEI display.
- Man hinh: Hang hoa > chi tiet san pham > tab Serial/IMEI va Gia von cuoi.
- Nghiep vu: serial da ban khong duoc hien badge `San ban`.
- Rui ro chinh: UI doc `repair_status=ready` nhu sellable, trong khi physical `status` moi la truth chinh.

## Source da kiem tra
- `resources/js/Pages/Welcome.vue`
- `app/Http/Controllers/ProductController.php`
- `app/Models/SerialImei.php`
- `app/Models/Product.php`
- `app/Services/SerialAvailabilityService.php`
- `tests/Feature/Products/ProductSerialStatusDisplayTest.php`
- `tests/Feature/Products/HOTFIX2434ProductSerialDismantledDisplayTest.php`
- `tests/Feature/POS/Step246BPosReturnExchangeTest.php`
- `tests/Feature/Repair`
- `tests/Feature/OrderReturn`

## Hien trang
- Backend:
  - `ProductController@index` tinh `ready_count` dung: chi `status=in_stock` va khong `repair_status in (not_started, repairing)`.
  - `ProductController@serials?status=ready` dung: chi tra serial `in_stock` va khong trong repair flow.
- Frontend:
  - `Welcome.vue` tab Serial/IMEI va Gia von cuoi van co badge dua truc tiep vao `repair_status=ready`.
  - Vi vay serial `status=sold`, `repair_status=ready` co the hien ca `San ban` va `Da ban`.
- Database local:
  - Co nhieu row `sold | ready`, gom serial `4BKGN93`.
  - Day la data can hien thi dung, khong duoc auto update trong hotfix UI nay.

## Root cause
- UI dung `repair_status = ready` de hien `San ban` ma khong kiem tra `status = in_stock`.
- `repair_status=ready` chi noi luong sua chua da xong; khong dong nghia serial con trong kho hoac con ban duoc.
- Physical `status` phai thang `repair_status`.

## Read-only SQL local
Status summary:

| status | repair_status | total |
|---|---|---:|
| in_stock | NULL | 34 |
| in_stock | not_started | 25 |
| in_stock | repairing | 120 |
| in_stock | ready | 55 |
| sold | NULL | 70 |
| sold | ready | 136 |
| returned | NULL | 1 |
| dismantled | repairing | 8 |
| dismantled | ready | 1 |

Sample `status != in_stock AND repair_status = ready`:
- `100 | product_id=15 | 4BKGN93 | sold | ready`
- Query chi doc du lieu, khong update.

## Co anh huong du lieu dang co khong?
- Khong migration.
- Khong backfill.
- Khong update du lieu cu.
- Khong sua `serial_imeis.status`.
- Khong sua `serial_imeis.repair_status`.
- Khong sua ton kho, stock movement, hoa don, tra hang, sua chua, gia von.
- Chi sua helper/hien thi frontend va them test backend contract.

## Phuong an an toan
- Them helper:
  - `isSerialInRepairFlow(serial)`: chi true khi `status=in_stock` va repair_status dang `not_started/repairing`.
  - `isSerialReadyForSale(serial)`: chi true khi `status=in_stock` va khong trong repair flow.
  - `serialRowBadge(serial)`: status physical thang repair_status; non-in_stock khong hien ready badge.
- Tab Serial/IMEI:
  - row repair class chi ap dung khi `isSerialInRepairFlow`.
  - badge `San ban` chi qua `serialRowBadge`, khong dung truc tiep `repair_status=ready`.
- Tab Gia von cuoi:
  - icon/badge cung dung `serialRowBadge`.
  - serial da ban khong con hien check san ban.

## Khong duoc lam
- Khong update serial data.
- Khong cleanup `sold + ready` trong production.
- Khong sua core inventory/serial movement.
- Khong sua hoa don/tra hang/gia von.

## Tests
| Lenh | Ket qua |
|---|---|
| `php artisan test tests/Feature/Products/ProductSerialStatusDisplayTest.php` | PASS - 3 tests, 15 assertions |
| `php artisan test tests/Feature/Products` | PASS - 42 tests, 135 assertions |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS - 28 tests, 156 assertions |
| `php artisan test tests/Feature/Repair` | PASS - 4 tests, 9 assertions |
| `php artisan test tests/Feature/OrderReturn` | PASS - 53 tests, 213 assertions |
| `npm run build` | PASS - Vite built successfully |

Note: PHP CLI tren local van co startup warning thieu extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; cac test van pass.

## Manual QA
- Chua chay browser QA.
- Can kiem tra:
  - Serial `4BKGN93` hoac serial sold+ready khong con badge `San ban`.
  - Serial in_stock ready/null van co badge `San ban`.
  - Serial in_stock repairing/not_started hien repair badge.
  - Filter `San ban` khong hien serial da ban.
  - Tab Gia von cuoi khong hien check san ban cho serial da ban.

## Ket luan
- Dat ve code/test/build local.
- Chua co data mutation.
- Co the deploy code sau khi owner chap nhan; manual browser QA van can chay tren staging/production sau deploy.
