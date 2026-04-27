<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectSequence;
use Illuminate\Support\Facades\DB;

class ProjectCodeService
{
    /**
     * Gera o código para um projeto pai.
     * Usa lockForUpdate() para evitar race conditions.
     *
     * Formato: XXX000-YY
     */
    public function generateParentCode(Customer $customer): array
    {
        if (!$customer->code_prefix) {
            throw new \RuntimeException("Cliente '{$customer->name}' não possui prefixo de código (code_prefix).");
        }

        $sequence = null;
        $code     = null;

        DB::transaction(function () use ($customer, &$sequence, &$code) {
            $seq = ProjectSequence::where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (!$seq) {
                $seq = ProjectSequence::create([
                    'customer_id'   => $customer->id,
                    'last_sequence' => 0,
                ]);
            }

            $year   = now()->format('y');
            $prefix = strtoupper($customer->code_prefix);

            do {
                $seq->last_sequence += 1;
                $padded = str_pad($seq->last_sequence, 3, '0', STR_PAD_LEFT);
                $code   = $prefix . $padded . '-' . $year;
            } while (Project::withTrashed()->where('code', $code)->exists());

            $seq->save();
            $sequence = $seq->last_sequence;
        });

        return [
            'code'          => $code,
            'proj_sequence' => $sequence,
            'proj_year'     => now()->format('y'),
            'child_sequence' => null,
            'is_manual_code' => false,
        ];
    }

    /**
     * Gera o código para um projeto filho.
     * Incrementa child_sequence baseado no maior existente do pai.
     *
     * Formato: XXX000-YY-ZZ
     */
    public function generateChildCode(Project $parent): array
    {
        $lastChild = Project::where('parent_project_id', $parent->id)
            ->whereNotNull('child_sequence')
            ->max('child_sequence');

        $childSeq = ($lastChild ?? 0) + 1;
        $padded   = str_pad($childSeq, 2, '0', STR_PAD_LEFT);
        $code     = $parent->code . '-' . $padded;

        return [
            'code'           => $code,
            'proj_sequence'  => $parent->proj_sequence,
            'proj_year'      => $parent->proj_year,
            'child_sequence' => $childSeq,
            'is_manual_code' => false,
        ];
    }

    /**
     * Resolve o código a usar no store:
     *  - se veio código manual → valida unicidade e retorna como manual
     *  - se não veio → auto-gera
     */
    public function resolveForStore(?string $requestCode, Customer $customer, ?Project $parent): array
    {
        if ($requestCode && !Project::withTrashed()->where('code', $requestCode)->exists()) {
            return [
                'code'           => $requestCode,
                'proj_sequence'  => null,
                'proj_year'      => null,
                'child_sequence' => null,
                'is_manual_code' => true,
            ];
        }

        if ($parent) {
            return $this->generateChildCode($parent);
        }

        return $this->generateParentCode($customer);
    }
}
