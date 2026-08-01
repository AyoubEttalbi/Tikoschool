<?php

/*
 * Invariants that are cheap to state and expensive to rediscover.
 *
 * Each of these encodes a bug that actually shipped.
 */

/** Recursively read every .php file under a directory. */
function phpFilesIn(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

function sourceFiles(): array
{
    return phpFilesIn(base_path('app'));
}

test('teacher wallets are only mutated through TeacherWalletService', function () {
    $offenders = [];

    foreach (sourceFiles() as $path => $contents) {
        if (str_contains($path, 'TeacherWalletService.php')) {
            continue; // the one place allowed to touch it
        }

        // Strip comments so the explanatory notes about the old code do not trip this.
        $code = preg_replace('#(//.*$)|(/\*.*?\*/)#ms', '', $contents);

        if (preg_match("/(increment|decrement)\(\s*'wallet'/", $code)) {
            $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe(
        [],
        "Direct wallet mutation bypasses the append-only ledger, the row lock and the\n"
        . "idempotency constraint. Use TeacherWalletService::credit()/debit() instead.\n"
        . "Offending files:\n  " . implode("\n  ", $offenders)
    );
});

test('no double-quoted column identifiers are passed to DB::raw', function () {
    // `DB::raw('"schoolId"')` is a STRING LITERAL in MySQL, not a column reference, so the
    // predicate compared a constant against a value and matched zero rows — silently.
    $offenders = [];

    foreach (sourceFiles() as $path => $contents) {
        $code = preg_replace('#(//.*$)|(/\*.*?\*/)#ms', '', $contents);

        if (preg_match('/DB::raw\(\s*\'"/', $code)) {
            $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([], 'Double quotes are string literals in MySQL, not identifiers.');
});

test('query logging is never left enabled in application code', function () {
    // DB::enableQueryLog() with no matching disable accumulates every query and its bound
    // parameters in memory for the rest of the request, and these were being written to the
    // log with real student and financial identifiers in them.
    $offenders = [];

    foreach (sourceFiles() as $path => $contents) {
        $code = preg_replace('#(//.*$)|(/\*.*?\*/)#ms', '', $contents);

        if (str_contains($code, 'enableQueryLog') || str_contains($code, 'getQueryLog')) {
            $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
});

test('the legacy Laravel 10 console kernel is gone', function () {
    // app/Console/Kernel.php is never loaded in Laravel 12. It defined a SECOND, conflicting
    // schedule that contradicted bootstrap/app.php and misled anyone reading it.
    expect(file_exists(app_path('Console/Kernel.php')))->toBeFalse();
});

test('config/app.php does not re-declare the framework provider list', function () {
    // A Laravel-10 era 'providers' array in config/app.php pins the framework provider set:
    // Laravel only falls back to its own maintained DefaultProviders list when this key is
    // absent, so a hardcoded copy silently suppresses providers added by later releases.
    // (Illuminate\Concurrency\ConcurrencyServiceProvider was one such casualty here.)
    //
    // Asserted against the SOURCE, not config('app.providers') — the framework populates
    // that key with its defaults at boot, so it is never null at runtime.
    $source = file_get_contents(config_path('app.php'));

    expect($source)->not->toMatch("/^\s*'providers'\s*=>\s*\[/m");
});

test('the framework default providers are in effect', function () {
    // Proves the point of the test above: this provider ships with Laravel 11+ and was
    // absent while config/app.php pinned the old list.
    expect(config('app.providers'))
        ->toContain(Illuminate\Concurrency\ConcurrencyServiceProvider::class);
});
