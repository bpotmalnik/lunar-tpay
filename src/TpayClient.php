<?php

namespace Bpotmalnik\LunarTpay;

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\RefundReason;
use Bpotmalnik\LunarTpay\Exceptions\TpayApiException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TpayClient implements TpayClientContract
{
    private const SANDBOX_URL = 'https://openapi.sandbox.tpay.com';

    private const PRODUCTION_URL = 'https://api.tpay.com';

    private const SANDBOX_CERTIFICATE_PREFIX = 'https://secure.sandbox.tpay.com';

    private const PRODUCTION_CERTIFICATE_PREFIX = 'https://secure.tpay.com';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $sandbox = false,
        private readonly ?string $cacheStore = null,
        private readonly int $cacheTtlBuffer = 60,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTransaction(array $payload): array
    {
        return $this->post('/transactions', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payTransaction(string $transactionId, array $payload): array
    {
        return $this->post("/transactions/{$transactionId}/pay", $payload);
    }

    /** @return array<string, mixed> */
    public function cancelTransaction(string $transactionId): array
    {
        return $this->post("/transactions/{$transactionId}/cancel", []);
    }

    /** @return array<string, mixed> */
    public function getTransaction(string $transactionId): array
    {
        return $this->get("/transactions/{$transactionId}");
    }

    /** @return array<string, mixed> */
    public function createRefund(string $transactionId, int $amount, ?RefundReason $reason = null): array
    {
        $payload = [
            'amount' => $this->minorToDecimal($amount),
        ];

        return $this->post("/transactions/{$transactionId}/refunds", $payload);
    }

    /** @return array<string, mixed> */
    public function getRefundStatus(string $refundId): array
    {
        return $this->get("/refunds/{$refundId}");
    }

    public function verifyNotificationSignature(string $payload, string $jws): bool
    {
        $parts = explode('.', $jws);

        if (count($parts) !== 3) {
            return false;
        }

        [$encodedHeader, , $encodedSignature] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);

        if (! is_array($header) || empty($header['x5u']) || ! is_string($header['x5u'])) {
            return false;
        }

        if (! str_starts_with($header['x5u'], $this->certificatePrefix())) {
            return false;
        }

        try {
            $certificate = $this->fetchCertificate($header['x5u']);
            $trustedCertificate = $this->fetchCertificate($this->rootCertificateUrl());

            if (openssl_x509_verify($certificate, $trustedCertificate) !== 1) {
                return false;
            }

            $publicKey = openssl_pkey_get_public($certificate);

            if ($publicKey === false) {
                return false;
            }

            $signedPayload = $encodedHeader.'.'.$this->base64UrlEncode($payload);
            $signature = $this->base64UrlDecode($encodedSignature);

            return openssl_verify($signedPayload, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $uri, array $payload): array
    {
        try {
            return Http::withToken($this->accessToken())
                ->acceptJson()
                ->withBody(json_encode($payload ?: new \stdClass, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'application/json')
                ->post($this->baseUrl().$uri)
                ->throw()
                ->json() ?? [];
        } catch (RequestException $e) {
            throw TpayApiException::fromResponse(
                $e->response->status(),
                $e->response->json() ?? [],
            );
        }
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        try {
            return Http::withToken($this->accessToken())
                ->acceptJson()
                ->get($this->baseUrl().$uri)
                ->throw()
                ->json() ?? [];
        } catch (RequestException $e) {
            throw TpayApiException::fromResponse(
                $e->response->status(),
                $e->response->json() ?? [],
            );
        }
    }

    private function accessToken(): string
    {
        return Cache::store($this->cacheStore)->remember(
            $this->cacheKey(),
            $this->tokenTtl(),
            fn () => $this->requestAccessToken(),
        );
    }

    private function requestAccessToken(): string
    {
        try {
            $response = Http::acceptJson()
                ->asMultipart()
                ->post($this->baseUrl().'/oauth/auth', [
                    ['name' => 'client_id', 'contents' => $this->clientId],
                    ['name' => 'client_secret', 'contents' => $this->clientSecret],
                ])
                ->throw()
                ->json() ?? [];
        } catch (RequestException $e) {
            throw TpayApiException::fromResponse(
                $e->response->status(),
                $e->response->json() ?? [],
            );
        }

        if (empty($response['access_token']) || ! is_string($response['access_token'])) {
            throw new TpayApiException('Tpay API did not return an access token');
        }

        return $response['access_token'];
    }

    private function tokenTtl(): int
    {
        return 7200 - $this->cacheTtlBuffer;
    }

    private function cacheKey(): string
    {
        return 'lunar-tpay-token:'.sha1($this->baseUrl().'|'.$this->clientId);
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    private function certificatePrefix(): string
    {
        return $this->sandbox ? self::SANDBOX_CERTIFICATE_PREFIX : self::PRODUCTION_CERTIFICATE_PREFIX;
    }

    private function rootCertificateUrl(): string
    {
        return $this->certificatePrefix().'/x509/tpay-jws-root.pem';
    }

    private function fetchCertificate(string $url): string
    {
        $response = Http::accept('application/x-pem-file')->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Unable to fetch Tpay certificate: {$url}");
        }

        return $response->body();
    }

    private function base64UrlEncode(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $payload): string
    {
        return base64_decode(strtr($payload, '-_', '+/')) ?: '';
    }

    private function minorToDecimal(int $amount): float
    {
        return round($amount / 100, 2);
    }
}
