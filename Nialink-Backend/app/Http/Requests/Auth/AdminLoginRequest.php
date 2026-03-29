<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],

            // Prohibited — admins do not use phone/PIN
            'phone_number' => ['prohibited'],
            'pin'          => ['prohibited'],
            'device_id'    => ['prohibited'],
        ];
    }
}
