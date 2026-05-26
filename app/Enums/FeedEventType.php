<?php

namespace App\Enums;

enum FeedEventType: string
{
    case HourOverrun          = 'hour_overrun';
    case SlaBreach            = 'sla_breach';
    case TicketSpike          = 'ticket_spike';
    case ChurnRisk            = 'churn_risk';
    case ExpansionOpportunity = 'expansion_opportunity';
    case ProjectStalled       = 'project_stalled';
    case AiSuggestion         = 'ai_suggestion';
    case FinancialAlert       = 'financial_alert';
    case TrainingRecommended  = 'training_recommended';
    case ContractRenewal      = 'contract_renewal';
    case Custom               = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::HourOverrun          => 'Estouro de horas',
            self::SlaBreach            => 'SLA crítico',
            self::TicketSpike          => 'Aumento de tickets',
            self::ChurnRisk            => 'Risco de churn',
            self::ExpansionOpportunity => 'Oportunidade de expansão',
            self::ProjectStalled       => 'Projeto parado',
            self::AiSuggestion         => 'Sugestão da IA',
            self::FinancialAlert       => 'Alerta financeiro',
            self::TrainingRecommended  => 'Treinamento recomendado',
            self::ContractRenewal      => 'Renovação de contrato',
            self::Custom               => 'Personalizado',
        };
    }
}
