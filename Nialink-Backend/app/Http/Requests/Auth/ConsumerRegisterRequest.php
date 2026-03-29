<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registration is public — no auth required
        return true;
    }

    public function rules(): array
    {
        return [
            // User identity
            'name'         => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:20', 'unique:users,phone_number'],
            'pin'          => ['required', 'string', 'min:4', 'max:6', 'confirmed'],
            'pin_confirmation' => ['required'],

            // Device — collected at registration so binding happens
            // concurrently with OTP verification (no separate round trip)
            'device_id'    => ['required', 'string', 'max:255'],
            'platform'     => ['required', 'in:android,ios,web'],
            'fcm_token'    => ['nullable', 'string'],
            'device_name'  => ['nullable', 'string', 'max:255'],
            'app_version'  => ['nullable', 'string', 'max:20'],

            // These fields belong to admin/merchant accounts — not consumers
            'email'        => ['prohibited'],
            'password'     => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.unique' => 'This phone number is already registered.',
            'pin.confirmed'       => 'PIN confirmation does not match.',
            'email.prohibited'    => 'Email is not used for consumer accounts.',
            'password.prohibited' => 'Password is not used for consumer accounts.',
        ];
    }
}
