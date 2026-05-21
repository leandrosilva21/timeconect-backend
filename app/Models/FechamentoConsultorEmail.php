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

    public const STATUS_ENVIADO = 'enviado';
    public const STATUS_FALHOU  = 'falhou';

    protected $fillable = [
        'sender_user_id',
        'consultant_user_id',
        'year_month',
        'to_email',
        'cc_email',
        'subject',
        'total_value',
        'pdf_path',
        'xlsx_path',
        'status',
        'provider_response',
        'sent_at',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
        'sent_at'     => 'datetime',
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
