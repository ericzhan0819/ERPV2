<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicIndexVehicleRequest;
use App\Http\Resources\PublicVehicleListResource;
use App\Http\Resources\PublicVehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 官網公開唯讀車輛查詢。刻意不使用 implicit route model binding：
 * 直接自行查詢並在找不到 / 非上架狀態時丟出統一的 NotFoundHttpException，
 * 避免預設的 ModelNotFoundException 訊息洩漏內部 model 類別名稱
 * （企劃書_v1.2.md 第 10.2 節、PLAN_v1.2.md 第 4.3 節）。
 */
class PublicVehicleController extends Controller
{
    public function index(PublicIndexVehicleRequest $request): AnonymousResourceCollection
    {
        // 只 eager load 封面照，不載入完整相簿：未登入使用者可用 per_page=100
        // 的單一請求換取每台車最多 60 張照片的完整序列化與 DB 讀取，形成低成本
        // 的匿名放大攻擊面（Codex adversarial review 指出）。列表頁只需要封面，
        // 完整 photos 陣列留給 show() 詳情頁。
        $vehicles = Vehicle::query()
            ->where('status', 'listed')
            ->with(['photos' => fn ($query) => $query->where('is_cover', true)])
            ->orderByDesc('listing_date')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page') ?? 20);

        return PublicVehicleListResource::collection($vehicles);
    }

    public function show(int $id): PublicVehicleResource
    {
        $vehicle = Vehicle::query()
            ->where('status', 'listed')
            ->with('photos')
            ->find($id);

        if ($vehicle === null) {
            throw new NotFoundHttpException('Vehicle not found');
        }

        return new PublicVehicleResource($vehicle);
    }
}
