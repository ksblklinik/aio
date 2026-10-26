<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Config\Config;
use Core\Config\Env;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private string $tempEnvFile;
    private string $tempConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempEnvFile = sys_get_temp_dir() . '/test_' . uniqid() . '.env';
        $this->tempConfigDir = sys_get_temp_dir() . '/config_' . uniqid();
        mkdir($this->tempConfigDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempEnvFile)) {
            unlink($this->tempEnvFile);
        }

        if (is_dir($this->tempConfigDir)) {
            foreach (glob($this->tempConfigDir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempConfigDir);
        }

        parent::tearDown();
    }

    public function test_env_parses_various_data_types_correctly(): void
    {
        $content = <<<ENV
# Komentar baris
APP_NAME="aio SaaS Framework"
APP_ENV=local
APP_DEBUG=true
APP_SECRET_NULL=null
EMPTY_VAL=
PORT=8000
RATIO=3.14
SINGLE_QUOTED='Single Quoted Value'
UNQUOTED_TEXT=JustSomeText
ENV;
        file_put_contents($this->tempEnvFile, $content);

        Env::loadFile($this->tempEnvFile, true);

        $this->assertSame('aio SaaS Framework', Env::get('APP_NAME'));
        $this->assertSame('local', Env::get('APP_ENV'));
        $this->assertTrue(Env::get('APP_DEBUG'));
        $this->assertNull(Env::get('APP_SECRET_NULL'));
        $this->assertSame('', Env::get('EMPTY_VAL'));
        $this->assertSame(8000, Env::get('PORT'));
        $this->assertSame(3.14, Env::get('RATIO'));
        $this->assertSame('Single Quoted Value', Env::get('SINGLE_QUOTED'));
        $this->assertSame('JustSomeText', Env::get('UNQUOTED_TEXT'));
    }

    public function test_env_returns_default_when_variable_missing(): void
    {
        $this->assertSame('default_val', Env::get('NON_EXISTENT_KEY_' . uniqid(), 'default_val'));
        $this->assertNull(Env::get('NON_EXISTENT_KEY_' . uniqid()));
    }

    public function test_config_reads_files_and_supports_dot_notation(): void
    {
        file_put_contents($this->tempConfigDir . '/app.php', "<?php return [
            'name' => 'Custom SaaS App',
            'debug' => true,
            'nested' => [
                'deep' => [
                    'key' => 'deep_value'
                ]
            ]
        ];");

        file_put_contents($this->tempConfigDir . '/database.php', "<?php return [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'path' => 'database.sqlite'
                ]
            ]
        ];");

        $config = new Config($this->tempConfigDir);

        $this->assertSame('Custom SaaS App', $config->get('app.name'));
        $this->assertTrue($config->get('app.debug'));
        $this->assertSame('deep_value', $config->get('app.nested.deep.key'));
        $this->assertSame('sqlite', $config->get('database.default'));
        $this->assertSame('database.sqlite', $config->get('database.connections.sqlite.path'));
        $this->assertSame('fallback', $config->get('app.unknown.key', 'fallback'));
    }

    public function test_config_can_set_dynamic_values(): void
    {
        $config = new Config($this->tempConfigDir);
        $config->set('dynamic.runtime.value', 12345);

        $this->assertSame(12345, $config->get('dynamic.runtime.value'));
    }

    public function test_global_helpers_env_and_config(): void
    {
        Env::set('GLOBAL_TEST_HELPER', 'helper_works');
        $this->assertSame('helper_works', env('GLOBAL_TEST_HELPER'));

        Config::getInstance()->set('test.helper', 'ok');
        $this->assertSame('ok', config('test.helper'));
        $this->assertSame('default', config('test.missing', 'default'));
    }
}
