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

    // Resource routes
    Route::resource('students', StudentsController::class);
    Route::resource('teachers', TeacherController::class);
    Route::resource('classes', ClassesController::class);
    Route::resource('assistants', AssistantController::class);
    Route::resource('offers', OfferController::class);
    Route::resource('invoices', InvoiceController::class);
    Route::resource('memberships', MembershipController::class);
    
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
    Route::delete('/students/invoices/{invoiceId}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::get('/employees/{employee}/transactions', [TransactionController::class, 'transactions'])->name('employees.transactions'); 

    // Message routes
    Route::get('/inbox', [MessageController::class, 'inbox'])->name('inbox');
    Route::post('/message/{user}', [MessageController::class, 'store'])->name('message.store');
    Route::get('/message/{user}', [MessageController::class, 'show'])->name('message.show');

    // Inertia pages
    Route::get('/results', function () {
        return Inertia::render('Menu/ResultsPage');
    });
   
    Route::get('/ViewAllAnnouncements', [AnnouncementController::class, 'viewAllAnnouncements'])->name('ViewAllAnnouncements');
    Route::get('/payments', function () {
        return Inertia::render('Menu/PaymentsPage');
    });

});

// Authentication routes
require __DIR__ . '/auth.php';