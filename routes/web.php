<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AttendanceController;
// Controllers
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OutboundMessageController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileImageController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeacherClassController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TeacherMembershipPaymentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CanViewTeacherProfile;
use App\Http\Middleware\CheckImpersonation;
use App\Http\Middleware\RequireRole;
// Middleware
use App\Http\Middleware\RoleRedirect;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Redirect to dashboard if authenticated, otherwise to login
Route::get('/', function () {
    return Auth::check() ? redirect('/dashboard') : redirect('/login');
});
Route::middleware('auth')->group(function () {
    // Dashboard route with RoleRedirect middleware
    Route::get('/dashboard', [StatsController::class, 'index'])
        ->middleware(RoleRedirect::class)
        ->name('dashboard');

    // Membership stats route
    Route::get('/stats/membership/{month?}', [StatsController::class, 'getStatsOfPaidUnpaid'])
        ->name('membership.stats')
        ->where('month', '[0-9]{4}-[0-9]{2}');

    // Profile routes
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
        Route::get('/select-profile', 'select')->name('profiles.select');
        Route::post('/select-profile', 'store')->name('profiles.store');

        // Self-service avatar: any user manages their OWN photo (admin → users row,
        // teacher/assistant → their staff row by email). Literal path, registered
        // before the wildcard /profile-images/{path} route below cannot shadow it
        // (different prefix anyway) — kept adjacent so the pairing stays obvious.
        // Throttled: uploads drive synchronous image processing (decode, resize,
        // re-encode) and are the one place a bored user can burn CPU on purpose.
        Route::post('/profile/image', 'uploadImage')->middleware('throttle:10,1')->name('profile.image.upload');
        Route::delete('/profile/image', 'removeImage')->name('profile.image.remove');
    });

    // Profile images — PRIVATE: served through this authed, record-scoped controller,
    // never as static files. The regex admits only <type>/<40hex>.webp and the
    // controller re-applies each record's existing visibility rules before streaming
    // bytes. Registered early so no wildcard route can shadow it.
    Route::get('/profile-images/{path}', [ProfileImageController::class, 'show'])
        ->where('path', '(?:students|teachers|assistants|admins)/[a-f0-9]{40}\.webp')
        ->name('profile-images.show');

    // Image removal: same authed group; the controller re-checks per record —
    // admins anywhere, staff on their own row, and an assistant on students it
    // may edit (SchoolScope::allowsStudent, mirroring upload rights).
    Route::delete('/profile-images/{type}/{id}', [ProfileImageController::class, 'destroy'])
        ->where(['type' => 'students|teachers|assistants|admins', 'id' => '[0-9]+'])
        ->name('profile-images.destroy');

    // Main resource routes - accessible by all authenticated users based on Menu.jsx
    //
    // NOTE the ->where('...', '[0-9]+') constraints. These wildcard `show` routes are
    // registered BEFORE the literal /create and /fix-counts routes further down, and an
    // unconstrained {param} matches the word "create" too. The literal routes were therefore
    // unreachable: model binding failed, the 404 was swallowed by Route::fallback, and the
    // user was silently redirected to /dashboard. Constraining to digits makes the literal
    // routes reachable regardless of registration order.
    Route::get('/classes', [ClassesController::class, 'index'])->name('classes.index');
    Route::get('/classes/{class}', [ClassesController::class, 'show'])->name('classes.show')->where('class', '[0-9]+');
    // The class roster PDF, from the Actions column of /classes. Digit-constrained like
    // its siblings so it can never shadow (or be shadowed by) a literal sibling route.
    Route::get('/classes/{class}/students/download', [ClassesController::class, 'downloadStudents'])
        ->where('class', '[0-9]+')
        ->name('classes.students.download');
    Route::get('/students', [StudentsController::class, 'index'])->name('students.index');
    Route::get('/students/{student}', [StudentsController::class, 'show'])->name('students.show')->where('student', '[0-9]+');
    Route::get('/students/{student}/download-pdf', [StudentsController::class, 'downloadPdf'])->name('students.downloadPdf')->where('student', '[0-9]+');
    Route::get('/teachers', [TeacherController::class, 'index'])->middleware(CanViewTeacherProfile::class)->name('teachers.index');
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show'])
        ->middleware(CanViewTeacherProfile::class)
        ->where('teacher', '[0-9]+')
        ->name('teachers.show');
    Route::get('/schools/{school}', [SchoolController::class, 'show'])->name('schools.show')->where('school', '[0-9]+');
    Route::get('/results', [ResultsController::class, 'index'])->name('results.index');
    Route::get('/attendances', [AttendanceController::class, 'index'])->name('attendances.index');

    // These five resources used to sit here with NO guard beyond `auth` — while the
    // `schools` resource on the very next line correctly chained AdminMiddleware. That
    // omission meant any authenticated teacher could DELETE any school's invoices, and
    // invoice deletion reverses teacher wallet credits, so it destroyed money, not just rows.
    //
    // RequireRole rather than AdminMiddleware on purpose: AdminMiddleware *redirects* to
    // /dashboard, so a denied XHR looks like a success to the frontend. RequireRole aborts
    // 403, which Inertia surfaces as an error.
    //
    // Role gating here is coarse. Object-level scoping (can THIS user touch THIS record)
    // lives in the controllers via App\Support\SchoolScope — both layers are required.

    // Students, invoices and memberships are managed from the student profile, which
    // teachers cannot open at all (StudentsController::show rejects the teacher role) and
    // which Menu.jsx does not offer them. Admin and assistant only.
    Route::middleware(RequireRole::class.':admin,assistant')->group(function () {
        Route::resources([
            'students' => StudentsController::class,
            'invoices' => InvoiceController::class,
            'memberships' => MembershipController::class,
        ], ['except' => ['show', 'index']]);

        // The invoices LIST page (the resources above deliberately exclude index).
        // This is the real destination of "Voir toutes les factures impayées" on the
        // assistant home — it used to point at GET /invoices when no such route existed,
        // hit Route::fallback and bounced the user back to where they started.
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    });

    // Results and attendance ARE teacher surfaces — teachers enter grades and take the
    // register (Menu.jsx: visible to all three roles). They stay open to every role, and
    // SchoolScope inside the controllers is what stops a teacher touching a class or
    // student that is not theirs.
    Route::resources([
        'results' => ResultsController::class,
        'attendances' => AttendanceController::class,
    ], ['except' => ['show', 'index']]);

    // Schools are admin-only: destroy() cascades school_teacher, assistant_school
    // and membership_monthly_stats. Route names are unchanged (schools.store/update/destroy).
    Route::resource('schools', SchoolController::class, ['except' => ['show', 'index']])
        ->middleware(AdminMiddleware::class);

    // Attendance stats route
    Route::get('/attendance/stats', [AttendanceController::class, 'getStats'])->name('attendance.stats');

    // Student promotion management routes - accessible to all authenticated users
    Route::get('/schoolyear/setup-promotions', [SchoolYearController::class, 'setupPromotions'])
        ->name('schoolyear.setup-promotions');
    Route::post('/schoolyear/setup-promotions', [SchoolYearController::class, 'setupPromotions']);
    Route::post('/schoolyear/update-promotion', [SchoolYearController::class, 'updatePromotion'])
        ->name('schoolyear.update-promotion');

    // Resource routes specific to admin users (create, edit, store, update, destroy)
    Route::middleware(AdminMiddleware::class)->group(function () {
        // Classes routes
        Route::post('/classes', [ClassesController::class, 'store'])->name('classes.store');
        Route::get('/classes/create', [ClassesController::class, 'create'])->name('classes.create');
        Route::get('/classes/{class}/edit', [ClassesController::class, 'edit'])->name('classes.edit');
        Route::put('/classes/{class}', [ClassesController::class, 'update'])->name('classes.update');
        Route::delete('/classes/{class}', [ClassesController::class, 'destroy'])->name('classes.destroy');

        // Teachers routes
        Route::get('/teachers/create', [TeacherController::class, 'create'])->name('teachers.create');
        Route::post('/teachers', [TeacherController::class, 'store'])->name('teachers.store');
        Route::get('/teachers/{teacher}/edit', [TeacherController::class, 'edit'])->name('teachers.edit');
        Route::put('/teachers/{teacher}', [TeacherController::class, 'update'])->name('teachers.update');
        Route::patch('/teachers/{teacher}', [TeacherController::class, 'update']);
        Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy'])->name('teachers.destroy');
    });

    // Changing a wallet balance is its own action, not a field on the edit form — see
    // TeacherController::adjustWallet().
    //
    // Deliberately OUTSIDE the AdminMiddleware group above. AdminMiddleware *redirects* to
    // /dashboard on denial, and it runs first, so wrapping this route in both would return
    // a 302 that the frontend reads as success — the caller would think the adjustment had
    // been applied. RequireRole aborts 403, which is the only answer a money endpoint can
    // give. @see CLAUDE.md on the two authorization layers.
    Route::post('/teachers/{teacher}/wallet', [TeacherController::class, 'adjustWallet'])
        ->middleware(RequireRole::class.':admin')
        ->name('teachers.wallet.adjust')
        ->where('teacher', '[0-9]+');

    // Invoice specific routes
    Route::controller(InvoiceController::class)->prefix('invoices')->group(function () {
        Route::get('/{id}/pdf', 'generateInvoicePdf')->name('invoices.pdf');
        Route::get('/{id}/download', 'download')->name('invoices.download');
        Route::post('/bulk-download', 'bulkDownload')->name('invoices.bulk.download');
        Route::get('/{id}/validate', 'validateInvoice')->name('invoices.validate')->where('id', '[0-9]+');
        // Server-side price quote for the invoice form (see InvoicePricingService).
        Route::post('/price', 'priceQuote')->name('invoices.price');
    });

    // Teacher invoices bulk download route
    Route::post('/teacher-invoices/bulk-download', [InvoiceController::class, 'teacherBulkDownload'])->name('teacher-invoices.bulk-download');

    // Direct PDF download route (GET request, no CSRF needed)
    Route::get('/teacher-invoices/download-pdf', [InvoiceController::class, 'teacherBulkDownload'])
        ->name('teacher-invoices.download-pdf');
    // Custom route for deleting an invoice from the student context. Same guard as the
    // `invoices` resource above — otherwise this is a second, unguarded door to the same
    // destroy() method, which reverses teacher wallet credits.
    Route::delete('/students/invoices/{id}', [InvoiceController::class, 'destroy'])
        ->middleware(RequireRole::class.':admin,assistant')
        ->name('students.invoices.destroy');

    // Results API routes
    Route::controller(ResultsController::class)->prefix('results')->group(function () {
        Route::get('/classes-by-teacher/{teacher_id}', 'getClassesByTeacher')->name('results.classes-by-teacher');
        Route::get('/students-by-class/{class_id}', 'getStudentsByClass')->name('results.students-by-class');
        Route::get('/by-class/{class_id}', 'getResultsByClass')->name('results.by-class');
        Route::get('/calculate-grade', 'calculateGrade')->name('results.calculate-grade');
        Route::post('/update-grade', 'updateGrade')->name('results.update-grade');
        Route::get('/subjects-by-teacher/{teacher_id}/{class_id?}', 'getSubjectsByTeacher')->name('results.subjects-by-teacher');
    });

    // Class specific routes
    Route::controller(ClassesController::class)->prefix('classes')->group(function () {
        // NOTE: `DELETE classes/students/{student}` was removed — ClassesController::removeStudent
        // hard-deletes the Student with no ownership or role check, and had zero frontend callers.
        // fix-counts mutates state, so it is POST and admin-only.
        Route::post('/fix-counts', 'fixAllClassCounts')
            ->middleware(AdminMiddleware::class)
            ->name('classes.fix-counts');
    });

    // Message routes
    Route::controller(MessageController::class)->group(function () {
        Route::get('/inbox', 'inbox')->name('inbox');
        Route::post('/message/{user}', 'store')->name('message.store');
        Route::get('/message/{user}', 'show')->name('message.show');
        Route::post('/message/{user}/read', 'markAsRead');
        Route::get('/unread-count', 'unreadCount');
        Route::get('/messages/last-messages', 'getLastMessages');
    });

    // Individual resource view routes - based on Menu.jsx visibility
    // Teachers, Students: accessible by admin, teacher, assistant
    // Assistants: index route is admin-only, but view route accessible by all authenticated users
    Route::get('/assistants/{assistant}', [AssistantController::class, 'show'])->name('assistants.show');
    Route::get('/assistants/{assistant}/student-payments', [AssistantController::class, 'studentPayments'])->name('assistants.student-payments');

    // Announcements view all - accessible by all authenticated users
    Route::get('/ViewAllAnnouncements', [AnnouncementController::class, 'viewAllAnnouncements'])
        ->name('ViewAllAnnouncements');

    // Mark all announcements as read
    Route::post('/announcements/mark-all-read', [AnnouncementController::class, 'markAllRead'])->name('announcements.markAllRead');

    // ADMIN ONLY routes based on Menu.jsx
    Route::middleware(AdminMiddleware::class)->group(function () {
        // Admin-only index routes based on Menu.jsx visibility
        Route::get('/assistants', [AssistantController::class, 'index'])->name('assistants.index');
        Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/othersettings', [LevelController::class, 'index'])->name('othersettings.index');

        // Teacher-Class management routes - security is handled in the controller
        Route::controller(TeacherClassController::class)->prefix('teacher-classes')->group(function () {
            Route::get('/', 'index')->name('teacher-classes.index');
            Route::get('/create', 'create')->name('teacher-classes.create');
            Route::post('/', 'store')->name('teacher-classes.store');
            Route::post('/bulk-assign', 'bulkAssign')->name('teacher-classes.bulk-assign');
            Route::post('/remove/{teacher_id}/{class_id}', 'removeTeacherFromClass')->name('teacher-classes.remove');
            Route::get('/classes-by-teacher/{teacherId}', 'getClassesByTeacher')->name('teacher-classes.classes-by-teacher');
            Route::get('/teachers-by-class/{classId}', 'getTeachersByClass')->name('teacher-classes.teachers-by-class');
        });

        // Admin resource methods (create, store, update, destroy)
        Route::resource('assistants', AssistantController::class, ['except' => ['show', 'index']]);
        Route::resource('offers', OfferController::class, ['except' => ['index']]);
        Route::resource('transactions', TransactionController::class, ['except' => ['index']]);
        Route::resource('announcements', AnnouncementController::class, ['except' => ['index']]);

        // Transaction routes - admin only
        Route::controller(TransactionController::class)->group(function () {
            Route::get('/batch-payment', 'batchPaymentForm')->name('transactions.batch-payment-form');
            Route::post('/batch-payment', 'batchPayEmployees')->name('transactions.batch-pay');
            Route::get('/recurring-transactions', 'showRecurringTransactions')->name('transactions.recurring');
            Route::get('/recurring-transactions/filter', 'recurringTransactions')->name('transactions.recurring.filter');
            Route::post('/recurring-transactions/process/{id}', 'processSingleRecurringTransaction')->name('transactions.process-single-recurring');
            Route::post('/recurring-transactions/process-selected', 'processSelectedRecurringTransactions')->name('transactions.process-selected-recurring');
            Route::post('/recurring-transactions/process-all', 'processAllRecurringTransactions')->name('transactions.process-all-recurring');
            Route::post('/recurring-transactions/process-month', 'processMonthRecurringTransactions')->name('transactions.process-month-recurring');
            Route::get('/employee-transactions/{employee}', 'employeeTransactions')->name('employee.transactions');
            Route::get('/employees/{employee}/transactions', 'transactions')->name('employees.transactions');
            Route::get('/admin-earnings-dashboard', 'getAdminEarningsDashboard')->name('admin.earnings.dashboard');
            Route::get('/filtered-monthly-stats', 'getFilteredMonthlyStats')->name('admin.filtered.monthly.stats');
            Route::get('/filtered-employee-data', 'getFilteredEmployeeData')->name('admin.filtered.employee.data');
            // NOTE: GET /debug-monthly-revenue removed — a diagnostic endpoint that dumped
            // revenue internals. Reachable only by admins, but it had no place in production.
        });

        // Payments page
        Route::get('/payments', function () {
            return Inertia::render('Menu/PaymentsPage');
        });

        // Other settings routes - admin only
        Route::prefix('othersettings')->group(function () {
            // Main othersettings routes
            Route::get('/', [LevelController::class, 'index'])->name('othersettings.index');
            Route::get('/levels', [LevelController::class, 'index'])->name('othersettings.levels');
            Route::get('/subjects', [SubjectController::class, 'index'])->name('othersettings.subjects');
            Route::get('/schools', [SchoolController::class, 'index'])->name('othersettings.schools');

            // School year transition route
            Route::post('/schoolyear/transition', [SchoolYearController::class, 'transition'])
                ->name('schoolyear.transition');

            /*
             * The level roster PDF.
             *
             * Registered before the {level} verb routes below and constrained to digits,
             * because a wildcard `{level}` segment registered first makes any literal
             * sibling unreachable and Route::fallback then redirects it to /dashboard —
             * the trap documented in CLAUDE.md.
             */
            Route::get('/levels/{level}/students/download', [LevelController::class, 'downloadStudents'])
                ->where('level', '[0-9]+')
                ->name('othersettings.levels.students.download');

            // Level routes
            Route::controller(LevelController::class)->group(function () {
                Route::post('/levels', 'store')->name('othersettings.levels.store');
                Route::put('/levels/{level}', 'update')->name('othersettings.levels.update');
                Route::delete('/levels/{level}', 'destroy')->name('othersettings.levels.destroy');
            });

            // Subject routes
            Route::controller(SubjectController::class)->group(function () {
                Route::post('/subjects', 'store')->name('othersettings.subjects.store');
                Route::put('/subjects/{subject}', 'update')->name('othersettings.subjects.update');
                Route::delete('/subjects/{subject}', 'destroy')->name('othersettings.subjects.destroy');
            });

            // School settings routes
            Route::controller(SchoolController::class)->group(function () {
                Route::post('/schools', 'store')->name('othersettings.schools.store');
                Route::put('/schools/{school}', 'update')->name('othersettings.schools.update');
                Route::delete('/schools/{school}', 'destroy')->name('othersettings.schools.destroy');
            });
        });

        // « Utilisateurs » in the sidebar. The URI used to be /setting under the label
        // « Paramètres », which hid an account-management page behind a settings name.
        // Internal route names (register/register.store) stay for compatibility.
        Route::get('/utilisateurs', [RegisteredUserController::class, 'show'])->name('register');
        Route::post('/utilisateurs', [RegisteredUserController::class, 'store'])->name('register.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // Add after other teacher routes, inside the admin middleware group if possible
        Route::post('/teachers-with-user', [TeacherController::class, 'storeWithUser'])->name('teachers.storeWithUser');
        Route::post('/assistants-with-user', [AssistantController::class, 'storeWithUser'])->name('assistants.storeWithUser');

        // Teacher Membership Payments (API routes for testing)
        Route::prefix('api/teacher-payments')->group(function () {
            Route::get('/', [TeacherMembershipPaymentController::class, 'index'])->name('teacher-payments.index');
            // Digits only: an unconstrained {id} shadowed /earnings-summary and
            // /pending-payments registered below it.
            Route::get('/{id}', [TeacherMembershipPaymentController::class, 'show'])->name('teacher-payments.show')->where('id', '[0-9]+');
            Route::post('/process-monthly', [TeacherMembershipPaymentController::class, 'processMonthlyPayments'])->name('teacher-payments.process-monthly');
            Route::get('/earnings-summary', [TeacherMembershipPaymentController::class, 'earningsSummary'])->name('teacher-payments.earnings-summary');
            Route::get('/pending-payments', [TeacherMembershipPaymentController::class, 'pendingPayments'])->name('teacher-payments.pending');
            // NOTE: POST /test-deletion removed. Despite the name it was NOT a test — it called
            // reverseInvoicePayments() against a real invoice, decrementing real teacher wallets.
        });
    });

    // Performance routes
    Route::get('/students/{student}/performance', [PerformanceController::class, 'show'])
        ->name('performance.student')
        ->middleware('auth');

    // Full staff directory with every user's name, email and role. Admin only — it is
    // reached from /utilisateurs, which is already admin-gated, and was the one route that
    // let any logged-in teacher enumerate every account in the system.
    Route::get('/users', [\App\Http\Controllers\UserController::class, 'index'])
        ->middleware(RequireRole::class.':admin')
        ->name('users.index');

    // Task board (kanban). School-scoped inside the controller via session('school_id');
    // RequireRole because a denied XHR must 403, not redirect like AdminMiddleware does.
    Route::middleware(RequireRole::class.':admin,assistant')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        // Admin-only: pick which school's board to work on (assistants are pinned).
        Route::post('/tasks/school', [TaskController::class, 'selectSchool'])->name('tasks.select-school');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update')->where('task', '[0-9]+');
        Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status')->where('task', '[0-9]+');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy')->where('task', '[0-9]+');
    });

    // The assistant's own salary/payments history. Assistants only: admins manage
    // payments from /transactions; teachers have no payroll page of their own here.
    Route::get('/my-payments', [\App\Http\Controllers\MyPaymentsController::class, 'index'])
        ->middleware(RequireRole::class.':assistant')
        ->name('assistant.my-payments');

    // Absence Log routes. The two absenceLog* methods already role-check internally; the
    // middleware makes that a route-table fact rather than something you have to read the
    // controller to discover, and covers the sibling routes that had no check at all.
    // `notify` sends a WhatsApp message to a real parent's phone — it was reachable by any
    // authenticated user for ANY student id.
    Route::middleware(RequireRole::class.':admin,assistant')->group(function () {
        Route::get('/absence-log', [AttendanceController::class, 'absenceLogPage'])->name('absence.log.page');
        Route::get('/api/absence-log', [AttendanceController::class, 'absenceLogData'])->name('absence.log.data');
        Route::post('/absence/{student}/notify', [AttendanceController::class, 'notifyParent'])->name('absence.notify');
        // Approve and queue every waiting notice for one day — the register records
        // notices without sending them; this is the human who says they may go out.
        Route::post('/absence-notifications/release', [AttendanceController::class, 'releaseNotifications'])
            ->name('absence.notifications.release');

        // Absence List page (frontend selection)
        Route::get('/absence-list', [AttendanceController::class, 'absenceListPage'])->name('absence-list');
        // Absence List PDF download (GET, not POST)
        Route::get('/absence-list/download', [AttendanceController::class, 'downloadAbsenceList'])->name('absence-list.download');

    });

    /*
     * What was actually sent to parents — admin only.
     *
     * Tighter than the notify button that creates the rows, which assistants may press.
     * The screen carries the gateway's QR code, and that QR is a CREDENTIAL: whoever
     * scans it links their own device to the school's WhatsApp account and can then read
     * every conversation on it.
     */
    Route::middleware(RequireRole::class.':admin')->group(function () {
        Route::get('/notifications', [OutboundMessageController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{message}/retry', [OutboundMessageController::class, 'retry'])
            ->name('notifications.retry')
            ->where('message', '[0-9]+');
        // Unlinks the school's phone. Destructive, admin-only, and it stops all delivery
        // until somebody scans a new code.
        Route::post('/notifications/disconnect', [OutboundMessageController::class, 'disconnect'])
            ->name('notifications.disconnect');
        // The way back. Without it, a revoked pairing left the screen with nothing to click.
        Route::post('/notifications/connect', [OutboundMessageController::class, 'connect'])
            ->name('notifications.connect');
    });

    // Cashier — daily cash register. MUST stay inside the auth group: the role check
    // below is null-safe, so an unauthenticated caller would otherwise skip it entirely.
    Route::get('/cashier/daily', function (\Illuminate\Http\Request $request) {
        if (Auth::user()->role === 'teacher') {
            return redirect('/dashboard')->with('error', 'Accès refusé.');
        }

        return app(CashierController::class)->daily($request);
    })->name('cashier.daily');

    // Redirect /cashier to today's view
    Route::get('/cashier', function () {
        if (Auth::user()->role === 'teacher') {
            return redirect('/dashboard')->with('error', 'Accès refusé.');
        }

        return redirect()->route('cashier.daily', ['date' => Carbon::today()->toDateString()]);
    })->name('cashier');
});

