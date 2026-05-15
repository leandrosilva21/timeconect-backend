<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id', 'created_by_id',
        'area_requisitante', 'project_name', 'product_owner', 'modulo_tecnologia',
        'tipo_necessidade', 'tipo_necessidade_outro', 'nivel_urgencia',
        'descricao', 'cenario_atual', 'cenario_desejado',
        'status', 'kanban_column', 'reviewed_by_id', 'reviewed_at', 'notas_revisao', 'contract_id',
        'linked_contract_id', 'linked_coordinator_id', 'linked_project_id', 'req_decision',
        'req_decided_at',
    ];

    protected $casts = [
        'reviewed_at'    => 'datetime',
        'req_decided_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'deleted_at'  => 'datetime',
    ];

    const STATUS_PENDENTE   = 'pendente';
    const STATUS_EM_ANALISE = 'em_analise';
    const STATUS_APROVADO   = 'aprovado';
    const STATUS_CONVERTIDO = 'convertido';
    const STATUS_RECUSADO   = 'recusado';

    const TIPOS = [
        'implantacao_modulo'        => 'Implantação de Módulo',
        'treinamento_erp'           => 'Treinamento ERP',
        'atualizacao_versao_erp'    => 'Atualização de Versão do ERP',
        'entrega_obrigacao'         => 'Entrega de Obrigação',
        'fluig'                     => 'Fluig',
        'desenvolvimento_web_app'   => 'Desenvolvimento Web/App',
        'customizacao_erp_protheus' => 'Customização ERP Protheus',
        'integracao_erp_protheus'   => 'Integração com ERP Protheus',
        'outro'                     => 'Outro',
    ];

    const URGENCIAS = [
        'quando_possivel' => 'Quando for possível',
        'baixo'           => 'Baixo',
        'medio'           => 'Médio',
        'alto'            => 'Alto',
        'altissimo'       => 'Altíssimo',
    ];

    const URGENCIA_PRIORITY = [
        'quando_possivel' => 0,
        'baixo'           => 1,
        'medio'           => 2,
        'alto'            => 3,
        'altissimo'       => 4,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function contract(): BelongsTo         { return $this->belongsTo(Contract::class); }
    public function linkedContract(): BelongsTo   { return $this->belongsTo(Contract::class, 'linked_contract_id'); }
    public function linkedCoordinator(): BelongsTo{ return $this->belongsTo(User::class, 'linked_coordinator_id'); }
    public function linkedProject(): BelongsTo    { return $this->belongsTo(Project::class, 'linked_project_id'); }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContractRequestMessage::class);
    }
}
