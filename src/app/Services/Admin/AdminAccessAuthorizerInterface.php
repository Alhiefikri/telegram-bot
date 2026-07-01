<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

interface AdminAccessAuthorizerInterface
{
    public function authorize(Request $request): User|RedirectResponse;
}
