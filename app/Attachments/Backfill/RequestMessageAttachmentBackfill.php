<?php

namespace App\Attachments\Backfill;

use App\Models\ContractRequestMessage;
use App\Models\ContractRequestMessageAttachment;

class RequestMessageAttachmentBackfill extends MessageAttachmentBackfillBase
{
    protected function entityType(): string { return 'REQUEST_MESSAGE'; }
    protected function attachmentModel(): string { return ContractRequestMessageAttachment::class; }
    protected function messageModel(): string { return ContractRequestMessage::class; }
    protected function authorColumn(): string { return 'user_id'; }
}
