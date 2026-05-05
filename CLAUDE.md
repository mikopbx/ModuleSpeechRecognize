# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Что это

Модуль расширения для **MikoPBX** (`moduleUniqueID` = `ModuleSpeechRecognize`, `module.json`), выполняющий распознавание речи (STT) для записей разговоров и постпроцессинг транскриптов через GPT. Целевая платформа: PHP 7.4.6 на Phalcon (поддерживается и Phalcon 4, и Phalcon 5 — см. ниже). PSR-4 namespace `Modules\ModuleSpeechRecognize\` смонтирован в корень модуля (`composer.json`).

В рантайме модуль разворачивается на устройстве MikoPBX в `/storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/`. Здесь, в репозитории — исходники без `vendor/` обновлений в проде.

## Команды

- `composer install` — установить зависимости (`cesargb/php-log-rotation`, `textalk/websocket`).
- `composer dump-autoload` — пересобрать PSR-4 автозагрузку при добавлении/перемещении классов.
- Тестов и линтеров в проекте нет (`scripts` в `composer.json` отсутствует).

Ручной запуск воркеров на установленном PBX (для отладки):

```sh
php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/bin/SpeechRecognizeDaemon.php
php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/bin/ConnectorDb.php start
php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/bin/safeScript.php
```

Опциональный аргумент `start` в CLI включает построчный отладочный вывод в stdout (см. `bin/SpeechRecognizeDaemon.php`, `bin/ConnectorDb.php`).

Сборка docker-образа Tinkoff VoiceKit (выполняется автоматически из `SpeechRecognizeConf::installDockerImg()`, но можно руками):

```sh
docker build --tag tinkoff:tts-v1 - < bin/docker/Dockerfile
```

Обращения к собственному REST API модуля (примеры см. в `Lib/RestAPI/Controllers/ApiController.php`):

```sh
curl 'http://127.0.0.1/pbxcore/api/speech-recognize/get-cdr-data?limit=2&offset=0'
curl 'http://127.0.0.1/pbxcore/api/speech-recognize/add-manual-task?linkedid=...'
curl 'http://127.0.0.1/pbxcore/api/speech-recognize/get-gpt-results?time=...'
curl -X POST http://127.0.0.1/pbxcore/api/speech-recognize/add-gpt-task -H 'Content-Type: application/json' -d '{...}'
```

Просмотр данных модуля:

```sh
sqlite3 /storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/db/module.db \
  "select id,linkedId,waitRecognize,requestId,changeTime,closeTime from m_GptTasks"
