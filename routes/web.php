<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\RoleRedirect;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\StatsController;

// Redirect to dashboard if authenticated, otherwise to login
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    // Dashboard route with RoleRedirect middleware
    Route::get('/dashboard', [StatsController::class, 'index'])
        ->middleware(RoleRedirect::class) // Apply RoleRedirect first
        ->name('dashboard');

    // Profile routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Resource routes
    Route::resource('students', StudentsController::class);
    Route::resource('teachers', TeacherController::class);
    Route::resource('classes', ClassesController::class);
    Route::resource('assistants', AssistantController::class);
    Route::resource('offers', OfferController::class);
    Route::resource('invoices', InvoiceController::class);
    Route::resource('memberships', MembershipController::class);



    // Admin-only routes
    Route::middleware(AdminMiddleware::class)->group(function () {
        Route::resource('othersettings', LevelController::class);
        Route::delete('/othersettings/levels/{level}', [LevelController::class, 'destroy'])->name('othersettings.destroy');
        Route::post('/othersettings', [SubjectController::class, 'store'])->name('othersettings.store');
        Route::put('/othersettings/{subject}', [SubjectController::class, 'update'])->name('othersettings.update');
        Route::delete('/othersettings/subjects/{subject}', [SubjectController::class, 'destroy'])->name('othersettings.destroy');
        Route::post('/othersettings/levels', [LevelController::class, 'store'])->name('othersettings.levels.store');
        Route::post('/othersettings/subjects', [SubjectController::class, 'store'])->name('othersettings.subjects.store');
        Route::get('/setting', [RegisteredUserController::class, 'show'])->name('register');
        Route::post('/setting', [RegisteredUserController::class, 'store'])->name('register.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Additional routes
    Route::delete('/classes/students/{student}', [ClassesController::class, 'removeStudent'])->name('classes.removeStudent');
    Route::delete('/students/invoices/{invoiceId}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

    // Inertia pages
    Route::get('/results', function () {
        return Inertia::render('Menu/ResultsPage');
    });
    Route::get('/attendance', function () {
        return Inertia::render('Menu/AttendancePage');
    });
    Route::get('/announcements', function () {
        return Inertia::render('Menu/AnnouncementsPage');
    });
});

// Authentication routes
require __DIR__ . '/auth.php';