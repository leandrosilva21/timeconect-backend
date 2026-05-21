<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove as CHECK constraints de `type` em contract_attachments e project_attachments.
 *
 * Motivo: o app migrou para "campo único de anexo" e o BE passou a enviar
 * type='aprovacao_cliente' (validado em ContractController::uploadAttachment como
 * `in:proposta,contrato,logo,aprovacao_cliente`), mas a CHECK do banco só permitia
 * proposta/contrato/logo → INSERT rejeitado silenciosamente (arquivo gravado no disco,
 * linha rejeitada, anexo "sumia"). Caso real: contrato 164 / projeto AAM004-25-05 (prod).
 *
 * A validação de `type` fica como fonte única no BE (request->validate `in:`), evitando
 * o drift DB↔BE que causou a perda. Idempotente (IF EXISTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contract_attachments DROP CONSTRAINT IF EXISTS contract_attachments_type_check');
        DB::statement('ALTER TABLE project_attachments DROP CONSTRAINT IF EXISTS project_attachments_type_check');
    }

    public function down(): void
    {
        // No-op proposital: a CHECK estrita causava perda silenciosa de anexo.
        // A validação de type vive no BE; não reintroduzir a constraint no rollback.
    }
};
