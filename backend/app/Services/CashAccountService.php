<?php

namespace App\Services;

use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CashAccountService
{
    public function listAccounts(): Collection
    {
        return CashAccount::query()->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAccount(array $data): CashAccount
    {
        $account = new CashAccount([
            'name' => $data['name'],
            'type' => $data['type'],
            'opening_balance' => (int) $data['opening_balance'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $account->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(CashAccount $account, array $data): CashAccount
    {
        $account->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'opening_balance' => (int) $data['opening_balance'],
            'is_active' => $data['is_active'],
        ]);
        $account->save();

        return $account;
    }

    public function deleteAccount(CashAccount $account): void
    {
        if ($account->moneyEntries()->exists()) {
            throw ValidationException::withMessages([
                'cash_account_id' => ['此帳戶已有收支紀錄，不得刪除，請改為停用'],
            ]);
        }

        $account->delete();
    }
}
