<?php

namespace App\Attachments;

use App\Attachments\Exceptions\EntityNotFoundException;
use App\Attachments\Exceptions\InvalidCategoryException;
use App\Attachments\Exceptions\InvalidMimeTypeException;
use App\Attachments\Exceptions\UnknownEntityTypeException;
use App\Models\Contract;
use App\Models\ContractMessage;
use App\Models\ContractRequestMessage;
use App\Models\Expense;
use App\Models\FechamentoNota;
use App\Models\HourContribution;
use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\StageActivityEvent;
use App\Models\Timesheet;
use App\Models\User;
use Closure;

/**
 * FASE 11 — Fonte ÚNICA de verdade das entidades anexáveis do Minutor.
 *
 * Princípios:
 *  - entity_type é uma chave string controlada (PROJECT, EXPENSE, ...) — NUNCA livre.
 *  - O AttachmentService NUNCA importa Project/Expense/etc — só consulta o registry.
 *  - Adicionar nova entidade anexável = adicionar UMA entrada aqui; nada mais quebra.
 *  - Validações (mime, size, category, permission) vivem aqui — não espalhadas.
 *
 * Como pensar em "permission_check":
 *  - Recebe (User $user, ?Model $entity, string $action) e retorna bool.
 *  - $action ∈ {view, upload, delete, restore}.
 *  - Polices de negócio das entidades-donas ficam no controller delas — aqui só
 *    delegamos. Quando o registry não tem regra específica, fallback é admin-only.
 *
 * Lista de allowed_mime curada: deliberadamente restrita (nada de .exe, .sh, .js).
 * Extensões aceitas espelham o que cada módulo realmente recebe hoje (com folga).
 */
