# Заметка по потоку DELIVERY

## Контекст

Нужно автоматизировать переход сделки в `DELIVERY` через Bitrix24 REST. Обязательные части сценария:

- входящий webhook о смене стадии
- очередь между ingest и worker
- чтение сделки, контакта и товарных позиций из Bitrix24
- вызов внешнего courier mock
- расчёт `RiskScore`
- запись результата обратно в сделку
- запись в timeline
- дедуп задач
- retry, rate-limit и idempotency

Ограничение по времени небольшое, поэтому решение намеренно собрано как простой сервис, а не как отдельная платформа. Реального портала нет (хотя его можно подключить, но на поднятие и тестирование было недостаточно времени, так что в интеграции с реальным могут быть ошибки), поэтому в эмуляции и тестах используется фейковый клиент (постарался приблизить к реальному Bitrix24).

## Решение

1. CRM-источником правды является только Bitrix24 REST.
2. Локально хранится только маленький SQLite state-store:
   - `incoming_events`
   - `deal_state`
   - `task_locks`
3. Входящий webhook валидирует секретный URL, `member_id`, размер тела и JSON.
4. Подписка на `ONCRMDEALUPDATE` регистрируется отдельной CLI-командой через Bitrix REST `event.get` + `event.bind`.
   Для этого используется отдельный app-auth клиент, потому что методы событий у Bitrix24 работают в контексте авторизации приложения.
5. `IngestWebhookEvent` использует стандартный payload `ONCRMDEALUPDATE`, где в webhook обычно есть только `data.FIELDS.ID`, а `crm.deal.get` выступает источником текущих `STAGE_ID/CATEGORY_ID`:
   - берёт `deal_id` из `ONCRMDEALUPDATE`
   - читает текущее состояние сделки через `crm.deal.get`
   - сравнивает текущую stage/category с локальным snapshot стадии
   - если snapshot пустой, пытается подтвердить вход в стадию через `crm.stagehistory.list`
   - пропускает события вне нужной стадии/категории
   - режет повторы по `event_key`
   - ставит событие в RabbitMQ только при реальном входе в target stage/category
6. Worker читает сообщение, забирает сделку, товарные позиции и связанный контакт напрямую из Bitrix24.
   Для контакта сначала вызывается `crm.deal.contact.items.get`, а `CONTACT_ID` из `crm.deal.get` остаётся только fallback-вариантом.
7. ETA считается внешним HTTP-вызовом в courier mock, а `RiskScore` считается локально.
8. Результат пишется обратно в Bitrix24 через `crm.deal.update`, затем создаётся timeline-запись, затем при необходимости задача и опциональный запуск БП.
9. Чтобы повторный retry не создавал дублей, worker помечает завершённые шаги в локальном state-store:
   - `deal_saved_at`
   - `timeline_saved_at`
   - `bp_started_at`
10. Для задач используется локальный `task_locks`, а для timeline в текст комментария добавляется `event_key=...`.
11. Для Bitrix REST используется один прямой клиент с rate-limit, retry на `503/429/QUERY_LIMIT_EXCEEDED`, backoff+jitter и guard против nested batch.

## Почему решение именно такое

- Это максимально близко к реальному сценарию Bitrix24, но без перегруза лишними слоями.
- Локальный SQLite остаётся только там, где он действительно полезен: идемпотентность, retry state и дедуп задач.
- Удалён локальный mock CRM, потому что он уже не нужен и только раздувает код.
- Между webhook и worker остаётся RabbitMQ, чтобы показать честную асинхронную обработку и выдержать повторные доставки.
- Код держится на нескольких понятных классах, а не на наборе мелких repository/factory/mapper-обёрток.

## Поток worker

1. Взять событие из очереди.
2. Поднять или переиспользовать `trace-id`.
3. Прочитать из Bitrix24:
   - `crm.deal.get`
   - `crm.deal.productrows.get`
   - `crm.contact.get`
4. Посчитать `SUM_PRODUCTS`.
5. Вызвать courier mock и получить `eta_days`.
6. Посчитать `RiskScore`.
7. Сформировать `diagnostic_payload_hash`.
8. Обновить UF-поля сделки.
9. Добавить timeline-запись с breakdown, `trace-id` и `event_key`.
10. При `RiskScore >= 60` создать задачу без дубля.
11. Если включён БП и задан template id, вызвать `bizproc.workflow.start`.
12. Пометить событие как `processed`.

## Идемпотентность

- Повтор того же webhook режется по `event_key`.
- Для одной сделки одновременно допускается только один активный queued-переход в `DELIVERY`.
- Если сделка вышла из `DELIVERY`, локальный `deal_state` обновляется, и следующий реальный вход в `DELIVERY` снова допускается.
- Повторная доставка сообщения из RabbitMQ безопасна, потому что worker смотрит локальные step markers.
- Задачи дедуплицируются по ключу `risk-delivery:{dealId}:{eventKey}`.
- Timeline дополнительно защищён поиском `event_key=...` в существующих log messages.

## Лимиты и повторные попытки

- Bitrix REST:
  - rate-limit по запросам в секунду
  - retry на `503`, `429`, `QUERY_LIMIT_EXCEEDED` и сетевые ошибки
  - exponential backoff + jitter
  - shared state file для нескольких процессов на одном хосте
  - nested batch запрещён
- Courier mock:
  - retry на временных HTTP-ошибках
- RabbitMQ:
  - worker делает requeue до исчерпания retry budget
- SQLite:
  - write-path обёрнут в короткий retry на `database is locked`

## Безопасность

- Все секреты только в `.env`.
- Webhook проверяет `member_id`.
- Webhook использует секретный путь `/webhook/{APP_WEBHOOK_SECRET}`.
- Невалидный JSON и слишком большие payload'ы режутся на HTTP-слое.
- В логах не печатается реальный секретный segment webhook URL.
- В README нет реальных токенов и webhook URL.

## Бизнес-процесс

- Если `BP_ENABLED=0`, шаг БП полностью выключен.
- Если `BP_ENABLED=1`, но `BITRIX_BP_TEMPLATE_ID=0`, worker пишет warning и идёт дальше.
- Если заданы и флаг, и template id, вызывается `bizproc.workflow.start`.

В рамках тестового задания этого достаточно: БП либо реально доступен в портале, либо корректно отключён без падения основного потока.
