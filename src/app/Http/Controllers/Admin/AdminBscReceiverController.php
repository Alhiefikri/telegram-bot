<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BscReceiverManualVerifyRequest;
use App\Http\Requests\Admin\BscReceiverStoreRequest;
use App\Http\Requests\Admin\BscReceiverVerifyRequest;
use App\Models\BscPaymentReceiver;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Bsc\BscReceiverManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class AdminBscReceiverController extends Controller
{
    public function store(
        BscReceiverStoreRequest $request,
        BscReceiverManager $manager,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);

        try {
            $manager->add(
                (string) $request->validated('label'),
                (string) $request->validated('address'),
                $actor,
                $request->ip(),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->error($redirector, $exception->getMessage());
        }

        return $redirector->bscSettings()->with('status', 'BSC receiver ditambahkan sebagai pending verification. Challenge ownership tersedia di dashboard.');
    }

    public function verify(
        BscReceiverVerifyRequest $request,
        BscPaymentReceiver $receiver,
        BscReceiverManager $manager,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);

        try {
            $manager->verify(
                $receiver,
                (string) $request->validated('challenge_id'),
                (string) $request->validated('wallet_type'),
                (string) $request->validated('signature'),
                $actor,
                $request->ip(),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->error($redirector, $exception->getMessage());
        }

        return $redirector->bscSettings()->with('status', 'Ownership BSC receiver berhasil diverifikasi. Aktifkan receiver jika siap menerima pembayaran.');
    }

    public function challenge(
        Request $request,
        BscPaymentReceiver $receiver,
        BscReceiverManager $manager,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        try {
            $manager->renewChallenge($receiver, $this->actor($request), $request->ip());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->error($redirector, $exception->getMessage());
        }

        return $redirector->bscSettings()->with('status', 'Ownership challenge baru dibuat. Challenge sebelumnya sudah tidak berlaku.');
    }

    public function manualVerify(
        BscReceiverManualVerifyRequest $request,
        BscPaymentReceiver $receiver,
        BscReceiverManager $manager,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);

        try {
            $manager->manualVerify($receiver, true, $actor, $request->ip());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->error($redirector, $exception->getMessage());
        }

        return $redirector->bscSettings()->with('status', 'Receiver ditandai manual_verified. Jalur ini high risk; aktifkan hanya setelah review Owner.');
    }

    public function activate(
        BscPaymentReceiver $receiver,
        BscReceiverManager $manager,
        AdminDashboardRedirector $redirector,
        Request $request,
    ): RedirectResponse {
        try {
            $manager->activate($receiver, $this->actor($request), $request->ip());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->error($redirector, $exception->getMessage());
        }

        return $redirector->bscSettings()->with('status', 'BSC receiver diaktifkan untuk checkout baru.');
    }

    public function deactivate(
        BscPaymentReceiver $receiver,
        BscReceiverManager $manager,
        AdminDashboardRedirector $redirector,
        Request $request,
    ): RedirectResponse {
        try {
            $manager->deactivate($receiver, $this->actor($request), $request->ip());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->error($redirector, $exception->getMessage());
        }

        return $redirector->bscSettings()->with('status', 'BSC receiver dinonaktifkan. Payment existing tidak berubah.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function error(AdminDashboardRedirector $redirector, string $message): RedirectResponse
    {
        return $redirector->bscSettings()->withErrors(['receiver' => $message], 'bsc');
    }
}
