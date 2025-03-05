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
      "firstName" => "John",
      "lastName" => "Doe",
      "dateOfBirth" => "2000-01-01",
      "billingDate" => "2022-01-01",
      "address" => "123 Main St, Anytown, USA",
      "guardianName" => "Jane Doe",
      "CIN" => "CIN12345",
      "phoneNumber" => "1234567890",
      "email" => "john.doe@example.com",
      "massarCode" => "MASSAR123",
      "levelId" => 1,
      "class" => "2BAC SVT",
      "status" => "active",
      "assurance" => false,
      "createdAt" => "2022-01-01 12:00:00",
    ],
    [
      "id" => 2,
      "firstName" => "Jane",
      "lastName" => "Smith",
      "dateOfBirth" => "2001-02-02",
      "billingDate" => "2022-02-02",
      "address" => "456 Elm St, Othertown, USA",
      "guardianName" => "John Smith",
      "CIN" => "CIN67890",
      "phoneNumber" => "0987654321",
      "email" => "jane.smith@example.com",
      "massarCode" => "MASSAR456",
      "levelId" => 2,
      "class" => "1BAC PC",
      "status" => "active",
      "assurance" => true,
      "createdAt" => "2022-02-02 13:00:00",
    ],
    [
      "id" => 3,
      "firstName" => "Bob",
      "lastName" => "Johnson",
      "dateOfBirth" => "2002-03-03",
      "billingDate" => "2022-03-03",
      "address" => "789 Oak St, Smalltown, USA",
      "guardianName" => "Alice Johnson",
      "CIN" => "CIN11111",
      "phoneNumber" => "5551234567",
      "email" => "bob.johnson@example.com",
      "massarCode" => "MASSAR789",
      "levelId" => 3,
      "class" => "3BAC Math",
      "status" => "inactive",
      "assurance" => false,
      "createdAt" => "2022-03-03 14:00:00",
    ],
    [
      "id" => 4,
      "firstName" => "Alice",
      "lastName" => "Brown",
      "dateOfBirth" => "2003-04-04",
      "billingDate" => "2022-04-04",
      "address" => "901 Maple St, Largetown, USA",
      "guardianName" => "Mike Brown",
      "CIN" => "CIN22222",
      "phoneNumber" => "4445556666",
      "email" => "alice.brown@example.com",
      "massarCode" => "MASSAR012",
      "levelId" => 1,
      "class" => "2BAC Science",
      "status" => "active",
      "assurance" => true,
      "createdAt" => "2022-04-04 15:00:00",
    ],
    [
      "id" => 5,
      "firstName" => "Mike",
      "lastName" => "Davis",
      "dateOfBirth" => "2004-05-05",
      "billingDate" => "2022-05-05",
      "address" => "234 Pine St, Othertown, USA",
      "guardianName" => "Emily Davis",
      "CIN" => "CIN33333",
      "phoneNumber" => "3332221111",
      "email" => "mike.davis@example.com",
      "massarCode" => "MASSAR345",
      "levelId" => 2,
      "class" => "1BAC English",
      "status" => "active",
      "assurance" => false,
      "createdAt" => "2022-05-05 16:00:00",
    ],
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


