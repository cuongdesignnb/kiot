<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ExternalInventoryReservation;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePcReservations extends Command
{
    protected $signature = 'integrations:expire-pc-reservations {--chunk=200}';

    protected $description = 'Expire active Website PC inventory reservations without changing physical stock';

    public function handle(): int
    {
        $expiredCount = 0;
        $chunkSize = min(1000, max(1, (int) $this->option('chunk')));

        ExternalInventoryReservation::query()
            ->where('status', ExternalInventoryReservation::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($reservations) use (&$expiredCount) {
                foreach ($reservations as $candidate) {
                    DB::transaction(function () use ($candidate, &$expiredCount) {
                        $reservation = ExternalInventoryReservation::query()->lockForUpdate()->find($candidate->id);
                        if (! $reservation || $reservation->status !== ExternalInventoryReservation::STATUS_ACTIVE || $reservation->expires_at->isFuture()) {
                            return;
                        }

                        $order = Order::query()->lockForUpdate()->find($reservation->order_id);
                        if (! $order || $order->status === Order::STATUS_COMPLETED || $order->invoice()->exists()) {
                            return;
                        }

                        $reservation->update([
                            'status' => ExternalInventoryReservation::STATUS_EXPIRED,
                            'released_at' => now(),
                        ]);
                        $expiredCount++;

                        ActivityLog::log(
                            ActivityLog::ACTION_EXTERNAL_RESERVATION_EXPIRED,
                            "Giữ tồn Website PC hết hạn cho đơn {$order->code}",
                            $order,
                            ['reservation_id' => $reservation->id],
                        );
                    });
                }
            });

        $this->info("Expired {$expiredCount} Website PC reservation(s).");

        return self::SUCCESS;
    }
}
