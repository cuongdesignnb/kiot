<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class HolidayController extends Controller
{
    public function index(Request $request)
    {
        $query = Holiday::query();

        if ($request->filled('from')) {
            $query->whereDate('holiday_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('holiday_date', '<=', $request->date('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('holiday_date')->get(),
        ]);
    }

    public function show(Holiday $holiday)
    {
        return response()->json(['success' => true, 'data' => $holiday]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:holidays,holiday_date'],
            'name' => ['required', 'string', 'max:255'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'paid_leave' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $holiday = Holiday::create([
            'holiday_date' => $data['holiday_date'],
            'name' => $data['name'],
            'multiplier' => $data['multiplier'] ?? 1,
            'paid_leave' => (bool) ($data['paid_leave'] ?? false),
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Tạo ngày lễ thành công', 'data' => $holiday]);
    }

    public function storeRange(Request $request)
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'name' => ['required', 'string', 'max:255'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'paid_leave' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $created = [];
        $skipped = 0;

        foreach (CarbonPeriod::create($data['start_date'], $data['end_date']) as $date) {
            $holidayDate = $date->toDateString();

            if (Holiday::whereDate('holiday_date', $holidayDate)->exists()) {
                $skipped++;
                continue;
            }

            $created[] = Holiday::create([
                'holiday_date' => $holidayDate,
                'name' => $data['name'],
                'multiplier' => $data['multiplier'] ?? 1,
                'paid_leave' => (bool) ($data['paid_leave'] ?? false),
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu kỳ lễ tết',
            'data' => [
                'created' => count($created),
                'skipped' => $skipped,
                'items' => $created,
            ],
        ]);
    }

    public function update(Request $request, Holiday $holiday)
    {
        $data = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:holidays,holiday_date,' . $holiday->id],
            'name' => ['required', 'string', 'max:255'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'paid_leave' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $holiday->update([
            'holiday_date' => $data['holiday_date'],
            'name' => $data['name'],
            'multiplier' => $data['multiplier'] ?? $holiday->multiplier,
            'paid_leave' => (bool) ($data['paid_leave'] ?? $holiday->paid_leave),
            'status' => $data['status'] ?? $holiday->status,
            'notes' => $data['notes'] ?? $holiday->notes,
        ]);

        return response()->json(['success' => true, 'message' => 'Cập nhật ngày lễ thành công', 'data' => $holiday]);
    }

    public function destroy(Holiday $holiday)
    {
        $holiday->delete();
        return response()->json(['success' => true, 'message' => 'Xóa ngày lễ thành công']);
    }

    /**
     * Auto-generate Vietnamese holidays for a year
     */
    public function autoGenerate(Request $request)
    {
        $year = $request->integer('year', (int) now()->year);
        $holidays = $this->getVietnameseHolidays($year);

        $created = 0;
        $skipped = 0;

        foreach ($holidays as $holiday) {
            if (Holiday::where('holiday_date', $holiday['date'])->exists()) {
                $skipped++;
            } else {
                Holiday::create([
                    'holiday_date' => $holiday['date'],
                    'name' => $holiday['name'],
                    'multiplier' => $holiday['multiplier'] ?? 1,
                    'paid_leave' => $holiday['paid_leave'] ?? true,
                    'status' => 'active',
                    'notes' => $holiday['notes'] ?? null,
                ]);
                $created++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Đã tạo {$created} ngày lễ, bỏ qua {$skipped} ngày đã tồn tại",
            'data' => ['year' => $year, 'created' => $created, 'skipped' => $skipped],
        ]);
    }

    private function getVietnameseHolidays(int $year): array
    {
        $holidays = [];

        $holidays[] = ['date' => "{$year}-01-01", 'name' => 'Tết Dương lịch', 'multiplier' => 2, 'paid_leave' => true];
        $holidays[] = ['date' => "{$year}-04-30", 'name' => 'Ngày Giải phóng miền Nam', 'multiplier' => 2, 'paid_leave' => true];
        $holidays[] = ['date' => "{$year}-05-01", 'name' => 'Ngày Quốc tế Lao động', 'multiplier' => 2, 'paid_leave' => true];
        $holidays[] = ['date' => "{$year}-09-02", 'name' => 'Ngày Quốc khánh', 'multiplier' => 2, 'paid_leave' => true];

        $tetDates = $this->calculateLunarNewYear($year);
        foreach ($tetDates as $idx => $date) {
            $dayName = match ($idx) {
                0 => 'Giao thừa', 1 => 'Mùng 1 Tết', 2 => 'Mùng 2 Tết',
                3 => 'Mùng 3 Tết', 4 => 'Mùng 4 Tết', default => 'Tết Nguyên Đán',
            };
            $holidays[] = ['date' => $date, 'name' => $dayName, 'multiplier' => 2, 'paid_leave' => true, 'notes' => 'Tết Nguyên Đán ' . $year];
        }

        $hungVuong = $this->lunarToSolar($year, 3, 10);
        if ($hungVuong) {
            $holidays[] = ['date' => $hungVuong, 'name' => 'Giỗ Tổ Hùng Vương', 'multiplier' => 2, 'paid_leave' => true];
        }

        return $holidays;
    }

    private function calculateLunarNewYear(int $year): array
    {
        $tetFirstDay = match ($year) {
            2024 => '2024-02-10', 2025 => '2025-01-29', 2026 => '2026-02-17',
            2027 => '2027-02-06', 2028 => '2028-01-26', 2029 => '2029-02-13',
            2030 => '2030-02-03', default => null,
        };

        if (!$tetFirstDay) return [];

        $firstDay = Carbon::parse($tetFirstDay);
        return [
            $firstDay->copy()->subDay()->toDateString(),
            $firstDay->toDateString(),
            $firstDay->copy()->addDay()->toDateString(),
            $firstDay->copy()->addDays(2)->toDateString(),
            $firstDay->copy()->addDays(3)->toDateString(),
        ];
    }

    private function lunarToSolar(int $year, int $lunarMonth, int $lunarDay): ?string
    {
        if ($lunarMonth === 3 && $lunarDay === 10) {
            return match ($year) {
                2024 => '2024-04-18', 2025 => '2025-04-07', 2026 => '2026-04-27',
                2027 => '2027-04-16', 2028 => '2028-04-05', 2029 => '2029-04-23',
                2030 => '2030-04-12', default => null,
            };
        }
        return null;
    }
}
