<?php

namespace App\Http\Resources;

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
        // sales 只能看到自己建立的申請，或訂金/尾款/退款等銷售收款安全紀錄的金額；
        // 資金帳戶一律只給 admin/manager，避免洩漏其他人成本明細或帳戶配置。
        $canSeeAmount = $canSeeFull || (($user?->isSales() ?? false) && ($isOwner || $isSalesSafeCategory));

        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'direction' => $this->direction,
            'category' => $this->category,
            'amount' => $this->when($canSeeAmount, $this->amount),
            'vehicle_id' => $this->vehicle_id,
            'cash_account_id' => $this->when($canSeeFull, $this->cash_account_id),
            'counterparty_name' => $this->counterparty_name,
            'description' => $this->description,
            'approval_status' => $this->approval_status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'stock_no' => $this->vehicle->stock_no,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
            ] : null),
            'cash_account' => $this->when($canSeeFull, fn () => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'type' => $this->cashAccount->type,
            ] : null)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
