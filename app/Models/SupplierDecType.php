<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A decoration type for one supplier's wording (docs/decoration-types.md); beats dec_type_codes. */
class SupplierDecType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['type_code' => 'integer', 'staff' => 'boolean'];
    }

    /** @return array<string, int> code => type for a supplier */
    public static function forSupplier(?int $supplierId): array
    {
        return $supplierId ? self::query()->where('supplier_id', $supplierId)->pluck('type_code', 'code')->map(fn ($t) => (int) $t)->all() : [];
    }
}
