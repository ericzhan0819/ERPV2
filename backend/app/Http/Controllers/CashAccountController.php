<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CashAccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(
            CashAccount::query()->orderBy('id')->get(['id', 'name', 'type', 'is_active'])
        );
    }
}
