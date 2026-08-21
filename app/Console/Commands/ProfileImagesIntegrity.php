<?php

namespace App\Console\Commands;

use App\Models\Assistant;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ProfileImageUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Two-way integrity check between the database references and the files on disk
 * (spec Phase 16):
 *
 *   DB -> filesystem : every referenced image file exists?
 *   filesystem -> DB : every stored image file is referenced?
 *
 * Report-only by design: orphans are listed, never auto-deleted. Use
 * profile-images:integrity --strict in automation when a non-zero exit on
 * drift is wanted.
 */
class ProfileImagesIntegrity extends Command
{
    protected $signature = 'profile-images:integrity {--strict : Exit non-zero if any problem is found}';

    protected $description = 'Check profile-image references against files on disk, in both directions';

    public function handle(): int
    {
        $disk = Storage::disk('profile-images');

        // Soft-deleted rows still reference their files (a restore must get its
        // image back), so include them — excluding trashed would flag those files
        // as orphans and invite deleting data a student restore would need.
        $referencedByTable = [
            'students' => Student::withTrashed(),
            'teachers' => Teacher::withTrashed(),
            'assistants' => Assistant::withTrashed(),
            'admins' => User::where('role', 'admin'),
        ];

        $missing = [];
        $referencedPaths = [];

        foreach ($referencedByTable as $label => $query) {
            // toBase(): Eloquent's pluck() runs values through the models'
            // profileImage accessor, which would hand us resolved URLs instead of
            // the stored raw values and break every comparison below.
            $values = $query->whereNotNull('profile_image')
                ->where('profile_image', '!=', '')
                ->orderBy('id')
                ->toBase()
                ->pluck('profile_image', 'id');

            foreach ($values as $id => $raw) {
                // Legacy absolute URLs (old Cloudinary rows) are not files we manage.
                if (! ProfileImageUrl::isLogicalPath($raw)) {
                    continue;
                }

                $referencedPaths[$raw] = true;

                if (! $disk->exists($raw)) {
                    $missing[] = sprintf('%s #%d -> %s', $label, $id, $raw);
                }
            }
        }

        $orphans = [];
        foreach ($disk->allFiles() as $file) {
            if (! isset($referencedPaths[$file])) {
                $orphans[] = $file;
            }
        }

        // Files that don't even match our naming scheme (someone dropped junk in
        // the directory) get their own bucket so they aren't silently ignored.
        $foreign = array_filter($orphans, fn ($f) => ! preg_match(ProfileImageUrl::PATH_PATTERN, $f));
        $orphans = array_diff($orphans, $foreign);

        $managedCount = count($referencedPaths);

        $this->info("Referenced images: {$managedCount}");
        $this->info('Missing files: '.count($missing));
        foreach ($missing as $line) {
            $this->warn("  MISSING  {$line}");
        }
        $this->info('Orphaned files: '.count($orphans));
        foreach ($orphans as $line) {
            $this->warn("  ORPHAN   {$line}");
        }
        if ($foreign !== []) {
            $this->info('Foreign/unrecognised files: '.count($foreign));
            foreach ($foreign as $line) {
                $this->warn("  FOREIGN  {$line}");
            }
        }

        if ($this->option('strict') && (count($missing) > 0 || count($orphans) > 0 || $foreign !== [])) {
            $this->error('Integrity problems found.');

            return Command::FAILURE;
        }

        if ($missing === [] && $orphans === [] && $foreign === []) {
            $this->info('OK: every reference resolves and no orphaned files exist.');
        } else {
            $this->comment('Report only — nothing was deleted.');
        }

        return Command::SUCCESS;
    }
}
