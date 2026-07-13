<?php

namespace App\Http\Resources;

use App\Models\MoneyEntry;
use App\Services\MoneyEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoneyEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeFull = $user?->canViewFinancials() ?? false;
        $isOwner = $user !== null && (int) $this->created_by === $user->id;
        $isSalesSafeCategory = in_array($this->category, MoneyEntryService::SALES_SAFE_COLLECTION_CATEGORIES, true);
        $isSalarySettlement = $this->source_type === MoneyEntry::SOURCE_SALARY_SETTLEMENT;
        $canSeeSalaryDetails = ! $isSalarySettlement || ($user?->isAdmin() ?? false);
        $canSeeCashAccount = $canSeeFull && $canSeeSalaryDetails;
        // sales 只能看到自己建立的申請，或訂金/尾款/退款等銷售收款安全紀錄的金額；
        // 資金帳戶一律只給 admin/manager，避免洩漏其他人成本明細或帳戶配置。
        $canSeeAmount = $canSeeSalaryDetails
            && ($canSeeFull || (($user?->isSales() ?? false) && ($isOwner || $isSalesSafeCategory)));

        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'direction' => $this->direction,
            'category' => $this->category,
            'amount' => $this->when($canSeeAmount, $this->amount),
            'vehicle_id' => $this->vehicle_id,
            'cash_account_id' => $this->when($canSeeCashAccount, $this->cash_account_id),
            'counterparty_name' => $this->when($canSeeSalaryDetails, $this->counterparty_name),
            'description' => $this->when($canSeeSalaryDetails, $this->description),
            'approval_status' => $this->when($canSeeSalaryDetails, $this->approval_status),
            'approved_by' => $this->when($canSeeSalaryDetails, $this->approved_by),
            'approved_at' => $this->when($canSeeSalaryDetails, $this->approved_at?->toIso8601String()),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'stock_no' => $this->vehicle->stock_no,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
            ] : null),
            'cash_account' => $this->when($canSeeCashAccount, fn () => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'type' => $this->cashAccount->type,
            ] : null)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
