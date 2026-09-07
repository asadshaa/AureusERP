<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Database\Factories\ImportProfileMappingFactory;

class ImportProfileMapping extends Model
{
    use HasFactory;

    protected static function newFactory(): ImportProfileMappingFactory
    {
        return ImportProfileMappingFactory::new();
    }

    protected $table = 'accounting_import_profile_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_aliases'   => 'array',
            'transformations'  => 'array',
            'validation_rules' => 'array',
            'is_required'      => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class, 'profile_id');
    }
}
