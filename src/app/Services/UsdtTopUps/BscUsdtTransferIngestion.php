<?php

namespace App\Services\UsdtTopUps;

use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtDepositReceiver;
use App\Services\Bsc\BscChainFacts;
use App\Services\Bsc\BscFinalReceiptInspector;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscTokenTransferReader;
use App\Services\Bsc\BscTransferLogRpcClientInterface;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;

/** Bounded discovery only. Checkpoints/observations never authorize money. */
final class BscUsdtTransferIngestion
{
    public function __construct(
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BscFinalReceiptInspector $inspector,
        private readonly BscTokenTransferReader $reader,
    ) {}

    /**
     * $firstBlock is explicit on initial enrollment; subsequent calls must reuse
     * it. A failed/incomplete page never advances. Retired receivers still scan.
     *
     * @return array{status: string, reason: string, requests: int, pages: int, last_block: ?int}
     */
    public function scan(int $receiverId, int $firstBlock, int $maxPages = 2, int $requestBudget = 32): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('BSC ingestion cannot perform RPC inside a database transaction.');
        }
        if ($firstBlock < 0 || $maxPages < 1 || $maxPages > 10 || $requestBudget < 1 || $requestBudget > 100) {
            throw new InvalidArgumentException('BSC ingestion bounds are invalid.');
        }
        $receiver = CustomerUsdtDepositReceiver::query()->findOrFail($receiverId);
        $requests = 0;
        $pages = 0;
        $lastBlock = null;
        // References are intentional: the response reports actual completed
        // pages/requests, not values captured before RPC started.
        $report = static function (string $status, string $reason) use (&$requests, &$pages, &$lastBlock): array {
            return ['status' => $status, 'reason' => $reason, 'requests' => $requests, 'pages' => $pages, 'last_block' => $lastBlock];
        };
        if ($receiver->verified_at === null || (int) $receiver->chain_id !== 56
            || $receiver->token_contract !== BscReadiness::APPROVED_USDT_CONTRACT || (int) $receiver->token_decimals !== 18) {
            return $report('review', 'receiver_unqualified');
        }
        if (! $this->rpc instanceof BscTransferLogRpcClientInterface) {
            return $report('pending', 'rpc_log_capability_missing');
        }
        DB::table('usdt_bsc_scan_checkpoints')->insertOrIgnore([
            'receiver_id' => $receiverId, 'first_block' => $firstBlock,
            'version' => 0, 'updated_at' => now(),
        ]);
        $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiverId)->first();
        if ((int) $checkpoint->first_block !== $firstBlock) {
            throw new InvalidArgumentException('BSC ingestion first block cannot be changed after enrollment.');
        }
        $lastBlock = $checkpoint->last_block === null ? null : (int) $checkpoint->last_block;
        if ($checkpoint->review_reason !== null) {
            return $report('review', $checkpoint->review_reason);
        }
        $call = static function (callable $operation) use (&$requests, $requestBudget): mixed {
            if ($requests >= $requestBudget) {
                throw new OverflowException('BSC ingestion request budget exhausted.');
            }
            $requests++;

            return $operation();
        };
        try {
            if ($call(fn () => $this->rpc->chainId()) !== 56
                || $call(fn () => $this->rpc->tokenDecimals($receiver->token_contract)) !== 18) {
                return $report('review', 'rpc_network_or_token_mismatch');
            }
            $head = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber('latest')));
            $finalized = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber('finalized')));
            if ($head === null || $finalized === null || $finalized['number'] >= $head['number']) {
                return $report('pending', 'finality_unavailable');
            }
            $canonicalFinal = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber($finalized['number'])));
            if ($canonicalFinal === null) {
                return $report('pending', 'canonical_finality_unavailable');
            }
            if ($canonicalFinal !== $finalized) {
                $this->hold($receiverId, (int) $checkpoint->version, 'finalized_block_changed', ['expected' => $finalized, 'observed' => $canonicalFinal]);

                return $report('review', 'finalized_block_changed');
            }
            $chunk = min(1000, max(1, (int) config('services.bsc.log_scan_chunk_blocks', 1000)));
            for ($page = 0; $page < $maxPages; $page++) {
                if ($checkpoint->last_block !== null) {
                    $anchor = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber((int) $checkpoint->last_block)));
                    if ($anchor === null) {
                        return $report('pending', 'checkpoint_block_unavailable');
                    }
                    if ($anchor['number'] !== (int) $checkpoint->last_block || $anchor['hash'] !== $checkpoint->last_block_hash) {
                        $this->hold($receiverId, (int) $checkpoint->version, 'checkpoint_reorg', ['expected_number' => $checkpoint->last_block, 'expected_hash' => $checkpoint->last_block_hash, 'observed' => $anchor]);

                        return $report('review', 'checkpoint_reorg');
                    }
                }
                $from = $checkpoint->last_block === null ? $firstBlock : (int) $checkpoint->last_block + 1;
                if ($from > $finalized['number']) {
                    return $report('complete', 'caught_up_to_finalized');
                }
                $to = $from + min($chunk - 1, $finalized['number'] - $from);
                $boundary = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber($to)));
                if ($boundary === null || $boundary['number'] !== $to) {
                    return $report('pending', 'page_boundary_unavailable');
                }
                $logs = $call(fn () => $this->rpc->transferLogs($receiver->token_contract, $receiver->address_normalized, $from, $to));
                if (! array_is_list($logs) || count($logs) > BscFinalReceiptInspector::MAX_LOGS) {
                    return $report('pending', 'log_page_malformed_or_oversized');
                }
                $blocks = [$to => $boundary];
                $identities = [];
                foreach ($logs as $log) {
                    if (! is_array($log) || ! $this->inspector->validTransferEncoding($log)
                        || ($log['removed'] ?? null) !== false) {
                        return $report('pending', 'log_page_malformed');
                    }
                    $number = BscChainFacts::quantity($log['blockNumber'] ?? null);
                    $index = BscChainFacts::quantity($log['logIndex'] ?? null);
                    $hash = BscChainFacts::hash($log['transactionHash'] ?? null);
                    $blockHash = BscChainFacts::hash($log['blockHash'] ?? null);
                    if ($number === null || $number < $from || $number > $to || $index === null || $hash === null || $blockHash === null
                        || strtolower($log['address']) !== $receiver->token_contract
                        || '0x'.strtolower(substr($log['topics'][2], -40)) !== $receiver->address_normalized
                        || isset($identities[$hash.':'.$index])) {
                        return $report('pending', 'log_page_identity_mismatch');
                    }
                    $identities[$hash.':'.$index] = true;
                    if (! isset($blocks[$number])) {
                        $facts = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber($number)));
                        if ($facts === null || $facts['number'] !== $number) {
                            return $report('pending', 'log_block_unavailable');
                        }
                        $blocks[$number] = $facts;
                    }
                    if ($blocks[$number]['hash'] !== $blockHash) {
                        $this->hold($receiverId, (int) $checkpoint->version, 'log_block_reorg', ['expected_hash' => $blockHash, 'observed' => $blocks[$number]]);

                        return $report('review', 'log_block_reorg');
                    }
                }
                $afterBoundary = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber($to)));
                if ($afterBoundary === null) {
                    return $report('pending', 'page_boundary_unavailable');
                }
                if ($afterBoundary !== $boundary) {
                    $this->hold($receiverId, (int) $checkpoint->version, 'page_boundary_reorg', ['expected' => $boundary, 'observed' => $afterBoundary]);

                    return $report('review', 'page_boundary_reorg');
                }
                if ($checkpoint->last_block !== null) {
                    $afterAnchor = BscChainFacts::block($call(fn () => $this->rpc->blockByNumber((int) $checkpoint->last_block)));
                    if ($afterAnchor === null) {
                        return $report('pending', 'checkpoint_block_unavailable');
                    }
                    if ($afterAnchor['hash'] !== $checkpoint->last_block_hash) {
                        $this->hold($receiverId, (int) $checkpoint->version, 'checkpoint_reorg', ['expected_number' => $checkpoint->last_block, 'expected_hash' => $checkpoint->last_block_hash, 'observed' => $afterAnchor]);

                        return $report('review', 'checkpoint_reorg');
                    }
                }
                $decoded = $this->reader->inspectTransferLogs($logs, $receiver->token_contract, $receiver->address_normalized, '0');
                if (count($decoded->transfers) !== count($logs)) {
                    return $report('pending', 'log_page_decode_incomplete');
                }
                $committed = DB::transaction(function () use ($receiver, $checkpoint, $decoded, $to, $boundary): bool {
                    $locked = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiver->getKey())->lockForUpdate()->first();
                    if ((int) $locked->version !== (int) $checkpoint->version || $locked->review_reason !== null) {
                        return false;
                    }
                    foreach ($decoded->transfers as $transfer) {
                        DB::table('usdt_bsc_transfer_observations')->insertOrIgnore([
                            'receiver_id' => $receiver->getKey(), 'chain_id' => 56,
                            'tx_hash' => $transfer->txHash, 'log_index' => $transfer->logIndex,
                            'block_number' => $transfer->blockNumber, 'block_hash' => $transfer->blockHash,
                            'token_contract' => $transfer->tokenContract, 'receiver_address' => $transfer->receiverAddress,
                            'amount_units' => $transfer->amountUnits, 'observed_at' => now(),
                        ]);
                    }
                    DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiver->getKey())->update([
                        'last_block' => $to, 'last_block_hash' => $boundary['hash'],
                        'version' => (int) $locked->version + 1, 'updated_at' => now(),
                    ]);

                    return true;
                }, 5);
                if (! $committed) {
                    return $report('pending', 'stale_scan_version');
                }
                $pages++;
                $lastBlock = $to;
                $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiverId)->first();
                if ($checkpoint->review_reason !== null) {
                    return $report('review', $checkpoint->review_reason);
                }
                if ($to === $finalized['number']) {
                    return $report('complete', 'caught_up_to_finalized');
                }
            }

            return $report('pending', 'page_budget_reached');
        } catch (OverflowException) {
            return $report('pending', 'request_budget_reached');
        } catch (BscRpcException) {
            return $report('pending', 'rpc_unavailable');
        }
    }

    private function hold(int $receiverId, int $version, string $reason, array $evidence): void
    {
        DB::transaction(function () use ($receiverId, $version, $reason, $evidence): void {
            $receiver = CustomerUsdtDepositReceiver::query()->findOrFail($receiverId);
            // Same Customer -> account -> receiver -> checkpoint graph as
            // settlement/reorg finalization. Containment commits with detection,
            // while bounded monitoring materializes individual incidents later.
            Customer::query()->whereKey($receiver->customer_id)->lockForUpdate()->firstOrFail();
            $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $receiver->customer_id)->lockForUpdate()->first();
            CustomerUsdtDepositReceiver::query()->whereKey($receiverId)->lockForUpdate()->firstOrFail();
            $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiverId)->lockForUpdate()->firstOrFail();
            if ((int) $checkpoint->version !== $version || $checkpoint->review_reason !== null) {
                return;
            }
            DB::table('usdt_reorg_receiver_evidence')->insert([
                'receiver_id' => $receiverId, 'checkpoint_version' => $version + 1,
                'reason' => $reason, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiverId)->update([
                'review_reason' => $reason, 'version' => $version + 1, 'updated_at' => now(),
            ]);
            $credited = DB::table('customer_usdt_top_up_resolutions as resolutions')
                ->join('customer_usdt_top_ups as targets', 'targets.id', '=', 'resolutions.top_up_id')
                ->where('targets.receiver_id', $receiverId)->whereNotNull('resolutions.entry_id')->exists();
            if ($account !== null && $credited) {
                DB::table('customer_usdt_balance_accounts')->where('id', $account->id)->whereNull('frozen_reason')
                    ->update(['frozen_reason' => 'usdt_receiver_reorg:'.$receiverId, 'updated_at' => now()]);
            }
        }, 5);
    }
}
