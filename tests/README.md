# Regression checks

Run frontend startup, refresh scheduling, cache, failure, and lazy-loading tests:

```sh
node tests/frontend_startup_test.cjs
```

The Linux backend runner uses PHP 8.4, fake `zfs`/`zpool` executables, fixture
Unraid helpers, and a private tmpfs. It deliberately keeps `/tmp/publishPaused`
present and makes `publish()` throw if the direct-inventory route uses it.
It checks full and progressive inventory, root/child snapshots, failures, invalid
pool input, CLI timeout handling, and the existing mocked administration tests.
No real ZFS commands should be present in the fixture directory.

Run only inside a disposable Linux test environment or a private mount namespace:

```sh
sudo unshare --mount --propagation private sh tests/run_http_inventory_tests.sh /usr/bin/php
```

PHP and its libraries must reside outside `/tmp`, which the runner overlays.
Set `ZFSM_TEST_POSIX` to a `posix.so` path if your PHP does not build it in.
Do not run this test runner on a production
Unraid host. Testing uses simulated ZFS results, not real pool administration.

For live Unraid 7.3.2 verification after installation, confirm the plugin version
is 2026.09.25.112, hard-reload Main, and check the `inventory` HTTP response and
displayed dataset/snapshot counts. Check manual and automatic refresh and test
mutations only on an explicitly disposable dataset/snapshot. No changes to the
system publishing flag, nginx configuration, or Unraid API are required.

Run the plugin through a real Unraid webGUI tree (its plugin installer, CSRF prepend, helpers and pages), in a
disposable container, from a checkout of `unraid/webgui` at the tag under test:

```sh
docker run --rm -v "$PWD:/repo" -w /repo php:7.4-cli bash tests/unraid_compat_test.sh path/to/webgui 6.9.2
```

