<?php
/**
 * Minimal pure-PHP IMAP client (SSL sockets).
 * Avoids requiring the ext-imap PECL extension.
 */
declare(strict_types=1);

final class ImapClient
{
    /** @var resource|null */
    private $fp = null;
    private int $tag = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'ssl',
    ) {}

    public function connect(int $timeout = 30): void
    {
        $remote = match ($this->encryption) {
            'ssl'   => "ssl://{$this->host}:{$this->port}",
            'tls'   => "tcp://{$this->host}:{$this->port}",
            default => "tcp://{$this->host}:{$this->port}",
        };

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if ($fp === false) {
            throw new RuntimeException("IMAP connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($fp, $timeout);
        $this->fp = $fp;
        $this->readLine(); // greeting

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($pass));
    }

    public function select(string $mailbox = 'INBOX'): void
    {
        $this->command('SELECT ' . $this->quote($mailbox));
    }

    /**
     * Return up to $limit most recent UIDs (newest first).
     * @return list<int>
     */
    public function latestUids(int $limit = 20): array
    {
        $resp = $this->command('UID SEARCH ALL');
        $uids = [];
        foreach ($resp['lines'] as $line) {
            if (preg_match('/^\* SEARCH(?: (.+))?$/i', $line, $m) && !empty($m[1])) {
                foreach (preg_split('/\s+/', trim($m[1])) as $uid) {
                    if ($uid !== '' && ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        sort($uids, SORT_NUMERIC);
        if (count($uids) > $limit) {
            $uids = array_slice($uids, -$limit);
        }
        return array_reverse($uids);
    }

    public function fetchRaw(int $uid): string
    {
        $tag = $this->nextTag();
        $this->write("{$tag} UID FETCH {$uid} (BODY.PEEK[])\r\n");

        $message = '';
        while (($line = $this->readRawLine()) !== null) {
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $line)) {
                    throw new RuntimeException('FETCH failed: ' . trim($line));
                }
                break;
            }

            if (preg_match('/\{(\d+)\}\r?\n?$/', $line, $m)) {
                $message = $this->readBytes((int) $m[1]);
                $this->readRawLine(); // trailing CRLF after literal
            }
        }

        return $message;
    }

    public function logout(): void
    {
        try {
            if ($this->fp) {
                $this->command('LOGOUT');
            }
        } catch (Throwable) {
            // ignore
        }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
        $this->fp = null;
    }

    /** @return array{tag:string,lines:list<string>,status:string} */
    private function command(string $cmd): array
    {
        $tag = $this->nextTag();
        $this->write("{$tag} {$cmd}\r\n");
        $lines = [];
        while (($line = $this->readLine()) !== null) {
            $lines[] = $line;
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $line)) {
                    throw new RuntimeException('IMAP error: ' . trim($line));
                }
                return ['tag' => $tag, 'lines' => $lines, 'status' => trim($line)];
            }
        }
        throw new RuntimeException('IMAP connection closed unexpectedly');
    }

    private function nextTag(): string
    {
        $this->tag++;
        return 'A' . $this->tag;
    }

    private function quote(string $s): string
    {
        return '"' . addcslashes($s, '\\"') . '"';
    }

    private function write(string $data): void
    {
        if (!$this->fp) {
            throw new RuntimeException('Not connected');
        }
        if (fwrite($this->fp, $data) === false) {
            throw new RuntimeException('IMAP write failed');
        }
    }

    private function readLine(): ?string
    {
        if (!$this->fp) {
            return null;
        }
        $line = fgets($this->fp);
        return $line === false ? null : rtrim($line, "\r\n");
    }

    private function readRawLine(): ?string
    {
        if (!$this->fp) {
            return null;
        }
        $line = fgets($this->fp);
        return $line === false ? null : $line;
    }

    private function readBytes(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->fp, $n - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP truncated while reading literal');
            }
            $data .= $chunk;
        }
        return $data;
    }
}