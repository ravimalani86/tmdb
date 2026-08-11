<?php

declare(strict_types=1);

/**
 * Publish / read Firebase Remote Config parameter via service account.
 * Scope: https://www.googleapis.com/auth/firebase.remoteconfig
 */
final class FirebaseRemoteConfigAdmin
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.remoteconfig';

    /** @var array{project_id:string,parameter_key:string,service_account_path:string} */
    private array $cfg;

    /** @var array<string, mixed>|null */
    private ?array $serviceAccount = null;

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    /**
     * @param array<string, mixed> $config API config from config.php
     */
    public function __construct(array $config)
    {
        $firebase = is_array($config['firebase'] ?? null) ? $config['firebase'] : [];
        $this->cfg = [
            'project_id' => trim((string) ($firebase['project_id'] ?? '')),
            'parameter_key' => trim((string) ($firebase['remote_config_key'] ?? 'movflik_config')),
            'service_account_path' => trim((string) ($firebase['service_account_path'] ?? '')),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->cfg['project_id'] !== ''
            && $this->cfg['parameter_key'] !== ''
            && $this->cfg['service_account_path'] !== ''
            && is_readable($this->cfg['service_account_path']);
    }

    /**
     * @return array{
     *   configured:bool,
     *   project_id:string,
     *   parameter_key:string,
     *   service_account_present:bool
     * }
     */
    public function setupStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'project_id' => $this->cfg['project_id'],
            'parameter_key' => $this->cfg['parameter_key'],
            'service_account_present' => $this->cfg['service_account_path'] !== ''
                && is_readable($this->cfg['service_account_path']),
        ];
    }

    /**
     * @return array{
     *   parameter_key:string,
     *   etag:string,
     *   raw:string,
     *   config:?array<string, mixed>,
     *   parse_error:?string,
     *   template_version:?array<string, mixed>
     * }
     */
    public function getMovflikConfig(): array
    {
        $this->assertConfigured();
        [$template, $etag] = $this->fetchTemplate();
        $raw = $this->extractParameterValue($template, $this->cfg['parameter_key']);
        $parsed = null;
        $parseError = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $parsed = $decoded;
            } else {
                $parseError = 'Stored value is not valid JSON object/array';
            }
        }

        return [
            'parameter_key' => $this->cfg['parameter_key'],
            'etag' => $etag,
            'raw' => $raw,
            'config' => $parsed,
            'parse_error' => $parseError,
            'template_version' => is_array($template['version'] ?? null)
                ? $template['version']
                : null,
        ];
    }

    /**
     * @param array<string, mixed>|string $configJson Object or JSON string for movflik_config
     * @return array{
     *   action:string,
     *   parameter_key:string,
     *   etag:string,
     *   config_version:int,
     *   published_at:string,
     *   template_version:?array<string, mixed>
     * }
     */
    public function publishMovflikConfig($configJson, bool $bumpVersion = true): array
    {
        $this->assertConfigured();

        if (is_string($configJson)) {
            $decoded = json_decode($configJson, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('config must be valid JSON object');
            }
            $config = $decoded;
        } elseif (is_array($configJson)) {
            $config = $configJson;
        } else {
            throw new InvalidArgumentException('config must be object or JSON string');
        }

        if ($config === []) {
            throw new InvalidArgumentException('config cannot be empty');
        }

        if ($bumpVersion) {
            $current = isset($config['config_version']) ? (int) $config['config_version'] : 0;
            $config['config_version'] = max(1, $current + 1);
        } elseif (!isset($config['config_version'])) {
            $config['config_version'] = 1;
        } else {
            $config['config_version'] = (int) $config['config_version'];
        }

        $config['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $raw = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            throw new RuntimeException('Failed to encode config JSON');
        }

        // Remote Config string values have size limits; keep a clear error.
        if (strlen($raw) > 900000) {
            throw new InvalidArgumentException('config JSON is too large for Remote Config');
        }

        $lastError = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                [$template, $etag] = $this->fetchTemplate();
                if (!isset($template['parameters']) || !is_array($template['parameters'])) {
                    $template['parameters'] = [];
                }
                $key = $this->cfg['parameter_key'];
                $template['parameters'][$key] = [
                    'defaultValue' => [
                        'value' => $raw,
                    ],
                ];
                // Do not send version object back on PUT.
                unset($template['version']);

                [$published, $newEtag] = $this->putTemplate($template, $etag);

                return [
                    'action' => 'remote_config_published',
                    'parameter_key' => $key,
                    'etag' => $newEtag,
                    'config_version' => (int) $config['config_version'],
                    'published_at' => (string) $config['updated_at'],
                    'template_version' => is_array($published['version'] ?? null)
                        ? $published['version']
                        : null,
                ];
            } catch (RuntimeException $e) {
                $lastError = $e;
                // Retry once on etag conflict.
                if (!str_contains($e->getMessage(), '409') && !str_contains(strtolower($e->getMessage()), 'etag')) {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new RuntimeException('Publish failed');
    }

    private function assertConfigured(): void
    {
        if ($this->isConfigured()) {
            return;
        }
        throw new RuntimeException(
            'Firebase Remote Config admin is not configured. Set FIREBASE_PROJECT_ID and FIREBASE_SERVICE_ACCOUNT_PATH in api/.env'
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function fetchTemplate(): array
    {
        $url = $this->remoteConfigUrl();
        $res = $this->httpJson('GET', $url, null, [
            'Authorization: Bearer ' . $this->accessToken(),
            'Accept: application/json',
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                'Firebase GET remoteConfig failed HTTP ' . $res['status'] . ': ' . $res['body']
            );
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid remoteConfig template JSON');
        }
        $etag = trim((string) ($res['headers']['etag'] ?? $res['headers']['ETag'] ?? ''));
        if ($etag === '') {
            // Some stacks expose as list; fall back to wildcard only as last resort.
            $etag = '*';
        }
        return [$data, $etag];
    }

    /**
     * @param array<string, mixed> $template
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function putTemplate(array $template, string $etag): array
    {
        $url = $this->remoteConfigUrl();
        $body = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode remoteConfig template');
        }
        $res = $this->httpJson('PUT', $url, $body, [
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json; UTF-8',
            'Accept: application/json',
            'If-Match: ' . $etag,
        ]);
        if ($res['status'] === 409) {
            throw new RuntimeException('etag conflict 409 — retry');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                'Firebase PUT remoteConfig failed HTTP ' . $res['status'] . ': ' . $res['body']
            );
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            $data = [];
        }
        $newEtag = trim((string) ($res['headers']['etag'] ?? $res['headers']['ETag'] ?? $etag));
        return [$data, $newEtag];
    }

    private function remoteConfigUrl(): string
    {
        $projectId = rawurlencode($this->cfg['project_id']);
        return "https://firebaseremoteconfig.googleapis.com/v1/projects/{$projectId}/remoteConfig";
    }

    /**
     * @param array<string, mixed> $template
     */
    private function extractParameterValue(array $template, string $key): string
    {
        $params = $template['parameters'] ?? null;
        if (!is_array($params) || !isset($params[$key]) || !is_array($params[$key])) {
            return '';
        }
        $default = $params[$key]['defaultValue'] ?? null;
        if (!is_array($default)) {
            return '';
        }
        return (string) ($default['value'] ?? '');
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < ($this->accessTokenExpiresAt - 60)) {
            return $this->accessToken;
        }

        $sa = $this->loadServiceAccount();
        $jwt = $this->createServiceAccountJwt($sa);
        $post = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        $res = $this->httpJson('POST', self::TOKEN_URL, $post, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                'Google OAuth token failed HTTP ' . $res['status'] . ': ' . $res['body']
            );
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Google OAuth token response missing access_token');
        }
        $this->accessToken = (string) $data['access_token'];
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->accessTokenExpiresAt = time() + max(60, $expiresIn);
        return $this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadServiceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }
        $path = $this->cfg['service_account_path'];
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Cannot read Firebase service account JSON');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)
            || empty($data['client_email'])
            || empty($data['private_key'])
        ) {
            throw new RuntimeException('Invalid Firebase service account JSON');
        }
        $this->serviceAccount = $data;
        return $data;
    }

    /**
     * @param array<string, mixed> $sa
     */
    private function createServiceAccountJwt(array $sa): string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES) ?: '');
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => (string) $sa['client_email'],
            'sub' => (string) $sa['client_email'],
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => self::SCOPE,
        ], JSON_UNESCAPED_SLASHES) ?: '');

        $unsigned = $header . '.' . $payload;
        $key = openssl_pkey_get_private((string) $sa['private_key']);
        if ($key === false) {
            throw new RuntimeException('Invalid service account private_key');
        }
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('Failed to sign service account JWT');
        }
        return $unsigned . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function httpJson(string $method, string $url, ?string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Firebase admin');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[strtolower($name)] = $value;
                        $responseHeaders[$name] = $value;
                    }
                }
                return $len;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($respBody === false) {
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        return [
            'status' => $status,
            'body' => (string) $respBody,
            'headers' => $responseHeaders,
        ];
    }
}
