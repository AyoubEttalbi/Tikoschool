<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local E2E/demo accounts with known passwords.
 *
 * The original AssistantDashboardTestSeeder built assistant DATA (invoices,
 * memberships, attendances) but no users row, so nobody could actually log in
 * and look at it. This seeder is idempotent: run it again, it repairs instead
 * of duplicating. LOCAL ONLY — never call it from DatabaseSeeder.
 *
 * Accounts:
 *   admin     admin.demo@test.local   / password123
 *   assistant assist.demo@test.local  / password123  (2 schools → school switcher)
 */
class E2eDemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // Mechanical guard, not documentation: this seeder plants a known-password
        // ADMIN account. "LOCAL ONLY" in the docblock stopped nobody who ran
        // `db:seed --class=E2eDemoAccountsSeeder` against prod by accident.
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('E2eDemoAccountsSeeder skipped: local/testing only.');

            return;
        }

        // --- Admin -----------------------------------------------------------
        $admin = User::firstOrNew(['email' => 'admin.demo@test.local']);
        $admin->name = 'Admin Démo';
        $admin->password = Hash::make('password123');
        $admin->role = 'admin';
        $admin->email_verified_at ??= now();
        $admin->save();

        // --- Assistant: user + staff row + two schools -----------------------
        $assistantEmail = 'assist.demo@test.local';

        $assistantUser = User::firstOrNew(['email' => $assistantEmail]);
        $assistantUser->name = 'Assistante Démo';
        $assistantUser->password = Hash::make('password123');
        $assistantUser->role = 'assistant';
        $assistantUser->email_verified_at ??= now();
        $assistantUser->save();

        $assistant = \App\Models\Assistant::withTrashed()->firstOrNew(['email' => $assistantEmail]);
        $assistant->first_name = 'Assistante';
        $assistant->last_name = 'Démo';
        $assistant->phone_number = $assistant->phone_number ?: '0600000000';
        $assistant->status = 'active';
        $assistant->salary = 5000;
        $assistant->deleted_at = null; // a previous run may have trashed the row
        $assistant->save();

        $schools = [];
        foreach (['École Démo Al Fath', 'École Démo Annour'] as $index => $schoolName) {
            $slug = 'demo-school-'.($index + 1).'@test.local';

            $school = \App\Models\School::where('email', $slug)->first();
            if (! $school) {
                $school = \App\Models\School::create([
                    'name' => $schoolName,
                    'address' => 'Adresse de démonstration '.($index + 1),
                    'phone_number' => '05220000'.($index + 1),
                    'email' => $slug,
                ]);
            }
            $schools[] = $school;
            if (! $assistant->schools()->where('schools.id', $school->id)->exists()) {
                $assistant->schools()->attach($school->id);
            }
        }

        // --- A single-school assistant: the login path that auto-selects a school.
        // This is the account whose login used to land on assistants.show instead
        // of /dashboard — kept so the redirect stays covered end-to-end.
        $singleEmail = 'assist1.demo@test.local';
        $singleUser = User::firstOrNew(['email' => $singleEmail]);
        $singleUser->name = 'Assistant Mono-école';
        $singleUser->password = Hash::make('password123');
        $singleUser->role = 'assistant';
        $singleUser->email_verified_at ??= now();
        $singleUser->save();

        $singleRow = \App\Models\Assistant::withTrashed()->firstOrNew(['email' => $singleEmail]);
        $singleRow->first_name = 'Assistant';
        $singleRow->last_name = 'Mono-école';
        $singleRow->status = 'active';
        $singleRow->salary = 4000;
        $singleRow->deleted_at = null;
        if (! $singleRow->exists) {
            $singleRow->save();
            $singleRow->schools()->attach($schools[0]->id);
        } else {
            $singleRow->save();
            if (! $singleRow->schools()->where('schools.id', $schools[0]->id)->exists()) {
                $singleRow->schools()->attach($schools[0]->id);
            }
        }

        // A little work data in school 1 so the cockpit has real numbers:
        // one unpaid invoice (with its payment event), one membership expiring in 3 days.
        $school1 = $schools[0];
        $level = \App\Models\Level::first() ?? \App\Models\Level::create(['name' => 'Démo']);
        $class = \App\Models\Classes::firstOrCreate(
            ['name' => 'Classe Démo', 'school_id' => $school1->id],
            ['level_id' => $level->id],
        );

        $student = \App\Models\Student::firstOrCreate(
            ['email' => 'eleve.demo@test.local'],
            [
                'firstName' => 'Youssef',
                'lastName' => 'Démo',
                'dateOfBirth' => now()->subYears(12),
                'billingDate' => now(),
                'address' => 'Adresse élève démo',
                'guardianNumber' => '0600000001',
                'guardianName' => 'Parent Démo',
                'CIN' => 'DEMO000001',
                'phoneNumber' => '0600000001',
                'massarCode' => 'DEMO01',
                'levelId' => $level->id,
                'classId' => $class->id,
                'schoolId' => $school1->id,
                'status' => 'active',
            ],
        );

        $invoice = \App\Models\Invoice::where('student_id', $student->id)->where('type', 'invoice')->first();
        if (! $invoice) {
            $offer = \App\Models\Offer::first();
            $invoice = \App\Models\Invoice::create([
                'student_id' => $student->id,
                'offer_id' => $offer?->id,
                'type' => 'invoice',
                'months' => 1,
                'selected_months' => [now()->format('Y-m')],
                'billDate' => now(),
                'creationDate' => now(),
                'endDate' => now()->addMonth(),
                'totalAmount' => 1200,
                'amountPaid' => 500,
                'rest' => 700,
            ]);
            \App\Models\InvoicePaymentLog::recordDelta($invoice, 0, $admin->id);
        }

        \App\Models\Membership::firstOrCreate(
            ['student_id' => $student->id],
            [
                'offer_id' => \App\Models\Offer::value('id'),
                'teachers' => [],
                'payment_status' => 'pending',
                'is_active' => true,
                'start_date' => now()->subMonths(2),
                'end_date' => now()->addDays(3),
            ],
        );

        \App\Models\Announcement::firstOrCreate(
            ['title' => 'Réunion pédagogique'],
            [
                'content' => 'Une réunion pédagogique aura lieu vendredi à 17h dans la salle des professeurs.',
                'date_announcement' => now()->addDays(2),
                'visibility' => 'all',
            ],
        );

        // --- Tâches de démonstration (kanban) --------------------------------
        $taskTitles = [
            ['Rappeler le parent de Youssef', 'todo', 'high'],
            ['Préparer les reçus du mois', 'in_progress', 'normal'],
            ['Commander les fournitures', 'todo', 'low'],
            ['Archiver les bulletins signés', 'done', 'normal'],
        ];
        foreach ($taskTitles as [$title, $status, $priority]) {
            \App\Models\Task::firstOrCreate(
                ['school_id' => $school1->id, 'title' => $title],
                [
                    'status' => $status,
                    'priority' => $priority,
                    'due_date' => now()->addDays(3),
                    'assigned_to' => $assistantUser->id,
                    'created_by' => $admin->id,
                ],
            );
        }

        // --- Paiements de salaire de démonstration ---------------------------
        if (! \App\Models\Transaction::where('user_id', $assistantUser->id)->exists()) {
            \App\Models\Transaction::create([
                'user_id' => $assistantUser->id,
                'type' => 'salary',
                'amount' => 5000,
                'payment_date' => now()->subDays(20),
                'description' => 'Salaire du mois dernier',
                'is_recurring' => 0,
            ]);
            \App\Models\Transaction::create([
                'user_id' => $assistantUser->id,
                'type' => 'salary',
                'amount' => 5000,
                'payment_date' => now()->subDays(50),
                'description' => 'Salaire',
                'is_recurring' => 0,
            ]);
        }

        // Un avis d'absence en attente pour remplir la file « À valider ».
        if (\App\Models\Attendance::whereDate('date', now())->doesntExist()) {
            $attendance = \App\Models\Attendance::create([
                'student_id' => $student->id,
                'classId' => $class->id,
                'date' => now(),
                'status' => 'absent',
                'reason' => 'Maladie',
                'subject' => 'Math',
                'recorded_by' => $admin->id,
                'teacher_id' => \App\Models\Teacher::value('id'),
            ]);
            \App\Models\OutboundMessage::firstOrCreate(
                ['idempotency_key' => "demo-absence-{$attendance->id}"],
                [
                    'school_id' => $school1->id,
                    'student_id' => $student->id,
                    'attendance_id' => $attendance->id,
                    'type' => 'absence',
                    'channel' => 'whatsapp',
                    'recipient' => $student->guardianNumber,
                    'message' => 'Absence de votre enfant aujourd\'hui.',
                    'status' => \App\Models\OutboundMessage::STATUS_AWAITING_APPROVAL,
                ],
            );
        }

        $this->command->info('E2E accounts ready:');
        $this->command->line('  admin     admin.demo@test.local / password123');
        $this->command->line('  assistant assist.demo@test.local / password123');
        $this->command->line('  assistant (1 école) assist1.demo@test.local / password123');
    }
}
