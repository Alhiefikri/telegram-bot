<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Admin\BscReceiverMutationExecutor;
use App\Services\Admin\BscReceiverPendingMutationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminPasswordConfirmationController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessAdmin()) {
            return redirect()->route('admin.login');
        }

        return view('auth.confirm-password');
    }

    public function store(
        Request $request,
        BscReceiverPendingMutationStore $pendingMutations,
        BscReceiverMutationExecutor $executor,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessAdmin()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Sesi login berakhir. Login kembali untuk melanjutkan.'], 401)
                : redirect()->route('admin.login');
        }

        try {
            $validated = $request->validate([
                'password' => ['required', 'string'],
            ]);

            if (! Hash::check($validated['password'], (string) $user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'Password konfirmasi tidak cocok.',
                ]);
            }
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                throw $exception;
            }

            return response()->json(['message' => 'Password belum terkonfirmasi.', 'errors' => $exception->errors()], 422);
        }

        $request->session()->passwordConfirmed();

        if ($request->expectsJson()) {
            // Inline confirmation must not execute an unrelated pending BSC mutation.
            return response()->json(['confirmed' => true]);
        }

        $pendingMutation = $pendingMutations->consume($request, $user);
        if ($pendingMutation !== null) {
            $result = $executor->executePending($pendingMutation, $user);

            return $result->success
                ? $redirector->bscSettings()->with('status', $result->message)
                : $redirector->bscSettings()->withErrors(['receiver' => $result->message], 'bsc');
        }

        return redirect()->intended(route('admin.dashboard'));
    }
}
