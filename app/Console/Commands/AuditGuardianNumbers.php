<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Support\WhatsApp;
use Illuminate\Console\Command;

/**
 * Which parents can this school actually reach?
 *
 * Nobody knows. Guardian numbers are free text entered over years in at least four
 * formats, and a number that will not normalise does not error when a notice is sent — it
 * produces a message addressed to nobody, so the school believes the parent was told.
 * Silently-unreachable parents are the biggest real risk in the whole notification
 * feature, and until this command existed the only trace was a warning in a log file.
 *
 * Read-only. Prints counts and the student ids to go and fix.
 */
class AuditGuardianNumbers extends Command
{
    protected $signature = 'whatsapp:audit-numbers
                            {--active : Only students with status=active}
                            {--limit=40 : How many problem students to list}';

    protected $description = 'Report guardian numbers that are missing, unusable, or opted out';

    public function handle(): int
    {
        $query = Student::query()->select([
            'id', 'firstName', 'lastName', 'guardianNumber', 'notifyGuardian', 'status',
        ]);

        if ($this->option('active')) {
            $query->where('status', 'active');
        }

        $missing = [];
        $unusable = [];
        $optedOut = [];
        $ok = 0;

        // chunkById, not get(): this runs over every student in the school and there is no
        // reason to hold them all in memory at once.
        $query->chunkById(500, function ($students) use (&$missing, &$unusable, &$optedOut, &$ok) {
            foreach ($students as $student) {
                if (empty($student->guardianNumber)) {
                    $missing[] = $student;

                    continue;
                }

                if (WhatsApp::normalise($student->guardianNumber) === null) {
                    $unusable[] = $student;

                    continue;
                }

                if (! $student->notifyGuardian) {
                    $optedOut[] = $student;

                    continue;
                }

                $ok++;
            }
        });

        $total = $ok + count($missing) + count($unusable) + count($optedOut);

        $this->newLine();
        $this->table(['', 'Students'], [
            ['Reachable', $ok],
            ['No guardian number', count($missing)],
            ['Number cannot be used', count($unusable)],
            ['Notifications turned off', count($optedOut)],
            ['Total', $total],
        ]);

        $this->listProblems('Number cannot be used — these silently reach nobody', $unusable, true);
        $this->listProblems('No guardian number recorded', $missing, false);

        if ($total > 0) {
            $this->newLine();
            $this->line(sprintf('%d%% of students have a usable guardian number.', (int) round($ok / $total * 100)));
        }

        // Non-zero exit on unusable numbers only: "no number recorded" is a data-entry
        // backlog, but a number that LOOKS present and reaches nobody is a false sense of
        // safety, and is the case worth failing a scheduled check on.
        return count($unusable) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function listProblems(string $title, array $students, bool $showNumber): void
    {
        if ($students === []) {
            return;
        }

        $limit = (int) $this->option('limit');

        $this->newLine();
        $this->warn($title);

        foreach (array_slice($students, 0, $limit) as $student) {
            $line = sprintf('  #%-6d %s %s', $student->id, $student->firstName, $student->lastName);

            // The stored value is shown ONLY for unusable numbers, because seeing the
            // malformed text is the entire point of fixing it. Never for the rest.
            if ($showNumber) {
                $line .= '   ['.$student->guardianNumber.']';
            }

            $this->line($line);
        }

        if (count($students) > $limit) {
            $this->line(sprintf('  … and %d more (raise --limit to see them)', count($students) - $limit));
        }
    }
}
