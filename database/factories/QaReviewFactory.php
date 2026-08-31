<?php

namespace Database\Factories;

use App\Models\QaReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaReview>
 */
class QaReviewFactory extends Factory
{
    /**
     * Keep review fixtures explicit because valid evidence requires a matching Task and Agent session.
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
