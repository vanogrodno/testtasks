# Тестовое задание

В репозитории представлены решения задач:

- `02-wms-fbs-marking/` — анализ FBS-сборки, маркировки и инвентаризации;
- `04-support-incidents/` — разбор четырёх обращений сотрудников по логам;
- `06-open-improvement/` — идея и прототип мониторинга остановки обмена с 1С.

Отчёт об использовании ИИ, затраченном времени и продакшен-доработках находится в `REPORT.md`.

## Требования

- PHP 8.1 или новее для задания 02;
- PHP 7.4 или новее для вспомогательных скриптов заданий 04 и 06.

## Запуск

Все команды выполняются из корня репозитория.

### Задание 02

```bash
php 02-wms-fbs-marking/analyze.php
```

Результаты будут записаны в `02-wms-fbs-marking/output/`.

### Задание 04

Основной результат находится в `04-support-incidents/04-incidents.md`. Вспомогательный анализатор access-лога запускается с явным путём к исходному файлу:

```bash
php 04-support-incidents/analyze-inc2.php path/to/access.log
```

### Задание 06

```bash
php 06-open-improvement/06-prototype/monitor.php 06-open-improvement/06-prototype/queue-stuck.json
php 06-open-improvement/06-prototype/monitor.php 06-open-improvement/06-prototype/queue-warning.json
php 06-open-improvement/06-prototype/monitor.php 06-open-improvement/06-prototype/queue-healthy.json
```

Ожидаемые exit code: `2` для critical, `1` для warning и `0` для исправного снимка.
