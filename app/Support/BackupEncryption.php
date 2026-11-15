<?php

namespace App\Support;

use RuntimeException;

/**
 * Clientseitige Verschlüsselung der externen Sicherungen (Betriebsaudit
 * OPS-3), nur mit PHP-Bordmitteln (libsodium):
 *
 * - je Datei ein zufälliger Schlüssel für einen Datenstrom mit
 *   XChaCha20-Poly1305 (sodium secretstream, 1-MiB-Blöcke, jeder Block
 *   authentisiert, Reihenfolge und vollständiges Ende geprüft),
 * - dieser Schlüssel versiegelt mit dem öffentlichen X25519-Schlüssel
 *   (sodium sealed box, wie bei „age“).
 *
 * Der Server kennt nur den öffentlichen Schlüssel (BACKUP_ENCRYPTION_PUBLIC_KEY)
 * und kann die externen Kopien selbst nicht mehr lesen. Der geheime Schlüssel
 * gehört nicht auf den Server (Passwortmanager/Tresor), siehe deploy/README.md
 * „Backup zurückspielen“ (app:backup-decrypt).
 *
 * Dateiformat: MAGIC | versiegelter Schlüssel (80 Bytes) | Stream-Header
 * (24 Bytes) | Blöcke (je bis zu CHUNK_BYTES + 17 Bytes, letzter mit TAG_FINAL).
 */
class BackupEncryption
{
    public const MAGIC = "BSBACKUP-ENC1\n";

    public const EXTENSION = '.enc';

    private const CHUNK_BYTES = 1048576;

    private const SEALED_KEY_BYTES = SODIUM_CRYPTO_BOX_SEALBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

    /** @return array{public: string, secret: string} Base64 */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_box_keypair();

        return [
            'public' => base64_encode(sodium_crypto_box_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_box_secretkey($pair)),
        ];
    }

    /** Öffentlichen Schlüssel prüfen (Base64, 32 Bytes); wirft bei Fehlern. */
    public static function publicKey(string $base64): string
    {
        $key = base64_decode(trim($base64), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_PUBLIC_KEY ist kein gültiger Schlüssel (php artisan app:backup-keygen).');
        }

        return $key;
    }

    public static function encryptFile(string $source, string $target, string $publicKeyBase64): void
    {
        $publicKey = self::publicKey($publicKeyBase64);
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');
        try {
            self::write($out, self::MAGIC.sodium_crypto_box_seal($key, $publicKey).$header);
            // Ein Block Vorlauf: so trägt genau der letzte Block TAG_FINAL (auch bei leerer Datei).
            $chunk = self::read($in);
            do {
                $next = feof($in) ? '' : self::read($in);
                $final = $next === '' && feof($in);
                self::write($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '',
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
                $chunk = $next;
            } while (! $final);
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    public static function decryptFile(string $source, string $target, string $secretKeyBase64): void
    {
        $secret = base64_decode(trim($secretKeyBase64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new RuntimeException('Der geheime Schlüssel ist ungültig.');
        }
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));

        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Keine verschlüsselte Sicherung (Kennung fehlt).');
            }
            $key = sodium_crypto_box_seal_open((string) fread($in, self::SEALED_KEY_BYTES), $pair);
            if ($key === false) {
                throw new RuntimeException('Die Datei wurde nicht für diesen Schlüssel verschlüsselt.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            sodium_memzero($key);

            $final = false;
            while (! $final) {
                $block = self::read($in, self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                $result = $block === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
                if ($result === false) {
                    throw new RuntimeException('Die Datei ist beschädigt, unvollständig oder verändert.');
                }
                [$plain, $tag] = $result;
                self::write($out, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (fread($in, 1) !== '') {
                throw new RuntimeException('Nach dem Ende der Sicherung folgen weitere Daten.');
            }
        } catch (RuntimeException $e) {
            fclose($out);
            @unlink($target);
            throw $e;
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Datei „{$path}“ lässt sich nicht öffnen.");
        }

        return $handle;
    }

    /** @param  resource  $handle */
    private static function read($handle, int $bytes = self::CHUNK_BYTES): string
    {
        // fread() kann weniger liefern als verlangt: bis zur Blockgröße sammeln.
        $data = '';
        while (strlen($data) < $bytes && ! feof($handle)) {
            $part = fread($handle, $bytes - strlen($data));
            if ($part === false) {
                throw new RuntimeException('Lesefehler.');
            }
            $data .= $part;
        }

        return $data;
    }

    /** @param  resource  $handle */
    private static function write($handle, string $data): void
    {
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('Schreibfehler (Speicher voll?).');
        }
    }
}
