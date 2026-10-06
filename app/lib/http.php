<?php
/**
 * Shared HTTP helpers (curl only: file_get_contents() fails for HTTPS in this container).
 *
 * Both helpers return:
 *   [
 *     'ok'           => bool,     // 2xx status AND non-empty body
 *     'status'       => int,      // HTTP status (0 on transport failure)
 *     'body'         => ?string,  // raw body, null on transport failure
 *     'error'        => ?string,  // curl error, "HTTP <code>", or "Empty response body"; null when ok
 *     'headers'      => array,    // final response headers, lower-cased name => value
 *     'content_type' => string,
 *   ]
 *
 * Phase 2 replacements:
 *   - ainews curlGet($u, $t, $h)        -> visionect_http_get($u, $t, $h)       (check ['ok'], not just non-null)
 *   - ainews curlPost($u, $b, $h, $t)   -> visionect_http_post($u, $b, $h, $t)
 *   - admin api.php curl_fetch($u, $t)  -> visionect_http_get($u, $t)
 *   - comics curlResponse($u, $t, $opt) -> visionect_http_get($u, $t, $acceptHeaders, $cookieOpts)
 *       $acceptHeaders = the same Accept + Accept-Language lines curlResponse sends today
 *       (BunnyCDN requires them on every GoComics request);
 *       $cookieOpts = [CURLOPT_COOKIE => '...'] from loadGoComicsCookieOptions().
 *       comics used a Chrome/146 UA; add CURLOPT_USERAGENT to $curlOpts if Bunny needs it.
 *       $result['headers']['cdn-challenge'] is still available for challenge detection.
 */

const VISIONECT_HTTP_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

function visionect_http_get(string $url, int $timeout = 20, array $headers = [], array $curlOpts = []): array
{
    $opts = [CURLOPT_HTTPGET => true];
    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = array_values($headers);
    }

    // $curlOpts wins over everything (array_replace keeps integer CURLOPT_* keys).
    return visionect_http_request($url, array_replace($opts, $curlOpts), $timeout);
}

function visionect_http_post(string $url, string $body, array $headers = [], int $timeout = 30): array
{
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
    ];
    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = array_values($headers);
    }

    return visionect_http_request($url, $opts, $timeout);
}

/** @internal */
function visionect_http_request(string $url, array $opts, int $timeout): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => null,
            'error' => 'curl_init failed',
            'headers' => [],
            'content_type' => '',
        ];
    }

    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_USERAGENT => VISIONECT_HTTP_USER_AGENT,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            // A new status line means a redirect hop: keep only the final response's headers.
            if (stripos($line, 'HTTP/') === 0) {
                $responseHeaders = [];
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ];

    curl_setopt_array($ch, array_replace($defaults, $opts));
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $body = is_string($body) ? $body : null;
    $ok = $body !== null && $body !== '' && $status >= 200 && $status < 300;

    $error = null;
    if (!$ok) {
        if ($curlError !== '') {
            $error = $curlError;
        } elseif ($status < 200 || $status >= 300) {
            $error = 'HTTP ' . $status;
        } else {
            $error = 'Empty response body';
        }
    }

    return [
        'ok' => $ok,
        'status' => $status,
        'body' => $body,
        'error' => $error,
        'headers' => $responseHeaders,
        'content_type' => $contentType,
    ];
}
