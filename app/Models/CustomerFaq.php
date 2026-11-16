<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerFaq extends Model
{
    use HasUuids;

    protected $table = 'customer_faq';

    protected $fillable = ['source_key', 'question', 'answer', 'status', 'sort_order'];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CustomerFaqCategory::class, 'customer_faq_category_item', 'faq_id', 'category_id')
            ->withPivot('sort_order');
    }
}
