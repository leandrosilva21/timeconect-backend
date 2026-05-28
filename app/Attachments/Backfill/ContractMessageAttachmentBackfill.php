<?php

namespace App\Attachments\Backfill;

use App\Models\ContractMessage;
use App\Models\ContractMessageAttachment;

class ContractMessageAttachmentBackfill extends MessageAttachmentBackfillBase
{
    protected function entityType(): string { return 'CONTRACT_MESSAGE'; }
    protected function attachmentModel(): string { return ContractMessageAttachment::class; }
    protected function messageModel(): string { return ContractMessage::class; }
    protected function authorColumn(): string { return 'user_id'; }
}
