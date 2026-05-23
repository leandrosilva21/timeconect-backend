<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de cada tentativa de envio do fechamento de consultor por e-mail.
 */
class FechamentoConsultorEmail extends Model
{
    use HasFactory;

    public const STATUS_ENVIADO  = 'enviado';
    public const STATUS_FALHOU   = 'falhou';
    public const STATUS_RECEBIDO = 'recebido'; // resposta do consultor (inbound)

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND  = 'inbound';

    protected $fillable = [
        'sender_user_id',
        'consultant_user_id',
        'year_month',
        'direction',
        'to_email',
        'cc_email',
        'from_email',
        'subject',
        'message_id',
        'graph_message_id',
        'in_reply_to',
        'body',
        'is_continuation',
        'total_value',
        'pdf_path',
        'xlsx_path',
        'status',
        'provider_response',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'total_value'     => 'decimal:2',
        'is_continuation' => 'boolean',
        'sent_at'         => 'datetime',
        'received_at'     => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }
}