// NOTE: a duplicate, UNAUTHENTICATED `DELETE students/invoices/{id}` used to live here.
// The guarded copy inside the auth group above (name: students.invoices.destroy) is the only one now.

// Authentication routes
require __DIR__.'/auth.php';

// REMOVED (unauthenticated information disclosure):
//   GET /debug-session      — returned session()->all(), including the caller's CSRF token
//   GET /debug-mobile-auth  — returned session id, all cookies and all session data
//   GET /debug-invoice-data — returned financial data with no auth
// Do not reintroduce these without an app()->environment('local') guard.

// Admin impersonation.
// Starting an impersonation requires being an admin.
Route::middleware(['auth', AdminMiddleware::class])->group(function () {
    Route::post('/admin/view-as/{user}', [AdminController::class, 'viewAs'])->name('admin.view-as');
});

// Ending one deliberately does NOT use AdminMiddleware: while impersonating, the effective
// user is the impersonated (non-admin) account, so AdminMiddleware would redirect and make
// switch-back unreachable. That is exactly why a second, completely UNGUARDED copy of this
// route used to be registered further down the file.
//
// CheckImpersonation is the correct guard: it resolves session('admin_user_id') and requires
// that user to exist AND still be an admin before AdminController::switchBack logs them in.
Route::middleware(['auth', CheckImpersonation::class])
    ->post('/admin/switch-back', [AdminController::class, 'switchBack'])
    ->name('admin.switch-back');

