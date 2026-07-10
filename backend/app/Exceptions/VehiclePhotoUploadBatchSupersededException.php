<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * VehiclePhotoService::uploadPhotos() 內部使用：這次請求對
 * vehicle_photo_upload_batches 的 claim_token fencing 檢查失敗，代表這批上傳已經
 * 被另一個帶著同一把 idempotency_key、租約過期後續傳認領的請求取代。捕捉端不可
 * 再對這個 batch 做任何 photo_ids／租約寫入，只能把已經是這次呼叫自己建立的照片
 * 清乾淨，並把明確的錯誤往外拋（見 uploadPhotos() 的處理）。
 */
class VehiclePhotoUploadBatchSupersededException extends RuntimeException {}
