<?php

namespace As\OnecApi\Audit;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class AuditLogTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_as_onec_api_audit_log';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary(true)->configureAutocomplete(true),
            (new StringField('REQUEST_ID'))->configureRequired(true)->configureSize(32),
            (new DatetimeField('CREATED_AT'))->configureRequired(true),
            (new StringField('OPERATION'))->configureRequired(true)->configureSize(64),
            (new StringField('STATUS'))->configureRequired(true)->configureSize(16),
            new IntegerField('HTTP_CODE'),
            new IntegerField('TOTAL'),
            new IntegerField('UPDATED'),
            new IntegerField('FAILED'),
            new IntegerField('NO_CHANGE'),
            (new StringField('CLIENT_IP'))->configureSize(45),
            (new StringField('BODY_SHA256'))->configureSize(64),
            new IntegerField('BODY_BYTES'),
            new TextField('DETAILS'),
        ];
    }
}
