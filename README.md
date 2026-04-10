# Автоматизация стадии DELIVERY

Небольшой PHP 8.3 сервис под тестовое задание: входящий webhook `ONCRMDEALUPDATE` попадает в RabbitMQ, сервис берёт из webhook только `FIELDS[ID]`, дочитывает текущую сделку через Bitrix24 REST, фильтрует нужную категорию, определяет реальный вход в `DELIVERY` по локальному snapshot стадии и fallback через `crm.stagehistory.list`, а worker читает сделку, контакт и товарные позиции напрямую из Bitrix24 REST, вызывает courier mock, считает `RiskScore`, пишет результат обратно в сделку, добавляет запись в timeline и создаёт задачу без дублей.

Проект специально оставлен простым:

- без UI
- без локального mock CRM
- без лишних repository/factory/service-слоёв
- с одним локальным SQLite state-store только для идемпотентности, ретраев и дедупа задач

## Что внутри

- `public/index.php` — webhook endpoint
- `src/UseCase/IngestWebhookEvent.php` — валидация webhook и постановка в очередь
- `src/UseCase/ProcessDeliveryEvent.php` — worker с основной логикой
- `src/Integration/Bitrix/BitrixCrm.php` — прямые вызовы Bitrix24 REST
- `src/Integration/Bitrix/BitrixRestClient.php` — retry, rate-limit, backoff+jitter, batch guard
- `src/Integration/Persistence/DeliveryStateStore.php` — локальный SQLite state-store для `incoming_events`, stage snapshot и task locks
- `mock-courier/public/index.php` — mock сервиса расчёта доставки
- `docs/adr-delivery-workflow.md` — короткая дизайн-заметка

## Запуск

```bash
cp .env.example .env
# заполните в .env Bitrix webhook и названия UF-полей
docker compose up --build -d
docker compose exec app php bin/app db:migrate
docker compose exec app php bin/app app:reset-state
# для реального портала укажите публичный HTTPS APP_URL или передайте --handler-url
docker compose exec app php bin/app app:subscribe-webhook --handler-url=https://your-public-host/webhook/your-secret
```

После этого сервис ждёт webhook на `/webhook/{APP_WEBHOOK_SECRET}`, а worker уже читает очередь.

Локальный `http://localhost:8080` нужен для разработки и `app:simulate-events`. Для реальной подписки Bitrix24 нужен публичный HTTPS URL.

`app:reset-state` очищает локальный SQLite state-store и RabbitMQ queue. Это не трогает Bitrix24, только локальные дедуп-данные приложения.

RabbitMQ management UI: `http://localhost:15672`, логин и пароль берите из `.env`.

## Что нужно настроить в Bitrix24

В `.env` обязательны:

- `BITRIX_MEMBER_ID` — `member_id` вашего портала
- `BITRIX_WEBHOOK_URL` — входящий вебхук Bitrix24 для обычных CRM REST-вызовов worker'а
- `BITRIX_APP_REST_URL` — OAuth REST endpoint приложения вида `https://portal.bitrix24.ru/rest`
- `BITRIX_APP_ACCESS_TOKEN` — access token приложения для `event.get/event.bind`
- `BITRIX_FIELD_CITY_CODE_TO`
- `BITRIX_FIELD_WEIGHT_KG`
- `BITRIX_FIELD_SLA_DUE_AT`
- `BITRIX_FIELD_RISK_SCORE`
- `BITRIX_FIELD_ETA_DAYS`
- `BITRIX_FIELD_DELIVERY_ZONE`
- `BITRIX_FIELD_DIAGNOSTIC_HASH`
- `BITRIX_FIELD_RAW_QUOTE_JSON`

Эти поля ожидаются на сделке. Контакт читается через `CONTACT_ID`, телефон и email берутся из `crm.contact.get`.

## Команды

```bash
docker compose exec app php bin/app db:migrate
docker compose exec app php bin/app app:reset-state
docker compose exec app php bin/app app:subscribe-webhook --handler-url=https://example.com/webhook/secret
docker compose exec app php bin/app app:consume --limit=10 --dry-run
docker compose exec app php bin/app app:simulate-events --count=200 --duplicate-every=25 --deal-ids=101,102,103
docker compose exec app php bin/app app:report
docker compose exec app composer test
```

