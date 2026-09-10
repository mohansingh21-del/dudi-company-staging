<?php

namespace App\Services\Concerns;

use App\Mail\LowStockAlertMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared by the stock service and the inventory controller. The recipients and
 * the swallow-and-log failure handling are the same wherever stock runs low —
 * only the product and the store named in the mail differ.
 */
trait SendsLowStockAlert
{
    /**
     * @param  string       $productName
     * @param  float        $currentStock
     * @param  string|null  $storeName  The store the stock ran low at.
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
