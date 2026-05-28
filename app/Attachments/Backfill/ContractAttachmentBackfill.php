<?php

namespace App\Attachments\Backfill;

use App\Models\ContractAttachment;

class ContractAttachmentBackfill extends EntityAttachmentBackfillBase
{
    protected function entityType(): string { return 'CONTRACT'; }
    protected function attachmentModel(): string { return ContractAttachment::class; }
    protected function entityIdColumn(): string { return 'contract_id'; }
}
