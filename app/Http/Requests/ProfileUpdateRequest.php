<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),

                // Identity is joined on EMAIL, not a foreign key:
                //   User::teacher()   = hasOne(Teacher::class, 'email', 'email')
                //   User::assistant() = hasOne(Assistant::class, 'email', 'email')
                //
                // So without these rules a user could change their profile email to match an
                // existing teacher/assistant row and silently inherit that person's profile,
                // schools and wallet. UserController::update already performs the equivalent
                // check on the admin path — this is the self-service path it omitted.
                Rule::unique('teachers', 'email')->ignore($user->email, 'email'),
                Rule::unique('assistants', 'email')->ignore($user->email, 'email'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
        ];
    }
}
