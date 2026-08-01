<?php

use App\Models\User;

/*
 * This app has NO public self-registration. The `register` route name is mapped to
 * GET/POST /setting behind auth + AdminMiddleware, so only an admin may create accounts.
 * These tests pin that down — a regression here would mean anyone could create an account.
 */

test('guests cannot reach the account creation screen', function () {
    $this->get('/setting')->assertRedirect('/login');
});

test('non-admins cannot reach the account creation screen', function () {
    $teacher = User::factory()->create(['role' => 'teacher']);

    $this->actingAs($teacher)->get('/setting')->assertRedirect('/dashboard');
});

test('non-admins cannot create accounts', function () {
    $teacher = User::factory()->create(['role' => 'teacher']);

    $this->actingAs($teacher)->post('/setting', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'Str0ngPassword123',
        'password_confirmation' => 'Str0ngPassword123',
        'role' => 'admin',
    ]);

    expect(User::where('email', 'intruder@example.com')->exists())->toBeFalse();
});

test('an admin can create an account', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/setting', [
        'name' => 'New Staff',
        'email' => 'new.staff@example.com',
        'password' => 'Str0ngPassword123',
        'password_confirmation' => 'Str0ngPassword123',
        'role' => 'assistant',
    ]);

    expect(User::where('email', 'new.staff@example.com')->where('role', 'assistant')->exists())
        ->toBeTrue();
});

test('a weak password is rejected', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->from('/setting')
        ->post('/setting', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'assistant',
        ])
        ->assertSessionHasErrors('password');

    expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
});
