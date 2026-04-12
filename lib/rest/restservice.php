<?php

namespace As\Onecstock\Rest;

/**
 * Единая точка регистрации REST: обработчик события OnRestServiceBuildDescription.
 *
 * Для приёма остатков извне используйте вебхук с методом {@see StockImportService::METHOD} (см. PHPDoc
 * {@see StockImportService}) — это основной публичный API модуля для интеграций.
 *
 * Новые методы добавляются отдельными классами с методом {@see getRestDescription()} и подключаются
 * в {@see onRestServiceBuildDescription()} без дополнительных registerEventHandler в install.
 *
 * Scope вебхука: см. константы в доменных классах (например {@see StockImportService::SCOPE}).
 */
final class RestService
{
    /**
     * @return array<string, array<string, array{callback: array{0:class-string, 1:string}}>>
     */
    public static function onRestServiceBuildDescription(): array
    {
        $merged = [];
        foreach (self::descriptionProviders() as $class) {
            $part = $class::getRestDescription();
            foreach ($part as $scope => $methods) {
                if (!isset($merged[$scope])) {
                    $merged[$scope] = [];
                }
                $merged[$scope] = array_merge($merged[$scope], $methods);
            }
        }

        return $merged;
    }

    /**
     * @return list<class-string>
     */
    private static function descriptionProviders(): array
    {
        return [
            StockImportService::class,
        ];
    }
}
