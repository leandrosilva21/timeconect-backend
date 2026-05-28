<?php

namespace App\Attachments\Backfill;

use App\Models\ProjectAttachment;

class ProjectAttachmentBackfill extends EntityAttachmentBackfillBase
{
    protected function entityType(): string { return 'PROJECT'; }
    protected function attachmentModel(): string { return ProjectAttachment::class; }
    protected function entityIdColumn(): string { return 'project_id'; }
}
