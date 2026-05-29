<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractMessage extends Model
{
    protected $fillable = ['contract_id', 'user_id', 'message', 'visibility'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Anexos da mensagem — FASE 11.7 (PR 7b): polimórficos via tabela `attachments`.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'entity_id')
            ->where('attachments.entity_type', 'CONTRACT_MESSAGE')
            ->whereNull('attachments.deleted_at');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ContractMessageRead::class, 'message_id');
    }
}
