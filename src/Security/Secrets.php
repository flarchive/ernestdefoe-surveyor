<?php

namespace Ernestdefoe\Surveyor\Security;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Keys Surveyor holds for other services, encrypted at rest (AES-256-GCM).
 *
 * Flarum has no application key of its own, so the encryption key lives in
 * a file in Flarum's storage folder (made once, readable only by the web
 * server's user), never in the database: a leaked database dump does not
 * leak the secrets. Values are never sent to a browser; the admin only ever
 * sees whether one is set and a safe label (a service account's email).
 */
class Secrets
{
    public const GOOGLE = 'google_service_account';
    public const PAGESPEED = 'pagespeed_key';
    public const ANTHROPIC = 'anthropic_key';

    private const CIPHER = 'aes-256-gcm';

    private ?string $key = null;

    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(
        protected ConnectionInterface $db,
        protected Paths $paths,
    ) {
    }

    public function get(string $name): ?string
    {
        if (array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }

        $row = $this->db->table('surveyor_secrets')->where('name', $name)->value('value');

        return $this->memo[$name] = $row === null ? null : $this->decrypt((string) $row);
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function label(string $name): ?string
    {
        return $this->db->table('surveyor_secrets')->where('name', $name)->value('label');
    }

    public function put(string $name, string $value, ?string $label = null): void
    {
        $this->db->table('surveyor_secrets')->upsert(
            [['name' => $name, 'value' => $this->encrypt($value), 'label' => $label, 'updated_at' => Carbon::now()]],
            ['name'],
            ['value', 'label', 'updated_at']
        );
        $this->memo[$name] = $value;
    }

    public function forget(string $name): void
    {
        $this->db->table('surveyor_secrets')->where('name', $name)->delete();
        $this->memo[$name] = null;
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Surveyor could not encrypt a secret.');
        }

        return 'v1:'.base64_encode($iv.$tag.$cipher);
    }

    public function decrypt(string $stored): ?string
    {
        $raw = str_starts_with($stored, 'v1:') ? base64_decode(substr($stored, 3), true) : false;

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        // A different key file (a copied database on another server): treat as not set.
        return $plain === false ? null : $plain;
    }

    public function keyFile(): string
    {
        return rtrim($this->paths->storage, '/').'/surveyor.key';
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $file = $this->keyFile();

        if (! is_file($file)) {
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                fwrite($handle, base64_encode(random_bytes(32)));
                fclose($handle);
                @chmod($file, 0600);
            }
        }

        $key = is_readable($file) ? base64_decode(trim((string) file_get_contents($file)), true) : false;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Surveyor could not read or create its key file at '.$file.'.');
        }

        return $this->key = $key;
    }
}
