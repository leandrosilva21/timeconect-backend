<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Ai\BotQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BotQueryController extends Controller
{
    /** Cooldown anti-abuse entre @bot queries do mesmo user (segundos). */
    public const COOLDOWN_SECONDS = 30;

    public function __construct(protected BotQueryService $botQuery)
    {
    }

    /**
     * POST /api/v1/conversations/{id}/bot-query
     * body: { "question": "como estão os projetos da VEDAMOTORS?" }
     */
    public function ask(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'question' => 'required|string|min:2|max:2000',
        ]);

        // User precisa ser participante da conversa
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        // Cooldown
        $cacheKey = "bot_query_cooldown:user_{$user->id}";
        if (Cache::has($cacheKey)) {
            return response()->json([
                'message' => 'Aguarde alguns segundos antes de perguntar novamente ao BOT.',
                'retry_after_seconds' => Cache::get($cacheKey),
            ], 429);
        }
        Cache::put($cacheKey, self::COOLDOWN_SECONDS, now()->addSeconds(self::COOLDOWN_SECONDS));

        $result = $this->botQuery->ask($user, $conv, $data['question']);

        $result['user_message']->load('sender:id,name,profile_photo');
        $result['bot_message']->load('sender:id,name,profile_photo');

        return response()->json([
            'user_message' => (new MessageResource($result['user_message']))->toArray($request),
            'bot_message'  => (new MessageResource($result['bot_message']))->toArray($request),
            'tools_called' => $result['tools_called'],
        ]);
    }
}
