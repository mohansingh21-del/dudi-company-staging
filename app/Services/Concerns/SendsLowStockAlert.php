<?php

namespace App\Services\Concerns;

use App\Mail\LowStockAlertMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared by both inventories: the mine's own stock (InventoryStockService) and
 * each outside store's stock (StoreStockService). The recipients and the
 * swallow-and-log failure handling are identical for both — only the location
 * named in the mail differs.
 */
trait SendsLowStockAlert
{
    /**
     * @param  string       $productName
     * @param  float        $currentStock
     * @param  string|null  $storeName  Null for the mine's own inventory.
     * @return void
     */
    protected function sendLowStockAlert($productName, $currentStock, $storeName = null)
    {
        try {
            $recipients = User::whereHas('roles', function ($query) {
                $query->whereIn('slug', ['super-admin', 'supervisor']);
            })->get();

            $emails = $recipients->pluck('email')->filter()->toArray();
            if (!empty($emails) && class_exists(LowStockAlertMail::class)) {
                Mail::to($emails)->send(new LowStockAlertMail($productName, $currentStock, $storeName));
            }
        } catch (\Throwable $th) {
            Log::error("Failed to send low stock alert email: " . $th->getMessage());
        }
    }
}
