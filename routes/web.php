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
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\AdminController;
use App\Http\Middleware\CheckImpersonation;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\ResultsController;
// Redirect to dashboard if authenticated, otherwise to login
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});
Route::post('/admin/switch-back', [AdminController::class, 'switchBack'])
    ->middleware(['auth', CheckImpersonation::class])
    ->name('admin.switch-back');
Route::post('/admin/view-as/{user}', [AdminController::class, 'viewAs'])
    ->middleware(['auth', AdminMiddleware::class])
    ->name('admin.view-as');
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
    Route::get('/select-profile', [ProfileController::class, 'select'])->name('profiles.select');
    Route::post('/select-profile', [ProfileController::class, 'store']);
    
    // Resource routes
    Route::resource('students', StudentsController::class);
    Route::resource('teachers', TeacherController::class);
    Route::resource('classes', ClassesController::class);
    Route::resource('assistants', AssistantController::class);
    Route::resource('offers', OfferController::class);
    Route::resource('invoices', InvoiceController::class);
    Route::resource('memberships', MembershipController::class);
    Route::resource('results', ResultsController::class);
    
    Route::resource('attendances', AttendanceController::class);
    

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
        Route::resource('announcements', AnnouncementController::class);
        Route::resource('transactions', TransactionController::class);
    });
    // Invoice PDF route
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'generateInvoicePdf'])->name('invoices.pdf');
    Route::delete('/students/invoices/{invoiceId}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::get('/invoices/{id}/download', [InvoiceController::class, 'download'])->name('invoices.download');
    Route::post('/invoices/bulk-download', [InvoiceController::class, 'bulkDownload'])
    ->name('invoices.bulk.download')
    ->middleware('web'); // Ensure all web middleware is applied

    // Results API routes
    Route::get('/results/classes-by-teacher/{teacher_id}', [ResultsController::class, 'getClassesByTeacher'])->name('results.classes-by-teacher');
    Route::get('/results/students-by-class/{class_id}', [ResultsController::class, 'getStudentsByClass'])->name('results.students-by-class');
    Route::get('/results/by-class/{class_id}', [ResultsController::class, 'getResultsByClass'])->name('results.by-class');
    Route::get('/results/calculate-grade', [ResultsController::class, 'calculateGrade'])->name('results.calculate-grade');
    Route::post('/results/update-grade', [ResultsController::class, 'updateGrade'])->name('results.update-grade');
    Route::get('/results/subjects-by-teacher/{teacher_id}/{class_id?}', [ResultsController::class, 'getSubjectsByTeacher'])->name('results.subjects-by-teacher');

    // Replace the closure with the controller method
    Route::get('/results', [ResultsController::class, 'index'])->name('results.index');

    // Batch payment routes
    Route::get('/batch-payment', [TransactionController::class, 'batchPaymentForm'])->name('transactions.batch-payment-form');
    Route::post('/batch-payment', [TransactionController::class, 'batchPayEmployees'])->name('transactions.batch-pay');

    // Recurring transactions
    Route::get('/recurring-transactions', [TransactionController::class, 'showRecurringTransactions'])->name('transactions.recurring');
    Route::post('/recurring-transactions/process/{id}', [TransactionController::class, 'processSingleRecurringTransaction'])->name('transactions.process-single-recurring');
    Route::post('/recurring-transactions/process-selected', [TransactionController::class, 'processSelectedRecurringTransactions'])->name('transactions.process-selected-recurring');
    Route::post('/recurring-transactions/process-all', [TransactionController::class, 'processAllRecurringTransactions'])->name('transactions.process-all-recurring');
    Route::post('/recurring-transactions/process-month', [TransactionController::class, 'processMonthRecurringTransactions'])->name('transactions.process-month-recurring');

    // Transaction routes
    Route::get('employee-transactions/{employee}', [TransactionController::class, 'employeeTransactions'])
        ->name('employee.transactions');


    // Class routes
    Route::delete('/classes/students/{student}', [ClassesController::class, 'removeStudent'])->name('classes.removeStudent');
    Route::get('/classes/fix-counts', [ClassesController::class, 'fixAllClassCounts'])->name('classes.fix-counts');
    Route::delete('/students/invoices/{invoiceId}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::get('/employees/{employee}/transactions', [TransactionController::class, 'transactions'])->name('employees.transactions'); 

    // Message routes
    Route::get('/inbox', [MessageController::class, 'inbox'])->name('inbox');
    Route::post('/message/{user}', [MessageController::class, 'store'])->name('message.store');
    Route::get('/message/{user}', [MessageController::class, 'show'])->name('message.show');
    Route::post('/message/{user}/read', [MessageController::class, 'markAsRead']);
    Route::get('/unread-count', [MessageController::class, 'unreadCount']);
    Route::get('/messages/last-messages', [MessageController::class, 'getLastMessages']);
    // Inertia pages
    Route::get('/ViewAllAnnouncements', [AnnouncementController::class, 'viewAllAnnouncements'])->name('ViewAllAnnouncements');
    Route::get('/payments', function () {
        return Inertia::render('Menu/PaymentsPage');
    });

});