```

## Архитектура

Три кооперирующих процесса плюс HTTP-уровень MikoPBX:

1. **`SpeechRecognizeDaemon`** (`bin/SpeechRecognizeDaemon.php`) — бесконечный цикл `sleep(5)`. На каждой итерации:
   - `startRecognize()` — выбирает CDR-записи (либо все, начиная с `cdr_offset`, либо только из `m_ManualTasks`), отправляет `recordingfile` на распознавание.
   - `getRecognizeResponses()` — забирает результаты «long» режима из VoiceKit.
   - `startGetGptResponse()` — отправляет накопленные транскрипты в GPT и забирает результат.
   PID-лок: `/var/run/speech-recognize.pid` (используется `posix_kill($pid, 0)`).

2. **`ConnectorDb`** (`bin/ConnectorDb.php`) — Beanstalk-воркер (наследник `MikoPBX\Core\Workers\WorkerBase`), который **владеет всеми обращениями к Phalcon-моделям модуля**. Все остальные процессы (daemon, REST-контроллеры, веб-контроллер админки) ходят к моделям только через `ConnectorDb::invoke(self::FUNC_*, [...])`. Это сделано чтобы изолировать ORM-инициализацию от короткоживущих CLI/HTTP-процессов.
   - Транспорт: запрос сериализуется в JSON; если влез в `MAX_INLINE_PAYLOAD_BYTES` (512 байт) — летит инлайном, иначе пишется во временный файл `tmp/ModuleSpeechRecognize/temp-*` и в очередь кладётся путь.
   - Ответ записывается в файл и читается клиентом по пути из `tube->reply()`.
   - Все публичные имена RPC-методов вынесены в константы `FUNC_*` — добавляя метод, **обязательно** заведи константу и используй её в вызовах, а не строковый литерал.
   - PID-лок: `/var/run/connector-db.pid`.

3. **`safeScript.php`** — запускается из cron каждые 5 минут (`SpeechRecognizeConf::createCronTasks()`). Гарантирует, что docker-образ собран, контейнер запущен, daemon и `ConnectorDb` живы. Это единственный вход для авто-восстановления упавших процессов — не дублируй эту логику.

4. **REST-слой**: `SpeechRecognizeConf::getPBXCoreRESTAdditionalRoutes()` регистрирует роуты `/pbxcore/api/speech-recognize/*` в ядре MikoPBX, привязанные к `Lib/RestAPI/Controllers/ApiController.php`. Веб-кабинет рулится из `App/Controllers/ModuleSpeechRecognizeController.php` + `App/Forms/...` + `App/Views/index.volt`.

### STT-бэкенды

- **Основной (продакшн):** Tinkoff VoiceKit в Docker-контейнере `tinkoff-tts` (`tinkoff:tts-v1`). Запускается из `SpeechRecognizeConf::onAfterModuleEnable()` через бинарь docker, поставляемый соседним модулем `ModuleDocker` (`dirname($moduleDir) . '/ModuleDocker/bin/docker'`). Контейнер монтирует каталог записей `/storage/usbdisk1/mikopbx/astspool/monitor` и Python-скрипты из `Lib/python/`. Два режима: синхронный (`recognize.py`) и отложенный (`miko-recognize-long.py` + `miko-recognize-long-get-result.py`) — переключается флагом `useLongRecognize`. В режиме «long» опросы результатов идут через таблицу `m_ModuleRecognizeOperations`.
- **Альтернативный (T-One через WebSocket):** `Lib/TOneSTT.php`, `bin/get-stt.php`, `bin/get-stt-eagi.php`. Декодируется mp3 через `lame | sox` в PCM16LE 8 kHz, гонится в WS-сервер `ws://.../api/ws` чанками по 4800 байт, ждёт `event=ready` после каждого чанка. EAGI-вариант запускается из диалплана Asterisk (см. шапку `get-stt-eagi.php` для примера extensions.conf).

### GPT-постпроцессинг

`ApiController::sendGptTask()` шлёт задачу POST'ом на `https://speech.mikolab.ru/v1/gpt/completionAsync` с `Authorization: Key <PBXLicense>`; `ApiController::getGptTaskResult()` опрашивает `/v1/gpt/result/{id}`. На код 429 daemon усыпляется на 120 секунд через `$sleepGptTime` — не убирай эту защиту. Состояние задачи (`requestId`, `waitRecognize`, `closeTime`, `response`) живёт в `m_GptTasks`.

### Совместимость с Phalcon 4 и 5

`Lib/MikoPBXVersion.php` — единая точка определения версии PBX (`PBXVersion > 2024.2.3` → Phalcon 5). Все обращения к классам `Phalcon\Di`, `Phalcon\Validation`, `Phalcon\Text`, `Phalcon\Logger` идут только через `MikoPBXVersion::get*Class()` или `MikoPBXVersion::getDefaultDi()`. **Не импортируй эти классы напрямую** — сломаешь совместимость с одной из версий.

### Модели и БД

Phalcon-модели в `Models/` мапятся на таблицы с префиксом `m_*` в собственной SQLite-базе модуля (`db/module.db` на устройстве). Структура создаётся автоматически из аннотаций в `Setup/PbxExtensionSetup::installDB()` через `createSettingsTableByModelsAnnotations()` родителя — вручную миграции писать не нужно, достаточно изменить `@Column`/`@Indexes` аннотации.

- `ModuleSpeechRecognize` — настройки (apiKey/secretKey, флаги, `cdr_offset` — позиция курсора по CDR).
- `CdrText` — итоговые транскрипты, привязка по `UNIQUEID`/`linkedId`.
- `ManualTasks` — очередь ручного выбора звонков на распознавание (`closeTime=0` ⇒ не обработан).
- `RecognizeOperations` — операции «long» режима, ждущие забора результата.
- `GptTasks` — очередь и результаты GPT-задач.

CDR-данные читаются не из локальной базы модуля, а из общего CDR через `MikoPBX\Common\Providers\CDRDatabaseProvider::getCdr($filter)` с флагом `'miko_result_in_file' => true`.

### Локализация

`Messages/en.php` и `Messages/ru.php` — ассоциативные массивы с ключами вида `ms_*`. В контроллерах используются через `$this->translation->_('ms_Key')`. Новые тексты добавляй в **обе** локали.

## Соглашения, неочевидные из кода

- В `bin/*.php` всегда первой строкой идёт `require_once 'Globals.php'` — этот файл поставляется ядром MikoPBX в рантайме, его нет в репозитории. Не пытайся его создавать.
- `cli_set_process_title(SpeechRecognizeConf::DAEMON_TITLE)` критичен: `onAfterModuleDisable()` ищет процесс по этому имени, чтобы его убить.
- `getSttResultFileName()` возвращает имя `*_stt.txt` рядом с записью — этот файл является «уже распознано» маркером, проверяется до повторного вызова STT.
- Удаление файла-payload в `ConnectorDb::onEvents` происходит **до** обработки — если упадёшь после `unlink`, payload потерян. Если меняешь логику, держи это в уме.
