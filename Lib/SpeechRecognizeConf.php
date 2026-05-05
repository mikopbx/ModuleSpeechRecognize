<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */

namespace Modules\ModuleSpeechRecognize\Lib;

use MikoPBX\Core\System\Configs\CronConf;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleSpeechRecognize\bin\ConnectorDb;
use Modules\ModuleSpeechRecognize\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleSpeechRecognize\Lib\Stt\AudioPreparer;
use Modules\ModuleSpeechRecognize\Lib\Stt\MikoSttClient;
use Modules\ModuleSpeechRecognize\Lib\Stt\MikoSttException;
use Modules\ModuleSpeechRecognize\Lib\Stt\SkipAudioException;
use Throwable;

class SpeechRecognizeConf extends ConfigClass
{
    public const DAEMON_TITLE       = 'SpeechRecognizeDaemon';
    public const CONTAINER_NAME     = 'tinkoff-tts';
    public const CONTAINER_IMG_NAME = 'tinkoff:tts-v1';
    public const PROVIDER_TINKOFF   = 'tinkoff';
    public const PROVIDER_MIKO      = 'miko';
    private string $apiKey='';
    private string $secretKey='';
    private int $cdrOffset;
    private bool $useLongRecognize = false;
    private bool $recognizeAll = false;
    private string $provider = self::PROVIDER_TINKOFF;
    private int $syncMaxSeconds = 28;
    private bool $mikoUseDeferredGeneral = false;


    /**
     * Необходимо ли использовать отложенное распознавание.
     * @return bool
     */
    public function useLongRecognize():bool
    {
        return $this->useLongRecognize;
    }

    /**
     * Returns array of additional routes for PBXCoreREST interface from module
     *
     * [ControllerClass, ActionMethod, RequestTemplate, HttpMethod, RootUrl, NoAuth ]
     *
     * @return array
     * @example
     *  [[GetController::class, 'callAction', '/pbxcore/api/backup/{actionName}', 'get', '/', false],
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [ApiController::class, 'addManualTasks', '/pbxcore/api/speech-recognize/add-manual-task',  'get', '/', true],
            [ApiController::class, 'getCdrData',     '/pbxcore/api/speech-recognize/get-cdr-data',  'get', '/', true],
            [ApiController::class, 'addGptTask',     '/pbxcore/api/speech-recognize/add-gpt-task',  'post', '/', true],
            [ApiController::class, 'getGptResults',  '/pbxcore/api/speech-recognize/get-gpt-results',  'get', '/', true],
        ];
    }

    /**
     * Получение настроек;
     */
    public function getSettings(): void
    {
        $settings = (object)(ConnectorDb::invoke(ConnectorDb::FUNC_GET_SETTINGS));
        if (empty($settings)) {
            return;
        }
        $this->apiKey = $settings->apiKey;
        $this->secretKey = $settings->secretKey;
        $this->cdrOffset = intval($settings->cdr_offset);
        $this->useLongRecognize = intval($settings->useLongRecognize) === 1;
        $this->recognizeAll = intval($settings->recognizeAll) === 1;
        $providerRaw = strtolower(trim((string)($settings->provider ?? '')));
        $this->provider = ($providerRaw === self::PROVIDER_MIKO) ? self::PROVIDER_MIKO : self::PROVIDER_TINKOFF;
        $this->syncMaxSeconds = max(1, min(28, intval($settings->syncMaxSeconds ?? 28)));
        $this->mikoUseDeferredGeneral = intval($settings->mikoUseDeferredGeneral ?? 0) === 1;
        if(empty($this->cdrOffset)){
            $this->cdrOffset = 1;
        }
    }

    /**
     * Идентификатор активного провайдера (`tinkoff` или `miko`).
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    public function isMikoProvider(): bool
    {
        return $this->provider === self::PROVIDER_MIKO;
    }

    public function getSyncMaxSeconds(): int
    {
        return $this->syncMaxSeconds;
    }

    public function isMikoUseDeferredGeneral(): bool
    {
        return $this->mikoUseDeferredGeneral;
    }

    /**
     * Возвращает номер строки истории звонков.
     * @return int
     */
    public function getOffset():int
    {
        return $this->cdrOffset;
    }

    public function getDaemonPath():string
    {
        return $this->moduleDir.'/bin/SpeechRecognizeDaemon.php';
    }

    public function getConnectorDbPath():string
    {
        return $this->moduleDir.'/bin/ConnectorDb.php';
    }

