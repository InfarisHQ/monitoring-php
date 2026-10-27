<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/** Delivery to Guard. Never throws: telemetry must not break a request. */
class Transport
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body): bool
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return false;
        }
        $url = $this->config->apiUrl . $path;
        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->config->apiKey,
            'User-Agent: infaris-monitoring-php/' . Monitor::VERSION,
        ];

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->config->timeout * 1000),
                    CURLOPT_TIMEOUT_MS => (int) ($this->config->timeout * 1000),
                ]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                return $status >= 200 && $status < 300;
            }

            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => $this->config->timeout,
                'ignore_errors' => true,
            ]]);
            $result = @file_get_contents($url, false, $context);

            return $result !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
