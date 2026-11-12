<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Elastic Beanstalk runs .platform/hooks/ after a deploy and only
 * .platform/confighooks/ after a configuration change (log streaming, an
 * environment property). The HTTPS settings are written by a deploy hook, so a
 * configuration change without a matching config hook leaves nginx without
 * port 443 while the environment still shows green.
 */
class DeployHooksTest extends TestCase
{
    private const DEPLOY_HOOK = '.platform/hooks/postdeploy/02_https.sh';

    private const CONFIG_HOOK = '.platform/confighooks/postdeploy/02_https.sh';

    public function test_a_configuration_change_runs_the_https_script_again(): void
    {
        $hook = base_path(self::CONFIG_HOOK);
        $this->assertFileExists($hook);

        $source = (string) file_get_contents($hook);
        $this->assertStringStartsWith('#!/bin/bash', $source);
        $this->assertStringContainsString('/var/app/current/'.self::DEPLOY_HOOK, $source, 'It must run the deploy script, not a copy that can drift.');
        $this->assertFileExists(base_path(self::DEPLOY_HOOK));
    }

    public function test_the_configuration_hook_is_valid_shell_and_never_fails_the_update(): void
    {
        $hook = base_path(self::CONFIG_HOOK);

        exec('bash -n '.escapeshellarg($hook).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        $source = (string) file_get_contents($hook);
        $this->assertStringEndsWith("exit 0\n", $source);
    }

    public function test_the_configuration_hook_reports_a_missing_deploy_script_and_exits_cleanly(): void
    {
        $hook = base_path(self::CONFIG_HOOK);
        if (is_file('/var/app/current/'.self::DEPLOY_HOOK)) {
            $this->markTestSkipped('This machine has a live app folder.');
        }

        exec('bash '.escapeshellarg($hook).' 2>&1', $output, $code);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PRISM HTTPS:', implode("\n", $output));
    }

    private const AUDIT_HOOK = '.platform/hooks/postdeploy/03_audit_log.sh';

    private const AUDIT_CONFIG_HOOK = '.platform/confighooks/postdeploy/03_audit_log.sh';

    public function test_the_audit_log_hooks_are_valid_shell_and_never_fail_a_deploy(): void
    {
        foreach ([self::AUDIT_HOOK, self::AUDIT_CONFIG_HOOK] as $file) {
            $path = base_path($file);
            $this->assertFileExists($path);
            exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
            $this->assertStringEndsWith("exit 0\n", (string) file_get_contents($path));
        }
    }

    public function test_a_configuration_change_runs_the_audit_log_script_again(): void
    {
        $source = (string) file_get_contents(base_path(self::AUDIT_CONFIG_HOOK));

        $this->assertStringContainsString('/var/app/current/'.self::AUDIT_HOOK, $source);
    }

    public function test_the_audit_file_path_in_the_hook_matches_the_environment_setting(): void
    {
        $config = (string) file_get_contents(base_path('.ebextensions/03-audit-log.config'));
        $hook = (string) file_get_contents(base_path(self::AUDIT_HOOK));

        $this->assertStringContainsString('AUDIT_LOG_PATH: /var/log/prism/audit.log', $config);
        $this->assertStringContainsString('AUDIT_LOG_STACK: audit_stderr,audit_file', $config);
        $this->assertStringContainsString('LOG_FILE="$LOG_DIR/audit.log"', $hook);
        $this->assertStringContainsString('LOG_DIR=/var/log/prism', $hook);
    }
}
