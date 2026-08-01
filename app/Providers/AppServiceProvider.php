<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Password::defaults() is used by RegisteredUserController, NewPasswordController,
        // PasswordController, TeacherController and AssistantController — but was never
        // configured, so it silently meant `min(8)` and nothing else.
        //
        // `uncompromised()` calls the k-anonymity HaveIBeenPwned API. It fails OPEN if the
        // host has no egress, so it can never lock anyone out; it is skipped outside
        // production to keep tests and local development offline-friendly.
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers();

            return $this->app->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });

        // Fix for the "validateRequire" error - redirect "require" to "required"
        Validator::extend('require', function ($attribute, $value, $parameters, $validator) {
            // Just call the built-in required validator
            $required = $validator->validateRequired($attribute, $value);
            return $required;
        });
    }
}