    /**
     * Проверка, разрешено ли распознавание всех разговоров
     * @return bool
     */
    public function recognizeAllEnable():bool
    {
        return $this->recognizeAll;
    }

    /**
     * Проверка, заполнены ли настройки.
     * Для Tinkoff нужны apiKey + secretKey, для MIKO — наличие лицензии PBX.
     * @return bool
     */
    public function settingsExists():bool
    {
        if ($this->isMikoProvider()) {
            return $this->createMikoClient()->hasKey();
        }
        return !empty($this->apiKey) && !empty($this->secretKey);
    }

    /**
     * Создаёт HTTP-клиент к speech.mikolab.ru.
     */
    private function createMikoClient(): MikoSttClient
    {
        return new MikoSttClient();
    }

    /**
     * Подготавливает аудио для отправки в MIKO. Решает sync vs async по длительности.
     * @return array  результат AudioPreparer::prepare() + ключ 'useAsync'
     * @throws SkipAudioException
     */
    public function prepareForMiko(string $file, string $uniqueId, bool $force = false): array
    {
        $tmpDir = $this->getMikoTmpDir();
        $preparer = new AudioPreparer($tmpDir);
        $prepared = $preparer->prepare($file, $uniqueId, $force);
        $prepared['useAsync'] = $prepared['duration'] > $this->syncMaxSeconds;
        return $prepared;
    }

    /**
     * Лёгкая проверка длительности файла без перекодирования — нужна, чтобы
     * решить sync vs async, не тратя CPU. Возвращает length в секундах либо 0,
     * если probe не удался (вызывающий код должен трактовать как «отдать в async»).
     */
    public function probeDuration(string $file): float
    {
        $tmpDir = $this->getMikoTmpDir();
        $preparer = new AudioPreparer($tmpDir);
        $info = $preparer->probe($file);
        return (float)($info['duration'] ?? 0.0);
    }

    public function getMikoTmpDir(): string
    {
        $base = sys_get_temp_dir();
        $diBase = MikoPBXVersion::getDefaultDi();
        if ($diBase) {
            try {
                $config = $diBase->getShared('config');
                $tmpRoot = $config->path('core.tempDir');
                if (is_string($tmpRoot) && $tmpRoot !== '') {
                    $base = rtrim($tmpRoot, '/');
                }
            } catch (Throwable $e) {
                // оставим sys_get_temp_dir()
            }
        }
        return $base . '/ModuleSpeechRecognize/prepared';
    }

    private function cleanupPrepared(?array $prepared): void
    {
        if (!is_array($prepared) || empty($prepared['temporary']) || empty($prepared['path'])) {
            return;
        }
        if (is_file($prepared['path'])) {
            @unlink($prepared['path']);
        }
    }

