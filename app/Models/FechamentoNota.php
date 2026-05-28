<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notas fiscais do fechamento (NFS-e + Nota de débito) de uma entidade PJ
 * (consultor User ou Partner) em um mês. Cada documento tem seu próprio
 * status (pending/accepted/rejected) + motivo de recusa + auditoria de decisão.
 */
class FechamentoNota extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    /** Tipos de documento aceitos. */
    public const TIPOS = ['nfse', 'nota_debito'];

    protected $fillable = [
        'notable_type', 'notable_id', 'year_month',
        'nfse_path', 'nfse_original_name', 'nfse_status', 'nfse_reject_reason', 'nfse_decided_by', 'nfse_decided_at',
        'nota_debito_path', 'nota_debito_original_name', 'nota_debito_status', 'nota_debito_reject_reason', 'nota_debito_decided_by', 'nota_debito_decided_at',
    ];

    protected $casts = [
        'nfse_decided_at'        => 'datetime',
        'nota_debito_decided_at' => 'datetime',
    ];

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    public function nfseDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nfse_decided_by');
    }

    public function notaDebitoDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nota_debito_decided_by');
    }

    /** Bloco serializado de um documento (nfse|nota_debito) para o frontend. */
    public function docPayload(string $tipo): array
    {
        $prefix = $tipo; // 'nfse' | 'nota_debito'
        $decidedBy = $tipo === 'nfse' ? $this->nfseDecidedBy : $this->notaDebitoDecidedBy;

        return [
            'has_file'      => !empty($this->{$prefix . '_path'}),
            'original_name' => $this->{$prefix . '_original_name'},
            'status'        => $this->{$prefix . '_status'} ?? self::STATUS_PENDING,
            'reject_reason' => $this->{$prefix . '_reject_reason'},
            'decided_by'    => $decidedBy?->name,
            'decided_at'    => optional($this->{$prefix . '_decided_at'})->toISOString(),
        ];
    }

    /** Bloco completo (nfse + nota_debito) para a linha do fechamento. */
    public function toRowPayload(): array
    {
        return [
            'nfse'        => $this->docPayload('nfse'),
            'nota_debito' => $this->docPayload('nota_debito'),
        ];
    }

    /** Estrutura vazia (entidade PJ sem nenhuma nota lançada ainda). */
    public static function emptyRowPayload(): array
    {
        $empty = [
            'has_file'      => false,
            'original_name' => null,
            'status'        => self::STATUS_PENDING,
            'reject_reason' => null,
            'decided_by'    => null,
            'decided_at'    => null,
        ];

        return ['nfse' => $empty, 'nota_debito' => $empty];
    }
}
