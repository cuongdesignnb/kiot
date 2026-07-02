<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItemSerial extends Model
{
    protected $fillable = [
        'invoice_item_id',
        'serial_imei_id',
        'serial_number',
        'cost_price',
    ];

    protected $casts = [
        'cost_price' => 'decimal:0',
    ];

    public function invoiceItem()
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function serial()
    {
        return $this->belongsTo(SerialImei::class, 'serial_imei_id');
    }
}
