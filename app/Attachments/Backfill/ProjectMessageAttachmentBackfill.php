<?php

namespace App\Attachments\Backfill;

use App\Models\ProjectMessage;
use App\Models\ProjectMessageAttachment;

class ProjectMessageAttachmentBackfill extends MessageAttachmentBackfillBase
{
    protected function entityType(): string { return 'PROJECT_MESSAGE'; }
    protected function attachmentModel(): string { return ProjectMessageAttachment::class; }
    protected function messageModel(): string { return ProjectMessage::class; }
    protected function authorColumn(): string { return 'user_id'; }
}
