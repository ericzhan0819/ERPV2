<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | v1.2 使用 Laravel 內建 public disk（storage/app/public，需先執行
    | `php artisan storage:link`）。DB 只記 disk + path，不記本機絕對路徑，
    | 未來若要搬到 Cloudflare R2，只需新增 disk 設定並改變這裡的值。
    |
    */

    'disk' => env('VEHICLE_PHOTOS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | 上傳驗證限制
    |--------------------------------------------------------------------------
    */

    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    'max_file_size_kb' => 8192, // 單張最大 8MB

    // 檔案大小在 8MB 以內仍可能宣告超大像素尺寸（例如極端壓縮的 JPEG/PNG），這類圖片
    // 全解碼進記憶體的成本與像素數成正比，屬於 decompression bomb 風險，因此在解碼前
    // 先用 getimagesize() 讀檔頭檢查像素數，超過就直接拒絕，避免真的呼叫
    // ImageManager::read()。
    //
    // 這個上限不能只憑「一般手機拍照多少 MP」隨意猜（Codex adversarial review 第二輪
    // 指出 40MP 太寬鬆，因為 GD 的像素緩衝區是 C 層原生記憶體配置，完全不受 PHP
    // memory_limit 限制，也不受這裡的檔案大小上限限制）。也不能只挑一個「聽起來安全」
    // 的低門檻就下修，因為那樣反而會擋下合法的高畫素照片（第三輪 review 指出：13MP
    // 連常見手機/相機的正常直出解析度都會擋，這個功能本來就是設計來把大圖縮小存放，
    // 不是設計來拒絕大圖）。
    //
    // 依實際跑過 VehiclePhotoImageProcessor::process() 完整流程（decode → clone 展示圖
    // → scaleDown → 縮圖 → 兩次 toWebp 編碼）量測出的 RSS 峰值：
    //
    //   4MP      → 110.7MB RSS
    //   12MP     → 195.2MB RSS
    //   12.19MP  → 197.7MB RSS（4032x3024，iPhone 常見預設拍照解析度，直接實測）
    //   24MP     → 335.0MB RSS
    //   40MP     → 522.9MB RSS（Codex 在自己環境量到約 553MB，同量級）
    //
    // 這個上傳端點只開放 admin / manager 使用（見企劃書_v1.2.md 第 7 節權限表），
    // 真正會觸發上傳的使用者數量本來就很少。但「使用者少所以不會同時上傳」只是假設，
    // 不是程式碼裡真的有的保證（Codex adversarial review 第四輪指出：這個像素上限
    // 擋得住單一請求離譜到不合理的圖片，擋不住好幾個 admin/manager 剛好同時上傳、
    // 各自都在門檻內卻疊加起來一樣把 worker 記憶體吃光的並發情境）。因此下面
    // `processing_lock_*` 用全域 lock 把「同時間只解碼/編碼一張圖」變成強制保證，
    // 這個像素上限只需要負責擋住「單一請求」的尖峰記憶體，不用再兼顧並發疊加。
    //
    // 預設值選 24MP：涵蓋目前主流手機/相機的一般直出解析度，單檔尖峰約 335MB，對一台
    // 有數百 MB 到 1GB+ 可用記憶體的內部系統伺服器是可接受的量級。可用
    // VEHICLE_PHOTOS_MAX_MEGAPIXELS env 依實際部署環境的記憶體預算調整（調整前應先用
    // 同樣的量測方法重新量測，不要只憑感覺調數字）。
    'max_megapixels' => (float) env('VEHICLE_PHOTOS_MAX_MEGAPIXELS', 24),

    // VehiclePhotoImageProcessor 用這把全域 lock 確保任何時刻最多只有一個請求在做
    // decode/encode（真正吃記憶體的部分），避免多個 admin/manager 同時上傳時，各自
    // 合法的解碼疊加成好幾倍尖峰記憶體。lock 依賴 CACHE_STORE 支援 atomic lock
    // （本專案預設 database driver 已支援，見 config/cache.php）。
    'processing_lock_ttl_seconds' => (int) env('VEHICLE_PHOTOS_LOCK_TTL_SECONDS', 60),

    // 等不到 lock 就直接回錯誤，不讓 HTTP worker 無限期卡住等待。
    'processing_lock_wait_seconds' => (int) env('VEHICLE_PHOTOS_LOCK_WAIT_SECONDS', 30),

    'max_files_per_upload' => 20, // 一次上傳最多 20 張

    'max_photos_per_vehicle' => 60, // 每台車最多 60 張

    /*
    |--------------------------------------------------------------------------
    | 圖片重新編碼尺寸 / 品質
    |--------------------------------------------------------------------------
    |
    | 上傳的圖片一律重新編碼為 webp，藉此移除手機拍攝留下的 EXIF / GPS 資訊，
    | 並避免公開展示圖與縮圖直接使用過大的原圖。
    |
    */

    'display' => [
        'max_width' => 1920,
        'max_height' => 1920,
        'quality' => 82,
    ],

    'thumbnail' => [
        'max_width' => 400,
        'max_height' => 400,
        'quality' => 78,
    ],

];
