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

    protected function getUsersList($currentUser)
    {
        if (!$currentUser) return [];

        return User::query()
            ->where('id', '!=', $currentUser->id)
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                $user->profile_image = $this->getUserProfileImage($user);
                return $user;
            })
            ->toArray();
    }

    public function share(Request $request): array
{
    $user = $request->user();

    return [
        ...parent::share($request),

        'auth' => [
            'user' => $user,
            'isViewingAs' => Session::has('admin_user_id'),
            'profile_image' => $this->getUserProfileImage($user),
        ],

        'flash' => [
            'message' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
        ],

        'users' => $this->getUsersList($user),

        // ✅ Add active school globally
        'activeSchool' => session('school_id') ? [
            'id' => session('school_id'),
            'name' => session('school_name'),
        ] : null,
    ];
}
}