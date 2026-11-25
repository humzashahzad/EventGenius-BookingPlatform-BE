<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AI analysis of one review (see App\Services\Reviews\ReviewInsightService). */
class ReviewInsight extends Model
{
    /** Flags that keep a review out of venue aggregates and ranking. */
    public const EXCLUDING_FLAGS = ['spam', 'possible_duplicate'];

    protected $fillable = [
        'review_id', 'sentiment', 'label', 'confidence', 'language', 'aspects', 'flags', 'phrases',
        'model_version', 'analyzed_at',
    ];

    protected $casts = [
        'sentiment' => 'float',
        'confidence' => 'float',
        'aspects' => 'array',
        'flags' => 'array',
        'phrases' => 'array',
        'analyzed_at' => 'datetime',
    ];

    protected $hidden = ['phrases'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }
}
