<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Any language, spaces allowed — "محمد مرقة" is a username. Squished the
     * same way the login form squishes what's typed, so the two always meet.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['username' => Str::squish((string) $this->input('username'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', Rules\Password::defaults()],
            'role' => ['required', Rule::in(array_keys(config('roles.available')))],
        ];
    }
}
