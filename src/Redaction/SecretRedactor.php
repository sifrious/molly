<?php

namespace Sifrious\Molly\Redaction;

use Dotenv\Dotenv;
use Throwable;

/**
 * Replace secrets in text Molly records or prints with `[redacted:NAME]`.
 *
 * Three passes run in order: the values of secret-named environment variables
 * from the process and the workspace `.env` (read only), then common token
 * shapes, then, for settings snapshots, every value under a secret-named key.
 */
final class SecretRedactor
{
    /** Environment names whose values are treated as secrets. */
    private const SECRET_NAME = '/(^|_)(KEY|SECRET|TOKEN|PASSWORD|PASSWD|PASSPHRASE|CREDENTIALS?)(_|$)|^AWS_/i';

    /** Settings keys whose values are never stored. */
    private const SECRET_KEY = '/(^|[_\-.])(api_?key|app_key|private_key|access_key|secret_key|secret|token|password|passwd|passphrase|credentials?)$|^aws_/i';

    /** Values shorter than this, or plain words, are too common to replace safely. */
    private const MIN_LENGTH = 8;

    /** @var array<string, string> token kind => pattern */
    private const SHAPES = [
        'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        'github_token' => '/\bgh[pousr]_[A-Za-z0-9]{36,255}\b/',
        'github_pat' => '/\bgithub_pat_[A-Za-z0-9_]{22,255}\b/',
        'api_key' => '/\bsk-(?:[A-Za-z0-9_-]+-)?[A-Za-z0-9_-]{20,}/',
        'app_key' => '/\bbase64:[A-Za-z0-9+\/]{32,}={0,2}/',
        'jwt' => '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        'aws_access_key' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
    ];

    public function text(string $text, ?string $workspace = null): string
    {
        return $this->replace($text, $this->environmentSecrets($workspace));
    }

    /** Redact every string inside a value, keys included. */
    public function value(mixed $value, ?string $workspace = null): mixed
    {
        return $this->walk($value, $this->environmentSecrets($workspace), false);
    }

    /**
     * Redact a settings or effective-config snapshot. A value under a secret-named
     * key is replaced whole, whatever it holds.
     */
    public function settings(mixed $value, ?string $workspace = null): mixed
    {
        return $this->walk($value, $this->environmentSecrets($workspace), true);
    }

    public static function isSecretName(string $name): bool
    {
        return preg_match(self::SECRET_NAME, $name) === 1;
    }

    /** @param  array<string, string>  $secrets */
    private function walk(mixed $value, array $secrets, bool $byKey): mixed
    {
        if (is_string($value)) {
            return $this->replace($value, $secrets);
        }
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $name = is_string($key) ? $this->replace($key, $secrets) : $key;
            $redacted[$name] = $byKey && is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && $item !== null
                ? '[redacted:'.$key.']'
                : $this->walk($item, $secrets, $byKey);
        }

        return $redacted;
    }

    /** @param  array<string, string>  $secrets */
    private function replace(string $text, array $secrets): string
    {
        if ($text === '') {
            return $text;
        }
        if ($secrets !== []) {
            $text = strtr($text, $secrets);
        }
        foreach (self::SHAPES as $kind => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $text = (string) preg_replace($pattern, '[redacted:'.$kind.']', $text);
            }
        }

        return $text;
    }

    /**
     * Secret values from the process environment and the workspace `.env`, mapped
     * to their replacement. strtr() tries longer values first.
     *
     * @return array<string, string>
     */
    private function environmentSecrets(?string $workspace): array
    {
        $pairs = [];
        foreach ([getenv(), $_ENV, $_SERVER, $this->workspaceEnvironment($workspace)] as $source) {
            foreach (is_array($source) ? $source : [] as $name => $value) {
                if (is_string($name) && is_string($value) && self::isSecretName($name) && $this->worthRedacting($value)) {
                    $pairs[] = [$name, $value];
                }
            }
        }

        $secrets = [];
        foreach ($pairs as [$name, $value]) {
            $secrets[$value] ??= '[redacted:'.$name.']';
        }

        return $secrets;
    }

    private function worthRedacting(string $value): bool
    {
        $value = trim($value);
        if (strlen($value) < self::MIN_LENGTH) {
            return false;
        }

        return ! (ctype_alpha($value) && strlen($value) < 16);
    }

    /** @return array<string, string> */
    private function workspaceEnvironment(?string $workspace): array
    {
        if ($workspace === null || $workspace === '') {
            return [];
        }
        $path = rtrim($workspace, '/').'/.env';
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $values = Dotenv::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return [];
        }

        return $values;
    }
}
