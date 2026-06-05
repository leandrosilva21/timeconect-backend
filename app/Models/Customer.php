<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'company_name',
        'cgc',
        'active',
        'executive_id',
        'code_prefix',
        'fechamento_email',
        'emails_administrativos',
        'secondary_cgcs',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
        'emails_administrativos' => 'array',
        'secondary_cgcs' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /** Lista de e-mails administrativos (fechamento + comunicados). Fallback p/ fechamento_email legado. */
    public function adminEmails(): array
    {
        $list = is_array($this->emails_administrativos) ? $this->emails_administrativos : [];
        $list = array_values(array_filter(array_map('trim', $list), fn ($e) => $e !== ''));
        if (!$list && !empty($this->fechamento_email)) {
            $list = collect(preg_split('/[,;\s]+/', (string) $this->fechamento_email))
                ->map(fn ($e) => trim($e))->filter()->values()->all();
        }
        return $list;
    }

    /** Define a lista e mantém o fechamento_email legado sincronizado (= 1º e-mail). */
    public function setAdminEmails(array $emails): void
    {
        $clean = collect($emails)->map(fn ($e) => trim((string) $e))
            ->filter(fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
        $this->emails_administrativos = $clean;
        // Legado: guarda TODOS separados por vírgula (a tela de fechamento lê este campo).
        $this->fechamento_email = $clean ? implode(', ', $clean) : null;
    }

    /**
     * Valida se o CGC é um CPF ou CNPJ válido
     */
    public function isValidCgc(): bool
    {
        $cgc = preg_replace('/[^0-9]/', '', $this->cgc);
        
        // Verifica se é CPF (11 dígitos)
        if (strlen($cgc) === 11) {
            return $this->isValidCpf($cgc);
        }
        
        // Verifica se é CNPJ (14 dígitos)
        if (strlen($cgc) === 14) {
            return $this->isValidCnpj($cgc);
        }
        
        return false;
    }

    /**
     * Valida CPF
     */
    private function isValidCpf(string $cpf): bool
    {
        // Elimina CPFs conhecidos como inválidos
        if (in_array($cpf, [
            '00000000000', '11111111111', '22222222222', '33333333333',
            '44444444444', '55555555555', '66666666666', '77777777777',
            '88888888888', '99999999999'
        ])) {
            return false;
        }

        // Calcula os dígitos verificadores
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida CNPJ
     */
    private function isValidCnpj(string $cnpj): bool
    {
        // Elimina CNPJs conhecidos como inválidos
        if (in_array($cnpj, [
            '00000000000000', '11111111111111', '22222222222222', '33333333333333',
            '44444444444444', '55555555555555', '66666666666666', '77777777777777',
            '88888888888888', '99999999999999'
        ])) {
            return false;
        }

        // Valida primeiro dígito verificador
        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;
        
        if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) {
            return false;
        }

        // Valida segundo dígito verificador
        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;

        return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
    }

    /**
     * Formata o CGC para exibição
     */
    public function getFormattedCgcAttribute(): string
    {
        $cgc = preg_replace('/[^0-9]/', '', $this->cgc);
        
        if (strlen($cgc) === 11) {
            // Formata como CPF: 000.000.000-00
            return substr($cgc, 0, 3) . '.' . 
                   substr($cgc, 3, 3) . '.' . 
                   substr($cgc, 6, 3) . '-' . 
                   substr($cgc, 9, 2);
        }
        
        if (strlen($cgc) === 14) {
            // Formata como CNPJ: 00.000.000/0000-00
            return substr($cgc, 0, 2) . '.' . 
                   substr($cgc, 2, 3) . '.' . 
                   substr($cgc, 5, 3) . '/' . 
                   substr($cgc, 8, 4) . '-' . 
                   substr($cgc, 12, 2);
        }
        
        return $cgc;
    }

    /**
     * Retorna o tipo do documento (CPF ou CNPJ)
     */
    public function getCgcTypeAttribute(): string
    {
        $cgc = preg_replace('/[^0-9]/', '', $this->cgc);
        
        if (strlen($cgc) === 11) {
            return 'CPF';
        }
        
        if (strlen($cgc) === 14) {
            return 'CNPJ';
        }
        
        return 'Inválido';
    }

    /**
     * Relacionamento com projetos
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Relacionamento com usuários do cliente
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Relacionamento com o executivo responsável
     */
    public function executive(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executive_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }
} 