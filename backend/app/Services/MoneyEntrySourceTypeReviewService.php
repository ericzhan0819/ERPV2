<?php

namespace App\Services;

use App\Models\MoneyEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 支援 money-entries:source-type-review / money-entries:source-type-gate
 * 兩個 Artisan 指令的資料修復流程，僅處理人工明確確認過的 money_entry id
 * 清單，不做 category/status/buyer_name/sold_price 等 heuristic 判斷。
 */
class MoneyEntrySourceTypeReviewService
{
    public const ALLOWED_TARGETS = [
        MoneyEntry::SOURCE_MANUAL,
        MoneyEntry::SOURCE_VEHICLE_SHORTCUT,
        MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
        MoneyEntry::SOURCE_LEGACY_UNKNOWN,
    ];

    private const BACKUP_DIRECTORY = 'money-entry-source-type-backups';

    private const SNAPSHOT_COLUMNS = [
        'id', 'vehicle_id', 'cash_account_id', 'entry_date', 'direction',
        'category', 'amount', 'counterparty_name', 'description',
        'idempotency_key', 'source_type', 'created_by', 'updated_by',
        'created_at', 'updated_at',
    ];

    /**
     * @return array<int, int>
     */
    public function parseIds(?string $idsOption, ?string $fileOption): array
    {
        $ids = [];

        if ($idsOption !== null && trim($idsOption) !== '') {
            $ids = array_merge($ids, $this->splitIdList($idsOption));
        }

        if ($fileOption !== null && trim($fileOption) !== '') {
            $path = Str::startsWith($fileOption, '/') ? $fileOption : base_path($fileOption);

            if (! File::exists($path)) {
                throw new InvalidArgumentException("找不到 ID 清單檔案：{$fileOption}");
            }

            $contents = File::get($path);
            $ids = array_merge($ids, $this->splitIdList(str_replace(["\r\n", "\r"], "\n", $contents)));
        }

        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            throw new InvalidArgumentException('必須提供明確的 ID 清單（--ids 或 --file），不允許 category/status 等條件');
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function splitIdList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $ids = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (! ctype_digit($part)) {
                throw new InvalidArgumentException("ID 清單包含非法值：{$part}");
            }

            $ids[] = (int) $part;
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{total: int, changed: int, skipped: int, rows: array<int, array{id: int, previous_source_type: string, new_source_type: string, action: string}>}
     */
    public function review(array $ids, string $to, ?string $approver, ?string $reason, bool $dryRun): array
    {
        if (! in_array($to, self::ALLOWED_TARGETS, true)) {
            throw new InvalidArgumentException('--to 必須是 manual/vehicle_shortcut/vehicle_workflow/legacy_unknown 其中之一');
        }

        if (! $dryRun) {
            if ($approver === null || trim($approver) === '') {
                throw new InvalidArgumentException('非 dry-run 執行必須提供 --approver');
            }

            if ($reason === null || trim($reason) === '') {
                throw new InvalidArgumentException('非 dry-run 執行必須提供 --reason');
            }
        }

        if ($dryRun) {
            return $this->previewChanges($ids, $to);
        }

        return DB::transaction(fn () => $this->applyChanges($ids, $to, (string) $approver, (string) $reason));
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function previewChanges(array $ids, string $to): array
    {
        $entries = MoneyEntry::query()->whereIn('id', $ids)->get()->keyBy('id');

        $this->assertAllIdsFound($ids, $entries);

        $rows = [];
        $changed = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $entry = $entries[$id];

            if ($entry->source_type === $to) {
                $skipped++;
                $rows[] = [
                    'id' => $id,
                    'previous_source_type' => $entry->source_type,
                    'new_source_type' => $to,
                    'action' => 'skipped_noop',
                ];

                continue;
            }

            $changed++;
            $rows[] = [
                'id' => $id,
                'previous_source_type' => $entry->source_type,
                'new_source_type' => $to,
                'action' => 'would_change',
            ];
        }

        return [
            'total' => count($ids),
            'changed' => $changed,
            'skipped' => $skipped,
            'rows' => $rows,
            'backup_path' => null,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function applyChanges(array $ids, string $to, string $approver, string $reason): array
    {
        $entries = MoneyEntry::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        $this->assertAllIdsFound($ids, $entries);

        $rowsToChange = $entries->filter(fn (MoneyEntry $entry) => $entry->source_type !== $to);

        $backupPath = null;

        if ($rowsToChange->isNotEmpty()) {
            $backupPath = $this->writeBackup($ids, $to, $approver, $reason, $rowsToChange);
        }

        $rows = [];
        $changed = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $entry = $entries[$id];

            if ($entry->source_type === $to) {
                $skipped++;
                $rows[] = [
                    'id' => $id,
                    'previous_source_type' => $entry->source_type,
                    'new_source_type' => $to,
                    'action' => 'skipped_noop',
                ];

                continue;
            }

            $previousSourceType = $entry->source_type;
            $entry->source_type = $to;
            $entry->save();

            DB::table('money_entry_source_type_reviews')->insert([
                'money_entry_id' => $entry->id,
                'previous_source_type' => $previousSourceType,
                'new_source_type' => $to,
                'approver' => $approver,
                'reason' => $reason,
                'backup_path' => $backupPath,
                'money_entry_snapshot' => json_encode($this->snapshot($entry), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $changed++;
            $rows[] = [
                'id' => $id,
                'previous_source_type' => $previousSourceType,
                'new_source_type' => $to,
                'action' => 'changed',
            ];
        }

        return [
            'total' => count($ids),
            'changed' => $changed,
            'skipped' => $skipped,
            'rows' => $rows,
            'backup_path' => $backupPath,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @param  \Illuminate\Support\Collection<int, MoneyEntry>  $rowsToChange
     */
    private function writeBackup(array $ids, string $to, string $approver, string $reason, $rowsToChange): string
    {
        $filename = sprintf(
            '%s_%s.json',
            now()->format('Ymd_His'),
            Str::random(8)
        );

        $relativePath = self::BACKUP_DIRECTORY.'/'.$filename;

        Storage::disk('local')->put($relativePath, json_encode([
            'command_input' => [
                'ids' => $ids,
                'to' => $to,
            ],
            'approver' => $approver,
            'reason' => $reason,
            'created_at' => now()->toIso8601String(),
            'rows_before_update' => $rowsToChange->values()
                ->map(fn (MoneyEntry $entry) => $this->snapshot($entry))
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return Storage::disk('local')->path($relativePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MoneyEntry $entry): array
    {
        $snapshot = [];

        foreach (self::SNAPSHOT_COLUMNS as $column) {
            $value = $entry->getAttribute($column);
            $snapshot[$column] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
        }

        return $snapshot;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  \Illuminate\Support\Collection<int, MoneyEntry>  $entries
     */
    private function assertAllIdsFound(array $ids, $entries): void
    {
        $missing = array_values(array_diff($ids, $entries->keys()->all()));

        if (! empty($missing)) {
            throw new InvalidArgumentException('找不到以下 money_entry id：'.implode(',', $missing));
        }
    }

    /**
     * @return array{count: int, sample: array<int, array{id: int, category: string, amount: int, vehicle_id: int|null, source_type: string}>}
     */
    public function gate(int $sampleLimit = 20): array
    {
        $count = MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_LEGACY_UNKNOWN)->count();

        $sample = MoneyEntry::query()
            ->where('source_type', MoneyEntry::SOURCE_LEGACY_UNKNOWN)
            ->orderBy('id')
            ->limit($sampleLimit)
            ->get(['id', 'category', 'amount', 'vehicle_id', 'source_type'])
            ->map(fn (MoneyEntry $entry) => [
                'id' => $entry->id,
                'category' => $entry->category,
                'amount' => $entry->amount,
                'vehicle_id' => $entry->vehicle_id,
                'source_type' => $entry->source_type,
            ])
            ->all();

        return [
            'count' => $count,
            'sample' => $sample,
        ];
    }
}
