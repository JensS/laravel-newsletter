<?php

namespace JensS\Newsletter\Mailbox;

/**
 * Small IMAP4rev1 client (RFC 3501) with just what bounce processing needs:
 * LOGIN, SELECT, UID SEARCH/FETCH/STORE/MOVE/COPY, EXPUNGE. Pure PHP, so it
 * works on PHP 8.4+ where ext-imap is no longer bundled.
 */
final class ImapClient
{
    private int $tag = 0;

    /** @var list<string> */
    private array $capabilities = [];

    private bool $needsExpunge = false;

    public function __construct(private readonly ImapStream $stream) {}

    public static function connect(string $host, int $port = 993, string $encryption = 'ssl', int $timeout = 20): self
    {
        $client = new self(new SocketImapStream($host, $port, $encryption, $timeout));
        $client->greeting();

        if ($encryption === 'tls') {
            $client->command('STARTTLS');
            $client->stream->enableTls();
            $client->capabilities = [];
        }

        return $client;
    }

    public function greeting(): void
    {
        $line = $this->stream->readLine();

        if (! preg_match('/^\* (OK|PREAUTH)/i', $line)) {
            throw new ImapException('Unexpected IMAP greeting: '.trim($line));
        }

        $this->parseCapabilities($line);
    }

    public function login(string $username, string $password): void
    {
        if ($this->hasCapability('LOGINDISABLED')) {
            throw new ImapException('The server does not allow LOGIN on this connection (use encryption).');
        }

        $this->command('LOGIN '.$this->quote($username).' '.$this->quote($password), sensitive: true);
        $this->capabilities = [];
    }

    /** @return array{uidvalidity: int, exists: int} */
    public function select(string $folder): array
    {
        $info = ['uidvalidity' => 0, 'exists' => 0];

        foreach ($this->command('SELECT '.$this->quote($folder)) as [$line]) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $line, $m)) {
                $info['uidvalidity'] = (int) $m[1];
            }
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $info['exists'] = (int) $m[1];
            }
        }

        return $info;
    }

    /** @return list<int> */
    public function search(string $criteria): array
    {
        $uids = [];

        foreach ($this->command('UID SEARCH '.$criteria) as [$line]) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', trim($line), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        sort($uids);

        return $uids;
    }

    /** Full raw message without setting \Seen. */
    public function fetch(int $uid): ?string
    {
        foreach ($this->command("UID FETCH {$uid} (BODY.PEEK[])") as [$line, $literal]) {
            if ($literal !== null && preg_match('/FETCH/i', $line)) {
                return $literal;
            }
        }

        return null;
    }

    public function markSeen(int $uid): void
    {
        $this->command("UID STORE {$uid} +FLAGS.SILENT (\\Seen)");
    }

    public function move(int $uid, string $folder): void
    {
        if ($this->hasCapability('MOVE')) {
            $this->command("UID MOVE {$uid} ".$this->quote($folder));

            return;
        }

        $this->command("UID COPY {$uid} ".$this->quote($folder));
        $this->command("UID STORE {$uid} +FLAGS.SILENT (\\Seen \\Deleted)");
        $this->needsExpunge = true;
    }

    public function ensureFolder(string $folder): void
    {
        try {
            $this->command('CREATE '.$this->quote($folder));
        } catch (ImapException) {
            // already exists
        }
    }

    public function logout(): void
    {
        try {
            if ($this->needsExpunge) {
                $this->command('EXPUNGE');
            }
            $this->command('LOGOUT');
        } catch (ImapException) {
            // closing anyway
        } finally {
            $this->stream->close();
        }
    }

    public function hasCapability(string $capability): bool
    {
        if ($this->capabilities === []) {
            foreach ($this->command('CAPABILITY') as [$line]) {
                $this->parseCapabilities($line);
            }
        }

        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    /**
     * Send a command and collect untagged responses until the tagged completion.
     *
     * @return list<array{0: string, 1: ?string}> response lines with their literal (if any)
     */
    public function command(string $command, bool $sensitive = false): array
    {
        $tag = 'N'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $this->stream->write("{$tag} {$command}\r\n");
        $responses = [];

        while (true) {
            $line = $this->stream->readLine();
            $literal = null;

            // A line ending in {n} is followed by n bytes of literal data, then the rest of the line.
            while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literal = $this->stream->read((int) $m[1]);
                $line = rtrim($line)."\0".$this->stream->readLine();
            }

            if (str_starts_with($line, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $line)) {
                    $shown = $sensitive ? strtok($command, ' ').' …' : $command;
                    throw new ImapException("IMAP command failed ({$shown}): ".trim(substr($line, strlen($tag) + 1)));
                }

                return $responses;
            }

            $responses[] = [$line, $literal];
        }
    }

    private function parseCapabilities(string $line): void
    {
        if (preg_match('/CAPABILITY ([^\]\r\n]+)/i', $line, $m)) {
            $this->capabilities = array_map('strtoupper', preg_split('/\s+/', trim($m[1])) ?: []);
        }
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
