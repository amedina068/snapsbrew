<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the email-validation hardening applied for the Laravel 11 upgrade
 * (advisory GHSA-5vg9-5847-vvmq / CVE-2026-48019 — CRLF injection in the
 * default email rule). The framework version still flags in `composer audit`
 * (no fixed L11 release), so this is the app-level mitigation: the auth/profile
 * surfaces validate with `email:strict`, which rejects CRLF/header-injection
 * payloads while still accepting ordinary addresses.
 */
class EmailValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['snapsbrew.registration_enabled' => true]);
        $this->completeInstanceSetup();
    }

    private const CRLF_EMAIL = "user@example.com\r\nBcc: attacker@evil.com";

    /** The rule used across LoginRequest, CreateNewUser, UpdateUserProfileInformation. */
    private const RULE = 'required|string|email:strict';

    public static function validAddresses(): array
    {
        return [
            ['user@example.com'],
            ['user+tag@example.org'],
            ['user.name@sub.example.com'],
        ];
    }

    public static function injectionPayloads(): array
    {
        return [
            'crlf bcc header' => ["user@example.com\r\nBcc: attacker@evil.com"],
            'lf header' => ["user@example.com\nBcc: attacker@evil.com"],
            'trailing newline' => ["user@example.com\n"],
        ];
    }

    #[DataProvider('validAddresses')]
    public function test_strict_rule_accepts_ordinary_addresses(string $email)
    {
        $this->assertTrue(
            Validator::make(['email' => $email], ['email' => self::RULE])->passes(),
            "expected {$email} to pass the hardened email rule"
        );
    }

    #[DataProvider('injectionPayloads')]
    public function test_strict_rule_rejects_header_injection(string $email)
    {
        $this->assertFalse(
            Validator::make(['email' => $email], ['email' => self::RULE])->passes(),
            'expected the hardened email rule to reject a header-injection payload'
        );
    }

    /**
     * End-to-end wiring: a typo reverting any request class back to the bare
     * `email` rule must be caught, so post a CRLF payload through the real
     * routes and assert the request class rejects it (422 validation error).
     */
    public function test_login_request_rejects_crlf_email_end_to_end()
    {
        $response = $this->postJson('/login', [
            'email' => self::CRLF_EMAIL,
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_crlf_email_end_to_end()
    {
        $response = $this->postJson('/register', [
            'name' => 'Test User',
            'email' => self::CRLF_EMAIL,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        // Validation runs before any Stripe customer creation, so this fails
        // fast with a 422 and never touches the network.
        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['name' => 'Test User']);
    }
}
