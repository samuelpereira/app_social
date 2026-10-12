<?php

namespace Database\Factories;

use App\Enums\OauthProvider;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OauthIdentity>
 */
class OauthIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => OauthProvider::Google,
            'provider_user_id' => fake()->unique()->numerify('####################'),
            'sui_address' => '0x'.substr(hash('sha256', fake()->uuid()), 0, 64),
        ];
    }
}
