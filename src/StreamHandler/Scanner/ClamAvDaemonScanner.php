<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Scanner;

use Infocyph\Pathwise\Exceptions\MalwareScannerException;
use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
use Infocyph\Pathwise\StreamHandler\MalwareScannerProviderInterface;
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;

/**
 * Scans Pathwise-owned local staging files through a running ClamAV clamd daemon.
 *
 * Files are streamed with the clamd INSTREAM protocol, so the daemon never needs
 * filesystem access to the original upload path. Unix sockets are preferred.
 * TCP is accepted for loopback endpoints by default; remote TCP must be opted in
 * explicitly because clamd does not authenticate or encrypt its TCP protocol.
 */
final readonly class ClamAvDaemonScanner implements MalwareScannerInterface, MalwareScannerProviderInterface
{
    public function __construct(
        private string $endpoint,
        private float $connectTimeoutSeconds = 2.0,
        private float $ioTimeoutSeconds = 30.0,
        private int $chunkSize = 65_536,
        private int $maxResponseBytes = 8_192,
        private int $maxStreamBytes = 268_435_456,
        private bool $allowRemoteTcp = false,
    ) {
        $this->validateConfiguration();
    }

    public function providerId(): string
    {
        return 'clamav';
    }

    public function scan(MalwareScanRequest $request): MalwareScanVerdict
    {
        if ($request->size > $this->maxStreamBytes) {
            throw new MalwareScannerException('ClamAV scan input exceeds the configured stream limit.');
        }

        $input = fopen($request->localPath, 'rb');
        if (!is_resource($input)) {
            throw new MalwareScannerException('Unable to open ClamAV scan input.');
        }

        $socket = null;
        $deadline = hrtime(true) / 1_000_000_000 + $this->ioTimeoutSeconds;

        try {
            $socket = $this->connect($deadline);
            $this->writeFully($socket, "zINSTREAM\0", $deadline);
            $this->sendScanInput($input, $socket, $deadline);

            $this->writeFully($socket, pack('N', 0), $deadline);

            return $this->parseResponse($this->readResponse($socket, $deadline));
        } finally {
            fclose($input);
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @param resource $socket */
    private function assertTimeRemaining(mixed $socket, float $deadline): void
    {
        $remaining = $this->remainingSeconds($deadline);
        $seconds = (int) floor($remaining);
        $microseconds = max(1, (int) (($remaining - $seconds) * 1_000_000));
        if (!stream_set_timeout($socket, $seconds, $microseconds)) {
            throw new MalwareScannerException('Unable to configure ClamAV socket timeout.');
        }
    }

    /** @return resource */
    private function connect(float $deadline): mixed
    {
        $errorNumber = 0;
        $errorMessage = '';
        set_error_handler(static fn(): bool => true);

        try {
            $socket = stream_socket_client(
                $this->endpoint,
                $errorNumber,
                $errorMessage,
                min($this->connectTimeoutSeconds, $this->remainingSeconds($deadline)),
                STREAM_CLIENT_CONNECT,
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($socket)) {
            throw new MalwareScannerException(
                sprintf('Unable to connect to ClamAV daemon (%d).', $errorNumber),
            );
        }

        try {
            $this->assertTimeRemaining($socket, $deadline);
        } catch (\Throwable $exception) {
            fclose($socket);

            throw $exception;
        }

        return $socket;
    }

    private function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host, '[]'));

        if ($normalized === 'localhost' || $normalized === '::1') {
            return true;
        }

        $packed = filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? inet_pton($normalized)
            : false;

        return is_string($packed) && ord($packed[0]) === 127;
    }

    private function parseResponse(string $response): MalwareScanVerdict
    {
        $response = trim($response, "\0\r\n \t");
        if ($response === '') {
            throw new MalwareScannerException('ClamAV returned an empty response.');
        }
        if (str_ends_with($response, ': OK')) {
            return MalwareScanVerdict::CLEAN;
        }
        if (str_ends_with($response, ' FOUND')) {
            return MalwareScanVerdict::MALICIOUS;
        }

        throw new MalwareScannerException('ClamAV returned a scan error or unsupported response.');
    }

    /** @param resource $socket */
    private function readResponse(mixed $socket, float $deadline): string
    {
        $response = '';

        while (strlen($response) < $this->maxResponseBytes) {
            $this->assertTimeRemaining($socket, $deadline);
            $remaining = $this->maxResponseBytes - strlen($response);
            if ($remaining < 1) {
                break;
            }

            $chunk = $this->readResponseChunk($socket, max(1, min(4_096, $remaining)));
            if ($chunk === null) {
                return $response;
            }
            if ($chunk === '') {
                continue;
            }

            $response .= $chunk;
            $terminator = strpos($response, "\0");
            if ($terminator !== false) {
                return substr($response, 0, $terminator + 1);
            }
        }

        throw new MalwareScannerException('ClamAV response exceeds the configured limit.');
    }

    /** @param resource $socket */
    private function readResponseChunk(mixed $socket, int $readLength): ?string
    {
        $chunk = fread($socket, $readLength);
        if (!is_string($chunk) || $chunk === '') {
            return $this->responseReadEnded($socket, $chunk) ? null : '';
        }

        return $chunk;
    }

    private function remainingSeconds(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1_000_000_000;
        if ($remaining <= 0) {
            throw new MalwareScannerException('ClamAV operation timed out.');
        }

        return $remaining;
    }

    /** @param resource $socket */
    private function responseReadEnded(mixed $socket, mixed $chunk): bool
    {
        $metadata = stream_get_meta_data($socket);
        if ($metadata['timed_out']) {
            throw new MalwareScannerException('ClamAV response timed out.');
        }
        if (!is_string($chunk)) {
            throw new MalwareScannerException('Unable to read ClamAV response.');
        }

        return feof($socket);
    }

    /**
     * @param resource $input
     * @param resource $socket
     */
    private function sendScanInput(mixed $input, mixed $socket, float $deadline): void
    {
        $remaining = $this->maxStreamBytes;
        while (!feof($input)) {
            $this->assertTimeRemaining($socket, $deadline);
            $length = max(1, min($this->chunkSize, $remaining === PHP_INT_MAX ? $remaining : $remaining + 1));
            $chunk = fread($input, $length);
            if (!is_string($chunk) || ($chunk === '' && !feof($input))) {
                throw new MalwareScannerException('Unable to read ClamAV scan input.');
            }
            if ($chunk === '') {
                break;
            }

            $bytes = strlen($chunk);
            if ($bytes > $remaining) {
                throw new MalwareScannerException('ClamAV scan input exceeds the configured stream limit.');
            }
            $remaining -= $bytes;
            $this->writeFully($socket, pack('N', $bytes) . $chunk, $deadline);
        }
    }

    private function validateConfiguration(): void
    {
        $this->validateLimits();
        $this->validateEndpoint();
    }

    private function validateEndpoint(): void
    {
        if ($this->endpoint === '' || str_contains($this->endpoint, "\0")) {
            throw new \InvalidArgumentException('ClamAV endpoint must not be empty.');
        }

        if (str_starts_with($this->endpoint, 'unix://')) {
            if (strlen($this->endpoint) <= strlen('unix://')) {
                throw new \InvalidArgumentException('ClamAV Unix socket path is required.');
            }

            return;
        }

        if (!str_starts_with($this->endpoint, 'tcp://')) {
            throw new \InvalidArgumentException('ClamAV endpoint must use unix:// or tcp://.');
        }

        $parts = parse_url($this->endpoint);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_string($host) || $host === '' || !is_int($port)) {
            throw new \InvalidArgumentException('ClamAV TCP endpoint must include a valid host and port.');
        }
        if (!$this->allowRemoteTcp && !$this->isLoopbackHost($host)) {
            throw new \InvalidArgumentException(
                'Remote ClamAV TCP endpoints require explicit allowRemoteTcp=true.',
            );
        }
    }

    private function validateLimits(): void
    {
        if (
            !is_finite($this->connectTimeoutSeconds)
            || !is_finite($this->ioTimeoutSeconds)
            || $this->connectTimeoutSeconds <= 0
            || $this->ioTimeoutSeconds <= 0
            || $this->connectTimeoutSeconds > 86_400
            || $this->ioTimeoutSeconds > 86_400
        ) {
            throw new \InvalidArgumentException('ClamAV timeouts must be positive.');
        }
        if ($this->chunkSize < 1 || $this->chunkSize > 1_048_576) {
            throw new \InvalidArgumentException('ClamAV chunk size must be between 1 byte and 1 MiB.');
        }
        if ($this->maxResponseBytes < 128 || $this->maxResponseBytes > 65_536) {
            throw new \InvalidArgumentException('ClamAV response limit must be between 128 bytes and 64 KiB.');
        }
        if ($this->maxStreamBytes < 1) {
            throw new \InvalidArgumentException('ClamAV stream limit must be positive.');
        }
    }

    /** @param resource $stream */
    private function writeFully(mixed $stream, string $payload, float $deadline): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $this->assertTimeRemaining($stream, $deadline);
            $written = fwrite($stream, substr($payload, $offset));
            if (!is_int($written) || $written < 1) {
                $metadata = stream_get_meta_data($stream);
                if ($metadata['timed_out']) {
                    throw new MalwareScannerException('ClamAV request timed out.');
                }

                throw new MalwareScannerException('Unable to write ClamAV request.');
            }

            $offset += $written;
        }
    }
}
