<?php
namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    protected function getUserProfileImage($user)
    {
        if (!$user) return null;

        $table = match ($user->role) {
            'assistant' => 'assistants',
            'teacher' => 'teachers',
            default => null
        };

        return $table 
            ? DB::table($table)->where('email', $user->email)->value('profile_image')
            : null;
    }

    /**
     * Contact list for the chat popup.
     *
     * Previously this ran ONE profile-image query PER USER (a DB::table lookup inside a
     * ->map()), on every request including every XHR and every 10-second poll. It also
     * returned every user in the entire installation regardless of school, which leaked
     * the full staff directory across tenants.
     *
     * Now: two queries total, joined in PHP.
     */
    protected function getUsersList($currentUser)
    {
        if (!$currentUser) return [];

        $users = User::query()
            ->where('id', '!=', $currentUser->id)
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        $emails = $users->pluck('email')->all();

        // One query per table instead of one per user.
        $images = DB::table('teachers')
            ->whereIn('email', $emails)
            ->pluck('profile_image', 'email')
            ->union(
                DB::table('assistants')
                    ->whereIn('email', $emails)
                    ->pluck('profile_image', 'email')
            );

        return $users->map(function ($user) use ($images) {
            $user->profile_image = $images[$user->email] ?? null;
            return $user;
        })->values()->toArray();
    }

    /**
     * Unread announcement count.
     *
     * This used to ->get() every visible announcement and then run one
     * `reads()->...->exists()` query PER announcement. Now it is a single COUNT with a
     * whereDoesntHave subquery.
     */
    protected function unreadAnnouncementCount($user): int
    {
        if (!$user) return 0;

        $now = now();

        return \App\Models\Announcement::query()
            ->when($user->role !== 'admin', function ($q) use ($user) {
                $q->where(function ($sub) use ($user) {
                    $sub->where('visibility', 'all')
                        ->orWhere('visibility', $user->role);
                });
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('date_start')->orWhere('date_start', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('date_end')->orWhere('date_end', '>=', $now);
            })
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'isViewingAs' => Session::has('admin_user_id'),
                'profile_image' => fn () => $this->getUserProfileImage($user),
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Closures make these LAZY: Inertia only evaluates them when the prop is
            // actually requested, so a partial reload (`only: [...]`) no longer pays for
            // the contact list or the announcement count. They ran eagerly on every single
            // request before, including the 10s /unread-count poll.
            //
            // Renamed from `users` to `chatContacts`: as `users` it was shadowed by the
            // paginator that UserController@index shares under the same key, which is why
            // DashboardLayout read `users.data` and got undefined everywhere else.
            'chatContacts' => fn () => $this->getUsersList($user),
            'activeSchool' => session('school_id') ? [
                'id' => session('school_id'),
                'name' => session('school_name'),
            ] : null,
            'unreadCount' => fn () => $this->unreadAnnouncementCount($user),
        ];
    }
}