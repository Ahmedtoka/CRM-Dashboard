<?php

namespace App\Orders;

use App\Models\ShippingZoneRegion;
use Illuminate\Support\Facades\DB;

/**
 * One key per governorate for the /orders filter and analytics (fresh-orders review round 1): the order's
 * shipping_province_code, else its Shopify province NAME mapped back to the code, so "Cairo" / "القاهرة" without a
 * code and "C" with one land in the same bucket. Names come from config('crm.eg_provinces') (Arabic), Shopify's
 * standard English names below and the synced shipping zones (province_code, province_name). An unknown name stays
 * as it is.
 */
final class GovernorateKey
{
    /** Shopify's English names for the Egyptian provinces (its EG province list). */
    private const ENGLISH = [
        'ALX' => ['Alexandria'], 'ASN' => ['Aswan'], 'AST' => ['Asyut', 'Assiut'], 'BA' => ['Red Sea'], 'BH' => ['Beheira', 'Al Beheira'],
        'BNS' => ['Beni Suef'], 'C' => ['Cairo'], 'DK' => ['Dakahlia', 'Ad Daqahliyah'], 'DT' => ['Damietta'], 'FYM' => ['Faiyum', 'Fayoum'],
        'GH' => ['Gharbia', 'Al Gharbiyah'], 'GZ' => ['Giza', 'Al Jizah'], 'IS' => ['Ismailia'], 'JS' => ['South Sinai'],
        'KB' => ['Qalyubia', 'Al Qalyubia'], 'KFS' => ['Kafr el-Sheikh', 'Kafr El Sheikh'], 'KN' => ['Qena'], 'LX' => ['Luxor'],
        'MN' => ['Minya', 'Al Minya'], 'MNF' => ['Monufia', 'Menofia'], 'MT' => ['Matrouh'], 'PTS' => ['Port Said'], 'SHG' => ['Sohag'],
        'SHR' => ['Al Sharqia', 'Sharqia'], 'SIN' => ['North Sinai'], 'SUZ' => ['Suez'], 'WAD' => ['New Valley'],
    ];

    /** @var array<string, string>|null lower-cased name => code */
    private static ?array $names = null;

    /** @return array<string, string> lower-cased trimmed name => province code */
    public static function names(): array
    {
        if (self::$names !== null && ! app()->runningUnitTests()) {
            return self::$names;
        }

        $map = [];
        foreach (self::ENGLISH as $code => $list) {
            foreach ($list as $name) {
                $map[mb_strtolower($name)] = $code;
            }
        }
        foreach ((array) config('crm.eg_provinces', []) as $code => $name) {
            $map[mb_strtolower((string) $name)] = (string) $code;
        }
        foreach (ShippingZoneRegion::query()->where('country_code', 'EG')->whereNotNull('province_code')->whereNotNull('province_name')->get(['province_code', 'province_name']) as $r) {
            $map[mb_strtolower(trim((string) $r->province_name))] = (string) $r->province_code;
        }

        return self::$names = $map;
    }

    /** The code for a name (or a code), null when unknown. */
    public static function codeFor(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $codes = array_keys((array) config('crm.eg_provinces', []));
        if (in_array(strtoupper(trim($value)), $codes, true)) {
            return strtoupper(trim($value));
        }

        return self::names()[mb_strtolower(trim($value))] ?? null;
    }

    /** SQL expression (values quoted inline, so it can sit in SELECT and GROUP BY alike) for `orders`. */
    public static function sql(): string
    {
        $pdo = DB::connection()->getPdo();
        $cases = '';
        foreach (self::names() as $name => $code) {
            $cases .= ' when '.$pdo->quote($name).' then '.$pdo->quote($code);
        }

        $byName = $cases === '' ? 'orders.shipping_province' : "case lower(trim(orders.shipping_province)){$cases} else orders.shipping_province end";

        return "coalesce(nullif(orders.shipping_province_code, ''), {$byName})";
    }
}