`app:simulate-events` отправляет синтетические webhook-ивенты в форме, близкой к реальному `ONCRMDEALUPDATE`: в теле есть `event`, `event_handler_id`, `data.FIELDS.ID`, `ts` и блок `auth` с теми же ключами, которые обычно присылает Bitrix24. Для локальной эмуляции дополнительно ставится `simulate=true`, а текущее состояние сделки передаётся в `simulate_current`.

## Пробный прогон

```bash
docker compose exec app php bin/app app:consume --limit=10 --dry-run
```

В dry-run worker читает queued-события из локального state-store, обращается в Bitrix24 и courier mock, но не пишет ничего обратно в Bitrix и не меняет локальные статусы событий.

## Формула RiskScore

```text
25
+ 15 * Missing(ContactPhone)
+ 10 * Missing(ContactEmail)
+ min(20, 20 * OverdueHours/24)
+ 12 * (SUM_PRODUCTS > 150000)
+ 8  * (DeliveryZone in ["Z3","Z7"])
+ 5  * (eta_days >= 7)
```

`OverdueHours = max(0, now - UF_SLA_DUE_AT)` в часах. Итог ограничивается диапазоном `0..100`.

## Что именно делает worker

При входе сделки в `DELIVERY` worker:

1. Читает сделку через `crm.deal.get`
2. Читает контакт через `crm.contact.get`
3. Читает товарные позиции через `crm.deal.productrows.get`
4. Считает `SUM_PRODUCTS`
5. Вызывает courier mock
6. Считает `RiskScore`
7. Пишет UF-поля через `crm.deal.update`
8. Добавляет запись в timeline через `crm.timeline.logmessage.add`
9. При `RiskScore >= 60` создаёт задачу через `tasks.task.add`
10. Опционально запускает БП через `bizproc.workflow.start`

## Подписка На Событие

Требование "подписка на изменение сделки и запуск обработки при переходе в выбранную стадию" закрыто отдельной командой:

```bash
docker compose exec app php bin/app app:subscribe-webhook --handler-url=https://example.com/webhook/secret
```

Команда:

- собирает handler URL из `APP_URL + /webhook/{APP_WEBHOOK_SECRET}` или берёт его из `--handler-url`
- требует публичный `https://...` URL, потому что Bitrix24 не примет обычный `http://`
- через app-auth Bitrix REST проверяет текущие подписки вызовом `event.get`
- если `ONCRMDEALUPDATE` уже привязан к этому handler URL, ничего не дублирует
- если подписки ещё нет, регистрирует её через `event.bind`

Важно: по официальной документации `event.get` и `event.bind` работают только в контексте авторизации приложения, а не обычного входящего вебхука. Поэтому для команды `app:subscribe-webhook` нужно задать `BITRIX_APP_REST_URL` и `BITRIX_APP_ACCESS_TOKEN`. Остальные CRM-вызовы worker'а по-прежнему могут идти через `BITRIX_WEBHOOK_URL`.

Дальше Bitrix24 шлёт `ONCRMDEALUPDATE` на этот endpoint. `IngestWebhookEvent` делает ровно три шага:

1. Берёт из webhook только `data.FIELDS.ID`
2. Вызывает `crm.deal.get` и отфильтровывает чужую `CATEGORY_ID`
3. Подтверждает именно переход в нужную стадию сравнением с локальным snapshot, а при пустом snapshot делает fallback в `crm.stagehistory.list`

OAuth-поля из `auth` не используются как источник бизнес-данных и не участвуют в дедуп-ключе события.

Для чтения связанного контакта worker сначала использует `crm.deal.contact.items.get`, потому что поле `CONTACT_ID` в `crm.deal.get` помечено в документации Bitrix24 как устаревшее. Если список привязок недоступен, сервис делает fallback на `CONTACT_ID`.

## Идемпотентность и надёжность

