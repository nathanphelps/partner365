<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\GuestUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GuestUserFactory extends Factory
{
    protected $model = GuestUser::class;

    public function definition(): array
    {
        $email = fake()->safeEmail();

        return [
            'entra_user_id' => Str::uuid()->toString(),
            'email' => $email,
            'display_name' => fake()->name(),
            'user_principal_name' => str_replace('@', '_', $email).'#EXT#@contoso.onmicrosoft.com',
            'invitation_status' => InvitationStatus::Accepted,
            'account_enabled' => true,
        ];
    }
}
