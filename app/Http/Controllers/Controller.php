<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="Time Conect API",
 *      description="Sistema de apontamento de horas e despesas",
 *      @OA\Contact(
 *          email="admin@timeconect.com"
 *      ),
 *      @OA\License(
 *          name="MIT",
 *          url="https://opensource.org/licenses/MIT"
 *      )
 * )
 * 
 * @OA\Server(
 *      url=L5_SWAGGER_CONST_HOST,
 *      description="Servidor de Desenvolvimento"
 * )
 * 
 * @OA\SecurityScheme(
 *      securityScheme="bearerAuth",
 *      type="http",
 *      scheme="bearer",
 *      bearerFormat="JWT",
 *      description="Token de autenticação Bearer obtido através do endpoint de login"
 * )
 * 
 * @OA\Tag(
 *     name="Autenticação",
 *     description="Endpoints para login, logout e verificação de tokens"
 * )
 * 
 * @OA\Tag(
 *     name="Usuário",
 *     description="Endpoints para gerenciamento de dados do usuário"
 * )
 * 
 * @OA\Tag(
 *     name="Recuperação de Senha",
 *     description="Endpoints para reset e recuperação de senhas"
 * )
 * 
 * @OA\Tag(
 *     name="Sistema",
 *     description="Endpoints de sistema e health check"
 * )
 */
abstract class Controller
{
    /**
     * Resolve o intervalo de datas para endpoints de indicadores.
     * Suporta seleção de um único mês (month+year) ou faixa de meses
     * (start_month+start_year → month+year).
     *
     * Retorna [startDate, endDate] em formato 'Y-m-d', ou null se nenhum
     * parâmetro de período for fornecido.
     */
    protected function resolveIndicatorDateRange(\Illuminate\Http\Request $request): ?array
    {
        $month = $request->get('month');
        $year  = $request->get('year');

        if (!$month && !$year) {
            return null;
        }

        $endYear  = (int) ($year  ?: date('Y'));
        $endMonth = (int) ($month ?: 12);

        $startMonth = $request->filled('start_month') ? (int) $request->get('start_month') : $endMonth;
        $startYear  = $request->filled('start_year')  ? (int) $request->get('start_year')  : $endYear;

        $startDate = \Carbon\Carbon::create($startYear, $startMonth, 1)->startOfMonth()->format('Y-m-d');
        $endDate   = \Carbon\Carbon::create($endYear,   $endMonth,   1)->endOfMonth()->format('Y-m-d');

        return [$startDate, $endDate];
    }
}
