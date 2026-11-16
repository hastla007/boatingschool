<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerFaqCategory extends Model
{
    use HasUuids;

    protected $table = 'customer_faq_category';

    public $timestamps = false;

    protected $fillable = ['source_key', 'course_code', 'parent_id', 'name', 'slug', 'sort_order'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function faqs(): BelongsToMany
    {
        return $this->belongsToMany(CustomerFaq::class, 'customer_faq_category_item', 'category_id', 'faq_id')
            ->withPivot('sort_order')->where('customer_faq.status', 'published')
            ->orderByPivot('sort_order')->orderBy('customer_faq.question');
    }
}
