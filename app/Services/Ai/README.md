# AI Providers — `app/Services/Ai/`

Abstração de provedores de IA usada pelo `OperationalFeed` e por engines futuras (Health, CS).

## Como funciona

`AiProvider` é uma interface (`Contracts/AiProvider.php`) com:

```php
public function generateInsight(string $prompt, array $context = [], array $options = []): string;
public function name(): string;
```

`AiServiceProvider` (em `app/Providers/AiServiceProvider.php`) liga essa interface a um driver definido em
`config('services.ai.default_provider')`. Drivers atuais:

- `anthropic` (default) → `AnthropicProvider`
- `openai` → `OpenAiProvider`

Trocar provider é só `AI_DEFAULT_PROVIDER=openai` no `.env`. Nenhum código de domínio precisa mudar.

## Vars de ambiente

```
AI_DEFAULT_PROVIDER=anthropic        # anthropic | openai
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-6
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
```

## Consumo correto

Não chame `AnthropicProvider`/`OpenAiProvider` direto. Use `AiInsightService` — ele já trata erros,
registra o resultado no `OperationalFeed` e aplica `dedupe_key`.

```php
$service = app(\App\Services\Ai\AiInsightService::class);
$feed = $service->generateForCustomer($customer, ['tickets' => 8, 'saldo_horas' => -10]);
```

## Adicionar um novo provider

1. Crie `app/Services/Ai/MeuProvider.php` implementando `AiProvider`.
2. Adicione um caso no `match` em `app/Providers/AiServiceProvider.php`.
3. Adicione bloco `'meu_provider' => [...]` em `config/services.php` e vars no `.env`.
