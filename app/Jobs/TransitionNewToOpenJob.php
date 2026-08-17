<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransitionNewToOpenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $count = 0;
        
        DB::transaction(function () use (&$count) {
            ServiceRequest::where('status', 'new')
                ->where('created_at', '<=', now()->subHours(24))
                ->chunk(100, function ($requests) use (&$count) {
                    $ids = $requests->pluck('id');
                    $count += ServiceRequest::whereIn('id', $ids)->update([
                        'status' => 'open',
                        'opened_at' => now(),
                    ]);
                });
        });

        Log::info("TransitionNewToOpenJob completed. Total transitioned: {$count}");
    }
}
