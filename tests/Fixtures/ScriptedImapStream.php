<?php

namespace JensS\Newsletter\Tests\Fixtures;

use JensS\Newsletter\Mailbox\ImapStream;

/**
 * In-memory IMAP server for tests. Holds messages with UIDs and flags and
 * answers the commands the package's client uses.
 */
final class ScriptedImapStream implements ImapStream
{
    /** @var array<int, array{raw: string, flags: list<string>, folder: string}> */
    public array $messages = [];

    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    public array $folders = ['INBOX'];

    public int $uidValidity = 42;

    public bool $supportsMove = true;

    private string $buffer = "* OK [CAPABILITY IMAP4rev1 IDLE] Dovecot ready.\r\n";

    private string $selected = 'INBOX';

    public string $password = 'secret';

    public function add(string $raw, array $flags = []): int
    {
        $uid = $this->messages === [] ? 101 : max(array_keys($this->messages)) + 1;
        $this->messages[$uid] = ['raw' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 'flags' => $flags, 'folder' => 'INBOX'];

        return $uid;
    }

    public function write(string $data): void
    {
        [$tag, $command] = explode(' ', rtrim($data, "\r\n"), 2);
        $this->commands[] = $command;
        $out = '';

        if (preg_match('/^LOGIN "(.*)" "(.*)"$/', $command, $m)) {
            $out = $m[2] === $this->password ? "{$tag} OK Logged in\r\n" : "{$tag} NO [AUTHENTICATIONFAILED] Authentication failed.\r\n";
        } elseif ($command === 'CAPABILITY') {
            $out = '* CAPABILITY IMAP4rev1 UIDPLUS'.($this->supportsMove ? ' MOVE' : '')."\r\n{$tag} OK done\r\n";
        } elseif (preg_match('/^SELECT "(.*)"$/', $command, $m)) {
            $this->selected = $m[1];
            $count = count($this->inFolder());
            $out = "* {$count} EXISTS\r\n* OK [UIDVALIDITY {$this->uidValidity}] UIDs valid\r\n{$tag} OK [READ-WRITE] Select completed.\r\n";
        } elseif (preg_match('/^UID SEARCH (.*)$/', $command, $m)) {
            $uids = [];
            foreach ($this->inFolder() as $uid => $msg) {
                if ($m[1] === 'UNSEEN' && in_array('\\Seen', $msg['flags'], true)) {
                    continue;
                }
                if (preg_match('/^UID (\d+):\*$/', $m[1], $range) && $uid < (int) $range[1]) {
                    continue;
                }
                $uids[] = $uid;
            }
            // RFC 3501: "n:*" always includes the highest UID.
            if (str_starts_with($m[1], 'UID ') && $uids === [] && $this->inFolder() !== []) {
                $uids[] = max(array_keys($this->inFolder()));
            }
            $out = '* SEARCH '.implode(' ', $uids)."\r\n{$tag} OK Search completed.\r\n";
        } elseif (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\]\)$/', $command, $m)) {
            $raw = $this->messages[(int) $m[1]]['raw'];
            $out = "* 1 FETCH (UID {$m[1]} BODY[] {".strlen($raw)."}\r\n{$raw})\r\n{$tag} OK Fetch completed.\r\n";
        } elseif (preg_match('/^UID STORE (\d+) \+FLAGS\.SILENT \((.*)\)$/', $command, $m)) {
            foreach (explode(' ', $m[2]) as $flag) {
                $this->messages[(int) $m[1]]['flags'][] = $flag;
            }
            $out = "{$tag} OK Store completed.\r\n";
        } elseif (preg_match('/^UID MOVE (\d+) "(.*)"$/', $command, $m)) {
            $this->messages[(int) $m[1]]['folder'] = $m[2];
            $out = "{$tag} OK Move completed.\r\n";
        } elseif (preg_match('/^UID COPY (\d+) "(.*)"$/', $command, $m)) {
            $out = "{$tag} OK Copy completed.\r\n";
        } elseif (preg_match('/^CREATE "(.*)"$/', $command, $m)) {
            $exists = in_array($m[1], $this->folders, true);
            $this->folders[] = $m[1];
            $out = $exists ? "{$tag} NO [ALREADYEXISTS] Mailbox already exists\r\n" : "{$tag} OK Create completed.\r\n";
        } elseif ($command === 'EXPUNGE') {
            foreach ($this->messages as $uid => $msg) {
                if (in_array('\\Deleted', $msg['flags'], true)) {
                    unset($this->messages[$uid]);
                }
            }
            $out = "{$tag} OK Expunge completed.\r\n";
        } elseif ($command === 'LOGOUT') {
            // The next connection to this fake server starts with a fresh greeting.
            $out = "* BYE Logging out\r\n{$tag} OK Logout completed.\r\n* OK [CAPABILITY IMAP4rev1 IDLE] Dovecot ready.\r\n";
        } else {
            $out = "{$tag} BAD Unknown command\r\n";
        }

        $this->buffer .= $out;
    }

    public function readLine(): string
    {
        $pos = strpos($this->buffer, "\n");
        $line = $pos === false ? $this->buffer : substr($this->buffer, 0, $pos + 1);
        $this->buffer = substr($this->buffer, strlen($line));

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }

    public function enableTls(): void {}

    public function close(): void {}

    /** @return array<int, array{raw: string, flags: list<string>, folder: string}> */
    private function inFolder(): array
    {
        return array_filter($this->messages, fn ($m) => $m['folder'] === $this->selected);
    }
}
