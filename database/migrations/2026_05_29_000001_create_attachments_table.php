<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 11.1 — Camada global de anexos do Time Conect.
 *
 * Tabela polimórfica única: entity_type + entity_id apontam pra qualquer entidade
 * registrada no AttachableEntitiesRegistry. SEM foreign key real em entity_id (custo
 * do polimórfico); a integridade é validada por job diário (attachments:integrity-check)
 * e pelo registry no momento do upload.
 *
 * Princípios de modelagem:
 *  - Nunca consultar attachments via where('project_id', ...). Sempre via scope
 *    forEntity('PROJECT', $id) — abstração oficial.
 *  - storage_path é opaco (semantics decididas pelo StorageProvider). AttachmentService
 *    nunca toca Storage:: direto; só passa pelo provider.
 *  - metadata jsonb fica vazio hoje; expansível pra OCR/thumbnails/AI/assinatura/tags
 *    sem ALTER TABLE futuro.
 *
 * Storage físico legado (decisão FASE 11): arquivos antigos ficam onde estão; o
 * backfill (FASE 11.3) só grava o storage_path absoluto. Novos uploads usam estrutura
 * unificada: attachments/{entity_type_lower}/{entity_id}/{uuid}_{checksum8}.{ext}.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // Multi-tenant ready: nullable até esse domínio existir; preenchido futuramente
            // por trigger ou no upload. Já indexado pra não precisar de migration depois.
            $table->unsignedBigInteger('company_id')->nullable();

            // Entity polimórfica — validada pelo EntityRegistry, não por FK.
            // CHECK constraint adicionada via raw porque a lista é fonte única do registry
            // (PHP), e o constraint serve só como defesa em profundidade contra inserts diretos.
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');

            // Categoria semântica (proposal, evidence, expense_receipt, etc).
            // Cada entity_type define suas categorias permitidas no registry.
            $table->string('category', 64);

            // Identidade do arquivo.
            $table->string('original_name', 512);   // nome que o usuário vê / fez upload
            $table->string('file_name', 512);       // sanitizado (sem espaço/acento/path-traversal)
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');

            // SHA-256 hex — dedup, integridade, restore futuro.
            $table->string('checksum', 64);

            // Storage abstrato — provider é plugável (LocalStorageProvider hoje; S3/R2/Supabase
            // sem refator estrutural). storage_path é opaco: só o provider sabe interpretar.
            $table->string('storage_provider', 32)->default('local');
            $table->string('storage_path', 1024);

            // Permissionamento centralizado — resolvido pelo registry + visibility.
            //   internal   = ERPSERV (admin/coord/consultor/parceiro)
            //   customer   = cliente da entidade (via customer_id da entidade-dona)
            //   restricted = só uploader + admin (homologações, docs sensíveis)
            $table->string('visibility', 16)->default('internal');

            // Único FK real — usuário existe sempre.
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            // Extensão sem ALTER TABLE: OCR, thumbnails, AI, signature, tags…
            // json() (portável) em vez de jsonb — no Postgres é mapeado pra jsonb por configuração
            // de schema/driver; pra SQLite (testes) usa TEXT com cast 'array'.
            $table->json('metadata')->default('{}');

            $table->timestamps();
            $table->softDeletes(); // documento NUNCA é deletado físico — só soft delete.

            // ── Índices: governam acesso por (entity_type, entity_id) primeiro, depois
            // filtros secundários. Parciais com `WHERE deleted_at IS NULL` reduzem o
            // working set pra queries quentes (lista pra UI).
            $table->index(['entity_type', 'entity_id'], 'idx_attachments_entity');
            $table->index('company_id', 'idx_attachments_company');
            $table->index(['entity_type', 'category'], 'idx_attachments_entity_category');
            $table->index('uploaded_by', 'idx_attachments_uploaded_by');
            $table->index('created_at', 'idx_attachments_created_at');
            $table->index('checksum', 'idx_attachments_checksum');
        });

        // Recursos Postgres-only (índices parciais, CHECK constraints, COMMENT): só roda no
        // driver pgsql. Testes em SQLite continuam funcionais; em prod o CHECK é defesa em
        // profundidade contra inserts diretos.
        if (\DB::getDriverName() === 'pgsql') {
            // Índices parciais — caminho quente da UI (não-deletados).
            \DB::statement('CREATE INDEX idx_attachments_entity_live ON attachments(entity_type, entity_id) WHERE deleted_at IS NULL');
            \DB::statement('CREATE INDEX idx_attachments_category_live ON attachments(entity_type, category) WHERE deleted_at IS NULL');

            \DB::statement("ALTER TABLE attachments ADD CONSTRAINT chk_attachments_visibility CHECK (visibility IN ('internal','customer','restricted'))");
            \DB::statement("ALTER TABLE attachments ADD CONSTRAINT chk_attachments_storage_provider CHECK (storage_provider IN ('local','s3','r2','supabase'))");

            // Documentação inline no DB (psql \d+ mostra).
            \DB::statement("COMMENT ON TABLE attachments IS 'FASE 11: camada global polimórfica de anexos. entity_type validado pelo AttachableEntitiesRegistry.'");
            \DB::statement("COMMENT ON COLUMN attachments.entity_type IS 'Chave do EntityRegistry (PROJECT, EXPENSE, ...). Nunca string livre.'");
            \DB::statement("COMMENT ON COLUMN attachments.storage_path IS 'Opaco pro provider. Apenas StorageProvider sabe interpretar.'");
            \DB::statement("COMMENT ON COLUMN attachments.metadata IS 'Extensível: ocr, thumbnail, ai, signature, tags. AttachmentService nunca consulta o conteúdo.'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
