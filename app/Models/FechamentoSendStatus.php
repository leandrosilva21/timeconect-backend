<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Status de envio do fechamento por (tipo, entidade, ano-mês).
 * Guarda o ÚLTIMO envio (quando + por quem). Ver migration para a regra de backfill.
 */
class FechamentoSendStatus extends Model
{
    protected $table = 'fechamento_send_status';

    public const TIPO_CONSULTOR = 'consultor';
    public const TIPO_CLIENTE   = 'cliente';
    public const TIPO_PARCEIRO  = 'parceiro';

    protected $fillable = ['tipo', 'entity_id', 'year_month', 'sent_at', 'sent_by'];

    protected $casts = ['sent_at' => 'datetime'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** Marca (ou atualiza) o último envio de uma entidade num período. */
    public static function marcarEnviado(string $tipo, int $entityId, string $yearMonth, int $senderId): void
    {
        static::updateOrCreate(
            ['tipo' => $tipo, 'entity_id' => $entityId, 'year_month' => $yearMonth],
            ['sent_at' => now(), 'sent_by' => $senderId],
        );
    }

    /** Limpa o status (volta a "não enviado"). Não toca em históricos externos (ex.: thread do consultor). */
    public static function limpar(string $tipo, int $entityId, string $yearMonth): void
    {
        static::where('tipo', $tipo)
            ->where('entity_id', $entityId)
            ->where('year_month', $yearMonth)
            ->delete();
    }

    /**
     * Mapa [entity_id => ['envio_em' => ISO|null, 'envio_por' => nome|null]] para
     * uma lista de entidades num período — usado para enriquecer as listagens.
     */
    public static function mapFor(string $tipo, ?string $yearMonth, array $entityIds): array
    {
        if (!$yearMonth || empty($entityIds)) {
            return [];
        }

        return static::with('sender:id,name')
            ->where('tipo', $tipo)
            ->where('year_month', $yearMonth)
            ->whereIn('entity_id', $entityIds)
            ->whereNotNull('sent_at')
            ->get()
            ->keyBy('entity_id')
            ->map(fn ($r) => [
                'envio_em'  => $r->sent_at?->toISOString(),
                'envio_por' => $r->sender?->name,
            ])
            ->toArray();
    }
}
