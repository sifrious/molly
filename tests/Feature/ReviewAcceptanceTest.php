<?php

use Sifrious\Molly\Actions\ReviewChanges;

it('accepts complete reviews with no blocking findings', function (bool $warning): void {
    $review = ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The supplied function adds no indirection.']), 'findings' => []];
    if ($warning) {
        $review['checks']['F']['status'] = 'findings';
        $review['findings'][] = ['code' => 'F', 'severity' => 'warning'];
    }

    expect(app(ReviewChanges::class)->passed($review))->toBeTrue();
})->with([false, true]);

it('rejects blocking or incomplete review evidence', function (string $defect): void {
    $review = ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The supplied function adds no indirection.']), 'findings' => []];
    switch ($defect) {
        case 'missing check':
            unset($review['checks']['G']);
            break;
        case 'unknown status':
            $review['checks']['G']['status'] = 'skipped';
            break;
        case 'blank evidence':
            $review['checks']['G']['evidence'] = ' ';
            break;
        case 'invalid evidence':
            $review['checks']['G']['evidence'] = ['text'];
            break;
        case 'missing findings':
            unset($review['findings']);
            break;
        case 'invalid findings':
            $review['findings'] = 'none';
            break;
        case 'invalid finding':
            $review['findings'] = ['unexpected text'];
            break;
        case 'blocking finding':
            $review['checks']['E']['status'] = 'findings';
            $review['findings'][] = ['code' => 'E', 'severity' => 'blocking'];
            break;
    }

    expect(app(ReviewChanges::class)->passed($review))->toBeFalse();
})->with(['missing check', 'unknown status', 'blank evidence', 'invalid evidence', 'missing findings', 'invalid findings', 'invalid finding', 'blocking finding']);
