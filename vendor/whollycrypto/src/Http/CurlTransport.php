<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Http;

use WhollyCrypto\PrestaShop\Sdk\Exception\TransportException;
use WhollyCrypto\PrestaShop\Sdk\Options;
use WhollyCrypto\PrestaShop\Sdk\Internal\Compat;

final class CurlTransport implements TransportInterface
{
    /** @var \CurlHandle|resource|null PHP 7 uses a cURL resource; PHP 8 uses an object. */
    private $handle = null;

    public function send(
        #[\SensitiveParameter]
        Request $request,
        Options $options
    ): Response {
        $curl = $this->handle ??= curl_init();
        if ($curl === false) {
            $this->handle = null;
            throw new TransportException('Could not initialize the HTTPS transport.');
        }
        $body = '';
        $headers = [];
        $headerBytes = 0;
        $tooLarge = false;
        $lines = [];
        foreach ($request->headers() as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $request->url,
                CURLOPT_CUSTOMREQUEST => $request->method,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_CONNECTTIMEOUT => $options->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $options->timeoutSeconds,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $options): int {
                    if (strlen($body) + strlen($chunk) > $options->maxResponseBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$headerBytes, &$tooLarge): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 32_768) {
                        $tooLarge = true;
                        return 0;
                    }
                    if (Compat::startsWith($line, 'HTTP/')) {
                        $headers = []; // Ignore headers from interim 100/103 responses.
                    } elseif (Compat::contains($line, ':')) {
                        [$key, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($key))] = trim($value);
                    }
                    return strlen($line);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($curl, CURLOPT_PROTOCOLS_STR, $options->allowInsecureLocalhost ? 'https,http' : 'https');
            } else {
                curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | ($options->allowInsecureLocalhost ? CURLPROTO_HTTP : 0));
            }
            if ($request->body() !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $request->body());
            }
            if (curl_exec($curl) === false) {
                $code = curl_errno($curl);
                if ($tooLarge) {
                    throw new TransportException('Response exceeded the configured size limit.');
                }
                // Raw cURL errors can contain URLs. Never include them in exceptions.
                throw new TransportException(
                    sprintf('Wholly Crypto connection failed (cURL %d). Check HTTPS, DNS and connectivity.', $code),
                    in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR], true),
                );
            }
            return new Response((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $headers, $body);
        } finally {
            // Retain the connection/DNS cache, but clear request headers, body
            // and callbacks so sharing a transport cannot reuse credentials.
            curl_reset($curl);
        }
    }
}
