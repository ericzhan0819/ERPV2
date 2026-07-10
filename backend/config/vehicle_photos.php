<?php

// max_megapixels 提前算出來，讓下面 processing_lock_ttl_seconds 的預設值可以依實際
// 上限比例縮放，不會變成一個跟像素上限脫鉤、之後改了上限卻忘記一起調整的固定數字。
$maxMegapixels = (float) env('VEHICLE_PHOTOS_MAX_MEGAPIXELS', 24);

// 同樣提前算出來，讓下面 upload_batch_pending_ttl_seconds 的預設值可以依實際單檔
// 處理 TTL × 單次最多檔案數等比例縮放（見該欄位註解）。
$processingLockTtlSeconds = (int) env('VEHICLE_PHOTOS_LOCK_TTL_SECONDS', max(120, (int) ceil($maxMegapixels * 5)));
$maxFilesPerUpload = 20;

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
    'max_megapixels' => $maxMegapixels,

    // VehiclePhotoImageProcessor 用這把全域 lock 確保任何時刻最多只有一個請求在做
    // decode/encode（真正吃記憶體的部分），避免多個 admin/manager 同時上傳時，各自
    // 合法的解碼疊加成好幾倍尖峰記憶體。lock 依賴 CACHE_STORE 支援 atomic lock
    // （本專案預設 database driver 已支援，見 config/cache.php）。
    //
    // decodeAndStore() 會在每個重量級步驟「之間」呼叫 $lock->refresh() 續約，但續約
    // 沒辦法發生在單一步驟「執行中」——PHP 呼叫 GD 的 decode/toWebp() 是一次不可中斷
    // 的原生函式呼叫，中途沒有機會插入程式碼去續約（Codex adversarial review 第六輪
    // 指出：如果某一個步驟本身就跑得比 TTL 還久，lease 一樣會在那個步驟執行中過期，
    // 續約機制救不到）。這是 lease-based lock 的已知理論限制，沒有 fencing token 或
    // 換成 queue + 單一 worker 架構的話無法 100% 杜絕，這兩者都超出 v1.2 同步上傳的
    // 最小範圍。
    //
    // 因此改用「TTL 遠大於實測單一步驟最長耗時」來把風險壓到可接受範圍，而不是假裝
    // 完全杜絕：24MP 上限下實測最長單一步驟（展示圖 toWebp 編碼）約 976ms，即使主機
    // 嚴重降速到平常的 100 倍，單一步驟也還在 100 秒內。TTL 依 max_megapixels 等比例
    // 抓「每 MP 5 秒」（約 100 倍安全係數），並設 120 秒下限，這樣以後如果調高
    // VEHICLE_PHOTOS_MAX_MEGAPIXELS，這個安全網會跟著等比例放大，不會變成脫鉤、忘記
    // 同步調整的固定數字。真的頂到這個 TTL 代表主機已經退化到「一張在上限內的照片、
    // 單一個編碼步驟」都要跑超過 100 秒等級，那種狀況下 OS 通常會先介入（swap 崩潰、
    // OOM killer），已經不是這把 lock 能單獨解決的問題。
    'processing_lock_ttl_seconds' => $processingLockTtlSeconds,

    // 等不到 lock 就直接回錯誤，不讓 HTTP worker 無限期卡住等待。
    'processing_lock_wait_seconds' => (int) env('VEHICLE_PHOTOS_LOCK_WAIT_SECONDS', 30),

    'max_files_per_upload' => $maxFilesPerUpload, // 一次上傳最多 20 張

    'max_photos_per_vehicle' => 60, // 每台車最多 60 張

    // VehiclePhotoService::uploadPhotos() 在建立 vehicle_photo_upload_batches
    // reservation row 後逐檔處理圖片，每個檔案成功建立照片時都會在同一個 transaction
    // 內同步把該檔案的進度寫回 batch.photo_ids。若 worker 在處理過程中被強制中止、
    // 遇到 fatal error，或伺服器重啟，這筆 row 會停在「還沒處理完全部檔案」的狀態，
    // 需要有辦法讓之後帶著同一把 idempotency_key 的請求安全接手，否則這把 key 會
    // 永久卡死，需要人工介入資料庫才能恢復（Codex adversarial review 指出：這比單純
    // 重複建立照片更糟）。
    //
    // 解法是給每次「認領」這筆 batch 的處理程序核發一個有時效的租約
    // （processing_lease_expires_at，見 VehiclePhotoService::beginUploadBatch()）：
    // 租約仍在未來，代表可能真的還有人在處理，保守拒絕；租約已過期（或從未設定），
    // 代表前一個擁有者已經放棄，允許下一個帶著同一把 key 的請求續傳認領，只接著處理
    // batch.photo_ids 裡還沒做完的檔案，不重做已經真的建立過照片的部分。租約在
    // uploadPhotos() 自己的成功／失敗路徑都會被明確清空，讓「我們自己的 PHP 呼叫確定
    // 結束」的情況可以立即被下一個請求接手，不需要空等這個 TTL；只有「完全沒有機會
    // 執行到 catch」的情境（worker 被殺、fatal error）才會讓租約撐到自然過期。
    //
    // 這個 TTL 值本身是租約的時長，抓「單檔最長處理時間（processing_lock_ttl_seconds）
    // × 單次最多檔案數（max_files_per_upload）」，涵蓋「20 個檔案完全序列化排隊等同一把
    // 全域圖片處理 lock」的最壞情況，並設 900 秒（15 分鐘）下限，避免像素上限調得很低
    // 時算出過短的 TTL。這樣以後調高 VEHICLE_PHOTOS_MAX_MEGAPIXELS 或
    // VEHICLE_PHOTOS_LOCK_TTL_SECONDS，這個回收窗口會自動跟著等比例放大，不會變成
    // 脫鉤的固定數字。
    //
    // 這是 lease-based 機制的已知理論限制（與 processing_lock_ttl_seconds 同一類，沒有
    // fencing token 無法 100% 杜絕）：若前一次處理程序其實只是跑得比租約久、尚未真正
    // 放棄（例如卡在清空租約那一步之前主機就嚴重過載），這裡的續傳認領可能與它同時
    // 處理同一批剩餘檔案，兩者各自對同一個缺口建立照片，造成該區間重複。租約時長已
    // 設得遠大於實際批次處理所需時間，把這個窗口壓到可接受範圍；這個殘留風險遠優於
    // 「key 永久卡死、需要人工清資料庫」，也遠優於先前版本「回收就整批重新處理」對
    // 部分完成批次一定會重複的問題。
    'upload_batch_pending_ttl_seconds' => (int) env(
        'VEHICLE_PHOTOS_UPLOAD_BATCH_PENDING_TTL_SECONDS',
        max(900, $processingLockTtlSeconds * $maxFilesPerUpload)
    ),

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
