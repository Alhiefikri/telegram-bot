<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BscReceiverManualVerifyRequest;
use App\Http\Requests\Admin\BscReceiverStoreRequest;
use App\Http\Requests\Admin\BscReceiverVerifyRequest;
use App\Models\BscPaymentReceiver;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Admin\BscReceiverMutationExecutor;
use App\Services\Admin\BscReceiverMutationResult;
use App\Services\Admin\BscReceiverPendingMutation;
use App\Services\Admin\BscReceiverPendingMutationStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminBscReceiverController extends Controller
{
    public function store(
        BscReceiverStoreRequest $request,
        BscReceiverMutationExecutor $executor,
        BscReceiverPendingMutationStore $pendingMutations,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $pendingRedirect = $pendingMutations->redirectIfRequired(
            $request,
            $actor,
            BscReceiverPendingMutation::ACTION_STORE,
            [
                'label' => (string) $request->validated('label'),
                'address' => (string) $request->validated('address'),
            ],
        );
        if ($pendingRedirect !== null) {
            return $pendingRedirect;
        }

        return $this->respond(
            $executor->add(
                (string) $request->validated('label'),
                (string) $request->validated('address'),
                $actor,
                $request->ip(),
            ),
            $redirector,
        );
    }

    public function verify(
        BscReceiverVerifyRequest $request,
        BscPaymentReceiver $receiver,
        BscReceiverMutationExecutor $executor,
        BscReceiverPendingMutationStore $pendingMutations,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $pendingRedirect = $pendingMutations->redirectIfRequired(
            $request,
            $actor,
            BscReceiverPendingMutation::ACTION_VERIFY,
            [
                'receiver_id' => (int) $receiver->getKey(),
                'challenge_id' => (string) $request->validated('challenge_id'),
                'wallet_type' => (string) $request->validated('wallet_type'),
                'signature' => (string) $request->validated('signature'),
            ],
        );
        if ($pendingRedirect !== null) {
            return $pendingRedirect;
        }

        return $this->respond(
            $executor->verify(
                $receiver,
                (string) $request->validated('challenge_id'),
                (string) $request->validated('wallet_type'),
                (string) $request->validated('signature'),
                $actor,
                $request->ip(),
            ),
            $redirector,
        );
    }

    public function challenge(
        Request $request,
        BscPaymentReceiver $receiver,
        BscReceiverMutationExecutor $executor,
        BscReceiverPendingMutationStore $pendingMutations,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $pendingRedirect = $pendingMutations->redirectIfRequired(
            $request,
            $actor,
            BscReceiverPendingMutation::ACTION_CHALLENGE,
            ['receiver_id' => (int) $receiver->getKey()],
        );
        if ($pendingRedirect !== null) {
            return $pendingRedirect;
        }

        return $this->respond($executor->renewChallenge($receiver, $actor, $request->ip()), $redirector);
    }

    public function manualVerify(
        BscReceiverManualVerifyRequest $request,
        BscPaymentReceiver $receiver,
        BscReceiverMutationExecutor $executor,
        BscReceiverPendingMutationStore $pendingMutations,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $pendingRedirect = $pendingMutations->redirectIfRequired(
            $request,
            $actor,
            BscReceiverPendingMutation::ACTION_MANUAL_VERIFY,
            [
                'receiver_id' => (int) $receiver->getKey(),
                'high_risk_ack' => true,
            ],
        );
        if ($pendingRedirect !== null) {
            return $pendingRedirect;
        }

        return $this->respond($executor->manualVerify($receiver, $actor, $request->ip()), $redirector);
    }

    public function activate(
        BscPaymentReceiver $receiver,
        BscReceiverMutationExecutor $executor,
        BscReceiverPendingMutationStore $pendingMutations,
        AdminDashboardRedirector $redirector,
        Request $request,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $pendingRedirect = $pendingMutations->redirectIfRequired(
            $request,
            $actor,
            BscReceiverPendingMutation::ACTION_ACTIVATE,
            ['receiver_id' => (int) $receiver->getKey()],
        );
        if ($pendingRedirect !== null) {
            return $pendingRedirect;
        }

        return $this->respond($executor->activate($receiver, $actor, $request->ip()), $redirector);
    }

    public function deactivate(
        BscPaymentReceiver $receiver,
        BscReceiverMutationExecutor $executor,
        BscReceiverPendingMutationStore $pendingMutations,
        AdminDashboardRedirector $redirector,
        Request $request,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $pendingRedirect = $pendingMutations->redirectIfRequired(
            $request,
            $actor,
            BscReceiverPendingMutation::ACTION_DEACTIVATE,
            ['receiver_id' => (int) $receiver->getKey()],
        );
        if ($pendingRedirect !== null) {
            return $pendingRedirect;
        }

        return $this->respond($executor->deactivate($receiver, $actor, $request->ip()), $redirector);
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function respond(BscReceiverMutationResult $result, AdminDashboardRedirector $redirector): RedirectResponse
    {
        if (! $result->success) {
            return $redirector->bscSettings()->withErrors(['receiver' => $result->message], 'bsc');
        }

        return $redirector->bscSettings()->with('status', $result->message);
    }
}
