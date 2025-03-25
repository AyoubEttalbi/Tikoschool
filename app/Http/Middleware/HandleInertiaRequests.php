<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $profile_image = null;

        if ($user) {
            switch ($user->role) {
                case 'assistant':
                    $profile_image = DB::table('assistants')
                        ->where('email', $user->email)
                        ->value('profile_image');
                    break;
                case 'teacher':
                    $profile_image = DB::table('teachers')
                        ->where('email', $user->email)
                        ->value('profile_image');
                    break;
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'isViewingAs' => Session::has('admin_user_id'),
                'profile_image' => $profile_image, // Add profile image to the shared data
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}