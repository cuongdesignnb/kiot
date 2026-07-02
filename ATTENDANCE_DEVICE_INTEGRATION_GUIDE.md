# Hướng dẫn tích hợp máy chấm công cho dự án Laravel

> Tài liệu quy trình chi tiết từ A-Z để kết nối máy chấm công (ZKTeco / Ronald Jack) vào hệ thống website Laravel.  
> Có thể áp dụng cho bất kỳ dự án nào.

**Cập nhật:** 2026-03-01

---

## Mục lục

1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [Chuẩn bị hạ tầng](#2-chuẩn-bị-hạ-tầng)
3. [Database — Migration & Schema](#3-database--migration--schema)
4. [Backend — Models](#4-backend--models)
5. [Backend — Middleware xác thực HMAC](#5-backend--middleware-xác-thực-hmac)
6. [Backend — API Controllers](#6-backend--api-controllers)
7. [Backend — Service tính chấm công](#7-backend--service-tính-chấm-công)
8. [Backend — Job & Artisan Command](#8-backend--job--artisan-command)
9. [Routes — Định tuyến API](#9-routes--định-tuyến-api)
10. [Agent đồng bộ — PHP Agent (LAN)](#10-agent-đồng-bộ--php-agent-lan)
11. [Agent đồng bộ — C# Windows Service](#11-agent-đồng-bộ--c-windows-service)
12. [Frontend — Giao diện chấm công](#12-frontend--giao-diện-chấm-công)
13. [Triển khai & vận hành](#13-triển-khai--vận-hành)
14. [Xử lý sự cố](#14-xử-lý-sự-cố)
15. [Checklist triển khai](#15-checklist-triển-khai)

---

## 1. Tổng quan kiến trúc

### 1.1 Sơ đồ tổng quan

```
┌──────────────────┐     UDP 4370      ┌────────────────────┐     HTTPS + HMAC      ┌──────────────────────────┐
│  Máy chấm công   │ ◄───────────────► │   Agent đồng bộ    │ ──────────────────────►│   Server (Laravel)       │
│  (ZKTeco/Ronald  │                   │   (LAN - Windows)  │                        │                          │
│   Jack)          │                   │                    │                        │  1. Nhận & lưu log       │
│                  │                   │  - PHP script      │                        │  2. Map nhân viên        │
│  Lưu log nội bộ │                   │  - hoặc C# Service │                        │  3. Tính công tự động    │
│  (finger/face)   │                   │  - Chạy mỗi 30s   │                        │  4. API cho frontend     │
└──────────────────┘                   └────────────────────┘                        └──────────┬───────────────┘
                                                                                               │
                                                                                               ▼
                                                                                    ┌──────────────────────┐
                                                                                    │    Frontend (Vue)    │
                                                                                    │                      │
                                                                                    │  - Bảng chấm công    │
                                                                                    │  - Quản lý thiết bị  │
                                                                                    │  - Log chưa map      │
                                                                                    └──────────────────────┘
```

### 1.2 Luồng dữ liệu

```
Nhân viên quẹt vân tay/khuôn mặt
        │
        ▼
Máy chấm công lưu log (user_id + timestamp)
        │
        ▼ (UDP 4370)
Agent đọc log từ máy chấm công
        │
        ▼ (HTTPS POST + HMAC)
Server nhận log → Upsert vào attendance_logs
        │
        ▼ (Auto-mapping)
Map device_user_id → employee_id (qua attendance_code)
        │
        ▼ (Auto-recalculate)
Tính toán timekeeping_records (đi muộn, về sớm, tăng ca...)
        │
        ▼ (API)
Frontend hiển thị bảng chấm công
```

### 1.3 Hai loại Agent

| Loại | Ngôn ngữ | Chạy trên | Ưu điểm | Nhược điểm |
|------|----------|-----------|----------|------------|
| PHP Agent | PHP | Windows (LAN) | Đơn giản, không cần build | Cần cài PHP trên Windows |
| C# Service | C# (.NET 8) | Windows (Service) | Chạy nền, auto-start, ổn định | Cần build & cài MSI |

---

## 2. Chuẩn bị hạ tầng

### 2.1 Server (VPS)

```
- Laravel 11+ (PHP 8.2)
- MySQL / MariaDB
- Nginx (khuyến nghị tăng client_max_body_size 10M)
```

**Thêm vào `.env`:**
```env
# Khóa bí mật HMAC cho agent — đặt chuỗi ngẫu nhiên dài ≥ 32 ký tự
ATTENDANCE_AGENT_SECRET=your-long-random-secret-here-change-me

# (Tùy chọn) Dung sai thời gian cho HMAC (giây), mặc định 300 = 5 phút
ATTENDANCE_AGENT_TIMESTAMP_TOLERANCE=300
```

**Config `config/services.php`:**
```php
'attendance_agent' => [
    'hmac_key'            => env('ATTENDANCE_AGENT_SECRET'),
    'timestamp_tolerance' => env('ATTENDANCE_AGENT_TIMESTAMP_TOLERANCE', 300),
],
```

### 2.2 Máy chấm công

- Hỗ trợ giao thức ZK (UDP port 4370) — hầu hết máy ZKTeco, Ronald Jack đều hỗ trợ
- IP tĩnh trong LAN (VD: `192.168.1.222`)
- Port mặc định: `4370`
- `Comm Key = 0` (nếu khác 0, một số thư viện PHP không xác thực được — cần dùng SDK C#)

### 2.3 Máy Windows chạy Agent (tại cửa hàng/công ty)

- Windows 10/11, cùng mạng LAN với máy chấm công
- Có kết nối internet (để push log lên server)
- Đồng bộ giờ NTP (quan trọng cho HMAC không bị lệch)

---

## 3. Database — Migration & Schema

### 3.1 Bảng `attendance_devices` — Quản lý thiết bị

```php
Schema::create('attendance_devices', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('warehouse_id')->nullable(); // Thuộc kho/chi nhánh nào
    $table->string('name');                                  // Tên hiển thị (VD: "Máy CC Tầng 1")
    $table->string('device_id')->nullable()->unique();       // ID thiết bị (agent gửi lên)
    $table->string('model')->nullable();                     // Model máy (VD: "RJ X628C")
    $table->string('serial_number')->nullable();
    $table->string('ip_address');                            // IP trong LAN
    $table->unsignedInteger('tcp_port')->default(4370);
    $table->unsignedInteger('comm_key')->default(0);         // Mật khẩu giao tiếp
    $table->string('status')->default('active');             // active | inactive
    $table->text('notes')->nullable();
    $table->timestamp('last_sync_at')->nullable();           // Lần sync gần nhất
    $table->timestamps();

    $table->unique(['ip_address', 'tcp_port']);
    $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
});
```

### 3.2 Bảng `attendance_logs` — Log chấm công thô

```php
Schema::create('attendance_logs', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('attendance_device_id');      // FK → attendance_devices
    $table->unsignedBigInteger('employee_id')->nullable();   // FK → employees (null = chưa map)
    $table->string('device_user_id');                        // ID user trên máy chấm công
    $table->dateTime('punched_at');                          // Thời điểm quẹt
    $table->string('event_type')->nullable();                // in / out (nếu máy phân biệt)
    $table->json('raw')->nullable();                         // Dữ liệu thô từ máy
    $table->timestamps();

    // ★ UNIQUE KEY — đảm bảo idempotent (push nhiều lần không trùng)
    $table->unique(['attendance_device_id', 'device_user_id', 'punched_at'], 'attendance_log_unique');
    
    $table->index('employee_id');
    $table->index('punched_at');
    $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->cascadeOnDelete();
    $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
});
```

### 3.3 Bảng `employees` — Thêm cột `attendance_code`

```php
// Migration bổ sung
Schema::table('employees', function (Blueprint $table) {
    $table->string('attendance_code')->nullable()->after('code');
    $table->index('attendance_code');
});
```

> **`attendance_code`** là cầu nối giữa `device_user_id` (trên máy chấm công) và nhân viên trong hệ thống.  
> VD: Nhân viên có `attendance_code = "5"` → tất cả log với `device_user_id = "5"` sẽ map về nhân viên này.

### 3.4 Bảng `timekeeping_records` — Kết quả chấm công đã tính

```php
Schema::create('timekeeping_records', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('employee_id');
    $table->unsignedBigInteger('employee_work_schedule_id')->nullable();
    $table->unsignedBigInteger('warehouse_id')->nullable();
    $table->unsignedBigInteger('shift_id')->nullable();
    $table->date('work_date');
    $table->string('slot')->nullable();                    // "morning" / "afternoon" / "full"
    $table->dateTime('scheduled_start_at')->nullable();    // Giờ bắt đầu ca
    $table->dateTime('scheduled_end_at')->nullable();      // Giờ kết thúc ca
    $table->dateTime('check_in_at')->nullable();           // Giờ vào thực tế
    $table->dateTime('check_out_at')->nullable();          // Giờ ra thực tế
    $table->string('source')->default('none');             // 'device' | 'manual' | 'none'
    $table->string('attendance_type')->default('work');    // 'work' | 'leave_paid' | 'leave_unpaid'
    $table->boolean('manual_override')->default(false);    // true = không bị ghi đè khi recalculate
    $table->integer('late_minutes')->default(0);           // Số phút đi muộn
    $table->integer('early_minutes')->default(0);          // Số phút về sớm
    $table->integer('ot_minutes')->default(0);             // Số phút tăng ca
    $table->integer('worked_minutes')->default(0);         // Tổng phút làm việc
    $table->decimal('work_units', 3, 1)->default(0);       // Số công (0.5 / 1.0)
    $table->boolean('is_holiday')->default(false);
    $table->decimal('holiday_multiplier', 3, 1)->default(1.0);
    $table->json('raw')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
});
```

### 3.5 Bảng `timekeeping_settings` — Cài đặt chấm công

```php
Schema::create('timekeeping_settings', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('warehouse_id')->nullable()->unique(); // null = toàn hệ thống
    $table->decimal('standard_hours_per_day', 6, 2)->default(8);
    $table->boolean('use_shift_allowances')->default(true);
    $table->unsignedSmallInteger('late_grace_minutes')->default(0);   // Tha muộn X phút
    $table->unsignedSmallInteger('early_grace_minutes')->default(0);  // Tha về sớm X phút
    $table->boolean('allow_multiple_shifts_one_inout')->default(false);
    $table->boolean('enforce_shift_checkin_window')->default(false);
    $table->unsignedSmallInteger('ot_rounding_minutes')->default(0);  // Làm tròn OT (15/30 phút)
    $table->unsignedSmallInteger('ot_after_minutes')->default(0);     // OT tính sau X phút
    $table->string('status')->default('active');
    $table->timestamps();
});
```

### 3.6 Bảng phụ — Theo dõi agent & phiên bản

```php
// attendance_agent_sync_logs — Lịch sử đồng bộ
Schema::create('attendance_agent_sync_logs', function (Blueprint $table) {
    $table->id();
    $table->string('device_id');
    $table->string('app_version')->nullable();
    $table->string('sync_type');          // 'users' | 'logs' | 'full'
    $table->timestamp('started_at');
    $table->timestamp('finished_at')->nullable();
    $table->string('result');             // 'ok' | 'partial' | 'failed'
    $table->json('counts')->nullable();   // {"sent": 100, "saved": 98, "duplicates": 2}
    $table->json('errors')->nullable();
    $table->timestamps();
});

// attendance_bridge_versions — Auto-update cho C# agent
Schema::create('attendance_bridge_versions', function (Blueprint $table) {
    $table->id();
    $table->string('version')->unique();
    $table->string('channel')->default('stable');  // stable | beta
    $table->boolean('mandatory')->default(false);
    $table->string('min_supported')->nullable();
    $table->timestamp('released_at');
    $table->text('notes')->nullable();
    $table->string('download_url');
    $table->string('sha256')->nullable();
    $table->unsignedBigInteger('size_bytes')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

---

## 4. Backend — Models

### 4.1 AttendanceLog

```php
class AttendanceLog extends Model
{
    protected $fillable = [
        'attendance_device_id', 'employee_id', 'device_user_id',
        'punched_at', 'event_type', 'raw',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
        'raw'        => 'array',
    ];

    public function device()   { return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
```

### 4.2 AttendanceDevice

```php
class AttendanceDevice extends Model
{
    protected $fillable = [
        'warehouse_id', 'name', 'device_id', 'model', 'serial_number',
        'ip_address', 'tcp_port', 'comm_key', 'status', 'notes', 'last_sync_at',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function logs()      { return $this->hasMany(AttendanceLog::class); }
}
```

### 4.3 TimekeepingRecord

```php
class TimekeepingRecord extends Model
{
    protected $fillable = [
        'employee_id', 'employee_work_schedule_id', 'warehouse_id', 'shift_id',
        'work_date', 'slot', 'scheduled_start_at', 'scheduled_end_at',
        'check_in_at', 'check_out_at', 'source', 'attendance_type',
        'manual_override', 'late_minutes', 'early_minutes', 'ot_minutes',
        'worked_minutes', 'work_units', 'is_holiday', 'holiday_multiplier',
        'raw', 'notes',
    ];

    protected $casts = [
        'work_date'          => 'date',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at'   => 'datetime',
        'check_in_at'        => 'datetime',
        'check_out_at'       => 'datetime',
        'manual_override'    => 'boolean',
        'is_holiday'         => 'boolean',
        'raw'                => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function schedule() { return $this->belongsTo(EmployeeWorkSchedule::class, 'employee_work_schedule_id'); }
    public function shift()    { return $this->belongsTo(Shift::class); }
    public function warehouse(){ return $this->belongsTo(Warehouse::class); }
}
```

### 4.4 Employee (attendance fields)

```php
// Trong $fillable thêm:
'attendance_code',

// Relationship:
public function attendanceLogs()    { return $this->hasMany(AttendanceLog::class); }
public function timekeepingRecords(){ return $this->hasMany(TimekeepingRecord::class); }
public function schedules()         { return $this->hasMany(EmployeeWorkSchedule::class); }
```

---

## 5. Backend — Middleware xác thực HMAC

Agent gửi log lên server cần được xác thực mà **không dùng token/session** (vì agent là script tự động).  
Giải pháp: **HMAC-SHA256 signature** trên mỗi request.

### 5.1 Cách hoạt động

```
Agent tạo request:
  1. timestamp = unix time hiện tại (giây)
  2. body = JSON payload
  3. signature = hex(HMAC-SHA256(secret, "{timestamp}.{device_id}.{body}"))
  4. Gửi kèm headers:
     - X-Device-Id: "1"
     - X-Timestamp: "1709295600"
     - X-Signature: "abc123..."
     
Server kiểm tra:
  1. Lấy timestamp, device_id, signature từ headers
  2. Kiểm tra timestamp ±300 giây (chống replay)
  3. Tính lại expected = hex(HMAC-SHA256(secret, "{timestamp}.{device_id}.{body}"))
  4. So sánh hash_equals(expected, signature)
  5. Nếu đúng → cho qua, sai → 401
```

### 5.2 Code Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyAttendanceAgentSignature
{
    public function handle(Request $request, Closure $next)
    {
        $key       = config('services.attendance_agent.hmac_key');
        $tolerance = (int) config('services.attendance_agent.timestamp_tolerance', 300);
        $deviceId  = $request->header('X-Device-Id', '');
        $timestamp = $request->header('X-Timestamp', '');
        $signature = $request->header('X-Signature', '');

        if (!$key || !$deviceId || !$timestamp || !$signature) {
            return response()->json(['error' => 'Missing auth headers'], 401);
        }

        // Chống replay attack — kiểm tra timestamp lệch
        if (abs(time() - (int)$timestamp) > $tolerance) {
            return response()->json(['error' => 'Timestamp expired'], 401);
        }

        // Lấy raw body
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            $rawBody = $request->getContent();
        }

        // Tính chữ ký mong đợi
        $payload  = "{$timestamp}.{$deviceId}.{$rawBody}";
        $expected = hash_hmac('sha256', $payload, $key);

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Lưu device_id vào request để controller dùng
        $request->attributes->set('attendance_device_id', $deviceId);

        return $next($request);
    }
}
```

### 5.3 Đăng ký Middleware

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'attendance.agent' => \App\Http\Middleware\VerifyAttendanceAgentSignature::class,
    ]);
})
```

---

## 6. Backend — API Controllers

### 6.1 AttendanceAgentController — Nhận log từ Agent

**Endpoint chính: `POST /api/attendance-agent/push-logs`**

```php
public function pushLogs(Request $request)
{
    // 1. Validate
    $request->validate([
        'logs'               => 'required|array|max:5000',
        'logs.*.device_user_id' => 'required|string',
        'logs.*.punched_at'     => 'required|date',
        'logs.*.event_type'     => 'nullable|string',
    ]);

    // 2. Tìm hoặc tạo device record
    $deviceId = $request->attributes->get('attendance_device_id')
                ?: $request->header('X-Device-Id');
    $device = $this->findOrCreateDevice($deviceId);

    // 3. Chuẩn bị rows để upsert
    $rows = [];
    $employeeMap = Employee::whereNotNull('attendance_code')
        ->where('attendance_code', '<>', '')
        ->pluck('id', 'attendance_code');

    foreach ($request->logs as $log) {
        // Skip log không hợp lệ
        if ($log['device_user_id'] === '0') continue;
        
        $punchedAt = Carbon::parse($log['punched_at']);
        if ($punchedAt->isAfter(now()->addDays(2))) continue; // Tương lai > 2 ngày

        $rows[] = [
            'attendance_device_id' => $device->id,
            'employee_id'          => $employeeMap[$log['device_user_id']] ?? null,
            'device_user_id'       => $log['device_user_id'],
            'punched_at'           => $punchedAt->format('Y-m-d H:i:s'),
            'event_type'           => $log['event_type'] ?? null,
            'raw'                  => json_encode($log['raw'] ?? null),
            'created_at'           => now(),
            'updated_at'           => now(),
        ];
    }

    // 4. Upsert (idempotent — push nhiều lần không sao)
    DB::transaction(function () use ($rows, $device) {
        AttendanceLog::query()->upsert(
            $rows,
            ['attendance_device_id', 'device_user_id', 'punched_at'],  // unique key
            ['employee_id', 'event_type', 'raw', 'updated_at']         // update nếu trùng
        );
        $device->forceFill(['last_sync_at' => now()])->save();
    });

    // 5. Tự động tính lại chấm công (chạy đồng bộ, không cần queue)
    $dates = collect($rows)->pluck('punched_at')
        ->map(fn($d) => Carbon::parse($d)->toDateString());
    $from = $dates->min();
    $to   = $dates->max();

    RecalculateTimekeepingForRangeJob::dispatchSync($from, $to);

    return response()->json([
        'success' => true,
        'message' => 'OK',
        'data'    => ['saved' => count($rows)],
    ]);
}
```

**Các endpoint phụ:**

| Endpoint | Mục đích |
|----------|----------|
| `GET /api/attendance-agent/users` | Trả danh sách nhân viên có `attendance_code` — để agent đồng bộ 2 chiều |
| `POST /api/attendance-agent/refresh-mapping` | Re-map log chưa có `employee_id` |
| `POST /api/attendance-agent/sync-status` | Agent báo cáo kết quả sync |
| `GET /api/attendance-agent/bridge/latest` | Kiểm tra phiên bản mới cho auto-update |

### 6.2 AttendanceLogController — Quản lý log cho Admin

```php
// GET /api/attendance-logs — Danh sách log (có filter)
// Filters: employee_id, device_id, date_from, date_to, unmapped=1

// GET /api/attendance-logs/unmapped-users — Danh sách device_user_id chưa map
// → Trả mảng các device_user_id duy nhất cùng số log tương ứng

// POST /api/attendance-logs/refresh-mapping — Admin bấm nút re-map
// Dùng UPDATE JOIN (MySQL) để map hàng loạt:
DB::statement("
    UPDATE attendance_logs al
    JOIN employees e ON e.attendance_code = al.device_user_id
    SET al.employee_id = e.id, al.updated_at = NOW()
    WHERE al.employee_id IS NULL
      AND al.device_user_id <> '0'
      AND e.attendance_code IS NOT NULL AND e.attendance_code <> ''
");
```

### 6.3 AttendanceDeviceController — Quản lý thiết bị

```
GET    /api/attendance-devices              — Danh sách
POST   /api/attendance-devices              — Thêm mới
GET    /api/attendance-devices/{id}         — Chi tiết
PUT    /api/attendance-devices/{id}         — Cập nhật
DELETE /api/attendance-devices/{id}         — Xóa
POST   /api/attendance-devices/{id}/test-connection — Test kết nối TCP/UDP
POST   /api/attendance-devices/{id}/sync            — Sync trực tiếp từ server (qua ZKLib)
```

### 6.4 TimekeepingRecordController — Bảng chấm công

```
GET  /api/timekeeping-records               — Danh sách (filter: employee, date range, warehouse)
GET  /api/timekeeping-records/{id}          — Chi tiết
POST /api/timekeeping-records               — Tạo/sửa thủ công (upsert theo schedule)
PUT  /api/timekeeping-records/{id}          — Cập nhật
POST /api/timekeeping-records/recalculate   — Trigger tính lại cho khoảng ngày
```

---

## 7. Backend — Service tính chấm công

### 7.1 TimekeepingService — Thuật toán chính

```php
class TimekeepingService
{
    public function recalculateForRange(Carbon $from, Carbon $to, ?int $employeeId = null)
    {
        // Bước 1: Load lịch nghỉ lễ
        $holidays = Holiday::whereBetween('date', [$from, $to])->pluck('date')->toArray();

        // Bước 2: Load lịch làm việc (employee_work_schedules) trong khoảng
        $schedules = EmployeeWorkSchedule::with('shift')
            ->whereBetween('work_date', [$from, $to])
            ->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))
            ->get();

        // Bước 3: Load settings chấm công theo warehouse
        $settings = TimekeepingSetting::all()->keyBy('warehouse_id');

        foreach ($schedules as $schedule) {
            // Bỏ qua bản ghi đã chỉnh tay
            $existing = TimekeepingRecord::where('employee_work_schedule_id', $schedule->id)->first();
            if ($existing && $existing->manual_override) continue;

            // Bước 4: Xác định thời gian ca
            $scheduleStart = $this->getScheduleStart($schedule);
            $scheduleEnd   = $this->getScheduleEnd($schedule);

            // Bước 5: Tìm log chấm công trong cửa sổ ±8 giờ
            $logs = AttendanceLog::where('employee_id', $schedule->employee_id)
                ->whereBetween('punched_at', [
                    $scheduleStart->copy()->subHours(8),
                    $scheduleEnd->copy()->addHours(8),
                ])
                ->orderBy('punched_at')
                ->get();

            if ($logs->isEmpty()) {
                // Không có log → vắng mặt
                $this->upsertRecord($schedule, [
                    'source' => 'none',
                    'check_in_at' => null,
                    'check_out_at' => null,
                    'late_minutes' => 0,
                    'early_minutes' => 0,
                    'ot_minutes' => 0,
                    'worked_minutes' => 0,
                ]);
                continue;
            }

            // Bước 6: Xác định check-in / check-out
            $checkIn  = $logs->first()->punched_at;
            $checkOut = $logs->count() > 1 ? $logs->last()->punched_at : null;

            // ★ QUAN TRỌNG: Nếu chỉ có 1 log → chỉ có check-in, KHÔNG set check-out
            // (tránh lỗi check_in == check_out)
            
            // Bước 7: Lấy settings
            $setting      = $settings[$schedule->warehouse_id] ?? $settings[null] ?? null;
            $allowLate    = $setting->late_grace_minutes ?? 0;
            $allowEarly   = $setting->early_grace_minutes ?? 0;
            $otAfter      = $setting->ot_after_minutes ?? 0;
            $otRounding   = $setting->ot_rounding_minutes ?? 0;

            // Bước 8: Tính các chỉ số
            $lateMinutes = 0;
            if ($checkIn->greaterThan($scheduleStart)) {
                $lateMinutes = max(0, $checkIn->diffInMinutes($scheduleStart) - $allowLate);
            }

            $earlyMinutes = 0;
            $otMinutes = 0;
            if ($checkOut) {
                if ($checkOut->lessThan($scheduleEnd)) {
                    // ★ Về sớm — CHỈ tính khi checkout TRƯỚC giờ kết thúc
                    $earlyMinutes = max(0, $scheduleEnd->diffInMinutes($checkOut) - $allowEarly);
                } elseif ($checkOut->greaterThan($scheduleEnd)) {
                    // ★ Tăng ca — CHỈ tính khi checkout SAU giờ kết thúc
                    $rawOt = $checkOut->diffInMinutes($scheduleEnd) - $otAfter;
                    if ($rawOt > 0 && $otRounding > 0) {
                        $rawOt = intdiv($rawOt, $otRounding) * $otRounding;
                    }
                    $otMinutes = max(0, $rawOt);
                }
                
                $workedMinutes = $checkIn->diffInMinutes($checkOut);
            } else {
                $workedMinutes = 0;
            }

            // Bước 9: Upsert kết quả
            $this->upsertRecord($schedule, [
                'source'         => 'device',
                'check_in_at'    => $checkIn,
                'check_out_at'   => $checkOut,
                'late_minutes'   => $lateMinutes,
                'early_minutes'  => $earlyMinutes,
                'ot_minutes'     => $otMinutes,
                'worked_minutes' => $workedMinutes,
                'is_holiday'     => in_array($schedule->work_date->toDateString(), $holidays),
            ]);
        }
    }
}
```

### 7.2 Lưu ý quan trọng

| Vấn đề | Giải pháp |
|---------|-----------|
| `diffInMinutes()` luôn trả giá trị dương | Phải dùng `lessThan()` / `greaterThan()` kiểm tra trước khi tính |
| Chỉ có 1 log (chỉ check-in) | Không set `check_out_at`, tránh `check_in == check_out` |
| Ca đêm (qua ngày) | `scheduleEnd` cần +1 day nếu `endTime < startTime` |
| Bản ghi chỉnh tay | Kiểm tra `manual_override = true` → bỏ qua |
| Log nhiễu (quá xa ca) | Cửa sổ tìm kiếm ±8 giờ quanh ca làm việc |

---

## 8. Backend — Job & Artisan Command

### 8.1 RecalculateTimekeepingForRangeJob

```php
class RecalculateTimekeepingForRangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $employeeIds = [],
    ) {}

    public function handle(TimekeepingService $service): void
    {
        $from = Carbon::parse($this->from);
        $to   = Carbon::parse($this->to);

        if (empty($this->employeeIds)) {
            $service->recalculateForRange($from, $to);
        } else {
            foreach ($this->employeeIds as $empId) {
                $service->recalculateForRange($from, $to, $empId);
            }
        }
    }
}
```

> **Quan trọng:** Dùng `dispatchSync()` thay vì `dispatch()` để chạy ngay lập tức mà không cần queue worker.

### 8.2 AttendanceRefreshCommand

```bash
php artisan attendance:refresh              # Refresh 7 ngày gần nhất (mặc định)
php artisan attendance:refresh --days=30    # Refresh 30 ngày
php artisan attendance:refresh --from=2026-02-01 --to=2026-02-28   # Khoảng cụ thể
php artisan attendance:refresh --skip-mapping  # Chỉ tính lại, không map
```

**Logic:**
1. **Refresh mapping**: Tìm tất cả nhân viên có `attendance_code`, cập nhật `employee_id` cho log chưa map
2. **Recalculate**: Gọi `TimekeepingService::recalculateForRange()` cho từng nhân viên có log trong khoảng

### 8.3 Cron tự động (khuyến nghị)

```php
// bootstrap/app.php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('attendance:refresh --days=3')
             ->everyTenMinutes()
             ->withoutOverlapping();
})
```

```bash
# Thêm vào crontab (Linux VPS):
* * * * * cd /path/to/laravel && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. Routes — Định tuyến API

```php
// routes/api.php

use App\Http\Controllers\Api\AttendanceAgentController;
use App\Http\Controllers\Api\AttendanceLogController;
use App\Http\Controllers\Api\AttendanceDeviceController;
use App\Http\Controllers\Api\TimekeepingRecordController;
use App\Http\Controllers\Api\TimekeepingSettingController;

// ═══════════════════════════════════════════════
// Agent endpoints (HMAC auth, không cần Sanctum)
// ═══════════════════════════════════════════════
Route::middleware('attendance.agent')->group(function () {
    Route::post('attendance-agent/push-logs', [AttendanceAgentController::class, 'pushLogs']);
    Route::get('attendance-agent/users', [AttendanceAgentController::class, 'getUsers']);
    Route::post('attendance-agent/sync-status', [AttendanceAgentController::class, 'syncStatus']);
    Route::get('attendance-agent/bridge/latest', [AttendanceAgentController::class, 'bridgeLatest']);
    Route::post('attendance-agent/refresh-mapping', [AttendanceAgentController::class, 'refreshMapping']);
});

// ═══════════════════════════════════════════════
// Admin endpoints (Sanctum + permissions)
// ═══════════════════════════════════════════════
Route::middleware(['auth:sanctum'])->group(function () {

    // Thiết bị chấm công
    Route::prefix('attendance-devices')->middleware('permission:staff.view')->group(function () {
        Route::get('/', [AttendanceDeviceController::class, 'index']);
        Route::get('{device}', [AttendanceDeviceController::class, 'show']);
        Route::post('{device}/test-connection', [AttendanceDeviceController::class, 'testConnection']);
        Route::post('{device}/sync', [AttendanceDeviceController::class, 'sync']);

        Route::middleware('permission:staff.manage')->group(function () {
            Route::post('/', [AttendanceDeviceController::class, 'store']);
            Route::put('{device}', [AttendanceDeviceController::class, 'update']);
            Route::delete('{device}', [AttendanceDeviceController::class, 'destroy']);
        });
    });

    // Log chấm công
    Route::prefix('attendance-logs')->middleware('permission:staff.view')->group(function () {
        Route::get('/', [AttendanceLogController::class, 'index']);
        Route::get('unmapped-users', [AttendanceLogController::class, 'unmappedUsers']);

        Route::middleware('permission:staff.manage')->group(function () {
            Route::post('refresh-mapping', [AttendanceLogController::class, 'refreshMapping']);
        });
    });

    // Cài đặt chấm công
    Route::prefix('timekeeping-settings')->middleware('permission:staff.view')->group(function () {
        Route::get('/', [TimekeepingSettingController::class, 'index']);
        Route::middleware('permission:staff.manage')->group(function () {
            Route::post('/', [TimekeepingSettingController::class, 'store']);
        });
    });

    // Bản ghi chấm công
    Route::prefix('timekeeping-records')->middleware('permission:staff.view')->group(function () {
        Route::get('/', [TimekeepingRecordController::class, 'index']);
        Route::get('{id}', [TimekeepingRecordController::class, 'show']);
        Route::middleware('permission:staff.manage')->group(function () {
            Route::post('/', [TimekeepingRecordController::class, 'store']);
            Route::put('{id}', [TimekeepingRecordController::class, 'update']);
            Route::post('recalculate', [TimekeepingRecordController::class, 'recalculate']);
        });
    });
});
```

---

## 10. Agent đồng bộ — PHP Agent (LAN)

### 10.1 Yêu cầu

- Máy Windows cùng LAN với máy chấm công
- PHP 8.2+ (extensions: `sockets`, `curl`)
- Composer

### 10.2 Cài đặt

```powershell
# 1. Cài PHP trên Windows (dùng Scoop)
Set-ExecutionPolicy RemoteSigned -Scope CurrentUser
irm get.scoop.sh | iex
scoop install php

# 2. Bật extensions trong php.ini
# extension=curl
# extension=sockets

# 3. Cài Composer
# Tải tại: https://getcomposer.org/Composer-Setup.exe

# 4. Cài thư viện
cd tools/attendance-agent
composer install
```

### 10.3 Cấu hình

Copy `agent.config.example.bat` → `agent.config.bat`:

```bat
@echo off
set SERVER_URL=https://pos.yourdomain.com
set DEVICE_ID=1
set DEVICE_IP=192.168.1.222
set DEVICE_PORT=4370
set AGENT_SECRET=your-long-random-secret-must-match-server

REM Optional
set TIMEOUT=5
set INSECURE=0
set CA_BUNDLE=
set HTTP_TIMEOUT=20
set CONNECT_TIMEOUT=10
set IP_RESOLVE=v4
```

### 10.4 Chạy

```bat
# Chạy thủ công
run-agent.bat

# Hoặc trực tiếp
php agent.php --server=https://pos.yourdomain.com --device-id=1 --device-ip=192.168.1.222 --port=4370 --secret=YOUR_SECRET
```

### 10.5 Tự động hóa — Windows Task Scheduler

1. Mở **Task Scheduler** → Create Basic Task
2. Trigger: **Repeat every 1-5 minutes**
3. Action: **Start a program**
   - Program: `C:\php\php.exe` (hoặc đường dẫn PHP)
   - Arguments: `agent.php --server=... --device-id=... --device-ip=... --secret=...`
   - Start in: `C:\path\to\tools\attendance-agent`

### 10.6 Luồng hoạt động

```
1. Load checkpoint từ state.json (last_punched_at)
2. Kết nối máy chấm công (UDP 4370)
3. Tắt máy tạm (Disable) → Đọc log → Bật lại → Ngắt kết nối
4. Lọc log mới hơn checkpoint - 5 phút (chống mất log biên)
5. Chia batch 500 log/request → POST /api/attendance-agent/push-logs
6. Ký HMAC cho mỗi request:
   signature = hash_hmac('sha256', "{timestamp}.{device_id}.{json_body}", secret)
7. Lưu checkpoint mới vào state.json
```

---

## 11. Agent đồng bộ — C# Windows Service

> Phương án thay thế PHP Agent — ổn định hơn, chạy nền, auto-start, dùng SDK chính hãng.

### 11.1 Tổng quan

```
- Ngôn ngữ: C# (.NET 8), Worker Service template
- Cài đặt: MSI installer (WiX v4)
- Config: appsettings.json + UI cấu hình
- Kết nối máy: Dùng SDK chính hãng ZKTeco (zkemkeeper.dll)
- Push log: HTTPS + HMAC (giống PHP agent)
- Tính năng thêm: Auto-update, offline catch-up, user sync 2 chiều
```

### 11.2 Config format

```json
{
  "Server": {
    "BaseUrl": "https://pos.yourdomain.com",
    "Secret": "your-hmac-secret"
  },
  "Device": {
    "DeviceId": "1",
    "IpAddress": "192.168.1.222",
    "Port": 4370,
    "CommKey": 0
  },
  "Sync": {
    "IntervalSeconds": 30,
    "BatchSize": 500,
    "RetryCount": 3,
    "RetryDelayMs": 2000
  }
}
```

### 11.3 Xử lý offline (quan trọng!)

Khi máy Windows mất mạng hoặc tắt:

```
Khi khởi động lại:
1. Đọc checkpoint từ lần sync thành công cuối cùng
2. Đọc TẤT CẢ log từ máy chấm công
3. Lọc log từ checkpoint trở đi (overlap 5 phút)
4. Push hết lên server theo batch 500
5. Server tự xử lý idempotent (upsert unique key)
→ Không mất dữ liệu
```

### 11.4 API contracts cho C# agent

```
POST /api/attendance-agent/push-logs
  Body: { "logs": [{ "device_user_id": "5", "punched_at": "2026-03-01T08:30:00+07:00", "event_type": null }] }
  Headers: X-Device-Id, X-Timestamp, X-Signature

GET /api/attendance-agent/users
  Response: { "data": [{ "id": 1, "code": "NV001", "attendance_code": "5", "name": "Nguyễn Văn A" }] }

POST /api/attendance-agent/sync-status
  Body: { "device_id": "1", "app_version": "1.0.0", "sync_type": "logs", "counts": { "sent": 100, "saved": 98 } }

GET /api/attendance-agent/bridge/latest?channel=stable&current=1.0.0
  Response: { "update_available": true, "version": "1.1.0", "download_url": "...", "mandatory": false }
```

---

## 12. Frontend — Giao diện chấm công

### 12.1 Cấu trúc file

```
resources/
├── js/
│   ├── attendance-app.js                      # Entry: Bảng chấm công
│   ├── employee-attendance-devices-app.js     # Entry: Quản lý thiết bị
│   └── views/
│       └── AttendanceSheet.vue                # Component bảng chấm công
├── views/
│   └── employees/
│       ├── attendance.blade.php               # Blade: Bảng chấm công
│       ├── attendance-unmapped.blade.php       # Blade: Log chưa map
│       └── attendance-devices.blade.php       # Blade: Quản lý thiết bị
```

### 12.2 Đăng ký Vite entry

```js
// vite.config.js
export default defineConfig({
    plugins: [
        laravel({
            input: [
                // ... other entries
                'resources/js/attendance-app.js',
                'resources/js/employee-attendance-devices-app.js',
            ],
        }),
    ],
});
```

### 12.3 Web routes

```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::get('/employees/attendance', fn() => view('employees.attendance'));
    Route::get('/employees/attendance-unmapped', fn() => view('employees.attendance-unmapped'));
    Route::get('/employees/attendance-devices', fn() => view('employees.attendance-devices'));
});
```

---

## 13. Triển khai & vận hành

### 13.1 Triển khai lần đầu

```bash
# === TRÊN SERVER (VPS) ===

# 1. Chạy migration
php artisan migrate

# 2. Thêm biến môi trường
# .env
ATTENDANCE_AGENT_SECRET=TạoChuỗiNgẫuNhiênDàiỞĐây_ÍtNhất32KýTự

# 3. Clear config
php artisan config:clear

# 4. (Tùy chọn) Tạo thiết bị trong DB
# Hoặc agent sẽ tự tạo khi push log lần đầu

# === TRÊN MÁY WINDOWS (CỬA HÀNG) ===

# 5. Cài PHP + Composer (xem mục 10.2)
# 6. Copy folder tools/attendance-agent
# 7. Tạo agent.config.bat (secret phải KHỚP server)
# 8. Chạy run-agent.bat → Kiểm tra kết quả

# === TRÊN WEBSITE ===

# 9. Vào quản lý nhân viên → Nhập attendance_code cho từng nhân viên
#    (khớp với ID trên máy chấm công)
# 10. Bấm "Refresh Mapping" trên trang quản lý log
# 11. Kiểm tra bảng chấm công
```

### 13.2 Vận hành hàng ngày

- Agent tự chạy theo schedule (Task Scheduler hoặc chạy liên tục)
- Log push lên server → tự map → tự tính chấm công
- Admin kiểm tra bảng chấm công trên web
- Nếu có log "unmapped" → kiểm tra attendance_code của nhân viên

### 13.3 Thêm nhân viên mới

1. Đăng ký vân tay/khuôn mặt trên máy chấm công → ghi nhận **User ID** trên máy
2. Tạo nhân viên trên website → nhập `attendance_code = User ID` ở trên
3. Chạy `attendance:refresh` hoặc bấm "Refresh Mapping" trên web
4. Log mới sẽ tự map từ lần push tiếp theo

### 13.4 Thêm máy chấm công mới

1. Đặt IP tĩnh cho máy mới trên LAN
2. Tạo device record trên web (hoặc để agent tự tạo)
3. Cấu hình agent mới (hoặc thêm device vào config agent hiện tại)
4. Chạy agent → log từ máy mới sẽ về server

---

## 14. Xử lý sự cố

### 14.1 Agent không push được log

| Triệu chứng | Nguyên nhân | Giải pháp |
|-------------|-------------|-----------|
| HTTP 401 | HMAC sai | Kiểm tra `AGENT_SECRET` khớp giữa agent và `.env` server |
| HTTP 401 "Timestamp expired" | Lệch giờ | Đồng bộ NTP trên máy Windows |
| HTTP 500 | Lỗi server | Kiểm tra `storage/logs/laravel.log` |
| Timeout | Mạng chậm | Tăng `HTTP_TIMEOUT`, `CONNECT_TIMEOUT` |
| Lỗi 10040/10045 | UDP buffer Windows | Dùng bản ZKLib đã patch (tăng buffer, bỏ MSG_WAITALL) |
| "Class FreeSize not found" | Autoload lỗi | Chạy `composer dump-autoload` trong thư mục agent |
| SSL certificate error | Thiếu CA bundle | Đặt `INSECURE=1` (tạm) hoặc cấu hình `CA_BUNDLE` |

### 14.2 Log đã lên server nhưng không hiện chấm công

| Triệu chứng | Nguyên nhân | Giải pháp |
|-------------|-------------|-----------|
| Log `employee_id = null` | Chưa map | Kiểm tra `attendance_code` của nhân viên khớp `device_user_id` |
| Có log map rồi nhưng timekeeping trống | Chưa có lịch làm việc | Tạo `employee_work_schedule` cho nhân viên |
| Chấm công sai (về sớm nhưng hiện đi muộn) | Logic tính sai | Kiểm tra `TimekeepingService`, đảm bảo dùng `lessThan()`/`greaterThan()` |
| check_in == check_out | Chỉ có 1 log | Đảm bảo code KHÔNG set checkout khi chỉ có 1 log |

### 14.3 Mất dữ liệu khi offline

- Máy chấm công **LƯU NỘI BỘ** — dữ liệu không mất
- Khi agent chạy lại → đọc lại toàn bộ → push lên server
- Server **upsert** (unique key) → không trùng lặp
- Nếu lo lắng: chạy `php artisan attendance:refresh --days=30`

### 14.4 Lệnh kiểm tra nhanh

```bash
# Kiểm tra log gần nhất
php artisan tinker
AttendanceLog::latest('punched_at')->take(5)->get(['id','employee_id','device_user_id','punched_at']);

# Kiểm tra log chưa map
AttendanceLog::whereNull('employee_id')->count();

# Map lại và tính lại 7 ngày
php artisan attendance:refresh --days=7

# Tính lại khoảng cụ thể
php artisan attendance:refresh --from=2026-02-01 --to=2026-02-28
```

---

## 15. Checklist triển khai

### 15.1 Server

- [ ] Chạy migration (tạo bảng `attendance_devices`, `attendance_logs`, `timekeeping_records`, `timekeeping_settings`)
- [ ] Thêm cột `attendance_code` vào bảng `employees` 
- [ ] Thêm `ATTENDANCE_AGENT_SECRET` vào `.env`
- [ ] Thêm config `services.attendance_agent` vào `config/services.php`
- [ ] Tạo middleware `VerifyAttendanceAgentSignature` → đăng ký alias `attendance.agent`
- [ ] Tạo Models: `AttendanceLog`, `AttendanceDevice`, `TimekeepingRecord`, `TimekeepingSetting`
- [ ] Tạo Controllers: `AttendanceAgentController`, `AttendanceLogController`, `AttendanceDeviceController`, `TimekeepingRecordController`
- [ ] Tạo `TimekeepingService` + `RecalculateTimekeepingForRangeJob`
- [ ] Tạo command `attendance:refresh`
- [ ] Đăng ký routes (HMAC group + Sanctum group)
- [ ] `php artisan config:clear`
- [ ] Test endpoint: `GET /api/test` với HMAC headers

### 15.2 Agent (Windows)

- [ ] Cài PHP 8.2+ (extensions: sockets, curl)
- [ ] Cài Composer
- [ ] Copy folder agent + `composer install`
- [ ] Tạo `agent.config.bat` (secret KHỚP server)
- [ ] Test: `run-agent.bat` → HTTP 200
- [ ] Cấu hình Task Scheduler (mỗi 1-5 phút)

### 15.3 Dữ liệu

- [ ] Tạo thiết bị chấm công (IP, port, tên)
- [ ] Nhập `attendance_code` cho tất cả nhân viên (khớp User ID trên máy)
- [ ] Tạo lịch làm việc (`employee_work_schedules`) cho nhân viên
- [ ] Tạo ca làm việc (`shifts`) nếu cần
- [ ] Cấu hình `timekeeping_settings` (grace minutes, OT rules...)
- [ ] Bấm "Refresh Mapping" hoặc `php artisan attendance:refresh`
- [ ] Kiểm tra bảng chấm công

### 15.4 Monitoring

- [ ] (Tùy chọn) Cấu hình cron `attendance:refresh --days=3` mỗi 10 phút
- [ ] Kiểm tra log server (`storage/logs/laravel.log`)
- [ ] Kiểm tra trang "Unmapped Users" định kỳ
- [ ] Kiểm tra `last_sync_at` của thiết bị để detect agent dừng hoạt động

---

## Phụ lục: Tóm tắt API

| Method | Endpoint | Auth | Mục đích |
|--------|----------|------|----------|
| POST | `/api/attendance-agent/push-logs` | HMAC | Agent push log |
| GET | `/api/attendance-agent/users` | HMAC | Lấy danh sách nhân viên |
| POST | `/api/attendance-agent/sync-status` | HMAC | Agent báo cáo |
| GET | `/api/attendance-agent/bridge/latest` | HMAC | Check update |
| POST | `/api/attendance-agent/refresh-mapping` | HMAC | Re-map log |
| GET | `/api/attendance-devices` | Sanctum | List thiết bị |
| POST | `/api/attendance-devices` | Sanctum | Thêm thiết bị |
| POST | `/api/attendance-devices/{id}/test-connection` | Sanctum | Test kết nối |
| POST | `/api/attendance-devices/{id}/sync` | Sanctum | Sync trực tiếp |
| GET | `/api/attendance-logs` | Sanctum | List log |
| GET | `/api/attendance-logs/unmapped-users` | Sanctum | Log chưa map |
| POST | `/api/attendance-logs/refresh-mapping` | Sanctum | Admin re-map |
| GET | `/api/timekeeping-records` | Sanctum | Bảng chấm công |
| POST | `/api/timekeeping-records` | Sanctum | Chấm công thủ công |
| POST | `/api/timekeeping-records/recalculate` | Sanctum | Tính lại |
| GET | `/api/timekeeping-settings` | Sanctum | Lấy cài đặt |
| POST | `/api/timekeeping-settings` | Sanctum | Lưu cài đặt |
