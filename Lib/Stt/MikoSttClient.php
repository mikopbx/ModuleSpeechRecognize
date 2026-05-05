<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace Modules\ModuleSpeechRecognize\Lib\Stt;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MikoPBX\Common\Models\PbxSettings;
use Throwable;

/**
 * HTTP-клиент для STT-сервиса MIKO (speech.mikolab.ru), Yandex-совместимый API:
 *   POST /v1/stt/recognize:sync   — sync, до 30 сек
 *   POST /v1/stt/recognize        — async, любая длительность
 *   GET  /v1/stt/result/{id}      — результат async
 *
 * Авторизация: заголовок `Authorization: Key <PBXLicense>`,
 * тот же ключ используется в ApiController::sendGptTask() для /v1/gpt/*.
 */
class MikoSttClient
{
    public const HOST = 'https://speech.mikolab.ru';
    public const LANGUAGE_CODE = 'ru-RU';
    public const MODEL_GENERAL = 'general';
    public const MODEL_DEFERRED_GENERAL = 'deferred-general';
    private const HTTP_TIMEOUT = 60;
    private const HTTP_CONNECT_TIMEOUT = 10;

    private string $key;

    public function __construct(?string $key = null)
    {
        $this->key = $key ?? (string)PbxSettings::getValueByKey('PBXLicense');
    }

    public function hasKey(): bool
    {
        return $this->key !== '';
    }

    /**
     * Синхронное распознавание (≤ ~30 сек). Возвращает массив:
     *  ['text' => string, 'http_code' => int, 'raw' => string]
     * @throws MikoSttException
     */
    public function recognizeSync(string $filePath, string $requestId): array
    {
        if (!is_file($filePath)) {
            throw new MikoSttException('File not found: ' . $filePath, 0);
        }
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new MikoSttException('Cannot open file: ' . $filePath, 0);
        }
        try {
            $multipart = [
                // Сначала параметры, файл — последним (важно для async, для sync — для единообразия).
                ['name' => 'LanguageCode', 'contents' => self::LANGUAGE_CODE],
                ['name' => 'file',         'contents' => $fh, 'filename' => basename($filePath)],
            ];
            $body = $this->doRequest('POST', '/v1/stt/recognize:sync', $multipart, $requestId);
        } finally {
            if (is_resource($fh)) {
                @fclose($fh);
            }
        }
        $code = (int)($body['code'] ?? 0);
        if (!($body['ok'] ?? false)) {
            throw new MikoSttException($body['error'] ?? 'Unknown sync error', $code);
        }
        return [
            'text'      => (string)($body['result'] ?? ''),
            'http_code' => $code,
            'raw'       => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Постановка асинхронной задачи. Возвращает task id.
     * @throws MikoSttException
     */
    public function submitAsync(string $filePath, string $requestId, bool $deferred = false): string
    {
        if (!is_file($filePath)) {
            throw new MikoSttException('File not found: ' . $filePath, 0);
        }
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new MikoSttException('Cannot open file: ' . $filePath, 0);
        }
        try {
            $multipart = [
                ['name' => 'LanguageCode', 'contents' => self::LANGUAGE_CODE],
                ['name' => 'Model',        'contents' => $deferred ? self::MODEL_DEFERRED_GENERAL : self::MODEL_GENERAL],
                // file — строго последним (бриф §2.2).
                ['name' => 'file',         'contents' => $fh, 'filename' => basename($filePath)],
            ];
            $body = $this->doRequest('POST', '/v1/stt/recognize', $multipart, $requestId);
        } finally {
            if (is_resource($fh)) {
                @fclose($fh);
            }
        }
        $code = (int)($body['code'] ?? 0);
        if (!($body['ok'] ?? false)) {
            throw new MikoSttException($body['error'] ?? 'Unknown submit error', $code);
        }
        $taskId = (string)($body['result']['id'] ?? '');
        if ($taskId === '') {
            throw new MikoSttException('Empty task id in response', $code);
        }
        return $taskId;
    }

    /**
     * Получение результата async-задачи.
     * Возвращает массив:
     *  ['done' => bool, 'chunks' => array, 'http_code' => int, 'raw' => string]
     * @throws MikoSttException
     */
    public function fetchAsync(string $taskId, string $requestId): array
    {
        $body = $this->doRequest('GET', '/v1/stt/result/' . rawurlencode($taskId), null, $requestId);
        $code = (int)($body['code'] ?? 0);
        if (!($body['ok'] ?? false)) {
            throw new MikoSttException($body['error'] ?? 'Unknown fetch error', $code);
        }
        $result = $body['result'] ?? [];
        return [
            'done'      => (bool)($result['done'] ?? false),
            'chunks'    => is_array($result['chunks'] ?? null) ? $result['chunks'] : [],
            'http_code' => $code,
            'raw'       => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $multipart  если null — GET без тела
     * @param string $requestId
     * @return array  декодированный JSON-ответ
     * @throws MikoSttException
     */
    private function doRequest(string $method, string $path, ?array $multipart, string $requestId): array
    {
        $client = new Client([
            'base_uri'        => self::HOST,
            'timeout'         => self::HTTP_TIMEOUT,
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            'http_errors'     => false,
        ]);
        $options = [
            'headers' => [
                'Authorization' => 'Key ' . $this->key,
                'X-Request-ID'  => $requestId,
            ],
        ];
        if (is_array($multipart)) {
            $options['multipart'] = $multipart;
        }
        try {
            $response = $client->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new MikoSttException('Transport error: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new MikoSttException('Transport error: ' . $e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        $raw    = (string)$response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new MikoSttException(
                'Non-JSON response (HTTP ' . $status . '): ' . substr($raw, 0, 200),
                $status
            );
        }
        // Нормализуем code: если апстрим не положил code в тело, берём http-статус.
        if (!isset($decoded['code'])) {
            $decoded['code'] = $status;
        }
        return $decoded;
    }
}
