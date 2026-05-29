<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return number_format((float) $value, 2);
}

function number_fmt(mixed $value): string
{
    return number_format((float) $value, 2);
}

function route_url(string $path, array $params = []): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $url = ($base === '' || $base === '.') ? $path : $base . $path;
    return $params ? $url . '?' . http_build_query($params) : $url;
}
