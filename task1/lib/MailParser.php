<?php
/**
 * Decode MIME headers/bodies and extract preview + attachment names.
 */
declare(strict_types=1);

final class MailParser
{
    /**
     * @return array{
     *   message_id:string,
     *   from:string,
     *   subject:string,
     *   date:?string,
     *   preview:string,
     *   attachments:list<string>
     * }
     */
    public static function parse(string $raw): array
    {
        [$headersRaw, $bodyRaw] = self::splitHeadersBody($raw);
        $headers = self::parseHeaders($headersRaw);

        $messageId = trim($headers['message-id'] ?? '');
        if ($messageId === '') {
            $messageId = 'generated-' . sha1($raw);
        }
        $messageId = trim($messageId, "<> \t");

        $from = self::decodeMimeHeader($headers['from'] ?? '');
        $subject = self::decodeMimeHeader($headers['subject'] ?? '(no subject)');
        $date = self::normalizeDate($headers['date'] ?? null);

        $ctype = $headers['content-type'] ?? 'text/plain';
        $parts = self::walkMime($ctype, $headers, $bodyRaw);

        $preview = '';
        $attachments = [];
        foreach ($parts as $part) {
            $disp = strtolower($part['content_disposition'] ?? '');
            $isAttach = str_contains($disp, 'attachment')
                || (!empty($part['filename']) && !str_starts_with(strtolower($part['content_type']), 'text/'));

            if ($isAttach || !empty($part['filename'])) {
                $name = $part['filename'] ?: 'unnamed';
                if (!in_array($name, $attachments, true)) {
                    $attachments[] = $name;
                }
                continue;
            }

            if ($preview === '' && str_starts_with(strtolower($part['content_type']), 'text/plain')) {
                $preview = self::makePreview($part['body']);
            }
        }

        if ($preview === '') {
            foreach ($parts as $part) {
                if (str_starts_with(strtolower($part['content_type']), 'text/html')) {
                    $preview = self::makePreview(strip_tags($part['body']));
                    break;
                }
            }
        }

        return [
            'message_id'  => $messageId,
            'from'        => $from,
            'subject'     => $subject,
            'date'        => $date,
            'preview'     => $preview,
            'attachments' => $attachments,
        ];
    }

    /** @return array{0:string,1:string} */
    private static function splitHeadersBody(string $raw): array
    {
        if (preg_match("/\r\n\r\n|\n\n/", $raw, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            $sep = strlen($m[0][0]);
            return [substr($raw, 0, $pos), substr($raw, $pos + $sep)];
        }
        return [$raw, ''];
    }

    /** @return array<string,string> */
    private static function parseHeaders(string $raw): array
    {
        $raw = preg_replace("/\r\n[ \t]+/", ' ', $raw) ?? $raw;
        $raw = preg_replace("/\n[ \t]+/", ' ', $raw) ?? $raw;
        $headers = [];
        foreach (preg_split("/\r\n|\n/", $raw) as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $val = trim($value);
            if (isset($headers[$key])) {
                $headers[$key] .= ', ' . $val;
            } else {
                $headers[$key] = $val;
            }
        }
        return $headers;
    }

    public static function decodeMimeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) {
                return $decoded;
            }
        }
        // Fallback for =?UTF-8?B?...?= / =?UTF-8?Q?...?=
        return preg_replace_callback(
            '/=\?([^?]+)\?([bq])\?([^?]*)\?=/i',
            static function (array $m): string {
                $charset = $m[1];
                $data = strtoupper($m[2]) === 'B' ? base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                if ($data === false) {
                    return $m[0];
                }
                if (strtoupper($charset) !== 'UTF-8') {
                    $conv = @iconv($charset, 'UTF-8//IGNORE', $data);
                    return $conv !== false ? $conv : $data;
                }
                return $data;
            },
            $value
        ) ?? $value;
    }

    private static function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $ts = strtotime($date);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * @param array<string,string> $headers
     * @return list<array{content_type:string,content_disposition:?string,filename:?string,body:string}>
     */
    private static function walkMime(string $contentTypeHeader, array $headers, string $body): array
    {
        $type = self::headerParam($contentTypeHeader, null) ?: 'text/plain';
        $type = strtolower(trim(explode(';', $type)[0]));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = self::headerParam($contentTypeHeader, 'boundary');
            if ($boundary === null) {
                return [[
                    'content_type' => $type,
                    'content_disposition' => null,
                    'filename' => null,
                    'body' => $body,
                ]];
            }
            $parts = [];
            foreach (self::splitMultipart($body, $boundary) as $partRaw) {
                [$ph, $pb] = self::splitHeadersBody($partRaw);
                $phHeaders = self::parseHeaders($ph);
                $pct = $phHeaders['content-type'] ?? 'text/plain';
                $parts = array_merge($parts, self::walkMime($pct, $phHeaders, $pb));
            }
            return $parts;
        }

        $encoding = strtolower($headers['content-transfer-encoding'] ?? '7bit');
        $decoded = self::decodeBody($body, $encoding);
        $charset = self::headerParam($contentTypeHeader, 'charset') ?? 'UTF-8';
        if (!str_starts_with($type, 'text/')) {
            // binary — keep as-is for filename detection only
        } else {
            $decoded = self::toUtf8($decoded, $charset);
        }

        $disp = $headers['content-disposition'] ?? null;
        $filename = self::headerParam($disp ?? '', 'filename')
            ?? self::headerParam($contentTypeHeader, 'name');
        if ($filename !== null) {
            $filename = self::decodeMimeHeader($filename);
        }

        return [[
            'content_type' => $type,
            'content_disposition' => $disp,
            'filename' => $filename,
            'body' => $decoded,
        ]];
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--' . $boundary;
        $chunks = explode($delim, $body);
        $parts = [];
        foreach ($chunks as $chunk) {
            $chunk = ltrim($chunk, "\r\n");
            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }
            $parts[] = $chunk;
        }
        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper($charset);
        if ($charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'US-ASCII') {
            return $text;
        }
        $conv = @iconv($charset, 'UTF-8//IGNORE', $text);
        return $conv !== false ? $conv : $text;
    }

    private static function headerParam(string $header, ?string $name): ?string
    {
        if ($name === null) {
            return trim(explode(';', $header)[0]);
        }
        if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\s*=\s*("?)([^";]+)\1/i', $header, $m)) {
            return trim($m[2]);
        }
        return null;
    }

    private static function makePreview(string $text, int $max = 200): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1) . '…';
        }
        return $text;
    }
}