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
}
