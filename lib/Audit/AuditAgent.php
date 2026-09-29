<?php

namespace As\OnecApi\Audit;

use As\OnecApi\Installer;
use Bitrix\Main\Config\Option;

final class AuditAgent
{
    private const INTERVAL = 86400;
    private const NAME = '\\As\\OnecApi\\Audit\\AuditAgent::run();';

    public static function register(): void
    {
        if (!class_exists('CAgent') || \CAgent::GetList([], ['NAME' => self::NAME, 'MODULE_ID' => Installer::MODULE_ID])->Fetch()) {
            return;
        }
        \CAgent::AddAgent(self::NAME, Installer::MODULE_ID, 'N', self::INTERVAL);
    }

    public static function unregister(): void
    {
        if (class_exists('CAgent')) {
            \CAgent::RemoveAgent(self::NAME, Installer::MODULE_ID);
        }
    }

    public static function run(): string
    {
        $days = max(1, (int) Option::get(Installer::MODULE_ID, 'audit_retention_days', '30'));
        $maxRecords = max(1000, (int) Option::get(Installer::MODULE_ID, 'audit_max_records', '100000'));
        AuditService::cleanup($days, $maxRecords);

        return self::NAME;
    }
}
