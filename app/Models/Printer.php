<?php

namespace App\Models;

use App\Services\Printing\PrinterHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Printer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'station', 'connection_type', 'ip_address', 'port',
        'usb_vendor_id', 'usb_product_id', 'windows_share', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_active' => 'boolean',
            'status_checked_at' => 'datetime',
            'recovered_at' => 'datetime',
            'offline_since' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function isUsb(): bool
    {
        return $this->connection_type === 'usb';
    }

    /**
     * Printed through the Windows spooler rather than spoken to directly —
     * the only way a USB printer works in the packaged app, whose PHP has
     * no FFI extension for libusb.
     */
    public function isWindowsSpooler(): bool
    {
        return $this->connection_type === 'windows';
    }

    public function isCashier(): bool
    {
        return $this->station === 'cashier';
    }

    /** Down in either of the ways a printer can be down. */
    public function isDown(): bool
    {
        return in_array($this->status, [PrinterHealth::OFFLINE, PrinterHealth::UNRESPONSIVE], true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForStation(Builder $query, string $station): Builder
    {
        return $query->where('station', $station);
    }

    /**
     * Records the outcome of a real send or a connectivity probe.
     *
     * Kept as a thin delegate to PrinterHealth rather than doing the work
     * here: recovery has to stamp `recovered_at`, and that stamp is what
     * stops an hour of missed tickets from printing themselves the moment a
     * printer comes back. Having two places that can set `status` is how
     * that rule gets quietly bypassed.
     */
    public function recordStatus(bool $reachable, string $error = '', ?\DateTimeInterface $printedOrderAt = null): bool
    {
        $health = app(PrinterHealth::class);

        if ($reachable) {
            return $health->markReachable($this, $printedOrderAt);
        }

        $health->markUnreachable($this, $error);

        return false;
    }
}
