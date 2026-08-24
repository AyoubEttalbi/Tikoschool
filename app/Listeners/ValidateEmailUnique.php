<?php

namespace App\Listeners;

use App\Events\CheckEmailUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateEmailUnique
{
    public function handle(CheckEmailUnique $event)
    {
        // whereNull(deleted_at): DB::table() has no SoftDeletes scope, so without this
        // a soft-deleted teacher/assistant kept blocking their email forever — which
        // fought the re-hire flow that revives exactly those rows.
        $existsInTeachers = DB::table('teachers')
            ->where('email', $event->email)
            ->whereNull('deleted_at')
            ->when($event->ignoreId, function ($query, $ignoreId) {
                return $query->where('id', '!=', $ignoreId);
            })
            ->exists();

        $existsInAssistants = DB::table('assistants')
            ->where('email', $event->email)
            ->whereNull('deleted_at')
            ->when($event->ignoreId, function ($query, $ignoreId) {
                return $query->where('id', '!=', $ignoreId);
            })
            ->exists();

        if ($existsInTeachers || $existsInAssistants) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse e-mail est déjà utilisée par un autre enseignant ou assistant.',
            ]);
        }
    }
}
