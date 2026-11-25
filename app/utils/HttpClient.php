<?php

/**
 * HTTP通信（curl）
 *
 * テストではFlight::map('httpClient', ...)で偽物に差し替える
 */
class HttpClient
{
    /** 接続のタイムアウト（秒） */
    private const CONNECT_TIMEOUT = 5;
    /** 通信全体のタイムアウト（秒） */
    private const TIMEOUT = 10;

    /**
     * GETリクエスト
     *
     * @param string $url
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     * @throws RuntimeException 通信に失敗した場合
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->send($url, $headers, []);
    }

    /**
     * POSTリクエスト（フォーム形式）
     *
     * @param string $url
     * @param array<string, string> $params
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     * @throws RuntimeException 通信に失敗した場合
     */
    public function post(string $url, array $params, array $headers = []): array
    {
        return $this->send($url, $headers, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);
    }

    /**
     * リクエストを送信する
     *
     * @param string $url
     * @param array<int, string> $headers
     * @param array<int, mixed> $options
     * @return array{status: int, body: string}
     * @throws RuntimeException
     */
    private function send(string $url, array $headers, array $options): array
    {
        $options += [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            // HTTPS以外には接続しない（秘密情報を平文で送らない）
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if (PHP_OS_FAMILY === 'Windows')
        {
            // WindowsのPHPはCA証明書が未設定のことが多いため、OSの証明書ストアで検証する
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        if ($body === false)
        {
            throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
        }

        return [
            'status' => intval(curl_getinfo($curl, CURLINFO_RESPONSE_CODE)),
            'body' => strval($body),
        ];
    }
}
