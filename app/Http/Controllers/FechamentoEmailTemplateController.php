<?php

namespace App\Http\Controllers;

use App\Models\FechamentoEmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FechamentoEmailTemplateController extends Controller
{
    private function authorizeAdmin(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if (!$u->isAdmin() && !$u->isAdministrativo()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }
        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $items = FechamentoEmailTemplate::query()
            ->orderBy('categoria')
            ->orderBy('contract_type')
            ->orderByDesc('active')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $this->validateData($request);
        $tpl  = FechamentoEmailTemplate::create($data);
        if ($tpl->active) {
            $this->deactivateSiblings($tpl);
        }

        return response()->json(['data' => $tpl], 201);
    }

    public function update(Request $request, FechamentoEmailTemplate $template): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $this->validateData($request, $template);
        $template->update($data);
        if ($template->active) {
            $this->deactivateSiblings($template);
        }

        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, FechamentoEmailTemplate $template): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $template->delete();
        return response()->json(['success' => true]);
    }

    private function validateData(Request $request, ?FechamentoEmailTemplate $existing = null): array
    {
        $data = $request->validate([
            'categoria'     => ['required', Rule::in(FechamentoEmailTemplate::CATEGORIAS)],
            'contract_type' => ['nullable', Rule::in(FechamentoEmailTemplate::CONTRACT_TYPES)],
            'nome'          => ['nullable', 'string', 'max:255'],
            'subject'       => ['required', 'string', 'max:255'],
            'body'          => ['required', 'string'],
            'active'        => ['nullable', 'boolean'],
        ]);

        $categoria = $data['categoria'] ?? $existing?->categoria;
        // cliente é único (sem tipo de contrato); consultor/parceiro exigem o tipo.
        if ($categoria === 'cliente') {
            $data['contract_type'] = null;
        } elseif (empty($data['contract_type'])) {
            abort(response()->json([
                'message' => 'Selecione o tipo de contrato (cooperado/clt/pj).',
                'errors'  => ['contract_type' => ['Obrigatório para consultor/parceiro.']],
            ], 422));
        }
        $data['active'] = (bool) ($data['active'] ?? false);

        return $data;
    }

    /** Garante só 1 ativo por (categoria, contract_type). */
    private function deactivateSiblings(FechamentoEmailTemplate $tpl): void
    {
        FechamentoEmailTemplate::where('categoria', $tpl->categoria)
            ->where(function ($q) use ($tpl) {
                $tpl->contract_type === null
                    ? $q->whereNull('contract_type')
                    : $q->where('contract_type', $tpl->contract_type);
            })
            ->where('id', '!=', $tpl->id)
            ->update(['active' => false]);
    }
}
