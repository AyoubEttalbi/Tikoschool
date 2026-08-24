<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * « Mes paiements » — the assistant's own payroll history.
 *
 * The rows lived on the assistant profile page before, buried under work tabs;
 * tracking one's salary is a destination, not a tab. Same data, same shape the
 * AssistantPaymentsCard already renders.
 */
class MyPaymentsController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $transactions = collect();
        if ($user) {
            $transactions = Transaction::where('user_id', $user->id)
                ->orderByDesc('payment_date')
                ->get();

            // Mark recurring rows paid this month, same rule as AssistantController::show,
            // so AssistantPaymentsCard keeps its Payé/À venir badges without changes.
            $currentMonth = now()->format('Y-m');
            $startDate = \Carbon\Carbon::parse($currentMonth.'-01')->startOfMonth();
            $endDate = \Carbon\Carbon::parse($currentMonth.'-01')->endOfMonth();

            $transactions->each(function ($transaction) use ($startDate, $endDate) {
                if ($transaction->is_recurring) {
                    $transaction->paid_this_month = Transaction::where('is_recurring', 0)
                        ->where('description', 'like', '%(Recurring payment from #'.$transaction->id.')%')
                        ->whereBetween('payment_date', [$startDate, $endDate])
                        ->exists();
                }
            });
        }

        return Inertia::render('Menu/MyPaymentsPage', [
            'transactions' => $transactions,
            'userId' => $user?->id,
        ]);
    }
}
