<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    protected function getUserProfileImage($user)
    {
        if (! $user) {
            return null;
        }

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
     * Now: a small number of queries, joined in PHP, and scoped to the caller's schools.
     *
     * The comment above described the cross-tenant leak but only the N+1 half was fixed —
     * the query still returned every user in the installation. It is now restricted to
     * staff who share a school with the caller, plus admins (who must always be
     * reachable, or a teacher at a school with no other staff could message nobody).
     */
    protected function getUsersList($currentUser)
    {
        if (! $currentUser) {
            return [];
        }

        $schoolIds = SchoolScope::schoolIdsFor($currentUser);

        $users = User::query()
            ->where('id', '!=', $currentUser->id)
            ->when($schoolIds !== null, function ($q) use ($schoolIds) {
                // Staff identity joins users to teachers/assistants BY EMAIL, not by a
                // foreign key (see User::teacher()), so the school filter has to go
                // through those tables' emails rather than a user_id.
                $emails = collect();

                if (! empty($schoolIds)) {
                    // Both pivots and both staff tables carry softDeletes(), and a raw
                    // DB::table() join does not apply Eloquent's global scope — without
                    // these whereNulls a teacher detached from a school would still show up.
                    $emails = $emails
                        ->merge(
                            DB::table('teachers')
                                ->join('school_teacher', 'school_teacher.teacher_id', '=', 'teachers.id')
                                ->whereIn('school_teacher.school_id', $schoolIds)
                                ->whereNull('school_teacher.deleted_at')
                                ->whereNull('teachers.deleted_at')
                                ->pluck('teachers.email')
                        )
                        ->merge(
                            DB::table('assistants')
                                ->join('assistant_school', 'assistant_school.assistant_id', '=', 'assistants.id')
                                ->whereIn('assistant_school.school_id', $schoolIds)
                                ->whereNull('assistant_school.deleted_at')
                                ->whereNull('assistants.deleted_at')
                                ->pluck('assistants.email')
                        );
                }

                $emails = $emails->filter()->unique()->values()->all();

                $q->where(function ($inner) use ($emails) {
                    $inner->where('role', 'admin');

                    if (! empty($emails)) {
                        $inner->orWhereIn('email', $emails);
                    }
                });
            })
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
        if (! $user) {
            return 0;
        }

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
                // Several pages already read `flash.success` (PaymentsPage among them) while
                // only `message` was ever shared, so those banners never rendered. Both keys
                // are published rather than renaming one and breaking the other consumers.
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Structured money notice — see App\Support\PaymentNotice. Rendered as a
                // dialog by PaymentNoticeDialog, mounted once in DashboardLayout.
                'payment' => fn () => $request->session()->get('payment_notice'),
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
