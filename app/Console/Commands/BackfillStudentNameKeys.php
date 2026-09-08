<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class BackfillStudentNameKeys extends Command
{
    protected $signature = 'students:backfill-name-keys';

    protected $description = 'Fill normalized duplicate-detection name keys on legacy student rows.';

    public function handle(): int
    {
        // Query-builder update on purpose: it bypasses the model boot (no
        // class-count/movement side effects for thousands of untouched rows).
        // saveQuietly() would ALSO suppress the saving hook that stamps the
        // keys, so it cannot be used here.
        $count = 0;

        Student::withTrashed()
            ->where(fn ($query) => $query->whereNull('firstNameKey')->orWhereNull('lastNameKey'))
            ->chunkById(500, function ($students) use (&$count) {
                foreach ($students as $student) {
                    [$firstKey, $lastKey] = \App\Support\StudentName::keys(
                        (string) ($student->firstName ?? ''),
                        (string) ($student->lastName ?? '')
                    );
                    Student::withTrashed()->whereKey($student->id)->update([
                        'firstNameKey' => $firstKey,
                        'lastNameKey' => $lastKey,
                    ]);
                    $count++;
                }
            });

        $this->info("Stamped {$count} student(s).");

        return self::SUCCESS;
    }
}
