<?php

namespace App\Http\Concerns;

use App\Services\MasterUsageGuard;
use Illuminate\Database\Eloquent\Model;

trait GuardsMasterDeactivation
{
    /**
     * A 422 response when this master may not be switched off yet, null when it may.
     *
     * Only deactivation is guarded. Putting a row back on is always allowed —
     * nothing downstream breaks by having one more option in a picker.
     *
     * @param  mixed  $newStatus  the status being moved to, truthy for active
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function blockDeactivation(Model $master, $newStatus)
    {
        // Already off: switching it off again changes nothing, so there is
        // nothing to protect and no reason to run the counts.
        if ((bool) $newStatus || !$master->is_active) {
            return null;
        }

        $message = app(MasterUsageGuard::class)->blockMessage($master);

        if ($message === null) {
            return null;
        }

        return response()->json([
            'status' => 422,
            'message' => $message,
        ], 422);
    }
}
