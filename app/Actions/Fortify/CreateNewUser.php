<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\PakistanMobile;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        if (isset($input['mobile_number']) && is_string($input['mobile_number'])) {
            $input['mobile_number'] = PakistanMobile::normalize($input['mobile_number']);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'mobile_number' => ['bail', 'required', 'string', 'max:13', 'regex:/^\\+923[0-9]{9}$/', 'unique:users,mobile_number'],
        ])->validate();

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'mobile_number' => $input['mobile_number'],
            'password' => $input['password'],
        ]);
        $user->forceFill(['registration_expires_at' => now()->addHour()])->save();

        return $user;
    }
}
