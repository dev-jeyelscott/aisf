<?php

namespace Database\Factories;

use App\Models\AgentRunAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRunAction>
 */
class AgentRunActionFactory extends Factory
{
    /**
     * Keep action fixtures explicit because every action must identify a deliberate durable mutation.
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
