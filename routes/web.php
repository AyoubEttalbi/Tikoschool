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
use App\Http\Controllers\OffersController;
use App\Http\Controllers\AssistantsController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\Auth\RegisteredUserController;

Route::get('/', function () {
    return Inertia::render('Auth/Login' );
});


    // 'canLogin' => Route::has('login'),
    // 'canRegister' => Route::has('register'),
    // 'laravelVersion' => Application::VERSION,
    // 'phpVersion' => PHP_VERSION,

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::resource('students', StudentsController::class);
    Route::resource('classes', ClassesController::class);
    Route::resource('offers', OffersController::class);
    Route::resource('assistants', AssistantsController::class);
    Route::delete('/othersettings/levels/{level}', [LevelController::class, 'destroy'])->name('othersettings.destroy');

    Route::resource('othersettings', LevelController::class );
    Route::post('/othersettings', [SubjectController::class, 'store'])->name('othersettings.store');

    Route::put('/othersettings/{subject}', [SubjectController::class, 'update'])->name('othersettings.update');
    Route::delete('/othersettings/subjects/{subject}', [SubjectController::class, 'destroy'])->name('othersettings.destroy');
    Route::get('/setting', [RegisteredUserController::class, 'show'])->name('register')->middleware(AdminMiddleware::class);
    Route::post('/setting', [RegisteredUserController::class, 'store'])->name('register.store')->middleware(AdminMiddleware::class);;
    Route::resource('teachers', TeacherController::class);
    // Custom routes for Levels
Route::post('/othersettings/levels', [LevelController::class, 'store'])->name('othersettings.levels.store');
Route::get('/{view}', [SubjectController::class, 'index']);
// Custom routes for Subjects
Route::post('/othersettings/subjects', [SubjectController::class, 'store'])->name('othersettings.subjects.store');
});


require __DIR__.'/auth.php';





Route::get('/offers', function () {
    return Inertia::render('Menu/OffersPage');
})->middleware(AdminMiddleware::class);



Route::get('/classes', function () {    
    return Inertia::render('Menu/ClassesPage');
});

$classes = [
    1 => ['name' => '2BAC SVT G1', 'level' => '2BAC'],
    2 => ['name' => '1BAC PC G2', 'level' => '1BAC'],
    3 => ['name' => '3BAC Math G3', 'level' => '3BAC'],
    4 => ['name' => '2BAC Science G4', 'level' => '2BAC'],
    5 => ['name' => '1BAC English G5', 'level' => '1BAC'],
];

$students = [
    [
        "id" => 1,
        "studentId" => "1",
        "name" => "John Doe",
        "email" => "john@doe.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg",
        "phone" => "1234567890",
        "grade" => 5,
        "class" => "2BAC SVT G1",
        "address" => "123 Main St, Anytown, USA",
    ],
    [
        "id" => 2,
        "studentId" => "2",
        "name" => "Jane Smith",
        "email" => "jane@smith.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg",
        "phone" => "0987654321",
        "grade" => 5,
        "class" => "1BAC PC G2",
        "address" => "456 Elm St, Othertown, USA",
    ],
    [
        "id" => 3,
        "studentId" => "3",
        "name" => "Bob Johnson",
        "email" => "bob@johnson.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg",
        "phone" => "5551234567",
        "grade" => 4,
        "class" => "3BAC Math G3",
        "address" => "789 Oak St, Smalltown, USA",
    ],
    [
        "id" => 4,
        "studentId" => "4",
        "name" => "Alice Brown",
        "email" => "alice@brown.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg",
        "phone" => "4445556666",
        "grade" => 3,
        "class" => "2BAC Science G4",
        "address" => "901 Maple St, Largetown, USA",
    ],
    [
        "id" => 5,
        "studentId" => "5",
        "name" => "Mike Davis",
        "email" => "mike@davis.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg",
        "phone" => "3332221111",
        "grade" => 2,
        "class" => "1BAC English G5",
        "address" => "234 Pine St, Othertown, USA",
    ],
];

Route::get('/classes/{id}', function ($id) use ($classes, $students) {
    if (!isset($classes[$id])) {
        abort(404);
    }

    $className = $classes[$id]['name'];
    $classStudents = collect($students)->filter(fn($student) => $student['class'] === $className)->values();

    return Inertia::render('Menu/SingleClassPage', [
        'className' => $className,
        'students' => $classStudents,
    ]);
});


Route::get('/assistants', function () {
    return Inertia::render('Menu/AssistantsListPage');
});


Route::get('/assistants/{id}', function ($id) {
    return Inertia::render('Menu/SingleAssistantPage');
});

// Route::get('/othersettings', function () {
//     return Inertia::render('Menu/Othersettings');
// });