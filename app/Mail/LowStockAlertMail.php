<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LowStockAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $productName;
    public $leftQuantity;

    /**
     * Name of the outside store the stock sits at, or null for the mine's own
     * inventory. Optional so every existing call site keeps working unchanged.
     *
     * @var string|null
     */
    public $storeName;

    /**
     * Nothing left at all, rather than merely at or under min_stock.
     *
     * @var bool
     */
    public $outOfStock;

    public function __construct(string $productName, float $leftQuantity, $storeName = null, bool $outOfStock = false)
    {
        $this->productName = $productName;
        $this->leftQuantity = $leftQuantity;
        $this->storeName = $storeName;
        $this->outOfStock = $outOfStock;
    }

    public function build()
    {
        $location = $this->storeName ? " at {$this->storeName}" : '';

        $subject = $this->outOfStock
            ? "Out of Stock Alert: {$this->productName}{$location}"
            : "Low Stock Alert: {$this->productName} Minimum Quantity Reached{$location}";

        return $this->subject($subject)
                    ->view('emails.low_stock_alert');
    }
}
