<?php

/**
 * DUSK SUITE BOOTSTRAP — SERVE THE COMPILED BUILD, NOT THE VITE DEV SERVER.
 *
 * WHAT THIS FIXES
 * ---------------
 * Laravel's Vite integration renders `@vite(...)` as a link to the DEV SERVER
 * whenever `public/hot` exists, reading the URL from that file. On this machine
 * Vite binds to IPv6 loopback (`http://[::1]:5173`).
 *
 * The ChromeDriver-managed Chrome that Dusk drives CANNOT reach that address.
 * The consequence is severe and misleading:
 *
 *   - the JS bundle never loads,
 *   - React never mounts, so no page has any content,
 *   - EVERY browser test fails with a 20-second `waitFor`/`waitForText` timeout,
 *     reporting an element that is not "missing" so much as never rendered,
 *   - a screenshot is blank white, which reads like a renderer crash.
 *
 * The static build in `public/build` has no such dependency - it is plain files
 * served by `artisan serve` - so pointing the suite at it makes the browser tests
 * HERMETIC: no dev server needs to be running, and nothing depends on which
 * stack (IPv4/IPv6) that server happened to bind to.
 *
 * HOW IT WORKS
 * ------------
 * This file runs ONCE, before the suite starts and therefore before Dusk boots
 * the `artisan serve` process, and RENAMES `public/hot` out of the way. A
 * shutdown handler puts it back, so the developer's running Vite server is
 * undisturbed once the suite finishes - the same backup-and-restore discipline
 * Dusk itself applies to `.env`.
 *
 * WHY A BOOTSTRAP AND NOT THE TEST CASE
 * -------------------------------------
 * The hot file is read by the SEPARATE server process on EVERY request, not by
 * the test runner. A temporary `Vite::useHotFile()` call inside a test therefore
 * has no effect on what the browser receives. Moving the file is the only
 * mechanism that changes the server's behaviour, and it has to happen before the
 * server starts.
 *
 * IF NOTHING IS MOVED
 * -------------------
 * When `public/hot` is absent (the normal case: no dev server running) this file
 * does nothing at all, and the build is used as it would be anyway.
 */

$projectRoot = dirname(__DIR__, 1);

// `phpunit.dusk.xml` lives at the project root, so the bootstrap can be invoked
// from anywhere; resolve the hot file relative to the project, not the CWD.
$hotFile = $projectRoot.'/public/hot';
$stashFile = $projectRoot.'/public/hot.dusk-stash';

if (! file_exists($hotFile)) {
    // No dev server running - the compiled build is already in use. Nothing to do.
    return;
}

if (! @rename($hotFile, $stashFile)) {
    // Could not move it (permissions / a lock). Warn loudly rather than letting
    // the suite fail with a blank page and a misleading selector timeout.
    fwrite(
        STDERR,
        PHP_EOL.'[dusk] Could not move public/hot aside. The browser suite may fail to render.'.PHP_EOL
        .'[dusk] Stop the Vite dev server, or run `npm run build`, and re-run.'.PHP_EOL
    );

    return;
}

/*
 * Restore the file when the suite ends - however it ends - so the developer's
 * `npm run dev` session keeps working afterwards.
 */
register_shutdown_function(static function () use ($hotFile, $stashFile) {
    if (file_exists($stashFile) && ! file_exists($hotFile)) {
        @rename($stashFile, $hotFile);
    }
});
