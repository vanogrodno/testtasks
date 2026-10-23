<?php

declare(strict_types=1);

const WARNING_AGE_SECONDS = 300;
const CRITICAL_AGE_SECONDS = 600;
const CRITICAL_ATTEMPTS = 5;

$path = $argv[1] ?? null;
if ($path === null || !is_readable($path)) {
    fwrite(STDERR, "Использование: php monitor.php <queue-snapshot.json>\n");
    exit(64);
}

try {
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Не удалось прочитать файл {$path}");
    }
    $snapshot = json_decode(
        $contents,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($snapshot) || !isset($snapshot['jobs']) || !is_array($snapshot['jobs'])) {
        throw new UnexpectedValueException('Ожидался объект с массивом jobs');
    }
    if (!isset($snapshot['generated_at']) || !is_string($snapshot['generated_at'])) {
        throw new UnexpectedValueException('Отсутствует generated_at');
    }
    $now = new DateTimeImmutable($snapshot['generated_at']);
    $lastSuccess = isset($snapshot['last_success_at'])
        ? new DateTimeImmutable((string) $snapshot['last_success_at'])
        : null;

    $jobs = [];
    foreach ($snapshot['jobs'] as $index => $job) {
        if (!is_array($job)) {
            throw new UnexpectedValueException("jobs[{$index}] должен быть объектом");
        }
        if (($job['status'] ?? null) !== 'pending') {
            continue;
        }
        if (!isset($job['created_at'])) {
            throw new UnexpectedValueException("У jobs[{$index}] отсутствует created_at");
        }
        $attempts = $job['attempts'] ?? 0;
        if (!is_int($attempts) || $attempts < 0) {
            throw new UnexpectedValueException("У jobs[{$index}] некорректное attempts");
        }
        $job['_created_at'] = new DateTimeImmutable((string) $job['created_at']);
        $job['attempts'] = $attempts;
        $jobs[] = $job;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Некорректный снимок: {$e->getMessage()}\n");
    exit(65);
}

$system = (string) ($snapshot['system'] ?? 'Неизвестный обмен');

if ($jobs === []) {
    echo "OK · {$system} · очередь пуста\n";
    exit(0);
}

usort($jobs, static function (array $left, array $right): int {
    return $left['_created_at']->getTimestamp() <=> $right['_created_at']->getTimestamp();
});

$oldest = $jobs[0];
$ageSeconds = max(0, $now->getTimestamp() - $oldest['_created_at']->getTimestamp());
$maxAttemptsJob = $oldest;
foreach ($jobs as $job) {
    if ($job['attempts'] > $maxAttemptsJob['attempts']) {
        $maxAttemptsJob = $job;
    }
}
$criticalByAttempts = $maxAttemptsJob['attempts'] >= CRITICAL_ATTEMPTS;

$severity = 'OK';
$exitCode = 0;
if ($ageSeconds >= CRITICAL_AGE_SECONDS || $criticalByAttempts) {
    $severity = 'CRITICAL';
    $exitCode = 2;
} elseif ($ageSeconds >= WARNING_AGE_SECONDS) {
    $severity = 'WARNING';
    $exitCode = 1;
}

$ageMinutes = intdiv($ageSeconds, 60);
$lastSuccessMinutes = $lastSuccess === null
    ? null
    : intdiv(max(0, $now->getTimestamp() - $lastSuccess->getTimestamp()), 60);
$problemJob = $criticalByAttempts ? $maxAttemptsJob : $oldest;
$id = (string) ($problemJob['id'] ?? '?');
$attempts = $problemJob['attempts'];
$error = trim((string) ($problemJob['last_error'] ?? 'ошибка не указана'));
$lastSuccessText = $lastSuccessMinutes === null
    ? 'last success unknown'
    : "last success {$lastSuccessMinutes} min ago";

printf(
    "%s · %s · %d pending · oldest %d min · %s · job #%s · attempts %d · %s\n",
    $severity,
    $system,
    count($jobs),
    $ageMinutes,
    $lastSuccessText,
    $id,
    $attempts,
    $error
);

exit($exitCode);
