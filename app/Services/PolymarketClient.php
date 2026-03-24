<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolymarketClient
{
    private string $clobApiUrl;
    private string $privateKey;
    private string $apiKey;
    private string $apiSecret;
    private string $apiPassphrase;
    private bool $dryRun;

    public function __construct()
    {
        $this->clobApiUrl = config('polymarket.clob_api_url');
        $this->privateKey = config('polymarket.private_key');
        $this->apiKey = config('polymarket.api_key');
        $this->apiSecret = config('polymarket.api_secret');
        $this->apiPassphrase = config('polymarket.api_passphrase');
        $this->dryRun = config('polymarket.dry_run');
    }

    /**
     * Return the available USDC (collateral) balance, or null if the check fails.
     */
    public function getBalanceUsdc(): ?float
    {
        try {
            $response = Http::withHeaders($this->authHeaders())
                ->get("{$this->clobApiUrl}/balance-allowance", [
                    'asset_type' => 'COLLATERAL',
                ]);

            if ($response->successful()) {
                return (float) ($response->json('balance') ?? 0);
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('balance_fetch_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Return the midpoint price for a token, or null if unavailable.
     * Uses cache: 15s for success, 5min for failures.
     */
    public function getMidpoint(string $tokenId): ?float
    {
        $cacheKey = "midpoint:{$tokenId}";

        // Check for cached failure (stored as 'FAILED' string).
        $cached = Cache::get($cacheKey);
        if ($cached === 'FAILED') {
            return null;
        }
        if ($cached !== null) {
            return (float) $cached;
        }

        try {
            $response = Http::timeout(10)
                ->get("{$this->clobApiUrl}/midpoint", ['token_id' => $tokenId]);

            if ($response->successful()) {
                $mid = (float) ($response->json('mid') ?? 0);
                if ($mid > 0) {
                    Cache::put($cacheKey, $mid, 15);

                    return $mid;
                }
            }

            Cache::put($cacheKey, 'FAILED', 300);

            return null;
        } catch (\Throwable $e) {
            Log::warning('midpoint_fetch_failed', [
                'token_id' => $tokenId,
                'error' => substr($e->getMessage(), 0, 120),
            ]);
            Cache::put($cacheKey, 'FAILED', 300);

            return null;
        }
    }

    /**
     * Place a limit order on the CLOB. Returns the API response array or null on failure.
     * In dry-run mode, logs the order and returns a stub.
     */
    public function placeOrder(string $tokenId, string $side, float $price, float $size): ?array
    {
        if ($this->dryRun) {
            Log::info('DRY_RUN_order', [
                'token_id' => $tokenId,
                'side' => $side,
                'price' => $price,
                'size' => $size,
                'cost' => round($price * $size, 4),
            ]);

            return ['dry_run' => true];
        }

        try {
            $orderPayload = $this->buildSignedOrder($tokenId, $side, $price, $size);

            $response = Http::withHeaders($this->authHeaders())
                ->post("{$this->clobApiUrl}/order", $orderPayload);

            if ($response->successful()) {
                Log::info('order_placed', [
                    'token_id' => $tokenId,
                    'side' => $side,
                    'price' => $price,
                    'size' => $size,
                    'result' => $response->json(),
                ]);

                return $response->json();
            }

            Log::error('order_failed', [
                'token_id' => $tokenId,
                'side' => $side,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('order_failed', [
                'token_id' => $tokenId,
                'side' => $side,
                'price' => $price,
                'size' => $size,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build authentication headers for the CLOB API.
     */
    private function authHeaders(): array
    {
        return [
            'POLY_API_KEY' => $this->apiKey,
            'POLY_API_SECRET' => $this->apiSecret,
            'POLY_PASSPHRASE' => $this->apiPassphrase,
        ];
    }

    /**
     * Build and sign an order payload for the CLOB API.
     *
     * This is a simplified implementation. The full EIP-712 signing flow
     * is complex and may need refinement. For production, consider using
     * a Node.js sidecar with @polymarket/clob-client.
     */
    private function buildSignedOrder(string $tokenId, string $side, float $price, float $size): array
    {
        $sideInt = $side === 'BUY' ? 0 : 1;

        // Build the order struct that the CLOB API expects.
        $order = [
            'tokenID' => $tokenId,
            'price' => (string) $price,
            'size' => (string) $size,
            'side' => $sideInt,
            'feeRateBps' => '0',
            'nonce' => '0',
            'expiration' => '0',
            'signatureType' => 0,
        ];

        // EIP-712 signing with secp256k1.
        // In production, this needs the full typed-data hash flow.
        $messageHash = $this->hashOrder($order);
        $signature = $this->signMessage($messageHash);

        $order['signature'] = $signature;

        return [
            'order' => $order,
            'owner' => $this->getAddress(),
            'orderType' => 'GTC',
        ];
    }

    /**
     * Hash an order struct using keccak256 (simplified).
     */
    private function hashOrder(array $order): string
    {
        $encoded = json_encode($order);

        return \kornrunner\Keccak::hash($encoded, 256);
    }

    /**
     * Sign a message hash with the private key using secp256k1.
     */
    private function signMessage(string $messageHash): string
    {
        $ec = new \Elliptic\EC('secp256k1');
        $key = $ec->keyFromPrivate(ltrim($this->privateKey, '0x'));
        $signature = $key->sign($messageHash, ['canonical' => true]);

        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = dechex($signature->recoveryParam + 27);

        return '0x' . $r . $s . $v;
    }

    /**
     * Derive the Ethereum address from the private key.
     */
    private function getAddress(): string
    {
        $ec = new \Elliptic\EC('secp256k1');
        $key = $ec->keyFromPrivate(ltrim($this->privateKey, '0x'));
        $publicKey = $key->getPublic(false, 'hex');

        // Remove the 04 prefix, hash, take last 20 bytes.
        $hash = \kornrunner\Keccak::hash(hex2bin(substr($publicKey, 2)), 256);

        return '0x' . substr($hash, -40);
    }
}
