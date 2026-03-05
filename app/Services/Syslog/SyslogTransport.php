<?php

namespace App\Services\Syslog;

use RuntimeException;

class SyslogTransport
{
    public function __construct(
        private string $host,
        private int $port,
        private string $protocol,
        private int $facility = 16,
    ) {}

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function facility(): int
    {
        return $this->facility;
    }

    public function send(string $cefMessage, int $severity): void
    {
        $syslogMessage = $this->buildSyslogMessage($cefMessage, $severity);

        match ($this->protocol) {
            'udp' => $this->sendUdp($syslogMessage),
            'tcp', 'tls' => $this->sendTcp($syslogMessage),
            default => throw new RuntimeException("Unsupported protocol: {$this->protocol}"),
        };
    }

    public function buildSyslogMessage(string $message, int $severity): string
    {
        $priority = ($this->facility * 8) + $severity;
        $timestamp = now()->format('M d H:i:s');
        $hostname = gethostname() ?: 'partner365';

        return "<{$priority}>{$timestamp} {$hostname} {$message}";
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function test(): array
    {
        try {
            $this->send('CEF:0|Partner365|Partner365|1.0|test|Test Connection|3|msg=Test event', 6);

            return ['success' => true, 'message' => 'Test event sent successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function validateConfig(string $host, int $port, string $protocol): bool
    {
        if (empty($host)) {
            return false;
        }

        if ($port < 1 || $port > 65535) {
            return false;
        }

        if (! in_array($protocol, ['udp', 'tcp', 'tls'])) {
            return false;
        }

        return true;
    }

    private function sendUdp(string $message): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Failed to create UDP socket: '.socket_strerror(socket_last_error()));
        }

        try {
            $result = socket_sendto($socket, $message, strlen($message), 0, $this->host, $this->port);
            if ($result === false) {
                throw new RuntimeException('Failed to send UDP message: '.socket_strerror(socket_last_error($socket)));
            }
        } finally {
            socket_close($socket);
        }
    }

    private function sendTcp(string $message): void
    {
        $prefix = $this->protocol === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create();

        if ($this->protocol === 'tls') {
            stream_context_set_option($context, 'ssl', 'verify_peer', true);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', true);
        }

        $connection = @stream_socket_client(
            "{$prefix}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            timeout: 5,
            context: $context,
        );

        if ($connection === false) {
            throw new RuntimeException("Failed to connect to syslog ({$prefix}): {$errstr} ({$errno})");
        }

        try {
            $written = @fwrite($connection, $message."\n");
            if ($written === false) {
                throw new RuntimeException('Failed to write to syslog connection');
            }
        } finally {
            fclose($connection);
        }
    }
}
