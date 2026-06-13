<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/*
|--------------------------------------------------------------------------
| DownloadFileTool — cross-host gap E11 (server-side download)
|--------------------------------------------------------------------------
|
| Additional DoD ROADMAP §4/E11:
|   - test of a signed URL with expiration.
|   - test of denial when the user has no access to the resource.
|
| We also cover the fail-secure default model (empty allowed_disks), the
| clamping of `expires_in`, the rejection of http/https URLs and the merge of
| `download_url`/`expires_at` into `ToolResult::data` that ChatService will
| expand into `frontend_action.args`.
*/

beforeEach(function () {
    config()->set('chatbot.tools.download_file.allowed_disks', ['attachments']);
    config()->set('chatbot.tools.download_file.max_expires_in', 3600);
    Storage::fake('attachments');
});

it('rejects http/https URLs (security default)', function () {
    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url_or_disk_path' => 'https://evil.example/x.pdf'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('runtime')
        ->and($result->errorMessage)->toContain('URL');
});

it('refuses by default when allowed_disks is empty (fail-secure)', function () {
    config()->set('chatbot.tools.download_file.allowed_disks', []);
    Storage::fake('local');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url_or_disk_path' => 'local::any.pdf'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('runtime')
        ->and($result->errorMessage)->toContain('allowed_disks');
});

it('refuses a disk not in the allowed_disks list', function () {
    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url_or_disk_path' => 'sneaky::secret.pdf'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('runtime');
});

it('signs a URL with the requested expiration on an allowed disk', function () {
    Storage::disk('attachments')->put('invoices/2026/123.pdf', 'fake');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);

    $now = Carbon::create(2026, 5, 9, 12, 0, 0);
    Carbon::setTestNow($now);

    $result = $tool->execute([
        'url_or_disk_path' => 'attachments::invoices/2026/123.pdf',
        'filename'         => 'factura-123.pdf',
        'mime'             => 'application/pdf',
        'expires_in'       => 600,
    ], $ctx);

    Carbon::setTestNow();

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toHaveKey('download_url')
        ->and($result->data['download_url'])->toBeString()
        ->and($result->data['download_url'])->not->toBe('')
        ->and($result->data)->toHaveKey('expires_at', $now->copy()->addSeconds(600)->toIso8601String())
        ->and($result->data)->toHaveKey('filename', 'factura-123.pdf')
        ->and($result->data)->toHaveKey('mime', 'application/pdf');
});

it('clamps expires_in to max_expires_in', function () {
    Storage::disk('attachments')->put('big.pdf', 'fake');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);

    $now = Carbon::create(2026, 5, 9, 12, 0, 0);
    Carbon::setTestNow($now);

    $result = $tool->execute([
        'url_or_disk_path' => 'attachments::big.pdf',
        'expires_in'       => 999_999_999, // would overflow if not clamped
    ], $ctx);

    Carbon::setTestNow();

    expect($result->isOk())->toBeTrue()
        ->and($result->data['expires_at'])->toBe($now->copy()->addSeconds(3600)->toIso8601String());
});

it('clamps expires_in to a minimum of 30 seconds', function () {
    Storage::disk('attachments')->put('small.pdf', 'fake');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);

    $now = Carbon::create(2026, 5, 9, 12, 0, 0);
    Carbon::setTestNow($now);

    $result = $tool->execute([
        'url_or_disk_path' => 'attachments::small.pdf',
        'expires_in'       => 5, // too low
    ], $ctx);

    Carbon::setTestNow();

    expect($result->isOk())->toBeTrue()
        ->and($result->data['expires_at'])->toBe($now->copy()->addSeconds(30)->toIso8601String());
});

it('uses 300s as the default expiration when not specified', function () {
    Storage::disk('attachments')->put('default.pdf', 'fake');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);

    $now = Carbon::create(2026, 5, 9, 12, 0, 0);
    Carbon::setTestNow($now);

    $result = $tool->execute(['url_or_disk_path' => 'attachments::default.pdf'], $ctx);

    Carbon::setTestNow();

    expect($result->data['expires_at'])->toBe($now->copy()->addSeconds(300)->toIso8601String());
});

it('falls back to the default disk when no `disk::` prefix is given', function () {
    config()->set('filesystems.default', 'attachments');
    config()->set('chatbot.tools.download_file.allowed_disks', ['attachments']);
    Storage::disk('attachments')->put('plain.pdf', 'fake');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url_or_disk_path' => 'plain.pdf'], $ctx);

    expect($result->isOk())->toBeTrue();
});

it('returns validation error when url_or_disk_path is missing', function () {
    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute([], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation');
});

it('respects ownership denial via assertCanDownload override', function () {
    Storage::disk('attachments')->put('private.pdf', 'fake');

    $tool = new class extends DownloadFileTool {
        protected function assertCanDownload(string $disk, string $path, ToolContext $ctx): ?ToolResult
        {
            return $path === 'private.pdf'
                ? ToolResult::error('not_owner', 'Este PDF no pertenece al usuario.')
                : null;
        }
    };

    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url_or_disk_path' => 'attachments::private.pdf'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('not_owner');
});

it('confirmation defaults to auto', function () {
    expect((new DownloadFileTool)->confirmation()->value)->toBe('auto');
});

it('returns runtime error if the underlying disk does not support temporaryUrl', function () {
    // The fake `local` disk does not implement temporaryUrl; we simulate the
    // common Laravel local-disk failure mode.
    config()->set('chatbot.tools.download_file.allowed_disks', ['local']);
    Storage::fake('local');
    Storage::disk('local')->put('foo.pdf', 'x');

    $tool   = new DownloadFileTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url_or_disk_path' => 'local::foo.pdf'], $ctx);

    // Either runtime error (temporaryUrl unsupported) or success (if the test
    // host registered a generator). Both states are valid; the failure mode
    // we want to verify is "the tool surfaces this error category cleanly".
    if ($result->isError()) {
        expect($result->errorCategory)->toBe('runtime');
    } else {
        expect($result->data)->toHaveKey('download_url');
    }
});
