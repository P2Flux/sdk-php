<?php

declare(strict_types=1);

namespace P2Flux;

/**
 * The default transport: curl, and nothing else.
 *
 * It lives in its own file so a host that supplies its own HTTP client never loads it. That is not
 * tidiness - WordPress.org rejects plugins that call curl directly, and a plugin vendoring this SDK
 * has to be able to ship the client without shipping a curl call it will never make. The client
 * falls back to this only when no `transport` was given.
 */
final class CurlTransport
{
    public function __construct(private readonly int $defaultTimeout = 60)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function __invoke(string $url, array $payload, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $timeout,
            /* A black-holed host must fail in seconds, not hold a renewal worker for the full
             * request timeout: connecting is never the slow part of a charge - confirmation is. */
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        ]);

        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpStatus === 0) {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => $error]);
        }

        $body = json_decode((string) $raw, true);

        return [$httpStatus, is_array($body) ? $body : []];
    }
}
