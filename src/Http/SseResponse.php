<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * StreamedResponse tuned for Server-Sent Events.
 *
 * Drains every PHP output buffer via {@see self::closeOutputBuffers()}
 * and enables implicit flushing before the emitter runs, so each `echo`
 * reaches the client immediately instead of being held in PHP's output
 * buffer. SSE-specific HTTP headers must be supplied by the caller (e.g.
 * from a Neuron stream adapter).
 */
final class SseResponse extends StreamedResponse {

    /**
     * @param callable():void $emitter Echoes the SSE stream to the client.
     * @param array<string, string> $headers SSE headers (Content-Type, etc.).
     */
    public function __construct(callable $emitter, array $headers = []) {
        parent::__construct(
            static function() use ($emitter): void {
                self::closeOutputBuffers(0, true);
                ob_implicit_flush();

                $emitter();
            },
            200,
            $headers,
        );
    }

}
