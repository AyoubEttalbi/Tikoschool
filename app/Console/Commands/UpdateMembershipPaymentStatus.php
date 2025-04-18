<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Membership;
use Carbon\Carbon;

class UpdateMembershipPaymentStatus extends Command
{
    protected $signature = 'memberships:update-payment-status';
    protected $description = 'Update membership payment status to expired if end_date has passed';

    public function handle()
    {
        $now = Carbon::now();
        $expiredMemberships = Membership::where('end_date', '<', $now)
            ->where('payment_status', '!=', 'expired')
            ->get();

        foreach ($expiredMemberships as $membership) {
            $membership->payment_status = 'expired';
            $membership->is_active = false;
            $membership->save();
            $this->info("Membership ID {$membership->id} marked as expired.");
        }

        $this->info('Membership payment statuses updated.');
    }
}
