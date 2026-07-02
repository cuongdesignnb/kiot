# HOTFIX - Damage Actor, Serial Loading, Datetime

## Pham vi audit
- Module: Xuat huy hang hoa (`Damage`).
- Man hinh: `/damages/create`.
- Nghiep vu: chon nguoi xuat huy, chon thoi gian xuat huy, load serial/IMEI kha dung cho phieu xuat huy.
- Rui ro chinh: quyen load serial, actor admin khong gan employee, validate serial blocked khi hoan thanh phieu.

## Source da kiem tra
- `app/Http/Controllers/DamageController.php`
- `app/Models/User.php`
- `app/Models/Employee.php`
- `app/Services/SerialAvailabilityService.php`
- `routes/web.php`
- `resources/js/Pages/Damages/Create.vue`
- `resources/js/Components/DateTimePicker.vue`
- `tests/Feature/Damage/DamageCreateMetaTest.php`
- `tests/Feature/Damage/RR09DamageStockTest.php`

## Root cause
- Actor selector cu chi dua vao `employees`, nen admin user khong gan employee khong xuat hien.
- Serial selector goi endpoint product/POS, co the phu thuoc quyen khac voi nghiep vu Xuat huy va de bi ket loading khi endpoint loi.
- Backend completed damage moi chi check serial `status = in_stock`, chua dung chung `SerialAvailabilityService` de chan cac status/repair_status blocked.
- Date/time tren man tao chua tach ro ngay va gio.

## Thay doi
- `DamageController@create` tao `damageActorOptions` gom:
  - `employee:{id}` cho employee active;
  - `admin_user:{id}` cho admin user active khong co active employee tuong ung.
- `currentDamageActorKey` uu tien employee active cua user hien tai, fallback admin user hien tai.
- `DamageController@store` nhan `damage_actor_key`, resolve actor theo employee/admin va luu `created_by_name`, `destroyed_by_name`.
- Them endpoint rieng:
  - `GET /damages/products/{product}/serials`
  - middleware `permission:damages.create`
  - dung `SerialAvailabilityService::querySellableForProduct()` va `normalizeForResponse()`.
- Backend damage completed dung `SerialAvailabilityService::countSellable()` de reject serial sold/defective/dismantled/in_transit/warranty/returned va repair blocked.
- `Damages/Create.vue` dung `damageActorOptions`, gui `damage_actor_key`.
- Serial loader chi goi endpoint Damage moi, co timeout 8 giay, reset `serial_loading` trong `finally`, hien loi ro va giu nut `Tai lai`.
- Date/time tren UI tach input ngay va input gio, payload van gui canonical `yyyy-MM-ddTHH:mm`.

## Data safety
- Co migration khong: Khong.
- Co backfill khong: Khong.
- Co update du lieu cu khong: Khong.
- Co xoa du lieu khong: Khong.
- Co seed permission/role khong: Khong.
- Giao dich moi completed van dung logic ton kho/serial/stock movement hien co.
- Rollback plan: revert commit.

## Tests da chay
- `php artisan test tests/Feature/Damage/DamageCreateMetaTest.php`: PASS, 4 tests, 23 assertions.
- `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`: PASS, 5 tests, 12 assertions.
- `php artisan test tests/Feature/Damage`: PASS, 29 tests, 129 assertions.
- `npm run build`: PASS, Vite built successfully in 7.35s.

Ghi chu moi truong test: PHP local canh bao thieu extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; cac warning nay khong lam fail test.

## Manual QA checklist
- Mo `/damages/create`.
- Dropdown nguoi xuat huy co employee active va admin user active khong gan employee.
- Chon admin, luu draft, kiem tra nguoi tao/nguoi xuat huy theo admin.
- Chon san pham serial, danh sach serial load tu `/damages/products/{product}/serials`.
- Serial blocked khong xuat hien.
- Chon serial, hoan thanh phieu, serial sang `defective`.
- Chon ngay/gio bang UI, phieu luu dung `created_at`/`destroyed_date`.
- Huy phieu completed va kiem tra rollback ton/serial.

Manual browser QA chua chay trong moi truong nay.

## Ket luan
- Hotfix khong doi schema/data cu.
- Co the deploy sau khi pull code, clear cache route/config neu production dang cache, build frontend moi va QA browser cac case tren.
