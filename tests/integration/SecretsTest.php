<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Security\Secrets;
use PHPUnit\Framework\Attributes\Test;

class SecretsTest extends SurveyorTestCase
{
    #[Test]
    public function secrets_are_encrypted_at_rest_with_a_key_kept_outside_the_database(): void
    {
        /** @var Secrets $secrets */
        $secrets = $this->make(Secrets::class);
        $secrets->put(Secrets::ANTHROPIC, 'sk-ant-the-real-secret-value-0123456789');

        $stored = (string) $this->database()->table('surveyor_secrets')->where('name', Secrets::ANTHROPIC)->value('value');
        $this->assertStringStartsWith('v1:', $stored);
        $this->assertStringNotContainsString('the-real-secret', $stored);
        $this->assertStringNotContainsString('the-real-secret', (string) base64_decode(substr($stored, 3)));

        // A fresh instance (another request) reads it back with the key file.
        $this->assertSame('sk-ant-the-real-secret-value-0123456789', (new Secrets($this->database(), $this->make(\Flarum\Foundation\Paths::class)))->get(Secrets::ANTHROPIC));

        $this->assertFileExists($secrets->keyFile());
        $this->assertSame(0, fileperms($secrets->keyFile()) & 0o077, 'key file readable by its owner only');

        // Encrypting twice never gives the same text; tampering is detected.
        $this->assertNotSame($secrets->encrypt('x'), $secrets->encrypt('x'));
        $tampered = substr($stored, 0, -4).(substr($stored, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
        $this->assertNull($secrets->decrypt($tampered));
        $this->assertNull($secrets->decrypt('plain text'));
    }

    #[Test]
    public function no_secret_ever_reaches_a_browser(): void
    {
        $this->fakes();
        $account = GoogleTest::account();
        $this->http->on('oauth2.googleapis.com/token', ['status' => 200, 'body' => ['access_token' => 'ya29.token', 'expires_in' => 3600]]);

        $this->api('POST', '/search/connect', ['key' => $account['json'], 'property' => 'http://localhost/']);
        $this->api('POST', '/ai/key', ['key' => 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789']);
        $this->api('POST', '/speed/key', ['key' => 'AIzaSyA-abcdefghijklmnopqrstuvwxyz012']);

        $bodies = [
            (string) $this->api('GET', '/search')->getBody(),
            (string) $this->api('GET', '/ai')->getBody(),
            (string) $this->api('GET', '/speed')->getBody(),
            (string) $this->api('GET', '/diagnostics')->getBody(),
            (string) $this->send($this->request('GET', '/admin', ['authenticatedAs' => 1]))->getBody(),
            (string) $this->send($this->request('GET', '/api', ['authenticatedAs' => 1]))->getBody(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('PRIVATE KEY', $body);
            $this->assertStringNotContainsString('sk-ant-api03', $body);
            $this->assertStringNotContainsString('AIzaSyA', $body);
            $this->assertStringNotContainsString('v1:', $body);
        }

        // What the admin is told: that a key is set, and the account's email.
        $this->assertStringContainsString('surveyor@test-project.iam.gserviceaccount.com', $bodies[0]);
        $this->assertTrue(json_decode($bodies[1], true)['hasKey']);
    }
}
