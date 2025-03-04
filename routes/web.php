<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
});

require __DIR__.'/auth.php';

Route::get('/students', function () {
    return Inertia::render('Menu/StudentListPage');
});


$studentsData = [
    [
        "id" => 1,
        "studentId" => "1234567890",
        "name" => "John Doe",
        "email" => "john@doe.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg?auto=compress&cs=tinysrgb&w=1200",
        "phone" => "1234567890",
        "grade" => 5,
        "class" => "1B",
        "address" => "123 Main St, Anytown, USA",

        // Add these optional fields your component expects (mock values if needed)
        "bio" => "A dedicated student with a love for science and technology.",
        "bloodType" => "O+",
        "enrollmentDate" => "September 2024",
        "attendance" => "95%",
        "lessons" => 20,
    ],
    // Add other students similarly...
];

Route::get('/students/{id}', function ($id) use ($studentsData) {
    $student = collect($studentsData)->firstWhere('id', (int)$id);

    if (!$student) {
        abort(404);
    }

    return Inertia::render('Menu/SingleStudentPage', [
        'student' => $student
    ]);
});

Route::get('/teachers', function () {
    return Inertia::render('Menu/TeacherListPage');
});
$teachersData = [
    [
        "id" => 1,
        "teacherId" => "1234567890",
        "name" => "John Doe",
        "email" => "john@doe.com",
        "photo" => "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg?auto=compress&cs=tinysrgb&w=1200",
        "phone" => "1234567890",
        "subjects" => ["Math", "Geometry"],
        "classes" => ["1B", "2A", "3C"],
        "address" => "123 Main St, Anytown, USA"
    ],
   [
        "id" => 2,
        "teacherId" => "1234567890",
        "name" => "Jane Doe",
        "email" => "jane@doe.com",
        "photo" => "https://images.pexels.com/photos/936126/pexels-photo-936126.jpeg?auto=compress&cs=tinysrgb&w=1200",
        "phone" => "1234567890",
        "subjects" => ["Physics", "Chemistry"],
        "classes" => ["5A", "4B", "3C"],
        "address" => "123 Main St, Anytown, USA"
   ]
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