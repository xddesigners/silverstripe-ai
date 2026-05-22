<?php

namespace XD\SilverstripeAI\Models;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

class AIRequestLog extends DataObject
{
    private static string $table_name = 'AIRequestLog';

    private static array $db = [
        'Platform'         => 'Varchar(50)',
        'Model'            => 'Varchar(100)',
        'Mode'             => 'Varchar(20)',
        'PromptTokens'     => 'Int',
        'CompletionTokens' => 'Int',
        'TotalTokens'      => 'Int',
        'TokensAvailable'  => 'Boolean',
        'EstimatedCost'    => 'Decimal(10,6)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $summary_fields = [
        'Created'             => 'Date',
        'Member.Name'         => 'User',
        'Platform'            => 'Platform',
        'Model'               => 'Model',
        'Mode'                => 'Mode',
        'FormattedPrompt'     => 'Prompt tokens',
        'FormattedCompletion' => 'Completion tokens',
        'FormattedTotal'      => 'Total tokens',
        'FormattedCost'       => 'Est. cost',
    ];

    private static string $default_sort = 'Created DESC';

    public function getFormattedPrompt(): string
    {
        return $this->TokensAvailable ? (string) $this->PromptTokens : '—';
    }

    public function getFormattedCompletion(): string
    {
        return $this->TokensAvailable ? (string) $this->CompletionTokens : '—';
    }

    public function getFormattedTotal(): string
    {
        return $this->TokensAvailable ? (string) $this->TotalTokens : '—';
    }

    public function getFormattedCost(): string
    {
        if (!$this->TokensAvailable || !$this->EstimatedCost) {
            return '—';
        }

        return '$' . number_format((float) $this->EstimatedCost, 6);
    }

    public function canCreate($member = null, $context = []): bool { return false; }
    public function canEdit($member = null): bool { return false; }
    public function canDelete($member = null): bool { return false; }

    /**
     * Write a log entry. Silently swallows errors so logging never breaks a request.
     */
    public static function log(
        string $platform,
        string $model,
        string $mode,
        ?int $promptTokens,
        ?int $completionTokens,
        ?float $estimatedCost
    ): void {
        try {
            $log = static::create();
            $log->Platform         = $platform;
            $log->Model            = $model;
            $log->Mode             = $mode;
            $tokensAvailable       = $promptTokens !== null || $completionTokens !== null;
            $log->TokensAvailable  = $tokensAvailable;
            $log->PromptTokens     = $promptTokens ?? 0;
            $log->CompletionTokens = $completionTokens ?? 0;
            $log->TotalTokens      = ($promptTokens ?? 0) + ($completionTokens ?? 0);
            $log->EstimatedCost    = $estimatedCost ?? 0;
            $log->MemberID         = Security::getCurrentUser()?->ID ?? 0;
            $log->write();
        } catch (\Throwable $e) {
            // Never let logging break the AI request
        }
    }
}
