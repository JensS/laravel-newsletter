<?php

namespace JensS\Newsletter\Mailbox;

/** Line-oriented transport underneath the IMAP client (swappable in tests). */
interface ImapStream
{
    public function write(string $data): void;

    /** One line including its trailing CRLF. */
    public function readLine(): string;

    public function read(int $bytes): string;

    public function enableTls(): void;

    public function close(): void;
}
