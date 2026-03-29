<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string'],
            'pin'          => ['required', 'string', 'min:4', 'max:6'],
            // device_id is required — the middleware will also verify this
            // matches an active trusted device after authentication
            'device_id'    => ['required', 'string'],

            // Prohibited — consumers do not use email/password
            'email'        => ['prohibited'],
            'password'     => ['prohibited'],
        ];
    }
}
