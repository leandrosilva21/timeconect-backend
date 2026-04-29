<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MovideskTicket;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
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
            $response = Http::get("{$this->baseUrl()}/tickets", [
                'token'   => $this->token(),
                'id'      => $ticketId,
                '$expand' => 'clients($expand=organization),owner,actions($expand=timeAppointments)',
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
     * Processa TODAS as ações do ticket (modo sync/cron).
     * Retorna número de timesheets criados.
     */
    public function processTicket(array $ticketDetails): int
    {
        $this->saveTicketData($ticketDetails);

        $actions = $ticketDetails['actions'] ?? [];
        $created = 0;

        foreach ($actions as $action) {
            foreach ($action['timeAppointments'] ?? [] as $appointment) {
                if ($this->processAppointment($ticketDetails, $action, $appointment)) {
                    $created++;
                }
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

    private function processAppointment(array $ticket, array $action, array $appointment): bool
    {
        $appointmentId = $appointment['id'] ?? null;

        // Deduplicação por movidesk_appointment_id
        if ($appointmentId && Timesheet::where('movidesk_appointment_id', $appointmentId)->exists()) {
            Log::info('⏭️ [MOVIDESK] Apontamento já importado', [
                'movidesk_appointment_id' => $appointmentId,
            ]);
            return false;
        }

        try {
            $effortHours   = $this->extractEffortHours($appointment);
            $effortMinutes = $effortHours ? $this->calculateEffortMinutes($effortHours) : 0;

            if ($effortMinutes < 5) {
                Log::info('⏭️ [MOVIDESK] Apontamento ignorado (duração < 5 min)', [
                    'effort_minutes'          => $effortMinutes,
                    'movidesk_appointment_id' => $appointmentId,
                ]);
                return false;
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

        if ($email) {
            $user = User::where('email', $email)->where('enabled', true)->first();
            if ($user) return $user->id;
            Log::warning('⚠️ [MOVIDESK] Usuário não encontrado ou inativo — usando usuário padrão', ['email' => $email]);
        } else {
            Log::warning('⚠️ [MOVIDESK] Email ausente na ação — usando usuário padrão', ['action_id' => $action['id'] ?? null]);
        }

        return $this->getDefaultUserId();
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

        $orgName = $target['organization']['businessName']
            ?? $target['businessName']
            ?? null;

        if (!$orgName) {
            Log::warning('⚠️ [MOVIDESK] businessName não encontrado', ['client' => $target]);
            return $this->getDefaultCustomerId();
        }

        $customer = Customer::where(function ($q) use ($orgName) {
            $q->where('name', $orgName)->orWhere('company_name', $orgName);
        })->where('active', true)->first();

        if (!$customer) {
            Log::warning('⚠️ [MOVIDESK] Cliente não encontrado no sistema', ['organization' => $orgName]);
            return $this->getDefaultCustomerId();
        }

        return $customer->id;
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
            $serviceType = ServiceType::where('code', 'sustentacao')
                ->orWhere('name', 'Sustentação')
                ->first();

            if ($serviceType) {
                // Busca o projeto sem filtro de status para detectar projetos inativos
                $project = Project::where('customer_id', $customerId)
                    ->where('service_type_id', $serviceType->id)
                    ->first();

                if ($project) {
                    if ($project->isActive()) {
                        return $project->id;
                    }

                    // Projeto encontrado mas inativo → usa projeto padrão
                    Log::warning('⚠️ [MOVIDESK] Projeto do cliente está inativo — usando projeto padrão', [
                        'project_id'   => $project->id,
                        'project_name' => $project->name,
                        'status'       => $project->status,
                        'customer_id'  => $customerId,
                    ]);
                }
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
            $timesheet->origin                  = 'webhook';
            $timesheet->status                  = $conflict
                ? Timesheet::STATUS_CONFLICTED
                : Timesheet::STATUS_PENDING;
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
        return User::where('email', $email)->value('id');
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
