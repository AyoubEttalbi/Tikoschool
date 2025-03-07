<?php

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
    Route::resource('othersettings', LevelController::class );
    Route::get('/setting', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/setting', [RegisteredUserController::class, 'store'])->name('register.store');
});

require __DIR__.'/auth.php';


// Route::get('/othersettings', function () {
//     $levels = Level::all();
//     return Inertia::render('Menu/Othersettings', [
//         'levels' => $levels,
//     ]);
// });

Route::get('/teachers', function () {
    return Inertia::render('Menu/TeacherListPage');
});
$teachersData = [
    [     
        "id" => 1,
      "firstName" => "Jane",
      "lastName" => "Smith",
      "address" => "456 Elm St, Othertown, USA",
      "phoneNumber" => "0987654321",
      "email" => "jane.smith@example.com",
      "status" => "active",
      "subjects" => ["History", "Geography", "French"],
      "wallet" => 500,
      "groupName" => "Group B",
    ],
    [
      "id" => 2,
      "firstName" => "Bob",
      "lastName" => "Johnson",
      "address" => "789 Oak St, Smalltown, USA",
      "phoneNumber" => "5551234567",
      "email" => "bob.johnson@example.com",
      "status" => "inactive",
      "subjects" => ["Math", "Science", "English"],
      "wallet" => 2000,
      "groupName" => "Group C",
    ],
    [
      "id" => 3,
      "firstName" => "Alice",
      "lastName" => "Brown",
      "address" => "901 Maple St, Largetown, USA",
      "phoneNumber" => "4445556666",
      "email" => "alice.brown@example.com",
      "status" => "active",
      "subjects" => ["Art", "Music", "Drama"],
      "wallet" => 1500,
      "groupName" => "Group D",
    ],
    [
      "id" => 4,
      "firstName" => "Mike",
      "lastName" => "Davis",
      "address" => "234 Pine St, Othertown, USA",
      "phoneNumber" => "3332221111",
      "email" => "mike.davis@example.com",
      "status" => "active",
      "subjects" => ["PE", "Sports", "Fitness"],
      "wallet" => 2500,
      "groupName" => "Group E",
    ],
    [
      "id" => 5,
      "firstName" => "Emily",
      "lastName" => "Taylor",
      "address" => "567 Cedar St, Smalltown, USA",
      "phoneNumber" => "7778889999",
      "email" => "emily.taylor@example.com",
      "status" => "inactive",
      "subjects" => ["Computing", "IT", "Networking"],
      "wallet" => 3000,
      "groupName" => "Group F",
    ],
  ];

Route::get('/teachers/{id}', function ($id) use ($teachersData) {
    $teacher = collect($teachersData)->firstWhere('id', (int)$id);
    if (!$teacher) {
        abort(404);
    }
    return Inertia::render('Menu/SingleTeacherPage', [
        'teacher' => $teacher
    ]);
});


Route::get('/offers', function () {
    return Inertia::render('Menu/OffersPage');
});



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