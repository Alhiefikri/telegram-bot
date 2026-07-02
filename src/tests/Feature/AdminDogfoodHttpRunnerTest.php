<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use App\Support\AdminDogfoodHttpRunner;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDogfoodHttpRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_logs_in_and_submits_admin_form_through_web_session(): void
    {
        $admin = User::factory()->create([
            'email' => 'dogfood-admin@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::Owner,
        ]);

        $runner = new AdminDogfoodHttpRunner(
            $this->app->make(HttpKernel::class),
            $admin->email,
            'correct-password',
        );

        $login = $runner->login();
        $this->assertSame('302->200', $login->statusSummary());

        $category = $runner->submitForm('POST', '/admin/categories', [
            'category_name' => 'Perf Dogfood Runner Category',
            'category_description' => 'Created by authenticated dogfood runner.',
        ]);

        $this->assertSame('302->200', $category->statusSummary());
        $this->assertDatabaseHas('categories', [
            'name' => 'Perf Dogfood Runner Category',
            'slug' => 'perf-dogfood-runner-category',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'category.created',
            'subject_type' => Category::class,
        ]);
    }
}
