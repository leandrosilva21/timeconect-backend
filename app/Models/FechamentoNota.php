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
        'nfse_status', 'nfse_reject_reason', 'nfse_decided_by', 'nfse_decided_at', 'nfse_valor',
        'nota_debito_status', 'nota_debito_reject_reason', 'nota_debito_decided_by', 'nota_debito_decided_at', 'nota_debito_valor',
        'upload_liberado', 'liberado_por', 'liberado_em',
    ];

    protected $casts = [
        'nfse_decided_at'        => 'datetime',
        'nota_debito_decided_at' => 'datetime',
        'nfse_valor'             => 'decimal:2',
        'nota_debito_valor'      => 'decimal:2',
        'upload_liberado'        => 'boolean',
        'liberado_em'            => 'datetime',
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

    /**
     * Bloco serializado de um documento (nfse|nota_debito) para o frontend.
     *
     * FASE 11.7 — has_file / original_name vêm do attachment vivo da categoria.
     * Status / decisão continuam aqui (não há equivalente na camada Attachment).
     */
    public function docPayload(string $tipo): array
    {
        $prefix = $tipo; // 'nfse' | 'nota_debito'
        $decidedBy = $tipo === 'nfse' ? $this->nfseDecidedBy : $this->notaDebitoDecidedBy;

        $att = Attachment::query()
            ->forEntity('FECHAMENTO_NOTA', $this->id)
            ->ofCategory($prefix)
            ->visible()
            ->latest('id')
            ->first();

        $valor = $this->{$prefix . '_valor'};

        return [
            'has_file'      => $att !== null,
            'original_name' => $att?->original_name,
            'status'        => $this->{$prefix . '_status'} ?? self::STATUS_PENDING,
            'reject_reason' => $this->{$prefix . '_reject_reason'},
            'decided_by'    => $decidedBy?->name,
            'decided_at'    => optional($this->{$prefix . '_decided_at'})->toISOString(),
            'valor'         => $valor !== null ? (float) $valor : null,
            'stale_reason'  => null, // preenchido por rowPayloadWithStale quando o valor difere do recebimento
        ];
    }

    /** Bloco completo (nfse + nota_debito) para a linha do fechamento. */
    public function toRowPayload(): array
    {
        return [
            'nfse'            => $this->docPayload('nfse'),
            'nota_debito'     => $this->docPayload('nota_debito'),
            'upload_liberado' => (bool) $this->upload_liberado,
            'liberado_por'    => $this->liberado_por,
            'liberado_em'     => optional($this->liberado_em)->toISOString(),
        ];
    }

    /**
     * Payload das notas marcando (apenas como AVISO, sem travar nada) o documento cujo valor
     * declarado ficou diferente do recebimento atual — ex.: serviço/despesa/ajuste mudou depois
     * do envio. Não altera arquivo, valor nem status; só preenche `stale_reason`.
     */
    public function rowPayloadWithStale(?float $expected): array
    {
        $payload = $this->toRowPayload();
        if ($expected !== null) {
            foreach (self::TIPOS as $tipo) {
                $valor = $this->{$tipo . '_valor'};
                if ($valor !== null && abs((float) $valor - $expected) > 0.01) {
                    $payload[$tipo]['stale_reason'] = 'Recebimento do fechamento alterado para R$ '
                        . number_format($expected, 2, ',', '.') . ' — o valor declarado (R$ '
                        . number_format((float) $valor, 2, ',', '.') . ') está diferente. Entre em contato com o financeiro.';
                }
            }
        }
        return $payload;
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
            'valor'         => null,
            'stale_reason'  => null,
        ];

        return [
            'nfse'            => $empty,
            'nota_debito'     => $empty,
            'upload_liberado' => false,
            'liberado_por'    => null,
            'liberado_em'     => null,
        ];
    }
}
