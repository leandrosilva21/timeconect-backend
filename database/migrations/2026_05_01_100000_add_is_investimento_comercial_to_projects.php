<?php

use App\Models\ContractType;
use App\Models\Project;
use App\Models\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_investimento_comercial')->default(false)->after('contract_request_id');
        });

        $this->createRetroactiveProjects();
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_investimento_comercial');
        });
    }

    private function createRetroactiveProjects(): void
    {
        $serviceTypeId  = ServiceType::where('code', 'projeto')->value('id');
        $contractTypeId = ContractType::where('code', 'on_demand')->value('id');

        if (!$serviceTypeId || !$contractTypeId) {
            return;
        }

        $customerIds = \App\Models\Customer::pluck('id');

        foreach ($customerIds as $customerId) {
            $alreadyExists = Project::withTrashed()
                ->where('customer_id', $customerId)
                ->where('is_investimento_comercial', true)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Project::create([
                'name'                      => 'Investimento Comercial',
                'code'                      => 'IC-' . $customerId,
                'customer_id'               => $customerId,
                'service_type_id'           => $serviceTypeId,
                'contract_type_id'          => $contractTypeId,
                'status'                    => 'started',
                'is_investimento_comercial' => true,
                'is_manual_code'            => true,
            ]);
        }
    }
};
