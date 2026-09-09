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

    public function __construct(string $productName, float $leftQuantity, $storeName = null)
    {
        $this->productName = $productName;
        $this->leftQuantity = $leftQuantity;
        $this->storeName = $storeName;
    }

    public function build()
    {
        $location = $this->storeName ? " at {$this->storeName}" : '';

        return $this->subject("Low Stock Alert: {$this->productName} Minimum Quantity Reached{$location}")
                    ->view('emails.low_stock_alert');
    }
}
