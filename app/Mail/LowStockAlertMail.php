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

    public function __construct(string $productName, float $leftQuantity)
    {
        $this->productName = $productName;
        $this->leftQuantity = $leftQuantity;
    }

    public function build()
    {
        return $this->subject("Low Stock Alert: {$this->productName} Minimum Quantity Reached")
                    ->view('emails.low_stock_alert');
    }
}
