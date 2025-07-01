<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        // ...existing code...
        'can.view.teacher.profile' => \App\Http\Middleware\CanViewTeacherProfile::class,
        // ...existing code...
    ];

    // ...existing code...
}