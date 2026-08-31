<?php

namespace Database\Factories;

use App\Models\AgentSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentSession>
 */
class AgentSessionFactory extends Factory
{
    /**
     * Keep session fixtures explicit because valid ownership requires an existing Project Agent and subject.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}
