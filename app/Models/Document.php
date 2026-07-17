<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $table = 'documents';
    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
    ];

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'document_id', 'id');
    }
}