class AttachableEntitiesRegistry
{
    /** Subset comum: PDFs, planilhas, imagens (uso transversal). */
    private const MIME_DOCS = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/csv',
        'text/plain',
    ];
    private const MIME_IMAGES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];
    private const MIME_DOCS_AND_IMAGES = [
        ...self::MIME_DOCS,
        ...self::MIME_IMAGES,
    ];

    /**
     * Definição central. NÃO alterar em runtime — é constante (readonly por convenção).
     *
     * Schema de cada entrada:
     *   'model'              => class-string<Model>
     *   'categories'         => string[] — categorias permitidas pra esse entity_type
     *   'default_visibility' => 'internal' | 'customer' | 'restricted'
     *   'permission_check'   => Closure(User, ?Model, string $action): bool   (lazy via getEntities)
     *   'allowed_mime'       => string[]
     *   'max_size_mb'        => int
     *   'allowed_extensions' => string[]   (defesa em profundidade contra MIME spoof)
     */
    private static ?array $entities = null;

    /**
     * Construção lazy — fechamentos com `use ($adminOnly)` etc. Mantida em memória
     * após primeira chamada (singleton de fato dentro do request).
     */
    private static function getEntities(): array
    {
        if (self::$entities !== null) {
            return self::$entities;
        }

        // Closures de autorização reutilizadas. Convenção: $entity pode ser null no
        // momento de "upload" quando ainda não temos o objeto (validamos por type+id
        // antes via `validate()`).
        $adminOnly = fn (User $user) => $user->isAdmin();
        $adminOrAdministrativo = fn (User $user) => $user->isAdmin() || $user->isAdministrativo();
        $internalStaff = fn (User $user) => $user->isAdmin() || $user->isAdministrativo() || $user->isCoordenador() || $user->isConsultor();
        $internalOrPartner = fn (User $user) => $user->isAdmin() || $user->isAdministrativo() || $user->isCoordenador() || $user->isConsultor() || ($user->type === 'parceiro_admin');
        // Cliente só vê anexos da sua própria customer-entity.
        $isClienteOfCustomer = function (User $user, $entity, ?int $customerId) {
            return $user->type === 'cliente'
                && $user->customer_id !== null
                && (int) $user->customer_id === (int) $customerId;
        };

        self::$entities = [
            // ── PROJECT ───────────────────────────────────────────────────────
            'PROJECT' => [
                'model' => Project::class,
                'categories' => ['proposal', 'contract', 'evidence', 'attachment', 'image', 'logo'],
                'default_visibility' => 'internal',
                'permission_check' => function (User $user, $entity, string $action) use ($internalStaff, $isClienteOfCustomer) {
                    if ($internalStaff($user)) return true;
                    if ($action === 'view' && $entity !== null) {
                        return $isClienteOfCustomer($user, $entity, $entity->customer_id ?? null);
                    }
                    return false;
                },
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','docx','doc','xlsx','xls','csv','txt','png','jpg','jpeg','webp','gif'],
                'max_size_mb' => 50,
            ],

            // ── EXPENSE ───────────────────────────────────────────────────────
            'EXPENSE' => [
                'model' => Expense::class,
                'categories' => ['receipt'],
                'default_visibility' => 'internal',
                'permission_check' => function (User $user, $entity, string $action) use ($internalOrPartner) {
                    // Cliente NÃO vê despesa interna por padrão.
                    return $internalOrPartner($user);
                },
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','png','jpg','jpeg','webp','xlsx','xls','csv'],
                'max_size_mb' => 10,
            ],

            // ── TIMESHEET ─────────────────────────────────────────────────────
            'TIMESHEET' => [
                'model' => Timesheet::class,
                'categories' => ['attachment'],
                'default_visibility' => 'internal',
                'permission_check' => fn (User $user) => $internalOrPartner($user),
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','png','jpg','jpeg','webp','xlsx','docx','txt'],
                'max_size_mb' => 10,
            ],

            // ── CONTRACT ──────────────────────────────────────────────────────
            'CONTRACT' => [
                'model' => Contract::class,
                'categories' => ['proposal', 'contract', 'logo', 'client_approval', 'attachment'],
                'default_visibility' => 'internal',
                'permission_check' => function (User $user, $entity, string $action) use ($adminOrAdministrativo, $internalStaff) {
                    if ($action === 'view') return $internalStaff($user);
                    return $adminOrAdministrativo($user);
                },
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','docx','doc','xlsx','xls','png','jpg','jpeg','webp'],
                'max_size_mb' => 50,
            ],

            // ── HOUR_CONTRIBUTION (proposta de aporte) ────────────────────────
            // MIME types alinhados ao validation rule do HourContributionController
            // (aceita doc/xls/ppt + imagens + txt/csv/zip — proposta comercial pode vir
            // em vários formatos). max_size_mb=20 espelha o legado.
            'HOUR_CONTRIBUTION' => [
                'model' => HourContribution::class,
                'categories' => ['proposal'],
                'default_visibility' => 'internal',
                'permission_check' => fn (User $user) => $adminOrAdministrativo($user),
                'allowed_mime' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'image/png',
                    'image/jpeg',
                    'text/plain',
                    'text/csv',
                    'application/zip',
                    'application/x-zip-compressed',
                ],
                'allowed_extensions' => ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','txt','csv','zip'],
                'max_size_mb' => 20,
            ],

            // ── STAGE_ACTIVITY_EVENT (timeline cronograma + comentários atividade) ─
            'STAGE_ACTIVITY_EVENT' => [
                'model' => StageActivityEvent::class,
                'categories' => ['attachment', 'evidence', 'image'],
                'default_visibility' => 'internal',
                'permission_check' => function (User $user, $entity, string $action) use ($internalStaff, $isClienteOfCustomer) {
                    if ($internalStaff($user)) return true;
                    // Portal cliente também posta comentário com anexo — visível só pra cliente da customer.
                    if ($action !== 'delete' && $entity !== null) {
                        // resolver customer_id via stage->project->customer_id (lazy só quando precisar).
                        $cid = optional(optional(optional($entity)->stage)->project)->customer_id
                            ?? optional(optional(optional($entity)->delivery)->activity?->project ?? null)?->customer_id;
                        return $isClienteOfCustomer($user, $entity, $cid);
                    }
                    return false;
                },
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','png','jpg','jpeg','webp','docx','xlsx','txt'],
                'max_size_mb' => 25,
            ],

            // ── PROJECT_MESSAGE (chat de projeto) ─────────────────────────────
            'PROJECT_MESSAGE' => [
                'model' => ProjectMessage::class,
                'categories' => ['attachment', 'image'],
                'default_visibility' => 'internal',
                'permission_check' => fn (User $user) => $internalStaff($user),
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','png','jpg','jpeg','webp','docx','xlsx','txt'],
                'max_size_mb' => 25,
            ],

            // ── CONTRACT_MESSAGE (chat de contrato) ───────────────────────────
            'CONTRACT_MESSAGE' => [
                'model' => ContractMessage::class,
                'categories' => ['attachment', 'image'],
                'default_visibility' => 'internal',
                'permission_check' => fn (User $user) => $internalStaff($user),
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','png','jpg','jpeg','webp','docx','xlsx','txt'],
                'max_size_mb' => 25,
            ],

            // ── REQUEST_MESSAGE (chat de requisição — pré-projeto, cliente participa) ─
            'REQUEST_MESSAGE' => [
                'model' => ContractRequestMessage::class,
                'categories' => ['attachment', 'image'],
                // Cliente PARTICIPA da requisição (envia e vê anexos).
                'default_visibility' => 'customer',
                'permission_check' => function (User $user, $entity, string $action) use ($internalStaff, $isClienteOfCustomer) {
                    if ($internalStaff($user)) return true;
                    if ($entity !== null) {
                        $cid = optional(optional($entity)->contractRequest)->customer_id;
                        return $isClienteOfCustomer($user, $entity, $cid);
                    }
                    return false;
                },
                'allowed_mime' => self::MIME_DOCS_AND_IMAGES,
                'allowed_extensions' => ['pdf','png','jpg','jpeg','webp','docx','xlsx','txt'],
                'max_size_mb' => 25,
            ],

            // ── FECHAMENTO_NOTA (NFS-e + nota débito do parceiro/consultor) ────
            'FECHAMENTO_NOTA' => [
                'model' => FechamentoNota::class,
                'categories' => ['nfse', 'nota_debito'],
                'default_visibility' => 'restricted',
                'permission_check' => fn (User $user) => $adminOrAdministrativo($user),
                'allowed_mime' => ['application/pdf'],
                'allowed_extensions' => ['pdf'],
                'max_size_mb' => 10,
            ],

            // ── USER (avatar) ─────────────────────────────────────────────────
            'USER' => [
                'model' => User::class,
                'categories' => ['avatar'],
                'default_visibility' => 'internal',
                'permission_check' => function (User $user, $entity, string $action) {
                    // Próprio user OU admin.
                    if ($user->isAdmin()) return true;
                    return $entity !== null && (int) $user->id === (int) $entity->id;
                },
                'allowed_mime' => self::MIME_IMAGES,
                'allowed_extensions' => ['png','jpg','jpeg','webp'],
                'max_size_mb' => 5,
            ],
        ];

        return self::$entities;
    }

    /**
     * Lista de todos os entity_types registrados (uso em CHECK constraints e testes).
     */
    public static function knownTypes(): array
    {
        return array_keys(self::getEntities());
    }

    /**
     * Retorna a definição completa do entity_type ou lança UnknownEntityTypeException.
     */
    public static function definition(string $entityType): array
    {
        $entities = self::getEntities();
        if (!isset($entities[$entityType])) {
            throw UnknownEntityTypeException::for($entityType);
        }
        return $entities[$entityType];
    }

    /**
     * Resolve o modelo Eloquent da entidade-dona pelo id. Lança EntityNotFoundException
     * se o registro não existe ou está soft-deleted (depende de cada model — Project
     * tem SoftDeletes, Expense não).
     */
    public static function resolve(string $entityType, int $entityId)
    {
        $def = self::definition($entityType);
        $modelClass = $def['model'];
        $entity = $modelClass::find($entityId);
        if ($entity === null) {
            throw EntityNotFoundException::for($entityType, $entityId);
        }
        return $entity;
    }

    /**
     * Valida só que a entidade existe sem retornar — útil pra precondições.
     */
    public static function validate(string $entityType, int $entityId): void
    {
        self::resolve($entityType, $entityId);
    }

    /**
     * Verifica permissão do usuário pra ação na entidade.
     *  $action ∈ {view, upload, delete, restore}
     * Se $entity for null, executa o check sem entity (útil pro upload — registry
     * já validou existência separadamente).
     */
    public static function can(User $user, string $entityType, $entity, string $action): bool
    {
        $def = self::definition($entityType);
        /** @var Closure $check */
        $check = $def['permission_check'];
        try {
            return (bool) $check($user, $entity, $action);
        } catch (\Throwable $e) {
            // Defensivo: closure mal-formada não deve travar o serviço — log + nega.
            report($e);
            return false;
        }
    }

    /**
     * Categoria permitida pra esse entity_type?
     */
    public static function assertCategory(string $entityType, string $category): void
    {
        $def = self::definition($entityType);
        if (!in_array($category, $def['categories'], true)) {
            throw InvalidCategoryException::for($entityType, $category, $def['categories']);
        }
    }

    /**
     * MIME permitido (defesa em profundidade contra spoof — ver também extensão).
     */
    public static function assertMime(string $entityType, string $mime): void
    {
        $def = self::definition($entityType);
        if (!in_array($mime, $def['allowed_mime'], true)) {
            throw InvalidMimeTypeException::for($entityType, $mime, $def['allowed_mime']);
        }
    }

    /**
     * Extensão permitida — checado em CIMA do MIME pra resistir a MIME spoofing.
     */
    public static function assertExtension(string $entityType, string $extension): void
    {
        $def = self::definition($entityType);
        $ext = strtolower(ltrim($extension, '.'));
        if (!in_array($ext, $def['allowed_extensions'], true)) {
            throw InvalidMimeTypeException::for($entityType, ".{$ext}", array_map(fn ($e) => '.' . $e, $def['allowed_extensions']));
        }
    }

    public static function maxSizeMb(string $entityType): int
    {
        return (int) self::definition($entityType)['max_size_mb'];
    }

    public static function defaultVisibility(string $entityType): string
    {
        return (string) self::definition($entityType)['default_visibility'];
    }

    /**
     * Reset interno — APENAS pra testes (PHPUnit setUp/tearDown). Não usar em runtime.
     */
    public static function reset(): void
    {
        self::$entities = null;
    }
}
