<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238), compatible with Google
 * Authenticator, 1Password, Bitwarden, Authy...
 */
class TwoFactor
{
    public function __construct(protected Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return strlen($code) === 6 && (bool) $this->engine->verifyKey($secret, $code, 1);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    public function qrCodeSvg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /** @return Collection<int, string> */
    public function recoveryCodes(): Collection
    {
        return collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)));
    }

    /** Consumes a recovery code; returns false when it is unknown. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = collect($user->two_factor_recovery_codes ?? []);
        $code = Str::lower(trim($code));

        if (! $codes->contains($code)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $codes->reject(fn ($c) => $c === $code)->values()->all()])->save();

        return true;
    }
}
