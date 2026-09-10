<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentAuditAction: string implements HasLabel
{
    case Uploaded = 'uploaded';

    case VersionAdded = 'version_added';

    case Attached = 'attached';

    case Detached = 'detached';

    case Viewed = 'viewed';

    case Downloaded = 'downloaded';

    case Archived = 'archived';

    case Restored = 'restored';

    case AccessDenied = 'access_denied';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Uploaded      => __('accounting::enums/document-audit-action.uploaded'),
            self::VersionAdded  => __('accounting::enums/document-audit-action.version-added'),
            self::Attached      => __('accounting::enums/document-audit-action.attached'),
            self::Detached      => __('accounting::enums/document-audit-action.detached'),
            self::Viewed        => __('accounting::enums/document-audit-action.viewed'),
            self::Downloaded    => __('accounting::enums/document-audit-action.downloaded'),
            self::Archived      => __('accounting::enums/document-audit-action.archived'),
            self::Restored      => __('accounting::enums/document-audit-action.restored'),
            self::AccessDenied  => __('accounting::enums/document-audit-action.access-denied'),
        };
    }
}
