<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCashAccountRequest;
use App\Http\Requests\UpdateCashAccountRequest;
use App\Http\Requests\UpdateCashAccountStatusRequest;
use App\Http\Resources\CashAccountResource;
use App\Models\CashAccount;
use App\Services\CashAccountService;
use App\Services\MoneyEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashAccountController extends Controller
{
    public function __construct(
        private readonly CashAccountService $cashAccountService,
        private readonly MoneyEntryService $moneyEntryService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return CashAccountResource::collection($this->cashAccountService->listAccounts());
    }

    /**
     * 供表單選單使用的最小欄位清單（不含 opening_balance/current_balance），
     * 讓 sales 也能在收訂金 / 收尾款 / 支出登記等流程選擇資金帳戶，
     * 但不會因此取得資金帳戶餘額。
     */
    public function options(): JsonResponse
    {
        $data = $this->cashAccountService->listAccounts()
            ->map(fn (CashAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'is_active' => $account->is_active,
            ]);

        return response()->json(['data' => $data]);
    }

    public function store(StoreCashAccountRequest $request): CashAccountResource
    {
        $account = $this->cashAccountService->createAccount($request->validated());

        return new CashAccountResource($account);
    }

    public function show(CashAccount $cashAccount): CashAccountResource
    {
        return new CashAccountResource($cashAccount);
    }

    public function update(UpdateCashAccountRequest $request, CashAccount $cashAccount): CashAccountResource
    {
        $account = $this->cashAccountService->updateAccount($cashAccount, $request->validated());

        return new CashAccountResource($account);
    }

    public function updateStatus(UpdateCashAccountStatusRequest $request, CashAccount $cashAccount): CashAccountResource
    {
        $account = $this->cashAccountService->setActive($cashAccount, $request->boolean('is_active'));

        return new CashAccountResource($account);
    }

    public function destroy(CashAccount $cashAccount): JsonResponse
    {
        $this->cashAccountService->deleteAccount($cashAccount);

        return response()->json(['message' => '資金帳戶已刪除']);
    }

    public function balances(): JsonResponse
    {
        $accounts = $this->cashAccountService->listAccounts();

        $data = $accounts->map(fn (CashAccount $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'opening_balance' => $account->opening_balance,
            'is_active' => $account->is_active,
            'current_balance' => $this->moneyEntryService->balanceForAccount($account),
        ]);

        return response()->json(['data' => $data]);
    }
}
