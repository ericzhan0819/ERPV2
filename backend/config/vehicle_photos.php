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
    // 這個上限不能只憑「一般手機拍照多少 MP」隨意猜（Codex adversarial review 指出
    // 40MP 太寬鬆，因為 GD 的像素緩衝區是 C 層原生記憶體配置，完全不受 PHP
    // memory_limit 限制，也不受這裡的檔案大小上限限制）。改成依實際跑過
    // VehiclePhotoImageProcessor::process() 完整流程（decode → clone 展示圖 →
    // scaleDown → 縮圖 → 兩次 toWebp 編碼）量測出的 RSS 峰值反推：
    //
    //   4MP      → 110.7MB RSS
    //   12MP     → 195.2MB RSS
    //   12.19MP  → 197.7MB RSS（4032x3024，iPhone 常見預設拍照解析度，直接實測，
    //                           而非用下面的線性擬合外推，避免「拿來當理由的例子
    //                           自己反而超過門檻」）
    //   24MP     → 335.0MB RSS
    //   40MP     → 522.9MB RSS（Codex 在自己環境量到約 553MB，同量級）
    //
    // 線性擬合約為「65MB 基底 + 每 MP 追加 11.5MB」。13MP（略高於 4032x3024 這個常見
    // 手機預設輸出解析度，留一點餘裕涵蓋 Android 常見的 12~13MP 直出照片）單檔尖峰約
    // 210MB，是在「留給小型內部系統單一 worker 合理記憶體預算」與「涵蓋一般手機直出
    // 照片」之間的折衷值。若之後要調高，必須先用同樣方法重新量測，並確認目標部署
    // 環境單一 worker 可承受的尖峰記憶體。
    'max_megapixels' => 13,

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
