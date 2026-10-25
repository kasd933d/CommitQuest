<?php

namespace App\Enums;

enum ActivityType: string
{
    case Commit = 'commit';
    case PrOpened = 'pr_opened';
    case PrMerged = 'pr_merged';
    case PrReview = 'pr_review';
    case IssueClosed = 'issue_closed';
    case NewRepo = 'new_repo';

    public function xp(): int
    {
        return match ($this) {
            self::Commit => 5,
            self::PrOpened => 20,
            self::PrMerged => 40,
            self::PrReview => 25,
            self::IssueClosed => 15,
            self::NewRepo => 30,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Commit => 'Commit',
            self::PrOpened => 'Pull request opened',
            self::PrMerged => 'Pull request merged',
            self::PrReview => 'Pull request reviewed',
            self::IssueClosed => 'Issue closed',
            self::NewRepo => 'New repository',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