- Входящие события пишутся в локальную таблицу `incoming_events`, где `event_key` уникален.
- Повтор того же webhook не публикует второе сообщение.
- Стандартный `ONCRMDEALUPDATE` не даёт прошлую стадию и обычно присылает только `FIELDS[ID]`, поэтому локальный `deal_state` хранит последнюю известную `stage/category`, а при пустом snapshot сервис дополнительно смотрит `crm.stagehistory.list`.
- Если публикация в RabbitMQ не удалась, snapshot стадии не переключается в `DELIVERY`, поэтому повторная доставка того же перехода не теряется.
- При worker-ошибке событие получает `failed`, сообщение уходит в `nack/requeue`, а retry budget ограничен.
- Ошибки publish в RabbitMQ не расходуют worker retry budget.
- Для задач есть локальный `task_locks` store, поэтому повторы одного и того же события не создают вторую задачу.
- Для timeline в текст записи добавляется `event_key=...`, а перед повторной записью worker проверяет через `crm.timeline.logmessage.list`, нет ли уже такой записи.
- Для burst-нагрузки на SQLite поверх `busy_timeout` добавлен короткий retry на `database is locked`.
- Финальные шаги worker делаются поэтапно и помечаются в локальном state-store, чтобы повторная попытка не начинала всё с нуля.

## Trace-ID и Hash

- `trace-id` генерируется один раз на событие и сохраняется в локальном state-store, поэтому повторные ретраи используют тот же trace
- `trace-id` попадает в логи, timeline и заголовок `X-Trace-Id` для courier mock
- `diagnostic_payload_hash` — `sha1` от сделки, контакта, товарных позиций и raw ответа courier mock

## Лимиты Bitrix24

`BitrixRestClient` закрывает обязательную часть по надёжности:

- retry на `503`, `429`, `QUERY_LIMIT_EXCEEDED` и сетевые таймауты
- exponential backoff + jitter
- локальный rate-limit
- shared state file для координации лимита между несколькими процессами на одном хосте
- guard против nested `batch`

Batch в основном сценарии намеренно не используется: тут мало вызовов с побочными эффектами, а надёжность и читаемость важнее.

## Безопасность

- все секреты только в `.env`
- в README и коде нет захардкоженных webhook/token значений
- webhook проверяет `member_id`
- webhook использует секретный URL `/webhook/{APP_WEBHOOK_SECRET}`
- есть лимит на размер тела `APP_MAX_WEBHOOK_BYTES`
- невалидный JSON получает `422` с текстом `Невалидный JSON`
- в логах путь вебхука редактируется до `/webhook/{secret}` или `/webhook/{invalid}`

## Бизнес-процесс

Поддержка БП оставлена опциональной:

- если `BP_ENABLED=0`, ничего не запускается
- если `BP_ENABLED=1`, но `BITRIX_BP_TEMPLATE_ID=0`, worker пишет warning и корректно пропускает шаг
- если заданы и флаг, и template id, worker вызывает `bizproc.workflow.start`

## Тесты

```bash
docker compose exec app composer test
```

В наборе есть unit-тесты на:

- `RiskScoreCalculator`
- courier mock rules
- `CourierQuoteClient`
- `BitrixRestClient`
- `DeliveryStateStore`
- ingest и worker flow

## Пример webhook

Для локальной эмуляции без живого Bitrix24:

```bash
curl -X POST "http://localhost:8080/webhook/$(grep '^APP_WEBHOOK_SECRET=' .env | cut -d= -f2-)" \
  -H "Content-Type: application/json" \
  -d '{
    "simulate":true,
    "event":"ONCRMDEALUPDATE",
    "event_handler_id":"201",
    "data":{"FIELDS":{"ID":"123"}},
    "ts":"1736405807",
    "auth":{
      "access_token":"fake-access-token",
      "expires_in":"3600",
      "scope":"crm",
      "domain":"some-domain.bitrix24.com",
      "server_endpoint":"https://oauth.bitrix24.tech/rest/",
      "status":"F",
      "client_endpoint":"https://some-domain.bitrix24.com/rest/",
      "member_id":"your-member-id",
      "refresh_token":"fake-refresh-token",
      "application_token":"fake-application-token"
    },
    "simulate_current":{"stage":"DELIVERY","category":"0"}
  }'
```

## Ограничения этой версии

- сервис интегрируется с реальным Bitrix24, поэтому полноценный end-to-end прогон требует доступного портала и реальных сделок
- локально в `docker compose` поднимаются только приложение, worker, RabbitMQ и courier mock
- state-store остаётся локальным SQLite. Для multi-host продовой схемы его лучше заменить общим хранилищем
