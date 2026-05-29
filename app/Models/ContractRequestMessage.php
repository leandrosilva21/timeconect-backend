<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractRequestMessage extends Model
{
    protected $fillable = ['contract_request_id', 'user_id', 'message'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ContractRequest::class, 'contract_request_id');
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
            ->where('attachments.entity_type', 'REQUEST_MESSAGE')
            ->whereNull('attachments.deleted_at');
    }
}
