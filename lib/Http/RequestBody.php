<?php

namespace As\OnecApi\Http;

/**
 * Единое чтение тела HTTP-запроса.
 *
 * Сервисы импорта и аудит получают одну и ту же неизменяемую строку, поэтому
 * аудит не зависит от того, допускает ли конкретный SAPI повторное чтение php://input.
 */
final class RequestBody
{
    private static ?string $raw = null;
    private static bool $read = false;

    public static function get(): ?string
    {
        if (!self::$read) {
            $raw = file_get_contents('php://input');
            self::$raw = $raw === false ? null : $raw;
            self::$read = true;
        }

        return self::$raw;
    }
}
