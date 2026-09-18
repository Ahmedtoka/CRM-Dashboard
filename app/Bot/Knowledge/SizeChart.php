<?php

namespace App\Bot\Knowledge;

use App\Models\BotSetting;

/**
 * The store's size chart (spec §4.2): editable by a supervisor, rendered as
 * Arabic text for the bot and optionally backed by an uploaded image.
 */
final class SizeChart
{
    public const DEFAULT = [
        'unit' => 'cm',
        'columns' => ['المقاس', 'الصدر', 'الوسط', 'الهيب', 'الوزن التقريبي'],
        'rows' => [
            ['S', '86-90', '66-70', '92-96', '50-57 كجم'],
            ['M', '90-94', '70-74', '96-100', '58-65 كجم'],
            ['L', '94-100', '74-80', '100-106', '66-74 كجم'],
            ['XL', '100-106', '80-86', '106-112', '75-83 كجم'],
            ['2XL', '106-112', '86-92', '112-118', '84-92 كجم'],
            ['3XL', '112-118', '92-98', '118-124', '93-100 كجم'],
        ],
        'note' => 'المقاسات تقريبية وقد تختلف حسب الموديل',
    ];

    private function __construct(private array $chart, private ?string $imagePath) {}

    public static function fromSettings(BotSetting $s): self
    {
        return new self(self::isValidShape($s->size_chart) ? $s->size_chart : self::DEFAULT, $s->size_chart_image_path);
    }

    /**
     * A malformed stored chart (missing columns/rows, a non-list rows array,
     * or a row whose length doesn't match the column count) must never reach
     * `toText()`/`toArray()` — the bot would otherwise render garbage or throw.
     * Falls back to `DEFAULT` instead.
     */
    private static function isValidShape(mixed $chart): bool
    {
        if (! is_array($chart) || $chart === []) {
            return false;
        }
        if (! isset($chart['columns'], $chart['rows']) || ! is_array($chart['columns']) || ! is_array($chart['rows']) || ! array_is_list($chart['columns']) || ! array_is_list($chart['rows'])) {
            return false;
        }
        $columnCount = count($chart['columns']);

        foreach ($chart['rows'] as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== $columnCount) {
                return false;
            }
        }

        return true;
    }

    /** @return array{unit:string, columns:list<string>, rows:list<list<string>>, note:?string} */
    public function toArray(): array
    {
        return [
            'unit' => (string) ($this->chart['unit'] ?? 'cm'),
            'columns' => array_values($this->chart['columns'] ?? []),
            'rows' => array_values($this->chart['rows'] ?? []),
            'note' => $this->chart['note'] ?? null,
        ];
    }

    public function toText(): string
    {
        $c = $this->toArray();
        $lines = ["جدول المقاسات ({$c['unit']}):"];
        foreach ($c['rows'] as $row) {
            $parts = [];
            foreach (array_slice($c['columns'], 1, null, true) as $i => $column) {
                $parts[] = $column.' '.($row[$i] ?? '');
            }
            $lines[] = ($row[0] ?? '').': '.implode('، ', $parts);
        }
        if (! empty($c['note'])) {
            $lines[] = $c['note'];
        }

        return implode("\n", $lines);
    }

    public function hasImage(): bool
    {
        return $this->imagePath !== null && $this->imagePath !== '';
    }

    /**
     * @param  array<string, mixed>  $data  the request payload being validated —
     *                                      a row's expected length is read from
     *                                      $data['columns'], never the global request().
     * @return array validation rules
     */
    public static function rules(array $data): array
    {
        $columnCount = is_array($data['columns'] ?? null) ? count($data['columns']) : 0;

        return [
            'unit' => ['required', 'string', 'max:10'],
            'columns' => ['required', 'array', 'min:2', 'max:8'],
            'columns.*' => ['required', 'string', 'max:40'],
            'rows' => ['required', 'array', 'min:1', 'max:20'],
            'rows.*' => ['required', 'array', 'size:'.$columnCount],
            'rows.*.*' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
