<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Validation\ValidationException;
use Core\Validation\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function testRequiredRule(): void
    {
        $validator = Validator::make([], [
            'name' => 'required',
            'email' => 'required',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors());
        $this->assertArrayHasKey('email', $validator->errors());

        $valid = Validator::make(['name' => 'Ahmad', 'email' => 'ahmad@example.com'], [
            'name' => 'required',
            'email' => 'required',
        ]);
        $this->assertTrue($valid->passes());
    }

    public function testEmailRule(): void
    {
        $invalid = Validator::make(['email' => 'not-an-email'], [
            'email' => 'required|email',
        ]);
        $this->assertTrue($invalid->fails());
        $this->assertStringContainsString('Format Email tidak valid', $invalid->errors()['email']);

        $valid = Validator::make(['email' => 'user@domain.com'], [
            'email' => 'required|email',
        ]);
        $this->assertTrue($valid->passes());
    }

    public function testMinAndMaxRules(): void
    {
        // String min & max
        $short = Validator::make(['password' => '12345'], ['password' => 'min:6']);
        $this->assertTrue($short->fails());
        $this->assertStringContainsString('minimal harus 6 karakter', $short->first('password'));

        $long = Validator::make(['name' => str_repeat('a', 51)], ['name' => 'max:50']);
        $this->assertTrue($long->fails());
        $this->assertStringContainsString('tidak boleh lebih dari 50 karakter', $long->first('name'));

        // Numeric min & max
        $numInvalid = Validator::make(['age' => 10], ['age' => 'numeric|min:18']);
        $this->assertTrue($numInvalid->fails());

        $numValid = Validator::make(['age' => 25], ['age' => 'numeric|min:18|max:65']);
        $this->assertTrue($numValid->passes());
    }

    public function testTypeRules(): void
    {
        $invalid = Validator::make(['age' => 'abc', 'qty' => 12.34], [
            'age' => 'numeric',
            'qty' => 'integer',
        ]);
        $this->assertTrue($invalid->fails());
        $this->assertArrayHasKey('age', $invalid->errors());
        $this->assertArrayHasKey('qty', $invalid->errors());

        $valid = Validator::make(['title' => 'Project Alpha', 'age' => 20, 'qty' => 10], [
            'title' => 'string',
            'age' => 'numeric',
            'qty' => 'integer',
        ]);
        $this->assertTrue($valid->passes());
    }

    public function testInRule(): void
    {
        $invalid = Validator::make(['role' => 'superuser'], [
            'role' => 'in:owner,admin,member',
        ]);
        $this->assertTrue($invalid->fails());

        $valid = Validator::make(['role' => 'admin'], [
            'role' => 'in:owner,admin,member',
        ]);
        $this->assertTrue($valid->passes());
    }

    public function testConfirmedRule(): void
    {
        $mismatch = Validator::make([
            'password' => 'secret123',
            'password_confirmation' => 'different123',
        ], [
            'password' => 'required|confirmed',
        ]);
        $this->assertTrue($mismatch->fails());
        $this->assertStringContainsString('Konfirmasi Kata Sandi tidak cocok', $mismatch->first('password'));

        $match = Validator::make([
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], [
            'password' => 'required|confirmed',
        ]);
        $this->assertTrue($match->passes());
    }

    public function testUniqueRuleWithDatabase(): void
    {
        // Insert a dummy user
        $this->db->statement("INSERT INTO users (email, name, password_hash) VALUES ('existing@example.com', 'Existing User', 'hash')");

        // Attempt duplicate
        $dup = Validator::make(['email' => 'existing@example.com'], [
            'email' => 'required|email|unique:users,email',
        ], $this->db->getPdo());

        $this->assertTrue($dup->fails());
        $this->assertStringContainsString('Email sudah terdaftar', $dup->first('email'));

        // New email should pass
        $new = Validator::make(['email' => 'newuser@example.com'], [
            'email' => 'required|email|unique:users,email',
        ], $this->db->getPdo());

        $this->assertTrue($new->passes());

        // Update scenario: ignore existing ID
        $update = Validator::make(['email' => 'existing@example.com'], [
            'email' => 'required|email|unique:users,email,1,id',
        ], $this->db->getPdo());

        $this->assertTrue($update->passes());
    }

    public function testValidatedThrowsValidationExceptionOnFailure(): void
    {
        $this->expectException(ValidationException::class);

        $validator = Validator::make([], ['name' => 'required']);
        $validator->validated();
    }

    public function testValidatedReturnsCleanArrayOnPass(): void
    {
        $data = [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'untracked_extra_field' => 'ignore_me',
        ];

        $validator = Validator::make($data, [
            'name' => 'required|string',
            'email' => 'required|email',
        ]);

        $validated = $validator->validated();
        $this->assertSame(['name' => 'Budi Santoso', 'email' => 'budi@example.com'], $validated);
        $this->assertArrayNotHasKey('untracked_extra_field', $validated);
    }
}
