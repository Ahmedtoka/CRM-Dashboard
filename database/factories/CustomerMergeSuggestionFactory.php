<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerMergeSuggestion>
 */
class CustomerMergeSuggestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'candidate_id' => Customer::factory(),
            'reason' => 'phone',
            'status' => 'open',
            'resolved_by_id' => null,
        ];
    }
}
