<?php

namespace JensS\Newsletter\Mailbox;

final class SocketImapStream implements ImapStream
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port, string $encryption = 'ssl', int $timeout = 20)
    {
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new ImapException("Cannot connect to {$host}:{$port}: {$error} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
    }

    public function write(string $data): void
    {
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $n) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new ImapException('Connection lost while writing.');
            }
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->socket);

        if ($line === false) {
            throw new ImapException(stream_get_meta_data($this->socket)['timed_out'] ? 'IMAP server timed out.' : 'Connection closed by IMAP server.');
        }

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->socket, $bytes - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new ImapException('Connection closed while reading.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    public function enableTls(): void
    {
        if (! @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new ImapException('STARTTLS negotiation failed.');
        }
    }

    public function close(): void
    {
        @fclose($this->socket);
    }
}