// School routes

    Route::get('/schools', [SchoolController::class, 'index'])->name('schools.index');
    Route::get('/schools/create', [SchoolController::class, 'create'])->name('schools.create');
    Route::post('/schools', [SchoolController::class, 'store'])->name('schools.store');
    Route::get('/schools/{school}', [SchoolController::class, 'show'])->name('schools.show');
    Route::get('/schools/{school}/edit', [SchoolController::class, 'edit'])->name('schools.edit');
    Route::put('/schools/{school}', [SchoolController::class, 'update'])->name('schools.update');
    Route::delete('/schools/{school}', [SchoolController::class, 'destroy'])->name('schools.destroy');

// Othersettings routes
Route::middleware(['auth'])->group(function () {
    Route::get('/othersettings', [LevelController::class, 'index'])->name('othersettings.index');
    Route::get('/othersettings/levels', [LevelController::class, 'index'])->name('othersettings.levels');
    Route::get('/othersettings/subjects', [SubjectController::class, 'index'])->name('othersettings.subjects');
    Route::get('/othersettings/schools', [SchoolController::class, 'index'])->name('othersettings.schools');
    
    // Level routes
    Route::post('/othersettings/levels', [LevelController::class, 'store'])->name('othersettings.levels.store');
    Route::put('/othersettings/levels/{level}', [LevelController::class, 'update'])->name('othersettings.levels.update');
    Route::delete('/othersettings/levels/{level}', [LevelController::class, 'destroy'])->name('othersettings.levels.destroy');
    
    // Subject routes
    Route::post('/othersettings/subjects', [SubjectController::class, 'store'])->name('othersettings.subjects.store');
    Route::put('/othersettings/subjects/{subject}', [SubjectController::class, 'update'])->name('othersettings.subjects.update');
    Route::delete('/othersettings/subjects/{subject}', [SubjectController::class, 'destroy'])->name('othersettings.subjects.destroy');
    
    // School routes
    Route::post('/othersettings/schools', [SchoolController::class, 'store'])->name('othersettings.schools.store');
    Route::put('/othersettings/schools/{school}', [SchoolController::class, 'update'])->name('othersettings.schools.update');
    Route::delete('/othersettings/schools/{school}', [SchoolController::class, 'destroy'])->name('othersettings.schools.destroy');


});

// Authentication routes
require __DIR__ . '/auth.php';

// Diagnostic route to debug the assistant data
Route::get('/debug-assistant/{id}', function($id) {
    $assistant = App\Models\Assistant::with('schools')->find($id);
    
    if (!$assistant) {
        return response()->json(['error' => 'Assistant not found'], 404);
    }
    
    $schoolIds = $assistant->schools->pluck('id')->toArray();
    $today = \Carbon\Carbon::now();
    
    // Recent absences
    $recentAbsences = \App\Models\Attendance::with(['student', 'class'])
        ->whereIn('status', ['absent', 'late'])
        ->where(function($query) use ($schoolIds) {
            // Get absences from classes directly related to schools
            $query->whereHas('class', function($classQuery) use ($schoolIds) {
                $classQuery->whereIn('school_id', $schoolIds);
            });
            
            // Also get absences from students belonging to the assistant's schools
            $query->orWhereHas('student', function($studentQuery) use ($schoolIds) {
                $studentQuery->whereIn('schoolId', $schoolIds);
            });
        })
        ->where('date', '>=', $today->copy()->subDays(7))
        ->orderBy('date', 'desc')
        ->limit(10)
        ->get();
    
    // Count total absences for See More button
    $totalAbsences = \App\Models\Attendance::whereIn('status', ['absent', 'late'])
        ->where(function($query) use ($schoolIds) {
            $query->whereHas('class', function($classQuery) use ($schoolIds) {
                $classQuery->whereIn('school_id', $schoolIds);
            });
            
            $query->orWhereHas('student', function($studentQuery) use ($schoolIds) {
                $studentQuery->whereIn('schoolId', $schoolIds);
            });
        })
        ->where('date', '>=', $today->copy()->subDays(7))
        ->count();
    
    // For debugging
    $allAttendances = \App\Models\Attendance::whereIn('status', ['absent', 'late'])
        ->where('date', '>=', $today->copy()->subDays(7))
        ->count();
    
    $studentsInSchools = \App\Models\Student::whereIn('schoolId', $schoolIds)->count();
    $classesInSchools = \App\Models\Classes::whereIn('school_id', $schoolIds)->count();
    
    return response()->json([
        'assistant' => $assistant->only(['id', 'first_name', 'last_name', 'email']),
        'schools' => $schoolIds,
        'debug' => [
            'total_absences_found' => $totalAbsences,
            'recent_absences_limit_10' => $recentAbsences->count(),
            'all_recent_absences' => $allAttendances,
            'students_in_schools' => $studentsInSchools,
            'classes_in_schools' => $classesInSchools,
        ],
        'recent_absences' => $recentAbsences->map(function($attendance) {
            return [
                'id' => $attendance->id,
                'student_id' => $attendance->student ? $attendance->student->id : null,
                'student_name' => $attendance->student ? $attendance->student->firstName . ' ' . $attendance->student->lastName : 'Unknown',
                'class_name' => $attendance->class ? $attendance->class->name : 'Unknown',
                'date' => $attendance->date,
                'status' => $attendance->status,
                'reason' => $attendance->reason
            ];
        })
    ]);
});