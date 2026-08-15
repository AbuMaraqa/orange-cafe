<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'station', 'connection_type', 'ip_address', 'port',
        'usb_vendor_id', 'usb_product_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_active' => 'boolean',
            'status_checked_at' => 'datetime',
        ];
    }

    public function isUsb(): bool
    {
        return $this->connection_type === 'usb';
    }

    /**
     * Records the outcome of a real send or a connectivity probe so the
     * admin printer list reflects reality instead of staying silent
     * between print jobs — see PrinterTransport, which is the only
     * caller, and the scheduled printers:check-status command that
     * probes idle printers so status doesn't go stale between orders.
     */
    public function recordStatus(bool $reachable): void
    {
        $this->forceFill([
            'status' => $reachable ? 'online' : 'offline',
            'status_checked_at' => now(),
        ])->save();
    }
}
