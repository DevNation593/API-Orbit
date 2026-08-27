<?php

use App\Services\WorkflowValidator;
use App\Support\UrlSafety;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('rejects private and credential-bearing webhook URLs', function (): void {
    expect(UrlSafety::isPublic('http://127.0.0.1:8080/hook'))->toBeFalse();
    expect(UrlSafety::isPublic('http://user:password@8.8.8.8/hook'))->toBeFalse();
});

it('rejects workflow code or unsupported triggers', function (): void {
    expect(fn () => app(WorkflowValidator::class)->validate([
        'trigger' => ['type' => 'custom.eval'],
        'conditions' => [],
        'actions' => [['type' => 'eval', 'code' => 'system("whoami")']],
    ]))->toThrow(ValidationException::class);
});
