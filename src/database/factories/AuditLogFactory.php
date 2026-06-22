<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'action' => 'created', 'subject_type' => 'Product', 'subject_id' => '1', 'old_values' => null, 'new_values' => ['name' => 'Example'], 'ip_address' => '127.0.0.1', 'created_at' => now()];
    }
}
