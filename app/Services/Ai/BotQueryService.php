<?php

namespace App\Services\Ai;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Tools\MinutorToolRegistry;
use App\Services\Inbox\InboxService;
use Illuminate\Support\Facades\Log;

/**
 * BotQueryService — invocado quando o usuário menciona @bot em uma conversa.
 * Persiste a mensagem do user, chama IA com tool-use, executa as tools
 * iterativamente até a IA dar a resposta final, e posta a resposta no chat.
 */
class BotQueryService
{
    public const MAX_ITERATIONS = 5;

    public function __construct(
        protected AnthropicProvider $ai,
        protected MinutorToolRegistry $tools,
        protected InboxService $inbox,
    ) {
    }

    /**
     * @return array{user_message:Message, bot_message:Message, tools_called:array<string>}
     */
    public function ask(User $user, Conversation $conversation, string $question): array
    {
        // 1) Persiste mensagem do user (texto integral, com o @bot)
        $userMsg = $this->inbox->persist($conversation, [
            'sender_user_id' => $user->id,
            'type'           => MessageType::User,
            'body'           => $question,
            'metadata'       => ['mentions_bot' => true],
        ]);

        // 2) Posta placeholder do BOT (substituído ao fim)
        $placeholder = $this->inbox->persist($conversation, [
            'sender_user_id' => null,
            'type'           => MessageType::Bot,
            'body'           => '🔍 Consultando o Minutor…',
            'metadata'       => ['pending' => true, 'user_message_id' => $userMsg->id],
        ]);

        try {
            [$reply, $toolsCalled] = $this->runToolLoop($user, $question);
        } catch (\Throwable $e) {
            Log::error('[BotQueryService] erro no tool loop', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            $reply = "❌ Erro ao consultar: " . $e->getMessage();
            $toolsCalled = [];
        }

        // 3) Atualiza o placeholder com a resposta final
        $placeholder->update([
            'body'     => $reply,
            'metadata' => [
                'pending'         => false,
                'user_message_id' => $userMsg->id,
                'tools_called'    => $toolsCalled,
                'provider'        => $this->ai->name(),
            ],
        ]);

        return [
            'user_message' => $userMsg->refresh(),
            'bot_message'  => $placeholder->refresh(),
            'tools_called' => $toolsCalled,
        ];
    }

    /**
     * Loop iterativo: chama IA, executa tools, devolve resultados, repete até end_turn.
     * @return array{0:string,1:array<string>}  [textoFinal, listaToolsChamadas]
     */
    protected function runToolLoop(User $user, string $question): array
    {
        $system = $this->systemPrompt($user);
        $tools = $this->tools->definitions();
        $messages = [
            ['role' => 'user', 'content' => $question],
        ];

        $toolsCalled = [];
        $finalText = '';

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $resp = $this->ai->callWithTools($system, $messages, $tools, ['max_tokens' => 2048]);
            $stop = $resp['stop_reason'] ?? null;
            $content = $resp['content'] ?? [];

            // Coleta texto desta resposta
            foreach ($content as $b) {
                if (($b['type'] ?? null) === 'text') {
                    $finalText = trim($b['text'] ?? '');
                }
            }

            if ($stop !== 'tool_use') {
                // Resposta final (end_turn ou max_tokens)
                return [$finalText, $toolsCalled];
            }

            // Adiciona resposta do assistant com os tool_use blocks
            $messages[] = ['role' => 'assistant', 'content' => $content];

            // Executa cada tool_use e devolve tool_result
            $toolResults = [];
            foreach ($content as $b) {
                if (($b['type'] ?? null) !== 'tool_use') continue;
                $name = $b['name'] ?? '';
                $input = $b['input'] ?? [];
                $toolsCalled[] = $name;

                $result = $this->tools->execute($name, $input);
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $b['id'] ?? '',
                    'content'     => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return [$finalText ?: '⚠️ Limite de iterações atingido sem resposta final.', $toolsCalled];
    }

    protected function systemPrompt(User $user): string
    {
        return <<<SYS
        Você é o BOT Minutor, assistente operacional da ERPSERV (consultoria TOTVS Protheus).
        Atua dentro do sistema Minutor (PSA: projetos, contratos, banco de horas, sustentação).

        Usuário atual: {$user->name} (id={$user->id}).

        Princípios:
        - Use APENAS as tools fornecidas para consultar dados. Não invente números.
        - Para qualquer pergunta sobre um cliente, comece por search_customer.
        - Seja conciso e operacional: bullets, números, e ação. Evite parágrafos longos.
        - Responda em português do Brasil.
        - Use unidades claras: horas (h), R\$ para valores.
        - Se a pergunta exigir dado fora do escopo das tools, diga abertamente.
        - Formate resposta final em markdown (bullets, negrito em números-chave).

        Formato sugerido pra "como está o cliente X":
        **Cliente:** Nome
        - X projetos (Y ativos, Z encerrados)
        - Horas: vendidas vs consumidas vs saldo
        - Contratos: tipos + valores
        - Próxima ação sugerida (se houver risco visível)
        SYS;
    }
}
