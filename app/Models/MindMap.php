<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MindMap extends Model
{
    protected $table = 'mind_maps';

    protected $fillable = [
        'user_id',
        'title',
        'description',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(MindMapNode::class)->orderBy('position');
    }
}
