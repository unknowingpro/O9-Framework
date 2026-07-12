<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * S3Driver — stores files on AWS S3 (or any S3-compatible endpoint: MinIO, R2, Wasabi …).
 *
 * No SDK dependency — pure PHP + cURL with AWS Signature V4.
 *
 * Config keys:
 *   key        AWS access key id
 *   secret     AWS secret access key
 *   region     e.g. us-east-1
 *   bucket     bucket name
 *   endpoint   optional custom endpoint (MinIO / R2)
 *   prefix     optional path prefix inside the bucket
 */
class S3Driver implements StorageDriverInterface
{
    private string $key;
    private string $secret;
    private string $region;
    private string $bucket;
    private string $endpoint;
    private string $prefix;

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg)
    {
        $this->key      = (string) ($cfg['key'] ?? '');
        $this->secret   = (string) ($cfg['secret'] ?? '');
        $this->region   = (string) ($cfg['region'] ?? 'us-east-1');
        $this->bucket   = (string) ($cfg['bucket'] ?? '');
        $this->endpoint = rtrim((string) ($cfg['endpoint'] ?? "https://s3.{$this->region}.amazonaws.com"), '/');
        $this->prefix   = rtrim((string) ($cfg['prefix'] ?? ''), '/');
        if ($this->key === '' || $this->secret === '' || $this->bucket === '') {
            throw new \RuntimeException('S3Driver: key, secret and bucket are required');
        }
    }

    public function name(): string { return 's3'; }

    // ── Public interface ──────────────────────────────────────────────────

    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
    {
        // Sign with the literal token "UNSIGNED-PAYLOAD" (accepted by AWS S3,
        // MinIO, R2, and Wasabi over HTTPS) and stream the file body via
        // CURLOPT_INFILE — no file content ever enters PHP memory.
        $s3key    = $this->s3Key($remotePath);
        $mime     = mime_content_type($tmpPath) ?: 'application/octet-stream';
        $headers  = $this->signStreaming('PUT', $s3key, ['Content-Type' => $mime]);
        $url      = $this->url($s3key);

        $fh = fopen($tmpPath, 'rb');
        if (!$fh) throw new \RuntimeException("S3 PUT: cannot open local file: $tmpPath");
        $fileSize = filesize($tmpPath);
        if ($fileSize === false) { fclose($fh); throw new \RuntimeException("S3 PUT: cannot stat local file: $tmpPath"); }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $fileSize,
            CURLOPT_HTTPHEADER     => $this->headerArray($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 0,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($err) throw new \RuntimeException("S3 PUT cURL error: $err");
        if ($code >= 300) throw new \RuntimeException("S3 PUT failed HTTP $code: $resp");
        return true;
    }

    public function get(string $remotePath): string
    {
        $s3key   = $this->s3Key($remotePath);
        $headers = $this->sign('GET', $s3key, '', []);
        $url     = $this->url($s3key);

        $tmp = tempnam(sys_get_temp_dir(), 'o9_s3_');
        if ($tmp === false) {
            throw new \RuntimeException('S3 GET: could not create temp file');
        }
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            throw new \RuntimeException('S3 GET: could not open temp file for writing');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $this->headerArray($headers),
            CURLOPT_FILE           => $fh,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($err)          { @unlink($tmp); throw new \RuntimeException("S3 GET cURL error: $err"); }
        if ($code >= 300)  { @unlink($tmp); throw new \RuntimeException("S3 GET failed HTTP $code"); }
        return $tmp;
    }

    /** Stream an object to PHP output with HTTP Range support (no temp file). */
    public function stream(string $remotePath, int $fileSize = 0): void
    {
        $s3key   = $this->s3Key($remotePath);
        $headers = $this->sign('GET', $s3key, '', []);
        $url     = $this->url($s3key);

        $r = \App\Core\RangeRequest::parse((string) ($_SERVER['HTTP_RANGE'] ?? ''), $fileSize);
        $r->applyHeaders($fileSize);
        if ($r->unsatisfiable) { return; } // 416 already sent — no body

        $ch   = curl_init($url);
        $opts = [
            CURLOPT_HTTPHEADER      => $this->headerArray($headers),
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_TIMEOUT         => 0,
            CURLOPT_LOW_SPEED_LIMIT => 512,
            CURLOPT_LOW_SPEED_TIME  => 60,
            CURLOPT_WRITEFUNCTION   => static function ($ch, string $chunk): int {
                if (connection_aborted()) return -1;
                echo $chunk;
                if (ob_get_level()) ob_flush();
                flush();
                return strlen($chunk);
            },
        ];
        if ($cr = $r->curlRange()) {
            $opts[CURLOPT_RANGE] = $cr;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("S3 stream cURL error: $err");
        if ($code >= 300) throw new \RuntimeException("S3 stream failed HTTP $code");
    }

    public function delete(string $remotePath): bool
    {
        $s3key   = $this->s3Key($remotePath);
        $headers = $this->sign('DELETE', $s3key, '', []);
        $url     = $this->url($s3key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => $this->headerArray($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code < 300 || $code === 404;
    }

    public function exists(string $remotePath): bool
    {
        $s3key   = $this->s3Key($remotePath);
        $headers = $this->sign('HEAD', $s3key, '', []);
        $url     = $this->url($s3key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_HTTPHEADER     => $this->headerArray($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    // ── AWS Signature V4 ─────────────────────────────────────────────────

    private function s3Key(string $remotePath): string
    {
        return ($this->prefix ? $this->prefix . '/' : '') . $remotePath;
    }

    private function url(string $s3key): string
    {
        return $this->endpoint . '/' . $this->bucket . '/' . ltrim($s3key, '/');
    }

    /**
     * Sign a streaming PUT request using UNSIGNED-PAYLOAD, so the file body
     * never has to be loaded into memory to compute its hash.
     *
     * @param array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private function signStreaming(string $method, string $s3key, array $extraHeaders): array
    {
        return $this->sign($method, $s3key, 'UNSIGNED-PAYLOAD', $extraHeaders, true);
    }

    /**
     * Sign a request and return merged headers (including Authorization).
     *
     * @param array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private function sign(string $method, string $s3key, string $body, array $extraHeaders, bool $unsignedPayload = false): array
    {
        $datetime = gmdate('Ymd\THis\Z');
        $date     = substr($datetime, 0, 8);
        $bodyHash = $unsignedPayload ? 'UNSIGNED-PAYLOAD' : hash('sha256', $body);
        $host     = (string) (parse_url($this->endpoint, PHP_URL_HOST) ?? '');

        $headers = array_merge($extraHeaders, [
            'Host'                 => $host,
            'x-amz-date'           => $datetime,
            'x-amz-content-sha256' => $bodyHash,
        ]);
        ksort($headers);

        // Canonical request
        $canonHeaders  = '';
        $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            $canonHeaders  .= "$lk:" . trim($v) . "\n";
            $signedHeaders .= ($signedHeaders ? ';' : '') . $lk;
        }
        $canonUri   = '/' . $this->bucket . '/' . ltrim($s3key, '/');
        $canonQuery = '';
        $canonReq   = implode("\n", [$method, $canonUri, $canonQuery, $canonHeaders, $signedHeaders, $bodyHash]);

        // String to sign
        $scope      = "$date/{$this->region}/s3/aws4_request";
        $stringSign = "AWS4-HMAC-SHA256\n$datetime\n$scope\n" . hash('sha256', $canonReq);

        // Signing key
        $sigKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $date, 'AWS4' . $this->secret, true),
                true), true),
            true);

        $signature = hash_hmac('sha256', $stringSign, $sigKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->key}/$scope, "
            . "SignedHeaders=$signedHeaders, Signature=$signature";

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function headerArray(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) $out[] = "$k: $v";
        return $out;
    }
}
