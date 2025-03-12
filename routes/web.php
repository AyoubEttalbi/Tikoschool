<?php
use App\http\Middleware\AdminMiddleware;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Models\Student;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\LevelController;
use App\Models\Level;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\MembershipController;
Route::get('/', function () {
    return Inertia::render('Auth/Login' );
});


Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::resource('students', StudentsController::class);
    Route::resource('teachers', TeacherController::class);
    Route::resource('classes', ClassesController::class);
    
    Route::resource('assistants', AssistantController::class);
    Route::delete('/othersettings/levels/{level}', [LevelController::class, 'destroy'])->name('othersettings.destroy');

    Route::resource('othersettings', LevelController::class );
    Route::resource('offers', OfferController::class );
    
    Route::post('/othersettings', [SubjectController::class, 'store'])->name('othersettings.store');

    Route::put('/othersettings/{subject}', [SubjectController::class, 'update'])->name('othersettings.update');
    Route::delete('/othersettings/subjects/{subject}', [SubjectController::class, 'destroy'])->name('othersettings.destroy');
    Route::get('/setting', [RegisteredUserController::class, 'show'])->name('register')->middleware(AdminMiddleware::class);
    Route::post('/setting', [RegisteredUserController::class, 'store'])->name('register.store')->middleware(AdminMiddleware::class);
    

    Route::post('/othersettings/levels', [LevelController::class, 'store'])->name('othersettings.levels.store');


    Route::post('/othersettings/subjects', [SubjectController::class, 'store'])->name('othersettings.subjects.store');
    Route::delete('/classes/students/{student}', [ClassesController::class, 'removeStudent'])->name('classes.removeStudent');
    Route::resource('memberships', MembershipController::class);
});


require __DIR__.'/auth.php';


