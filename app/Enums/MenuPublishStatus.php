<?php

namespace App\Enums;

enum MenuPublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __("menus.publish_status.{$this->value}");
    }
}
