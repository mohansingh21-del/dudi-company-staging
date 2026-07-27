<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\User;
use App\Mail\LowStockAlertMail;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InventoryStockService
{
    /**
     * Deduct stock for a product, ensuring quantity does not drop below min_stock.
     *
     * @param int $productId
     * @param float $quantity
     * @param int $userId
     * @param string|null $remarks
     * @return array
     */
    public function deductStock($productId, $quantity, $userId, $remarks = null)
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Inventory product with ID {$productId} not found."]
                ]
            ], 422));
        }

        $inventory = Inventory::where('product_id', $productId)->first();
        if (!$inventory) {
            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["No inventory stock record found for product '{$product->name}'."]
                ]
            ], 422));
        }

        $availableStock = (float) $inventory->left_quantity;
        $minStock = (float) $product->min_stock;
        $qtyToDeduct = (float) $quantity;

        // Check if assigning quantity causes stock to go below minimum stock
        if (($availableStock - $qtyToDeduct) < $minStock) {
            $productName = $product->name ?? 'Unknown Product';
            $this->sendLowStockAlert($productName, $availableStock);

            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Cannot assign/deduct stock for '{$productName}'. Stock level after deduction would drop below minimum stock limit ({$minStock}). Current available stock: {$availableStock}."]
                ]
            ], 422));
        }

        // Deduct inventory left_quantity
        $inventory->left_quantity -= $qtyToDeduct;
        $inventory->save();

        // Send low stock alert if left_quantity reaches or drops below min_stock
        if ((float) $inventory->left_quantity <= $minStock) {
            $this->sendLowStockAlert($product->name, (float) $inventory->left_quantity);
        }

        // Create inventory log entry
        InventoryLog::create([
            'product_id' => $productId,
            'user_id'    => $userId,
            'type'       => 'out',
            'action'     => 'service_spare_part',
            'quantity'   => -$qtyToDeduct,
            'remarks'    => "Deducted {$qtyToDeduct} units for service record" . ($remarks ? " - {$remarks}" : "")
        ]);

        return [
            'part_name'  => $product->name,
            'unit_price' => 0.00,
        ];
    }

    private function sendLowStockAlert($productName, $currentStock)
    {
        try {
            $recipients = User::whereHas('roles', function ($query) {
                $query->whereIn('slug', ['super-admin', 'supervisor']);
            })->get();

            $emails = $recipients->pluck('email')->filter()->toArray();
            if (!empty($emails) && class_exists(LowStockAlertMail::class)) {
                Mail::to($emails)->send(new LowStockAlertMail($productName, $currentStock));
            }
        } catch (\Throwable $th) {
            Log::error("Failed to send low stock alert email: " . $th->getMessage());
        }
    }
}
