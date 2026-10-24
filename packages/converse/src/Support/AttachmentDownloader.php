<?php

declare(strict_types=1);

namespace Laravel\Converse\Support;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Converse\Exceptions\AttachmentException;
use Laravel\Converse\Exceptions\AttachmentTooLargeException;
use Throwable;

class AttachmentDownloader
{
    protected const int CHUNK_BYTES = 8192;

    protected ?CurlHandler $handler = null;

    /**
     * @param  array<int, string>  $hosts
     */
    public function trusted(string $url, array $hosts): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        $host = Str::lower($parts['host']);

        return array_any($hosts, fn (string $pattern): bool => Str::is(Str::lower($pattern), $host));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function fetch(string $url, array $headers, int $maxBytes, int $timeout): string
    {
        return $this->read($this->request($url, $headers, $maxBytes, $timeout), $maxBytes);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function request(string $url, array $headers, int $maxBytes, int $timeout): Response
    {
        $exceeded = false;

        try {
            $response = Http::withHeaders($headers)
                ->withoutRedirecting()
                ->timeout(max(1, $timeout))
                ->withOptions([
                    'stream' => true,
                    'progress' => function (int $downloadTotal, int $downloaded) use ($maxBytes, &$exceeded): bool {
                        // @codeCoverageIgnoreStart
                        return $exceeded = $downloadTotal > $maxBytes || $downloaded > $maxBytes;
                        // @codeCoverageIgnoreEnd
                    },
                ])
                ->setHandler($this->handler ??= new CurlHandler)
                ->get($url);
        } catch (Throwable $throwable) {
            throw $exceeded ? new AttachmentTooLargeException : $throwable;
        }

        $response->throw();

        if (! $response->successful()) {
            throw new AttachmentException("The attachment host answered with status [{$response->status()}] instead of file bytes.");
        }

        if ((int) $response->header('Content-Length') > $maxBytes) {
            throw new AttachmentTooLargeException;
        }

        return $response;
    }

    public function read(Response $response, int $maxBytes): string
    {
        $body = $response->toPsrResponse()->getBody();
        $contents = '';

        while (! $body->eof() && ($chunk = $body->read(static::CHUNK_BYTES)) !== '') {
            $contents .= $chunk;

            if (strlen($contents) > $maxBytes) {
                throw new AttachmentTooLargeException;
            }
        }

        return $contents;
    }
}
