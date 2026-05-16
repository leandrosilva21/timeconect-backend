<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MovideskOrganization;
use App\Models\MovideskTicket;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MovideskService
{
    private function token(): ?string
    {
        return config('services.movidesk.token') ?: null;
    }

    private function baseUrl(): string
    {
        return 'https://api.movidesk.com/public/v1';
    }

    // ─────────────────────────────────────────────────────────────
    // API
    // ─────────────────────────────────────────────────────────────

    public function fetchTicket(int $ticketId): ?array
    {
        try {
            // Timeout curto: tickets travados não devem bloquear o lote inteiro
            // (job roda a cada 5min e processa dezenas de tickets em sequência).
            $response = Http::timeout(5)->get("{$this->baseUrl()}/tickets", [
                'token'   => $this->token(),
                'id'      => $ticketId,
                '$expand' => 'clients($expand=organization),owner,actions($expand=timeAppointments;$select=id,type,isPublic,htmlDescription,createdBy,timeAppointments)',
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('📦 [MOVIDESK] Erro ao buscar ticket', [
                'ticket_id' => $ticketId,
                'status'    => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('📦 [MOVIDESK] Exceção ao buscar ticket', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Variante leve do fetchTicket — usa $expand mínimo (só actions/timeAppointments,
     * sem clients/owner) e timeout maior. Pensada para slow-lane que reprocessa
     * tickets que travaram no fetchTicket normal. Lança exception em qualquer falha
     * (status != 200 ou exceção HTTP) — o caller registra o erro.
     */
    public function fetchTicketLight(int $ticketId): array
    {
        $response = Http::timeout(30)->get("{$this->baseUrl()}/tickets", [
            'token'   => $this->token(),
            'id'      => $ticketId,
            '$expand' => 'actions($expand=timeAppointments;$select=id,type,isPublic,htmlDescription,createdBy,timeAppointments)',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Movidesk HTTP {$response->status()}");
        }

        return $response->json();
    }

    /**
     * Busca tickets com lastUpdate > $since, com paginação automática.
     */
    public function fetchTicketsSince(Carbon $since): array
    {
        $tickets = [];
        $top     = 50;
        $skip    = 0;
        $filter  = "lastUpdate gt " . $since->utc()->format('Y-m-d\TH:i:s\Z');

        do {
            try {
                // Monta URL manualmente: Guzzle codifica '$' → '%24', Movidesk não aceita %24filter
                // $select é obrigatório na listagem do Movidesk (sem ele retorna 400)
                $url = "{$this->baseUrl()}/tickets"
                    . '?token=' . urlencode($this->token())
                    . '&$filter=' . urlencode($filter)
                    . '&$select=id,lastUpdate'
                    . '&$top=' . $top
                    . '&$skip=' . $skip;

                $response = Http::timeout(30)->get($url);

                Log::info('📦 [MOVIDESK SYNC] Resposta da API', [
                    'filter' => $filter,
                    'status' => $response->status(),
                    'skip'   => $skip,
                    'body_preview' => substr($response->body(), 0, 300),
                ]);

                if (!$response->successful()) {
                    Log::warning('📦 [MOVIDESK SYNC] Erro na listagem', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    break;
                }

                $page = $response->json();

                if (empty($page)) {
                    Log::info('📦 [MOVIDESK SYNC] Nenhum ticket retornado', ['filter' => $filter]);
                    break;
                }

                // API pode retornar objeto único em vez de array
                if (isset($page['id'])) {
                    $page = [$page];
                }

                $tickets = array_merge($tickets, $page);
                $skip   += $top;

                if (count($page) < $top) {
                    break; // última página
                }

            } catch (\Throwable $e) {
                Log::error('📦 [MOVIDESK SYNC] Exceção na listagem', ['error' => $e->getMessage()]);
                break;
            }
        } while (true);

        return $tickets;
    }

    // ─────────────────────────────────────────────────────────────
    // Processamento
    // ─────────────────────────────────────────────────────────────

    /**
     * Reprocessa um timesheet existente do Movidesk: re-busca o ticket e tenta
     * corrigir user_id, customer_id e project_id se estiverem com valores padrão.
     * Retorna array com 'updated' (bool), 'changes' (array), ou 'skipped' (string).
     */
    public function reprocessTimesheet(\App\Models\Timesheet $timesheet): array
    {
        if (!$timesheet->ticket || !$timesheet->movidesk_appointment_id) {
            return ['updated' => false, 'skipped' => 'no_ticket'];
        }

        $ticket = $this->fetchTicket((int) $timesheet->ticket);
        if (!$ticket) {
            return ['updated' => false, 'skipped' => 'ticket_not_found'];
        }

        // Localiza a ação e o apontamento específico
        $targetAction      = null;
        $targetAppointment = null;
        foreach ($ticket['actions'] ?? [] as $action) {
            foreach ($action['timeAppointments'] ?? [] as $appt) {
                if (($appt['id'] ?? null) == $timesheet->movidesk_appointment_id) {
                    $targetAction      = $action;
                    $targetAppointment = $appt;
                    break 2;
                }
            }
        }

        if (!$targetAction) {
            // Apontamento foi removido no Movidesk (deletado/recriado com novo id).
            // Soft-delete no Minutor para não ficar fantasma duplicado em
            // fechamentos, dashboards e relatórios. Mantém o registro físico
            // para auditoria.
            $timesheet->delete();
            Log::info('🗑️ [MOVIDESK] Apontamento órfão soft-deletado (não existe mais no Movidesk)', [
                'timesheet_id'            => $timesheet->id,
                'ticket'                  => $timesheet->ticket,
                'movidesk_appointment_id' => $timesheet->movidesk_appointment_id,
            ]);
            return [
                'updated' => true,
                'changes' => ['soft_deleted' => 'appointment_removed_in_movidesk'],
            ];
        }

        $defaultUserId = $this->getDefaultUserId();
        $changes       = [];

        // Tenta resolver o usuário pelo e-mail do createdBy da ação.
        // Se não encontrar no Minutor (equipe externa/cliente), usa o owner do ticket como fallback.
        $resolvedEmail = strtolower(trim($targetAction['createdBy']['email'] ?? ''));
        $resolvedUser  = $resolvedEmail
            ? User::where('email', $resolvedEmail)->where('enabled', true)->first()
            : null;

        if (!$resolvedUser) {
            $ownerEmail   = strtolower(trim($ticket['owner']['email'] ?? ''));
            $resolvedUser = $ownerEmail
                ? User::where('email', $ownerEmail)->where('enabled', true)->first()
                : null;
        }

        if ($resolvedUser && $resolvedUser->id !== $timesheet->user_id) {
            $changes['user_id'] = ['from' => $timesheet->user_id, 'to' => $resolvedUser->id];
            $timesheet->user_id = $resolvedUser->id;
        }

        // Trava manual: se o usuário editou qualquer campo de conteúdo via UI
        // (cliente/projeto/data/horários/effort/observação), o reprocess NÃO
        // sobrescreve mais. Edições do Minutor têm prioridade sobre o sync.
        if (!$timesheet->manual_project_edit) {
            // Corrige cliente
            $newCustomerId = $this->extractCustomerId($ticket);
            if ($newCustomerId && $newCustomerId !== $timesheet->customer_id) {
                $changes['customer_id'] = ['from' => $timesheet->customer_id, 'to' => $newCustomerId];
                $timesheet->customer_id = $newCustomerId;
            }

            // Corrige projeto usando o cliente atualizado
            $newProjectId = $this->extractProjectId($timesheet->customer_id);
            if ($newProjectId && $newProjectId !== $timesheet->project_id) {
                $changes['project_id'] = ['from' => $timesheet->project_id, 'to' => $newProjectId];
                $timesheet->project_id = $newProjectId;
            }

            // Atualizar data/horários/descrição/effort do Movidesk se divergirem
            $newDate          = $this->extractDate($targetAppointment);
            $newStartTime     = $this->extractTime($targetAppointment, 'periodStart');
            $newEndTime       = $this->extractTime($targetAppointment, 'periodEnd');
            $newEffortHours   = $this->extractEffortHours($targetAppointment);
            $newEffortMinutes = $newEffortHours ? $this->calculateEffortMinutes($newEffortHours) : null;
            $newObservation   = $this->buildObservation($ticket, $targetAction);

            // Regra global: apontamentos do Movidesk com duração < 5 min não devem
            // existir no Minutor. Se o reprocess detecta que o Movidesk agora
            // reporta < 5 min, soft-deleta o timesheet em vez de atualizar pra
            // um valor proibido (mesma política aplicada na criação em processAppointment).
            if ($newEffortMinutes !== null && $newEffortMinutes < 5) {
                $timesheet->_logSource = 'movidesk_sync';
                $timesheet->delete();
                Log::info('🗑️ [MOVIDESK] Timesheet soft-deletado no reprocess (duração < 5 min no Movidesk)', [
                    'timesheet_id'            => $timesheet->id,
                    'ticket'                  => $timesheet->ticket,
                    'movidesk_appointment_id' => $timesheet->movidesk_appointment_id,
                    'previous_effort_minutes' => (int) $timesheet->effort_minutes,
                    'new_effort_minutes'      => $newEffortMinutes,
                ]);
                return [
                    'updated' => true,
                    'changes' => ['soft_deleted' => 'movidesk_effort_below_5min'],
                ];
            }

            if ($newDate) {
                $currentDate = $timesheet->date
                    ? (is_string($timesheet->date) ? $timesheet->date : $timesheet->date->format('Y-m-d'))
                    : null;
                if ($currentDate !== $newDate) {
                    $changes['date'] = ['from' => $currentDate, 'to' => $newDate];
                    $timesheet->date = $newDate;
                }
            }
            if ($newStartTime) {
                $currentStart = $timesheet->start_time
                    ? (is_string($timesheet->start_time) ? substr($timesheet->start_time, 0, 5) : $timesheet->start_time->format('H:i'))
                    : null;
                if ($currentStart !== $newStartTime) {
                    $changes['start_time'] = ['from' => $currentStart, 'to' => $newStartTime];
                    $timesheet->start_time = $newStartTime;
                }
            }
            if ($newEndTime) {
                $currentEnd = $timesheet->end_time
                    ? (is_string($timesheet->end_time) ? substr($timesheet->end_time, 0, 5) : $timesheet->end_time->format('H:i'))
                    : null;
                if ($currentEnd !== $newEndTime) {
                    $changes['end_time'] = ['from' => $currentEnd, 'to' => $newEndTime];
                    $timesheet->end_time = $newEndTime;
                }
            }
            if ($newEffortMinutes !== null && (int) $newEffortMinutes !== (int) $timesheet->effort_minutes) {
                $changes['effort_minutes'] = ['from' => $timesheet->effort_minutes, 'to' => (int) $newEffortMinutes];
                $timesheet->effort_minutes = (int) $newEffortMinutes;
            }
            if ($newObservation !== null && $newObservation !== $timesheet->observation) {
                $changes['observation'] = ['from' => $timesheet->observation, 'to' => $newObservation];
                $timesheet->observation = $newObservation;
            }
        }

        if (!empty($changes)) {
            // Dispara observer pra gerar log de auditoria com source=movidesk_sync
            $timesheet->_logSource = 'movidesk_sync';
            $timesheet->save();
            return ['updated' => true, 'changes' => $changes];
        }

        return ['updated' => false, 'skipped' => 'nothing_changed'];
    }

    /**
     * Processa TODAS as ações do ticket (modo sync/cron).
     * Além de criar/atualizar appointments, detecta os timesheets órfãos
     * (existiam no DB mas o appointment correspondente sumiu do Movidesk)
     * e faz soft-delete.
     * Retorna número de timesheets criados.
     */
    public function processTicket(array $ticketDetails): int
    {
        $this->saveTicketData($ticketDetails);

        $ticketId = $ticketDetails['id'] ?? null;
        $actions  = $ticketDetails['actions'] ?? [];
        $created  = 0;

        // Coleta IDs que vieram do Movidesk neste sync — usados depois pra
        // detectar órfãos no DB.
        $movideskAppointmentIds = [];

        foreach ($actions as $action) {
            foreach ($action['timeAppointments'] ?? [] as $appointment) {
                if (!empty($appointment['id'])) {
                    $movideskAppointmentIds[] = $appointment['id'];
                }
                if ($this->processAppointment($ticketDetails, $action, $appointment)) {
                    $created++;
                }
            }
        }

        // Apontamentos órfãos: estão no DB com este ticket, mas sumiram do
        // Movidesk (deletado ou recriado com novo id após edição). Soft-delete
        // pra não ficar fantasma em dashboards/fechamentos.
        if ($ticketId) {
            $orphans = Timesheet::where('ticket', $ticketId)
                ->whereNotNull('movidesk_appointment_id')
                ->when(!empty($movideskAppointmentIds), fn($q) => $q->whereNotIn('movidesk_appointment_id', $movideskAppointmentIds))
                ->get();

            foreach ($orphans as $orphan) {
                Log::info('🗑️ [MOVIDESK SYNC] Apontamento órfão soft-deletado', [
                    'timesheet_id'            => $orphan->id,
                    'ticket'                  => $ticketId,
                    'movidesk_appointment_id' => $orphan->movidesk_appointment_id,
                ]);
                $orphan->_logSource = 'movidesk_sync';
                $orphanUserId = $orphan->user_id;
                $orphanDate   = $orphan->date instanceof \Carbon\Carbon
                    ? $orphan->date->format('Y-m-d')
                    : (string) $orphan->date;
                $orphan->delete(); // SoftDeletes — dispara observer pra gerar log
                // Após soft-delete, re-avaliar timesheets CONFLICTED do mesmo
                // user/data: os que conflitavam só com esse órfão voltam a pending.
                Timesheet::resolveStaleConflicts($orphanUserId, $orphanDate);
            }
        }

        return $created;
    }

    /**
     * Processa apenas o último timeAppointment da última ação (modo webhook).
     * Retorna 1 se criou, 0 se não.
     */
    public function processLastActionOnly(array $ticketDetails): int
    {
        $this->saveTicketData($ticketDetails);

        $actions = $ticketDetails['actions'] ?? [];

        if (empty($actions)) {
            Log::info('⏭️ [MOVIDESK] Nenhuma ação no ticket');
            return 0;
        }

        $action           = $actions[count($actions) - 1];
        $timeAppointments = $action['timeAppointments'] ?? [];

        if (empty($timeAppointments)) {
            Log::info('⏭️ [MOVIDESK] Última ação sem timeAppointments', [
                'action_id' => $action['id'] ?? null,
            ]);
            return 0;
        }

        return $this->processAppointment($ticketDetails, $action, $timeAppointments[0]) ? 1 : 0;
    }

    private const BLOCKED_OWNER_TEAMS = [
        'Promax Bardahl',
        'Manutenção Promax',
    ];

    private function processAppointment(array $ticket, array $action, array $appointment): bool
    {
        $appointmentId = $appointment['id'] ?? null;

        // Bloquear importação de tickets de equipes não gerenciadas pelo Minutor
        $ownerTeam = $ticket['ownerTeam'] ?? null;
        if ($ownerTeam && in_array(trim($ownerTeam), self::BLOCKED_OWNER_TEAMS, true)) {
            Log::info('⛔ [MOVIDESK] Apontamento bloqueado (equipe não permitida)', [
                'owner_team'              => $ownerTeam,
                'ticket_id'               => $ticket['id'] ?? null,
                'movidesk_appointment_id' => $appointmentId,
            ]);
            return false;
        }

        // Regra global de < 5 min APLICADA ANTES da lógica de dedup/edit. Sem isso,
        // um apontamento <5min pode "roubar" o appointment_id de um timesheet
        // recém-criado no MESMO loop processTicket via findEditedCandidate Nível 2
        // (ticket+user+date sem start_time): o sync criava o legítimo de 45min,
        // depois processava o de 1min, findEditedCandidate retornava o de 45min
        // como candidato, reprocessTimesheet aplicava o guard <5min e soft-deletava.
        // Resultado: nenhum timesheet sobrevivia. (Caso William Campana ticket 48125)
        $effortHours   = $this->extractEffortHours($appointment);
        $effortMinutes = $effortHours ? $this->calculateEffortMinutes($effortHours) : 0;
        if ($effortMinutes < 5) {
            Log::info('⏭️ [MOVIDESK] Apontamento ignorado (duração < 5 min)', [
                'effort_minutes'          => $effortMinutes,
                'movidesk_appointment_id' => $appointmentId,
            ]);
            return false;
        }

        // Apontamento já existe → reprocess pra refletir edições no Movidesk
        // (data, descrição, horários, effort). Trava manual de projeto/cliente
        // permanece ativa via $timesheet->manual_project_edit.
        if ($appointmentId) {
            $existing = Timesheet::where('movidesk_appointment_id', $appointmentId)->first();
            if ($existing) {
                $result = $this->reprocessTimesheet($existing);
                if ($result['updated'] ?? false) {
                    Log::info('🔄 [MOVIDESK] Apontamento atualizado via sync', [
                        'timesheet_id'            => $existing->id,
                        'movidesk_appointment_id' => $appointmentId,
                        'changes'                 => array_keys($result['changes'] ?? []),
                    ]);
                }
                return false; // não conta como criado, mas foi atualizado
            }

            // Detecção de EDIÇÃO COM NOVO appointment.id: o Movidesk, ao editar um
            // apontamento, às vezes descarta o id antigo e gera um novo. Sem essa
            // detecção, o sync criaria um novo timesheet e marcaria o original como
            // órfão (soft-delete), quebrando a auditoria (vira "deletado + criado"
            // em vez de "atualizado"). Aqui buscamos um candidato no DB com a mesma
            // chave natural (ticket + user + ação Movidesk) cujo movidesk_appointment_id
            // ESTEJA fora da lista atual do Movidesk — assinatura clássica de edição.
            $editCandidate = $this->findEditedCandidate($ticket, $action, $appointment, $appointmentId);
            if ($editCandidate) {
                $oldAppointmentId = $editCandidate->movidesk_appointment_id;
                // Aponta pro novo id no Movidesk; salvamos primeiro pra garantir
                // persistência mesmo se reprocessTimesheet não detectar outras mudanças.
                // O observer gera log de auditoria automaticamente (source=movidesk_sync).
                $editCandidate->movidesk_appointment_id = $appointmentId;
                $editCandidate->_logSource = 'movidesk_sync';
                $editCandidate->save();

                $result = $this->reprocessTimesheet($editCandidate->fresh());
                Log::info('🔄 [MOVIDESK] Apontamento ALTERADO no Movidesk reconhecido como edição (novo appointment.id)', [
                    'timesheet_id'                 => $editCandidate->id,
                    'old_movidesk_appointment_id'  => $oldAppointmentId,
                    'new_movidesk_appointment_id'  => $appointmentId,
                    'changes'                      => array_keys($result['changes'] ?? []),
                ]);
                return false; // não cria duplicata; foi update
            }
        }

        try {
            // Filtro por data mínima de apontamento (configurável no admin)
            $importStartDate = SystemSetting::get('movidesk_import_start_date');
            if ($importStartDate) {
                $appointmentDate = $this->extractDate($appointment);
                if ($appointmentDate && $appointmentDate < $importStartDate) {
                    Log::info('⏭️ [MOVIDESK] Apontamento ignorado (data anterior ao início de importação)', [
                        'appointment_date'        => $appointmentDate,
                        'import_start_date'       => $importStartDate,
                        'movidesk_appointment_id' => $appointmentId,
                    ]);
                    return false;
                }
            }

            $userId = $this->extractUserId($action);
            if (!$userId) return false;

            $customerId = $this->extractCustomerId($ticket);
            if (!$customerId) return false;

            $projectId = $this->extractProjectId($customerId);
            if (!$projectId) return false;

            $date      = $this->extractDate($appointment)      ?? now()->format('Y-m-d');
            $startTime = $this->extractTime($appointment, 'periodStart') ?? '00:00';
            $endTime   = $this->extractTime($appointment, 'periodEnd')   ?? '00:00';

            // isPublic === false → Ação interna; ausente ou true → pública
            // type === 2 significa "ação manual" (comentário), não "interna"
            $isInternal = isset($action['isPublic']) && $action['isPublic'] === false;

            $this->createTimesheet([
                'user_id'                 => $userId,
                'customer_id'             => $customerId,
                'project_id'              => $projectId,
                'date'                    => $date,
                'start_time'              => $startTime,
                'end_time'                => $endTime,
                'effort_minutes'          => $effortMinutes,
                'effort_hours'            => $effortHours,
                'observation'             => $this->buildObservation($ticket, $action),
                'ticket'                  => $ticket['id'] ?? null,
                'movidesk_appointment_id' => $appointmentId,
                'is_internal_action'      => $isInternal,
                'status'                  => $isInternal ? 'internal' : 'pending',
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK] Erro ao processar apontamento', [
                'error'                   => $e->getMessage(),
                'movidesk_appointment_id' => $appointmentId,
            ]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Extratores
    // ─────────────────────────────────────────────────────────────

    private function extractUserId(array $action): ?int
    {
        $email = $action['createdBy']['email'] ?? null;
        if ($email) $email = strtolower(trim($email));

        if ($email) {
            $user = User::where('email', $email)->where('enabled', true)->first();
            if ($user) return $user->id;
            Log::info('⏭️ [MOVIDESK] Apontamento descartado — agente não é consultor do Minutor', [
                'email'     => $email,
                'action_id' => $action['id'] ?? null,
            ]);
        } else {
            Log::warning('⚠️ [MOVIDESK] Apontamento descartado — email ausente na ação', [
                'action_id' => $action['id'] ?? null,
            ]);
        }

        // Retorna null = caller (processAppointment) descarta o apontamento.
        // Antes caía em getDefaultUserId() que atribuía a um usuário padrão —
        // poluía estatísticas dos consultores quando agentes externos (ex:
        // Promax) faziam ações no mesmo ticket.
        return null;
    }

    private function getDefaultUserId(): ?int
    {
        $id = SystemSetting::get('movidesk_default_user_id');
        if (!$id) {
            Log::error('🚨 [MOVIDESK] movidesk_default_user_id não configurado — apontamento descartado');
            return null;
        }
        return (int) $id;
    }

    private function extractCustomerId(array $ticket): ?int
    {
        $clients = $ticket['clients'] ?? [];

        if (empty($clients)) {
            return $this->getDefaultCustomerId();
        }

        $target = null;

        if (count($clients) > 1) {
            foreach ($clients as $client) {
                if (strpos($client['email'] ?? '', '@erpserv.com.br') === false) {
                    $target = $client;
                    break;
                }
            }
        }

        $target = $target ?? $clients[0];

        // Movidesk personType: 1=pessoa, 2=empresa, 4=departamento.
        // O client do ticket pode ser:
        //   - personType=2 (empresa) → o próprio client.id É o id da organização;
        //     organization vem null nesse caso (ticket aberto pela empresa direto).
        //   - personType=1 (pessoa) ou 4 (departamento) → tem organization aninhada
        //     com o id da empresa (caso GRUPO EUREKA tinha pessoa + org aninhada).
        $clientPersonType = $target['personType'] ?? null;
        $org              = $target['organization'] ?? null;
        $orgName          = is_array($org) ? ($org['businessName'] ?? null) : null;
        $orgName          = $orgName ?? ($target['businessName'] ?? null);

        if ($clientPersonType === 2) {
            // Client é uma empresa
            $orgId         = $target['id'] ?? null;
            $orgPersonType = 2;
        } else {
            // Client é pessoa/departamento — pega org aninhada
            $orgId         = is_array($org) ? ($org['id'] ?? null) : null;
            $orgPersonType = is_array($org) ? ($org['personType'] ?? null) : null;
        }

        // Departamento → resolve empresa-mãe via /persons/{id}.relationships.
        if ($orgPersonType === 4 && $orgId) {
            $parentMovideskId = $this->resolveDepartmentParentMovideskId((string) $orgId);
            if ($parentMovideskId) {
                $orgId = $parentMovideskId;
            }
        }

        // Chave única OBRIGATÓRIA: CNPJ.
        // sync-orgs vincula movidesk_organizations.cnpj <-> customers.cgc (dígitos);
        // aqui resolvemos cliente a partir do movidesk_id (ID estável da org no
        // Movidesk). Nome do solicitante/empresa NÃO é usado pra rotear — o
        // payload do Movidesk varia (trailing space, accents, casing) e isso já
        // causou ticket da EUREKA cair em ERPSERV (default).
        if (!$orgId) {
            Log::warning('⚠️ [MOVIDESK] Organization id ausente no ticket — usando default', [
                'business_name'   => $orgName,
                'org_person_type' => $orgPersonType,
                'ticket_id'       => $ticket['id'] ?? null,
            ]);
            return $this->getDefaultCustomerId();
        }

        $orgRecord = MovideskOrganization::where('movidesk_id', (string) $orgId)->first();

        if ($orgRecord && $orgRecord->customer_id) {
            Log::info('✅ [MOVIDESK] Cliente resolvido via movidesk_organizations (CNPJ)', [
                'movidesk_org_id' => $orgId,
                'cnpj'            => $orgRecord->cnpj,
                'customer_id'     => $orgRecord->customer_id,
            ]);
            return $orgRecord->customer_id;
        }

        // Fallback: org cacheada mas sem link, tenta match direto por CGC do
        // customers (mesma normalização do sync-orgs: só dígitos).
        if ($orgRecord && $orgRecord->cnpj) {
            $cgcDigits = preg_replace('/\D/', '', $orgRecord->cnpj);
            if ($cgcDigits) {
                $customer = Customer::where('active', true)
                    ->whereRaw("regexp_replace(coalesce(cgc, ''), '\\D', '', 'g') = ?", [$cgcDigits])
                    ->first();
                if ($customer) {
                    Log::info('✅ [MOVIDESK] Cliente resolvido via CGC (movidesk_org sem customer_id linkado)', [
                        'movidesk_org_id' => $orgId,
                        'cnpj'            => $orgRecord->cnpj,
                        'customer_id'     => $customer->id,
                    ]);
                    return $customer->id;
                }
            }
        }

        // Throttle: org sem CNPJ cadastrado é estado conhecido — não vale logar a
        // cada sync (a cada 5min). Loga no máximo 1x a cada 6h por org.
        $throttleKey = "movidesk:warn:unresolved-cnpj:{$orgId}";
        if (!Cache::has($throttleKey)) {
            Cache::put($throttleKey, 1, now()->addHours(6));
            Log::warning('⚠️ [MOVIDESK] Cliente não resolvido por CNPJ — rode movidesk:sync-orgs ou cadastre o CGC do cliente', [
                'movidesk_org_id' => $orgId,
                'business_name'   => $orgName,
                'org_person_type' => $orgPersonType,
                'cnpj_cached'     => $orgRecord?->cnpj,
            ]);
        }
        return $this->getDefaultCustomerId();
    }

    /**
     * Para um departamento Movidesk (personType=4), busca o ID da organização raiz
     * a partir de /persons/{id}.relationships[0].id. Cacheado por 1h.
     */
    private function resolveDepartmentParentMovideskId(string $departmentId): ?string
    {
        return Cache::remember(
            "movidesk:dept:parent:{$departmentId}",
            now()->addHour(),
            function () use ($departmentId) {
                try {
                    $url = "{$this->baseUrl()}/persons"
                        . '?token=' . urlencode($this->token() ?? '')
                        . '&id='    . urlencode($departmentId);

                    $response = Http::timeout(10)->get($url);
                    if (!$response->successful()) {
                        Log::warning('⚠️ [MOVIDESK] Falha ao buscar departamento', [
                            'department_id' => $departmentId,
                            'status'        => $response->status(),
                        ]);
                        return null;
                    }

                    $data = $response->json();
                    $rels = $data['relationships'] ?? [];
                    if (empty($rels)) {
                        return null;
                    }

                    return (string) ($rels[0]['id'] ?? '') ?: null;
                } catch (\Throwable $e) {
                    Log::warning('⚠️ [MOVIDESK] Erro ao resolver parent do departamento', [
                        'department_id' => $departmentId,
                        'error'         => $e->getMessage(),
                    ]);
                    return null;
                }
            }
        );
    }

    private function getDefaultCustomerId(): ?int
    {
        $id = SystemSetting::get('movidesk_default_customer_id');

        if (!$id) {
            Log::error('🚨 [MOVIDESK] movidesk_default_customer_id não configurado');
            return null;
        }

        return (int) $id;
    }

    private function extractProjectId(?int $customerId): ?int
    {
        if ($customerId) {
            // 1. Prioridade absoluta: projeto com flag movidesk_integration_enabled.
            // Garante no máximo 1 por cliente (regra aplicada em ProjectController).
            $flagged = Project::where('customer_id', $customerId)
                ->where('movidesk_integration_enabled', true)
                ->first();
            if ($flagged) {
                if ($flagged->isActive()) {
                    Log::info('✅ [MOVIDESK] Projeto resolvido via movidesk_integration_enabled', [
                        'customer_id' => $customerId,
                        'project_id'  => $flagged->id,
                        'project_name'=> $flagged->name,
                    ]);
                    return $flagged->id;
                }
                Log::warning('⚠️ [MOVIDESK] Projeto flagged inativo — caindo nos próximos fallbacks', [
                    'customer_id' => $customerId,
                    'project_id'  => $flagged->id,
                    'status'      => $flagged->status,
                ]);
            }

            // 2. Legado: projeto configurado manualmente na org Movidesk
            $org = MovideskOrganization::where('customer_id', $customerId)
                ->whereNotNull('project_id')
                ->first();

            if ($org && $org->project_id) {
                $orgProject = Project::find($org->project_id);
                if ($orgProject && $orgProject->isOpen()) {
                    Log::info('✅ [MOVIDESK] Projeto resolvido via vínculo de organização', [
                        'customer_id' => $customerId,
                        'project_id'  => $orgProject->id,
                        'project_name'=> $orgProject->name,
                    ]);
                    return $orgProject->id;
                }
                Log::warning('⚠️ [MOVIDESK] Projeto vinculado à org está inativo/inexistente — buscando sustentação', [
                    'customer_id' => $customerId,
                    'project_id'  => $org->project_id,
                ]);
            }

            // 2. Fallback: busca projeto de sustentação do cliente
            $project = Project::where('customer_id', $customerId)
                ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
                ->where(function ($q) {
                    $q->where('service_types.code', 'sustentacao')
                      ->orWhere('service_types.name', 'ilike', '%sustenta%');
                })
                ->select('projects.*')
                ->first();

            if ($project) {
                if ($project->isActive()) {
                    return $project->id;
                }

                Log::warning('⚠️ [MOVIDESK] Projeto de sustentação do cliente está inativo — usando projeto padrão', [
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'status'       => $project->status,
                    'customer_id'  => $customerId,
                ]);
            }
        }

        return $this->resolveDefaultProject();
    }

    private function resolveDefaultProject(): ?int
    {
        $defaultId = SystemSetting::get('movidesk_default_project_id');

        if (!$defaultId) {
            Log::error('🚨 [MOVIDESK] movidesk_default_project_id não configurado — apontamento descartado');
            return null;
        }

        $project = Project::find($defaultId);

        if (!$project) {
            Log::error('🚨 [MOVIDESK] Projeto padrão não encontrado', ['id' => $defaultId]);
            return null;
        }

        Log::info('ℹ️ [MOVIDESK] Usando projeto padrão', [
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => $project->status,
        ]);

        return $project->id;
    }

    private function extractDate(array $appointment): ?string
    {
        $val = $appointment['date'] ?? null;
        if (!$val) {
            Log::warning('⚠️ [MOVIDESK] date ausente no timeAppointment');
            return null;
        }
        try {
            return Carbon::parse($val)->format('Y-m-d');
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK] Erro ao parsear date', ['value' => $val]);
            return null;
        }
    }

    private function extractTime(array $appointment, string $field): ?string
    {
        $val = $appointment[$field] ?? null;
        if (!$val) {
            Log::warning("⚠️ [MOVIDESK] {$field} ausente no timeAppointment");
            return null;
        }
        try {
            return Carbon::parse($val)->format('H:i');
        } catch (\Throwable $e) {
            Log::error("🚨 [MOVIDESK] Erro ao parsear {$field}", ['value' => $val]);
            return null;
        }
    }

    private function extractEffortHours(array $appointment): ?string
    {
        $workTime = $appointment['workTime'] ?? null;
        if (!$workTime) {
            Log::warning('⚠️ [MOVIDESK] workTime ausente no timeAppointment');
            return null;
        }

        [$hours, $minutes] = array_pad(explode(':', $workTime), 2, '00');
        return ((int) $hours) . ':' . $minutes;
    }

    private function calculateEffortMinutes(string $effortHours): int
    {
        [$hours, $minutes] = array_pad(explode(':', $effortHours), 2, '0');
        return ((int) $hours * 60) + (int) $minutes;
    }

    private function buildObservation(array $ticket, array $action): string
    {
        $subject = $ticket['subject'] ?? '';
        $html    = $action['htmlDescription'] ?? '';
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/', ' ', $plain);

        if ($subject) {
            $escaped = preg_quote(trim($subject), '/');
            $plain   = preg_replace('/^' . $escaped . '\s*/iu', '', $plain);
            $plain   = trim($plain);
        }

        return $plain;
    }

    // ─────────────────────────────────────────────────────────────
    // Persistência
    // ─────────────────────────────────────────────────────────────

    /**
     * Procura um timesheet EXISTENTE que provavelmente corresponde a este appointment
     * mas com `movidesk_appointment_id` antigo — caso típico de edição no Movidesk
     * que recria o id do apontamento.
     *
     * Estratégia (em ordem de especificidade — só consolida se encontrar ÚNICO match):
     *   1. ticket + user + date + start_time (chave natural mais forte)
     *   2. ticket + user + date (cobre quando o start_time também foi editado)
     *
     * Excluímos timesheets já com o NOVO id e os já soft-deletados. Em qualquer caso
     * de ambiguidade (mais de 1 candidato) cai pro fluxo de criação normal — melhor
     * uma duplicata visível do que sobrescrever silenciosamente o timesheet errado.
     */
    private function findEditedCandidate(array $ticket, array $action, array $appointment, $newAppointmentId): ?\App\Models\Timesheet
    {
        $ticketId = $ticket['id'] ?? null;
        if (!$ticketId) return null;

        // Pra identificar o user, usamos a MESMA lógica do extractUserId. Se não
        // conseguirmos resolver o user, sem chave natural confiável — desiste.
        $userId = $this->extractUserId($action);
        if (!$userId) return null;

        $date = $this->extractDate($appointment);
        if (!$date) return null;

        $startTime = $this->extractTime($appointment, 'periodStart');

        // Lista TODOS os appointment.id atuais do ticket no Movidesk. Qualquer
        // timesheet cujo movidesk_appointment_id apareça nessa lista NÃO é um
        // candidato de edição — é um apontamento legítimo do próprio ciclo
        // (ou já existente vinculado a outro appt do mesmo ticket). Sem essa
        // exclusão, o Nível 2 sequestra o timesheet recém-criado do appt
        // anterior quando o ticket tem múltiplos apontamentos do mesmo
        // ticket+user+date (caso William ticket 47852 dia 13/05).
        $currentApptIds = [];
        foreach ($ticket['actions'] ?? [] as $a) {
            foreach ($a['timeAppointments'] ?? [] as $appt) {
                if (isset($appt['id'])) $currentApptIds[] = $appt['id'];
            }
        }

        $baseQuery = fn() => Timesheet::query()
            ->where('ticket', (string) $ticketId)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where(function ($q) use ($newAppointmentId) {
                $q->where('movidesk_appointment_id', '!=', $newAppointmentId)
                  ->orWhereNull('movidesk_appointment_id');
            })
            ->when(!empty($currentApptIds), fn ($q) => $q->whereNotIn('movidesk_appointment_id', $currentApptIds));

        // Nível 1: bate com start_time idêntico — assinatura forte de edição
        if ($startTime) {
            $matches = $baseQuery()->where('start_time', $startTime)->limit(2)->get();
            if ($matches->count() === 1) return $matches->first();
        }

        // Nível 2: sem start_time, só ticket+user+date (start/end pode ter mudado)
        $matches = $baseQuery()->limit(2)->get();
        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function createTimesheet(array $data): void
    {
        DB::beginTransaction();

        try {
            $conflict = Timesheet::where('user_id', $data['user_id'])
                ->where('project_id', $data['project_id'])
                ->where('date', $data['date'])
                ->where('status', '!=', Timesheet::STATUS_REJECTED)
                ->where(function ($q) use ($data) {
                    $q->where('start_time', '<', $data['end_time'])
                      ->where('end_time', '>', $data['start_time']);
                })
                ->first();

            $timesheet                          = new Timesheet();
            $timesheet->user_id                 = $data['user_id'];
            $timesheet->customer_id             = $data['customer_id'];
            $timesheet->project_id              = $data['project_id'];
            $timesheet->date                    = $data['date'];
            $timesheet->start_time              = $data['start_time'];
            $timesheet->end_time                = $data['end_time'];
            $timesheet->effort_minutes          = $data['effort_minutes'];
            $timesheet->observation             = $data['observation'];
            $timesheet->ticket                  = $data['ticket'];
            $timesheet->movidesk_appointment_id = $data['movidesk_appointment_id'] ?? null;
            $timesheet->is_internal_action      = $data['is_internal_action'] ?? false;
            $timesheet->origin = 'webhook';

            if ($data['is_internal_action'] ?? false) {
                $timesheet->status = Timesheet::STATUS_INTERNAL;
            } else {
                $timesheet->status = $conflict
                    ? Timesheet::STATUS_CONFLICTED
                    : Timesheet::STATUS_PENDING;
            }
            $timesheet->save();

            DB::commit();

            Log::info($conflict
                ? '⚠️ [MOVIDESK] Timesheet criado com status CONFLICTED'
                : '✅ [MOVIDESK] Timesheet criado', [
                'timesheet_id'            => $timesheet->id,
                'movidesk_appointment_id' => $data['movidesk_appointment_id'],
                'ticket'                  => $data['ticket'],
                'date'                    => $data['date'],
                'effort_hours'            => $data['effort_hours'],
                'status'                  => $timesheet->status,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('🚨 [MOVIDESK] Erro ao criar timesheet', [
                'error' => $e->getMessage(),
                'data'  => $data,
            ]);
        }
    }

    private function saveTicketData(array $ticket): void
    {
        try {
            $id = $ticket['id'] ?? null;
            if (!$id) return;

            $data = [
                'solicitante' => $this->extractSolicitante($ticket),
                'categoria'   => $ticket['category'] ?? null,
                'urgencia'    => $ticket['urgency'] ?? null,
                'responsavel' => $this->extractResponsavel($ticket),
                'nivel'       => $this->extractNivel($ticket),
                'servico'     => $ticket['serviceFirstLevel'] ?? $ticket['serviceSecondLevel'] ?? null,
                'titulo'      => $ticket['subject'] ?? null,
                'status'      => $ticket['status'] ?? null,
            ];

            // Save portal fields when present in the ticket data
            if (array_key_exists('baseStatus', $ticket))         $data['base_status']            = $ticket['baseStatus'];
            if (array_key_exists('origin', $ticket))             $data['origin']                  = $ticket['origin'];
            if (isset($ticket['owner']['email']))                 $data['owner_email']             = $ticket['owner']['email'];
            if (array_key_exists('ownerTeam', $ticket))          $data['owner_team']              = $ticket['ownerTeam'];
            if (array_key_exists('createdDate', $ticket))        $data['created_date']            = $this->parseTimestamp($ticket['createdDate']);
            if (array_key_exists('closedIn', $ticket))           $data['closed_in']               = $this->parseTimestamp($ticket['closedIn']);
            if (array_key_exists('resolvedIn', $ticket))         $data['resolved_in']             = $this->parseTimestamp($ticket['resolvedIn']);
            if (array_key_exists('slaResponseDate', $ticket))    $data['sla_response_date']       = $this->parseTimestamp($ticket['slaResponseDate']);
            if (array_key_exists('slaRealResponseDate', $ticket)) $data['sla_real_response_date'] = $this->parseTimestamp($ticket['slaRealResponseDate']);
            if (array_key_exists('slaSolutionDate', $ticket))    $data['sla_solution_date']       = $this->parseTimestamp($ticket['slaSolutionDate']);
            if (array_key_exists('slaResponseTime', $ticket))    $data['sla_response_time']       = $ticket['slaResponseTime'];
            if (array_key_exists('slaSolutionTime', $ticket))    $data['sla_solution_time']       = $ticket['slaSolutionTime'];

            MovideskTicket::updateOrCreate(['ticket_id' => $id], $data);
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK] Erro ao salvar dados do ticket', [
                'error' => $e->getMessage(),
                'id'    => $ticket['id'] ?? null,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Portal de Sustentação
    // ─────────────────────────────────────────────────────────────

    /**
     * Busca tickets dos últimos 90 dias para o Portal de Sustentação.
     */
    public function fetchPortalTickets(): array
    {
        $since  = '2026-01-01T00:00:00Z';
        $filter = "lastUpdate gt {$since}";
        $select = 'id,subject,status,baseStatus,category,urgency,origin,serviceFirstLevel,serviceSecondLevel,'
                . 'createdDate,closedIn,resolvedIn,slaSolutionDate,slaResponseDate,slaRealResponseDate,'
                . 'slaResponseTime,slaSolutionTime,ownerTeam,lastUpdate';

        $tickets = [];
        $top     = 50;
        $skip    = 0;

        do {
            try {
                $url = "{$this->baseUrl()}/tickets"
                    . '?token=' . urlencode($this->token())
                    . '&$filter=' . urlencode($filter)
                    . '&$select=' . urlencode($select)
                    . '&$expand=clients($expand=organization),owner'
                    . '&$top=' . $top
                    . '&$skip=' . $skip;

                $response = Http::timeout(60)->get($url);

                if (!$response->successful()) {
                    Log::warning('[MOVIDESK PORTAL] Erro na listagem', [
                        'status' => $response->status(),
                        'body'   => substr($response->body(), 0, 300),
                    ]);
                    break;
                }

                $page = $response->json();
                if (empty($page)) break;
                if (isset($page['id'])) $page = [$page];

                $tickets = array_merge($tickets, $page);
                $skip   += $top;

                if (count($page) < $top) break;

                // Rate limit: 10 req/min → sleep between pages
                sleep(7);
            } catch (\Throwable $e) {
                Log::error('[MOVIDESK PORTAL] Exceção na listagem', ['error' => $e->getMessage()]);
                break;
            }
        } while (true);

        return $tickets;
    }

    /**
     * Busca organizações do Movidesk via /persons com businessName e cpfCnpj.
     * Retorna array indexado por businessName (lowercase) => ['cpfCnpj' => ..., 'id' => ...]
     */
    public function fetchOrganizations(): array
    {
        $orgs = [];
        $top  = 100;
        $skip = 0;

        do {
            try {
                // Sem $filter — alguns tenants do Movidesk rejeitam OData filter em /persons
                $url = "{$this->baseUrl()}/persons"
                    . '?token='   . urlencode($this->token())
                    . '&$select=' . urlencode('id,businessName,cpfCnpj,isActive,personType')
                    . '&$top='    . $top
                    . '&$skip='   . $skip;

                $response = Http::timeout(30)->get($url);

                if (!$response->successful()) {
                    Log::warning('[MOVIDESK] Erro ao buscar persons', [
                        'status' => $response->status(),
                        'body'   => substr($response->body(), 0, 300),
                    ]);
                    break;
                }

                $page = $response->json();
                if (empty($page)) break;
                if (isset($page['id'])) $page = [$page];

                foreach ($page as $p) {
                    // personType 2 = organização/empresa; 1 = pessoa física/contato
                    if (($p['personType'] ?? 0) != 2) continue;

                    $cnpj = preg_replace('/[^0-9]/', '', $p['cpfCnpj'] ?? '');
                    if (strlen($cnpj) < 11) continue;

                    $name = trim($p['businessName'] ?? $p['corporateName'] ?? '');
                    if (!$name) continue;

                    $orgs[strtolower($name)] = [
                        'id'       => $p['id'] ?? null,
                        'name'     => $name,
                        'cpfCnpj'  => $cnpj,
                        'isActive' => (bool) ($p['isActive'] ?? true),
                    ];
                }

                $skip += $top;
                if (count($page) < $top) break;
                sleep(7);
            } catch (\Throwable $e) {
                Log::error('[MOVIDESK] Exceção ao buscar orgs', ['error' => $e->getMessage()]);
                break;
            }
        } while (true);

        return $orgs;
    }

    /**
     * Busca mapa email → nome da organização via /persons (campo organizations[]).
     * Usado para enriquecer solicitante.organization nos tickets.
     */
    public function fetchPersonOrgMap(): array
    {
        $map  = [];
        $top  = 100;
        $skip = 0;

        do {
            try {
                $url = "{$this->baseUrl()}/persons"
                    . '?token='   . urlencode($this->token())
                    . '&$select=' . urlencode('id,businessName,userName')
                    . '&$expand=' . urlencode('relationships($select=id,name)')
                    . '&$top='    . $top
                    . '&$skip='   . $skip;

                $response = Http::timeout(30)->get($url);
                if (!$response->successful()) break;

                $data = $response->json();
                if (empty($data)) break;
                if (isset($data['id'])) $data = [$data];

                foreach ($data as $person) {
                    $email = strtolower(trim($person['userName'] ?? ''));
                    if (!$email) continue;

                    // Organização vem em relationships[].name (onde id não é null)
                    $orgName = null;
                    foreach ($person['relationships'] ?? [] as $rel) {
                        if (!empty($rel['id']) && !empty($rel['name'])) {
                            $orgName = trim($rel['name']);
                            break;
                        }
                    }
                    if ($orgName) $map[$email] = $orgName;
                }

                // Log primeira pessoa para debug
                if ($skip === 0 && !empty($data)) {
                    Log::info('[MOVIDESK] fetchPersonOrgMap sample: ' . json_encode(array_slice($data, 0, 1)));
                }

                $skip += $top;
                if (count($data) < $top) break;
            } catch (\Throwable $e) {
                Log::error('[MOVIDESK] fetchPersonOrgMap erro: ' . $e->getMessage());
                break;
            }
        } while (true);

        Log::info('[MOVIDESK] fetchPersonOrgMap: ' . count($map) . ' pessoas com org.');
        return $map;
    }

    /**
     * Busca agentes (personType=1) do Movidesk via /persons.
     * Retorna array indexado por email (lowercase).
     */
    /**
     * Busca agentes do Movidesk via /persons.
     * Tenta filtro profileType eq 1 primeiro; se não funcionar, pagina tudo (máx 10 páginas).
     *
     * @param  string[]  $knownEmails  Emails dos owners vindos dos tickets (para validar matches)
     */
    public function fetchAgents(array $knownEmails = []): array
    {
        $knownSet = array_flip(array_map('strtolower', $knownEmails));
        $agents   = [];
        $top      = 100;

        // Tenta primeiro com filtro profileType eq 1 (agentes)
        $filterUrl = "{$this->baseUrl()}/persons"
            . '?token='   . urlencode($this->token())
            . '&$filter=' . urlencode('profileType eq 1')
            . '&$top='    . $top;

        try {
            $response = Http::timeout(30)->get($filterUrl);
            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data)) {
                    if (isset($data['id'])) $data = [$data];
                    foreach ($data as $p) {
                        $email = $this->extractPersonEmail($p);
                        if ($email) {
                            $agents[$email] = $this->buildAgentRecord($p, $email);
                        }
                    }
                    // Se filtro funcionou e retornou resultados, faz paginação completa
                    if (!empty($agents)) {
                        $skip = $top;
                        while (count($data) === $top) {
                            sleep(7);
                            $url = $filterUrl . '&$skip=' . $skip;
                            $response = Http::timeout(30)->get($url);
                            if (!$response->successful()) break;
                            $data = $response->json();
                            if (empty($data)) break;
                            if (isset($data['id'])) $data = [$data];
                            foreach ($data as $p) {
                                $email = $this->extractPersonEmail($p);
                                if ($email) $agents[$email] = $this->buildAgentRecord($p, $email);
                            }
                            $skip += $top;
                        }
                        Log::info('[MOVIDESK] fetchAgents via filtro profileType=1: ' . count($agents) . ' agentes');
                        return $agents;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[MOVIDESK] Filtro profileType falhou, tentando paginação', ['error' => $e->getMessage()]);
        }

        // Fallback: pagina tudo mas só guarda emails conhecidos (dos tickets)
        Log::info('[MOVIDESK] fetchAgents fallback: paginando /persons e filtrando por emails conhecidos');
        $skip     = 0;
        $maxPages = 20;
        $page     = 0;

        do {
            try {
                $url = "{$this->baseUrl()}/persons"
                    . '?token='   . urlencode($this->token())
                    . '&$top='    . $top
                    . '&$skip='   . $skip;

                $response = Http::timeout(30)->get($url);
                if (!$response->successful()) break;

                $data = $response->json();
                if (empty($data)) break;
                if (isset($data['id'])) $data = [$data];

                foreach ($data as $p) {
                    $email = $this->extractPersonEmail($p);
                    if (!$email) continue;
                    // Só guarda se for email de um responsável conhecido nos tickets
                    if (empty($knownSet) || isset($knownSet[$email])) {
                        $agents[$email] = $this->buildAgentRecord($p, $email);
                    }
                }

                $skip += $top;
                $page++;
                if (count($data) < $top || $page >= $maxPages) break;
                sleep(7);
            } catch (\Throwable $e) {
                Log::error('[MOVIDESK] Exceção paginando persons', ['error' => $e->getMessage()]);
                break;
            }
        } while (true);

        Log::info('[MOVIDESK] fetchAgents paginação: ' . count($agents) . ' agentes em ' . $page . ' páginas');
        return $agents;
    }

    private function extractPersonEmail(array $p): string
    {
        $email = strtolower(trim($p['userName'] ?? ''));
        if (!$email) {
            foreach ($p['emails'] ?? [] as $em) {
                if (!empty($em['email'])) {
                    $email = strtolower(trim($em['email']));
                    break;
                }
            }
        }
        return $email;
    }

    private function buildAgentRecord(array $p, string $email): array
    {
        $team = null;
        if (!empty($p['teams']) && is_array($p['teams'])) {
            $team = $p['teams'][0]['team']['name'] ?? $p['teams'][0]['name'] ?? null;
        }
        return [
            'id'       => $p['id'] ?? null,
            'name'     => trim($p['businessName'] ?? ''),
            'email'    => $email,
            'isActive' => (bool) ($p['isActive'] ?? true),
            'team'     => $team,
        ];
    }

    /**
     * Salva ticket com todos os campos do portal, resolvendo user_id e customer_id.
     */
    public function saveTicketForPortal(array $ticket): void
    {
        try {
            $id = $ticket['id'] ?? null;
            if (!$id) return;

            $existing = MovideskTicket::where('ticket_id', $id)->first();

            $newSolicitante = $this->extractSolicitante($ticket);

            // Preserve cpf_cnpj populated by sync-orgs — the ticket API never returns org CNPJs
            if ($newSolicitante && $existing?->solicitante) {
                $existingCnpj = $existing->solicitante['cpf_cnpj'] ?? null;
                if ($existingCnpj && !($newSolicitante['cpf_cnpj'] ?? null)) {
                    $newSolicitante['cpf_cnpj'] = $existingCnpj;
                }
            }

            // Preserve customer_id resolved by sync-orgs when the ticket API can't resolve it
            $newCustomerId = $this->resolveCustomerIdForPortal($ticket);
            if (!$newCustomerId && $existing?->customer_id) {
                $newCustomerId = $existing->customer_id;
            }

            MovideskTicket::updateOrCreate(['ticket_id' => $id], [
                'solicitante'            => $newSolicitante,
                'categoria'              => $ticket['category'] ?? null,
                'urgencia'               => $ticket['urgency'] ?? null,
                'responsavel'            => $this->extractResponsavel($ticket),
                'nivel'                  => $this->extractNivel($ticket),
                'servico'                => $ticket['serviceFirstLevel'] ?? $ticket['serviceSecondLevel'] ?? null,
                'titulo'                 => $ticket['subject'] ?? null,
                'status'                 => $ticket['status'] ?? null,
                'base_status'            => $ticket['baseStatus'] ?? null,
                'origin'                 => $ticket['origin'] ?? null,
                'owner_email'            => $ticket['owner']['email'] ?? null,
                'owner_team'             => $ticket['ownerTeam'] ?? null,
                'user_id'                => $this->resolveUserIdForPortal($ticket),
                'customer_id'            => $newCustomerId,
                'created_date'           => $this->parseTimestamp($ticket['createdDate'] ?? null),
                'closed_in'              => $this->parseTimestamp($ticket['closedIn'] ?? null),
                'resolved_in'            => $this->parseTimestamp($ticket['resolvedIn'] ?? null),
                'sla_response_date'      => $this->parseTimestamp($ticket['slaResponseDate'] ?? null),
                'sla_real_response_date' => $this->parseTimestamp($ticket['slaRealResponseDate'] ?? null),
                'sla_solution_date'      => $this->parseTimestamp($ticket['slaSolutionDate'] ?? null),
                'sla_response_time'      => $ticket['slaResponseTime'] ?? null,
                'sla_solution_time'      => $ticket['slaSolutionTime'] ?? null,
                'portal_synced_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK PORTAL] Erro ao salvar ticket', [
                'error' => $e->getMessage(),
                'id'    => $ticket['id'] ?? null,
            ]);
        }
    }

    private function resolveUserIdForPortal(array $ticket): ?int
    {
        $email = $ticket['owner']['email'] ?? null;
        if (!$email) return null;
        return User::where('email', strtolower(trim($email)))->value('id');
    }

    private function resolveCustomerIdForPortal(array $ticket): ?int
    {
        $clients = $ticket['clients'] ?? [];
        if (empty($clients)) return null;

        $target = null;
        foreach ($clients as $c) {
            if (strpos($c['email'] ?? '', '@erpserv') === false && !empty($c['email'])) {
                $target = $c;
                break;
            }
        }
        $target = $target ?? $clients[0];

        $org = $target['organization'] ?? null;

        // 1. Tenta por CNPJ — chave única confiável entre Movidesk e Minutor
        $cpfCnpj = preg_replace('/[^0-9]/', '', $target['cpfCnpj'] ?? '');
        if (!$cpfCnpj && is_array($org)) {
            $cpfCnpj = preg_replace('/[^0-9]/', '', $org['cpfCnpj'] ?? '');
        }
        if (strlen($cpfCnpj) >= 11) {
            $id = Customer::where('cgc', $cpfCnpj)->value('id');
            if ($id) return $id;
        }

        // 2. Fallback: nome da organização
        $orgName = is_array($org) ? ($org['businessName'] ?? null) : ($org ?: $target['businessName'] ?? null);
        if (!$orgName) return null;

        return Customer::where(function ($q) use ($orgName) {
            $q->where('name', $orgName)->orWhere('company_name', $orgName);
        })->value('id');
    }

    private function parseTimestamp(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractSolicitante(array $ticket): ?array
    {
        $clients = $ticket['clients'] ?? [];
        if (empty($clients)) return null;

        // 1. Preferir cliente com domínio diferente de @erpserv
        $target = null;
        foreach ($clients as $c) {
            if (strpos($c['email'] ?? '', '@erpserv') === false && !empty($c['email'])) {
                $target = $c;
                break;
            }
        }

        // 2. Se todos forem @erpserv, preferir pessoa (tem organization preenchida)
        if (!$target) {
            foreach ($clients as $c) {
                if (!empty($c['organization'])) {
                    $target = $c;
                    break;
                }
            }
        }

        // 3. Fallback: primeiro da lista
        $target = $target ?? $clients[0];

        // organization pode ser string (nome) ou array (objeto expandido)
        $org = $target['organization'] ?? null;
        $orgName = is_array($org) ? ($org['businessName'] ?? null) : ($org ?: null);

        // CNPJ: tenta no cliente direto, depois no objeto organization expandido
        $cpfCnpj = preg_replace('/[^0-9]/', '', $target['cpfCnpj'] ?? '');
        if (!$cpfCnpj && is_array($org)) {
            $cpfCnpj = preg_replace('/[^0-9]/', '', $org['cpfCnpj'] ?? '');
        }

        return [
            'name'         => $target['businessName'] ?? null,
            'email'        => $target['email'] ?? null,
            'organization' => $orgName,
            'cpf_cnpj'     => $cpfCnpj ?: null,
        ];
    }

    private function extractResponsavel(array $ticket): ?array
    {
        $owner = $ticket['owner'] ?? null;
        if (!$owner) return null;
        return ['name' => $owner['businessName'] ?? null, 'email' => $owner['email'] ?? null];
    }

    private function extractNivel(array $ticket): ?string
    {
        foreach ($ticket['customFieldValues'] ?? [] as $field) {
            if (($field['customFieldId'] ?? null) == 13485) {
                return $field['items'][0]['customFieldItem'] ?? null;
            }
        }
        return null;
    }
}
