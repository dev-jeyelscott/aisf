<?php

namespace Database\Factories;

use App\Models\AgentRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * Keep run fixtures explicit because every run belongs to a deliberately constructed logical session.
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
