<?php

use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * WHO MAY REMOVE A STUDENT'S PHOTO.
 *
 * The removal endpoint used to allow admins only — while the upload path accepted
 * the same photo from an assistant through StudentsController::store/update. The
 * asymmetry surfaced the moment assistants started capturing photos in the form:
 * save worked, then the ✕ button answered 403 on DELETE /profile-images/students/{id}.
 *
 * The rule now mirrors upload rights exactly: admin anywhere; assistant within
 * SchoolScope (their schools); teacher never.
 */

function removalFixture(): array
{
    Storage::fake('profile-images');

    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
        // A logical path so the fake disk actually holds a file to delete.
        'profile_image' => 'students/'.str_repeat('a', 40).'.webp',
    ]);
    Storage::disk('profile-images')->put($student->getRawOriginal('profile_image'), 'image-bytes');

    return [$school, $student];
}

function removalAssistant(School $school): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $row = Assistant::factory()->create(['email' => $email]);
    $row->schools()->attach($school->id);

    return $user;
}

it('lets an assistant remove a photo of a student inside their school', function () {
    [$school, $student] = removalFixture();
    $user = removalAssistant($school);

    $this->actingAs($user)
        ->delete("/profile-images/students/{$student->id}")
        ->assertRedirect();

    expect($student->fresh()->profile_image)->toBeNull()
        ->and(Storage::disk('profile-images')->exists($student->getRawOriginal('profile_image')))->toBeFalse();
});

it('forbids an assistant from removing an out-of-scope student\'s photo', function () {
    [$schoolA] = removalFixture();
    [, $otherStudent] = removalFixture(); // different school
    $user = removalAssistant($schoolA);

    $rawPath = $otherStudent->getRawOriginal('profile_image');

    $this->actingAs($user)
        ->delete("/profile-images/students/{$otherStudent->id}")
        ->assertForbidden();

    // Nothing was touched: the reference and the file both survive.
    expect($otherStudent->fresh()->profile_image)->not->toBeNull()
        ->and(Storage::disk('profile-images')->exists($rawPath))->toBeTrue();
});

it('still forbids teachers from removing student photos', function () {
    [$school, $student] = removalFixture();
    $teacher = User::factory()->create(['role' => 'teacher']);

    $this->actingAs($teacher)
        ->delete("/profile-images/students/{$student->id}")
        ->assertForbidden();

    expect($student->fresh()->profile_image)->not->toBeNull();
});

it('keeps admins able to remove any student photo', function () {
    [, $student] = removalFixture();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->delete("/profile-images/students/{$student->id}")
        ->assertRedirect();

    expect($student->fresh()->profile_image)->toBeNull();
});