    /**
     * Маппинг chunks из MIKO в формат, совместимый с convertResponse() (Tinkoff).
     * @param array $chunks
     * @return array
     */
    private function chunksToMessages(array $chunks): array
    {
        $messages = [];
        foreach ($chunks as $chunk) {
            $text = (string)($chunk['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $messages[] = [
                'text'     => $text,
                'channel'  => (int)($chunk['channel'] ?? 1),
                'start'    => $chunk['start_time'] ?? '',
                'end'      => $chunk['end_time'] ?? '',
                'sentiment'=> ['negative_prob_audio' => 0, 'negative_prob_audio_text' => 0],
                'gender'   => [],
            ];
        }
        return $messages;
    }

    /**
     * Sync-распознавание через MIKO. Возвращает messages в формате convertResponse().
     */
    private function recognizeMikoSync(string $filename, string $uniqueId): array
    {
        $resultFile = $this->getSttResultFileName($filename);
        if (file_exists($resultFile)) {
            return $this->convertResponse(file_get_contents($resultFile));
        }
        $prepared = null;
        try {
            $prepared = $this->prepareForMiko($filename, $uniqueId);
            $client = $this->createMikoClient();
            $requestId = $uniqueId . '.' . microtime(true);
            $response = $client->recognizeSync($prepared['path'], $requestId);
            $text = trim((string)$response['text']);
            if ($text === '') {
                file_put_contents($resultFile, '{"chunks":[]}');
                return [];
            }
            $messages = [[
                'text'     => $text,
                'channel'  => 1,
                'start'    => 0,
                'end'      => $prepared['duration'] ?? 0,
                'sentiment'=> ['negative_prob_audio' => 0, 'negative_prob_audio_text' => 0],
                'gender'   => [],
            ]];
            file_put_contents($resultFile, json_encode(['chunks' => $messages], JSON_UNESCAPED_UNICODE));
            return $messages;
        } catch (MikoSttException $e) {
            // Если файл оказался длиннее — отдаём пустой ответ, даемон переотправит как async.
            return [];
        } catch (SkipAudioException $e) {
            file_put_contents($resultFile, '{"chunks":[],"skipped":true}');
            return [];
        } finally {
            $this->cleanupPrepared($prepared);
        }
    }

    /**
     * Постановка асинхронной задачи в MIKO. Сохраняет task id в RecognizeOperations.
     */
    private function sendToRecognizeMiko(array $dataCdr): void
    {
        $resultFile = $this->getSttResultFileName($dataCdr['recordingfile']);
        if (file_exists($resultFile)) {
            return;
        }
        $prepared = null;
        try {
            $prepared = $this->prepareForMiko($dataCdr['recordingfile'], $dataCdr['UNIQUEID'] ?? ($dataCdr['linkedid'] ?? ''));
            $client = $this->createMikoClient();
            $requestId = ($dataCdr['UNIQUEID'] ?? 'cdr') . '.' . microtime(true);
            $taskId = $client->submitAsync($prepared['path'], $requestId, $this->mikoUseDeferredGeneral);
            $payload = $dataCdr;
            $payload['provider']    = self::PROVIDER_MIKO;
            $payload['submittedAt'] = time();
            ConnectorDb::invoke(ConnectorDb::FUNC_SAVE_RESULT_SEND_RECOGNIZE, [$payload, $taskId]);
        } catch (SkipAudioException $e) {
            // Битый/пустой/слишком длинный файл — заводим запись с fail=1, чтобы
            // не зацикливать ретраи. updateFailRecognizeOperations создаст
            // запись по UNIQUEID, если её ещё нет.
            ConnectorDb::invoke(
                ConnectorDb::FUNC_UPD_RECOGNIZE_OPERATIONS,
                [$dataCdr + ['provider' => self::PROVIDER_MIKO, 'fail' => 1]],
                false
            );
        } catch (MikoSttException $e) {
            // Сетевая/серверная ошибка submit — заводим запись со счётчиком попыток,
            // чтобы даемон вернулся к ней через nextRetryAt.
            $delay = 60;
            ConnectorDb::invoke(
                ConnectorDb::FUNC_UPD_RECOGNIZE_OPERATIONS,
                [$dataCdr + [
                    'provider'    => self::PROVIDER_MIKO,
                    'attempts'    => 1,
                    'nextRetryAt' => time() + $delay,
                    'fail'        => 0,
                ]],
                false
            );
        } finally {
            $this->cleanupPrepared($prepared);
        }
    }

    /**
     * Получение результата async-задачи MIKO. Возвращает messages в формате convertResponse()
     * либо пустой массив, если задача ещё в обработке.
     */
    public function getMikoRecognizeResponse(string $taskId, string $filename): array
    {
        $client = $this->createMikoClient();
        $requestId = basename($filename) . '.' . microtime(true);
        $response = $client->fetchAsync($taskId, $requestId);
        if (!$response['done']) {
            return [];
        }
        $messages = $this->chunksToMessages($response['chunks']);
        $resultFile = $this->getSttResultFileName($filename);
        file_put_contents($resultFile, json_encode(['chunks' => $messages], JSON_UNESCAPED_UNICODE));
        return $messages;
    }

    /**
     *  Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res    = new PBXApiResult();
        $res->processor = __METHOD__;
        $action = "{$request['action']}Action";
        $data   = $request['data']??[];
        if(method_exists($this, $action)){
            $res = $this->$action($data);
        }else{
            $res->success = false;
            $res->data = $request;
        }
        return $res;
    }

    public function checkAction():PBXApiResult
    {
        $res    = new PBXApiResult();
        $res->data[] = 'ddd';
        return $res;
    }

    /**
     * Возвращает путь к исполняемому файлу docker.
     * @return string
     */
    private function getDockerPath():string
    {
        return dirname($this->moduleDir) .DIRECTORY_SEPARATOR.'ModuleDocker'. DIRECTORY_SEPARATOR . 'bin'.DIRECTORY_SEPARATOR.'docker';
    }

    /**
     * Возваращает путь к образу контейнера.
     * @return string
     */
    private function getDockerFile():string
    {
        return "$this->moduleDir/bin/docker/Dockerfile";
    }

    /**
     * Возвращает идентификатор контейнера Docker.
     * @return string
     */
    public function getPidContainer():string{
        $dockerPath  = $this->getDockerPath();
        $grep        = Util::which('grep');
        $busybox     = Util::which('busybox');
        Processes::mwExec("$dockerPath ps | $busybox $grep ".self::CONTAINER_IMG_NAME." |{$busybox} awk  '{ print $1}'", $out);
        return implode('', $out);
    }

    /**
     * Установка образа контейнера. Для MIKO-провайдера контейнер не нужен.
     */
    public function installDockerImg():void
    {
        if ($this->isMikoProvider()) {
            return;
        }
        $dockerPath  = $this->getDockerPath();
        $imgPath     = $this->getDockerFile();

        $pid = Processes::getPidOfProcess('docker build');
        if(!empty($pid)){
            // Уже запущена сборка.
            return;
        }
        if(!$this->imgExists()){
            // Нужно запустить сборку.
            Processes::mwExecBg("$dockerPath build --tag ".self::CONTAINER_IMG_NAME." - < $imgPath");
        }
    }

    /**
     * Проверка существует ли образ docker.
     * @return bool
     */
    public function imgExists():bool
    {
        $busybox     = Util::which('busybox');
        $dockerPath  = $this->getDockerPath();
        $awcCmd      = '{ print $1 ":" $2 ":" $3}';
        $result      = Processes::mwExec("$dockerPath images | $busybox awk '$awcCmd' | $busybox grep '".self::CONTAINER_IMG_NAME."'");
        return ($result !== 1);
    }

    /**
     * Process after enable action in web interface
     *
     * @return void
     */
    public function onAfterModuleEnable(): void
    {
        if ($this->isMikoProvider()) {
            // MIKO-провайдеру контейнер не нужен — поднимаем только cron + safeScript,
            // он сам поднимет daemon и ConnectorDb.
            $cron = new CronConf();
            $cron->reStart();
            $path    = $this->getSafeScriptPath();
            $phpPath = Util::which('php');
            Processes::mwExecBg("$phpPath -f $path");
            return;
        }
        if(!$this->imgExists()){
            return;
        }
        if(!empty($this->getPidContainer())){
            return;
        }
        $cron = new CronConf();
        $cron->reStart();
        $dockerPath  = $this->getDockerPath();
        $params = [
            "$dockerPath run -t -d --rm --name ".self::CONTAINER_NAME,
            "-v /storage/usbdisk1/mikopbx/astspool/monitor:/storage/usbdisk1/mikopbx/astspool/monitor",
            "-v $this->moduleDir/Lib/python/recognize.py:/voicekit/python/recognize.py",
            "-v $this->moduleDir/Lib/python/common.py:/voicekit/python/common.py",
            "-v $this->moduleDir/Lib/python/miko-recognize-long.py:/voicekit/python/miko-recognize-long.py",
            "-v $this->moduleDir/Lib/python/miko-recognize-long-get-result.py:/voicekit/python/miko-recognize-long-get-result.py",
            self::CONTAINER_IMG_NAME
        ];
        Processes::mwExecBg(implode(' ', $params));

        $path = $this->getSafeScriptPath();
        $phpPath = Util::which('php');
        Processes::mwExecBg("$phpPath -f $path");
    }

    /**
     * Process after disable action in web interface
     *
     * @return void
     */
    public function onAfterModuleDisable(): void
    {
        $dockerPath = $this->getDockerPath();
        $pid = $this->getPidContainer();
        if(!empty($pid)){
            Processes::mwExec($dockerPath.' kill '.$pid);
        }
        $killPath = Util::which('kill');
        $busyPath = Util::which('busybox');
        Processes::mwExec($killPath.' $('.$busyPath.' ps | '.$busyPath.' grep '.SpeechRecognizeConf::DAEMON_TITLE.' | grep -v grep | cut -f 1 -d " ")');
    }

    /**
     * Проверка работы docker процесса.
     */
    public function checkStart():bool{
        $moduleEnabled  = PbxExtensionUtils::isEnabled($this->moduleUniqueId);
        if($moduleEnabled === true){
            $this->onAfterModuleEnable();
        }else{
            $this->onAfterModuleDisable();
        }

        return $moduleEnabled;
    }

    public function getSttResultFileName($filename):string
    {
        return Util::trimExtensionForFile($filename).'_stt.txt';
    }

    /**
     * Отправка файла на распознавание.
     * @param $filename
     * @param $uid
     * @param $linkedId
     */
    public function sendToRecognize($dataCdr):void
    {
        if ($this->isMikoProvider()) {
            $this->sendToRecognizeMiko($dataCdr);
            return;
        }
        $resultFile = $this->getSttResultFileName($dataCdr['recordingfile']);
        if(file_exists($resultFile)){
            return;
        }
        $countChannels = $this->getCountRecordChannels($dataCdr['recordingfile']);
        $dockerPath = $this->getDockerPath();
        $params = [
            "$dockerPath exec -t ".self::CONTAINER_NAME,
            "/usr/bin/python3 /voicekit/python/miko-recognize-long.py",
            "--api_key='$this->apiKey' --secret_key='$this->secretKey'",
            "-r 8000  -c $countChannels -e MPEG_AUDIO ",
            $dataCdr['recordingfile']
        ];

        $result = shell_exec(implode(' ', $params));
        ConnectorDb::invoke(ConnectorDb::FUNC_SAVE_RESULT_SEND_RECOGNIZE, [$dataCdr,$result]);
    }

    /**
     * Получение результата распознавания.
     * @param $id
     * @param $filename
     * @return array
     */
    public function getLongRecognizeResponse($id, $filename):array
    {
        $dockerPath = $this->getDockerPath();
        $params = [
            "$dockerPath exec -t ".self::CONTAINER_NAME,
            "/usr/bin/python3 /voicekit/python/miko-recognize-long-get-result.py",
            "--api_key='$this->apiKey' --secret_key='$this->secretKey'",
            "-i $id"
        ];
        $resultFile = $this->getSttResultFileName($filename);
        $output = shell_exec(implode(' ', $params));
        file_put_contents($resultFile, $output);
        return $this->convertResponse($output);
    }

    /**
     * Распознавание текста в mp3 файле.
     * @param $filename
     * @return array
     */
    public function recognize($filename):array
    {
        if ($this->isMikoProvider()) {
            // uniqueId для имени временного файла берём из basename — публичная сигнатура метода без UNIQUEID.
            return $this->recognizeMikoSync($filename, pathinfo($filename, PATHINFO_FILENAME));
        }
        $resultFile = $this->getSttResultFileName($filename);
        if(file_exists($resultFile)){
            return $this->convertResponse(file_get_contents($resultFile));
        }
        $countChannels  = $this->getCountRecordChannels($filename);
        $dockerPath     = $this->getDockerPath();
        $params = [
            "$dockerPath exec -t ".self::CONTAINER_NAME,
            "/usr/bin/python3 /voicekit/python/recognize.py",
            "--api_key='$this->apiKey' --secret_key='$this->secretKey'",
            "-r 8000  -c $countChannels -e MPEG_AUDIO ",
            $filename
        ];
        $output = shell_exec(implode(' ', $params));
        file_put_contents($resultFile, $output);
        return $this->convertResponse($output);
    }

    /**
     * Преобразование ответа в массив нужного формата.
     * @param $output
     * @return array
     */
    private function convertResponse($output):array
    {
        $messages = [];
        try {
            $data   = json_decode($output, true);
        }catch (Throwable $e){
            return $messages;
        }

        if(isset($data['response']['results'])){
            // Это ответ Long Request.
            $data = $data['response']['results'];
        }

        if(is_array($data)){
            foreach ($data as $row){
                $text = $row['alternatives'][0]['transcript']??'';
                if(empty($text)){
                    continue;
                }
                $messages[] = [
                    'text'     => $text,
                    'channel'  => $row['channel']??'',
                    'start'    => $row['start_time']??'',
                    'end'      => $row['end_time']??'',
                    'sentiment'=> $row['sentiment_analysis_result']??['negative_prob_audio'=>0, 'negative_prob_audio_text'=>0],
                    'gender'   => $row['gender_identification_result']??[],
                ];
            }
        }
        return $messages;
    }

    /**
     * Получение количества каналов звукового файла.
     * @param $filename
     * @return string
     */
    private function getCountRecordChannels($filename):string
    {
        $soxPath = Util::which('soxi');
        $busyboxPath = Util::which('busybox');
        $out = [];
        Processes::mwExec("$soxPath $filename | $busyboxPath grep Channels | $busyboxPath awk -F ':' '{ print $2}'", $out);

        return trim(implode('', $out));
    }

    private function getSafeScriptPath():string
    {
        return $this->moduleDir.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'safeScript.php';
    }

    /**
     * Добавление задач в crond.
     *
     * @param $tasks
     */
    public function createCronTasks(&$tasks): void
    {
        if ( !is_array($tasks)) {
            return;
        }
        $workerPath = $this->getSafeScriptPath();
        $phpPath = Util::which('php');
        $tasks[]      = "*/5 * * * * {$phpPath} -f {$workerPath} > /dev/null 2> /dev/null\n";
    }
}