// API route for fetching invoice details as JSON
Route::middleware('auth')->get('/api/invoices/{id}', [App\Http\Controllers\InvoiceController::class, 'apiShow']);

// API route for fetching upcoming announcements
Route::middleware('auth')->get('/api/upcoming-announcements', function () {
    $user = auth()->user();
    $userRole = $user ? $user->role : null;
    $now = now();
    $query = \App\Models\Announcement::query();
    $query->where('date_announcement', '>', $now);
    if ($userRole !== 'admin') {
        $query->where(function ($q) use ($userRole) {
            $q->where('visibility', 'all')
                ->orWhere('visibility', $userRole);
        });
    }
    $query->orderBy('date_announcement');
    $announcements = $query->get();

    return response()->json(['announcements' => $announcements]);
});

// API: Teacher earnings per month (paid only), and the per-invoice breakdown behind it.
//
// These were `auth` only. They take an OPTIONAL teacher_id filter and return every
// teacher's earnings across every school when it is omitted — so any signed-in teacher or
// assistant could read the whole payroll by requesting the URL directly. Nothing outside
// the admin-only payments screen ever calls them.
//
// RequireRole rather than AdminMiddleware: both are called with axios, and AdminMiddleware
// *redirects* to /dashboard, which arrives as a 200 full of HTML that the caller would try
// to read as JSON. RequireRole aborts 403.
Route::middleware(['auth', RequireRole::class.':admin'])->group(function () {
    Route::get('/teacher-earnings-report', [TransactionController::class, 'teacherMonthlyEarningsReport']);
    Route::get('/teacher-invoice-breakdown', [TransactionController::class, 'teacherInvoiceBreakdown']);
});

// Route to fetch all schools as JSON for filters (for frontend dropdowns)
Route::middleware('auth')->get('/schoolsForFilters', [SchoolController::class, 'listJson']);

// Route to fetch all classes as JSON for filters (for frontend dropdowns)
Route::middleware('auth')->get('/classesForFilters', [ClassesController::class, 'listJson']);

// Global fallback route: redirect any not found route to dashboard
Route::fallback(function () {
    return redirect('/dashboard');
});
