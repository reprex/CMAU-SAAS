<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    public const UPDATED_AT = null;

    public const MODES = ['keyword', 'ai', 'cached', 'blocked'];

    /** Plain words for each reason a search was blocked or kept to keyword results. */
    public const REASONS = [
        'honeypot' => 'Filled the hidden field (bot)',
        'rate_limit' => 'Too many searches a minute',
        'too_short' => 'Too short', 'too_long' => 'Too long',
        'link_or_markup' => 'Contained a link or markup',
        'repeated_characters' => 'Repeated characters', 'no_words' => 'No words',
        'no_page_token' => 'No page token (not from the page)', 'bad_page_token' => 'Forged page token',
        'too_fast' => 'Searched too soon after page load', 'page_token_expired' => 'Page left open too long',
        'keyword_enough' => 'Keyword search was enough', 'agent_keyword_only' => 'Verified bot or agent: keyword only',
        'turnstile_not_set_up' => 'Turnstile not set up yet', 'no_turnstile' => 'No human check',
        'turnstile_unreachable' => 'Turnstile unreachable', 'turnstile_failed' => 'Failed the human check',
        'turnstile_wrong_host' => 'Human check from another site',
        'ai_hourly_limit' => 'Visitor hit the hourly AI limit', 'ai_daily_budget' => 'Daily AI budget used',
        'ai_error' => 'Claude error (fell back to keyword)',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checks' => 'array', 'result_ids' => 'array', 'agent' => 'boolean', 'created_at' => 'datetime'];
    }
}
