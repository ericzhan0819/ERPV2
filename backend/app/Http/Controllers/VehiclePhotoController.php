<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderVehiclePhotosRequest;
use App\Http\Requests\StoreVehiclePhotoRequest;
use App\Http\Resources\VehiclePhotoResource;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Services\VehiclePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehiclePhotoController extends Controller
{
    public function __construct(
        private readonly VehiclePhotoService $vehiclePhotoService,
    ) {}

    public function index(Vehicle $vehicle): AnonymousResourceCollection
    {
        return VehiclePhotoResource::collection($this->vehiclePhotoService->listPhotos($vehicle));
    }

    public function store(StoreVehiclePhotoRequest $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        $photos = $this->vehiclePhotoService->uploadPhotos(
            $vehicle,
            $request->user(),
            $request->file('photos'),
        );

        return VehiclePhotoResource::collection($photos);
    }

    public function reorder(ReorderVehiclePhotosRequest $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        $photos = $this->vehiclePhotoService->reorder($vehicle, $request->validated()['photo_ids']);

        return VehiclePhotoResource::collection($photos);
    }

    public function setCover(Vehicle $vehicle, VehiclePhoto $photo): VehiclePhotoResource
    {
        $photo = $this->vehiclePhotoService->setCover($vehicle, $photo);

        return new VehiclePhotoResource($photo);
    }

    public function destroy(Vehicle $vehicle, VehiclePhoto $photo): JsonResponse
    {
        $this->vehiclePhotoService->deletePhoto($vehicle, $photo);

        return response()->json(['message' => '照片已刪除']);
    }
}
