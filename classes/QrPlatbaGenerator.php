<?php
/**
 * QR Platba generator.
 *
 * Primary path uses RikudouSage/QrPaymentCZ (rikudou/czqrpayment) when the
 * Composer dependencies are installed in the module's vendor/ directory.
 * If they are missing, a self-contained native fallback still produces a valid
 * Czech IBAN and SPAYD (SPD*1.0) string so the payment details always render —
 * only the rasterised QR image requires the library + a QR image provider.
 *
 * @author    Joelp <joelp@email.cz>
 * @license   MIT
 */

declare(strict_types=1);

namespace Joelp\QrPlatba;

use DateTime;
use Rikudou\CzQrPayment\QrPayment;
use Rikudou\Iban\Iban\CzechIbanAdapter;
use Rikudou\Iban\Iban\IBAN;

class QrPlatbaGenerator
{
    /** @var string|null full IBAN (overrides account/bank when set) */
    private $iban;

    /** @var string account number, optionally with a prefix, e.g. "19-2000145399" */
    private $account;

    /** @var string 4-digit bank code, e.g. "0800" */
    private $bankCode;

    public function __construct(?string $iban, string $account, string $bankCode)
    {
        $this->iban = $iban ? strtoupper(preg_replace('/\s+/', '', $iban)) : null;
        $this->account = trim($account);
        $this->bankCode = trim($bankCode);
    }

    /**
     * Build the payment artifacts for a single order.
     *
     * @return array{spayd:string,data_uri:?string,iban:string,using_library:bool}
     */
    public function build(
        int $variableSymbol,
        float $amount,
        string $currencyIso,
        ?string $comment = null,
        int $dueDays = 0
    ): array {
        $currencyIso = strtoupper($currencyIso ?: 'CZK');
        $comment = $this->sanitizeMessage($comment);

        $result = null;
        if ($this->libraryAvailable()) {
            try {
                $result = $this->buildWithLibrary($variableSymbol, $amount, $currencyIso, $comment, $dueDays);
            } catch (\Throwable $e) {
                // Library present but mis-wired (version/dep mismatch) — never let
                // that blank out the QR; the native builder produces the same SPAYD.
                $result = null;
            }
        }
        if ($result === null) {
            $result = $this->buildNative($variableSymbol, $amount, $currencyIso, $comment, $dueDays);
        }

        // Render the QR as SVG: resolution-independent (crisp on any DPI/device)
        // and, unlike the library's default PNG path, needs no GD extension.
        $result['data_uri'] = $this->renderSvg($result['spayd']);

        return $result;
    }

    public function libraryAvailable(): bool
    {
        return class_exists(QrPayment::class);
    }

    /** True when endroid/qr-code is present so we can render the SVG image. */
    public function imageAvailable(): bool
    {
        return class_exists(\Endroid\QrCode\Builder\Builder::class)
            && class_exists(\Endroid\QrCode\Writer\SvgWriter::class);
    }

    /**
     * Render the SPAYD string as an SVG data URI via endroid/qr-code.
     * SVG keeps the code sharp at any size and avoids the GD extension.
     */
    private function renderSvg(string $spayd): ?string
    {
        if (!$this->imageAvailable()) {
            return null;
        }

        try {
            return \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\SvgWriter())
                ->data($spayd)
                ->size(240)
                ->margin(8)
                ->build()
                ->getDataUri();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Render the SPAYD string as raw PNG bytes (for e-mail / hosted image URLs,
     * where SVG and data-URIs are not reliably displayed). Needs the GD
     * extension; returns null if PNG rendering is unavailable.
     */
    public function renderPngString(string $spayd): ?string
    {
        if (!class_exists(\Endroid\QrCode\Builder\Builder::class)
            || !class_exists(\Endroid\QrCode\Writer\PngWriter::class)
            || !extension_loaded('gd')) {
            return null;
        }

        try {
            return \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\PngWriter())
                ->data($spayd)
                ->size(300)
                ->margin(10)
                ->build()
                ->getString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildWithLibrary(
        int $vs,
        float $amount,
        string $currencyIso,
        ?string $comment,
        int $dueDays
    ): array {
        if ($this->iban) {
            $payment = new QrPayment(new IBAN($this->iban));
        } else {
            [$prefix, $number] = $this->splitAccount($this->account);
            $payment = new QrPayment(new CzechIbanAdapter(
                ($prefix !== '' ? $prefix . '-' : '') . $number,
                $this->bankCode
            ));
        }

        $payment->setVariableSymbol($vs)
            ->setAmount(round($amount, 2))
            ->setCurrency($currencyIso);

        if ($comment !== null && $comment !== '') {
            $payment->setComment($comment);
        }
        if ($dueDays > 0) {
            $payment->setDueDate(new DateTime('+' . $dueDays . ' days'));
        }

        return [
            'spayd' => $payment->getQrString(),
            'data_uri' => null, // set by build() via renderSvg()
            'iban' => (string) $payment->getIban(),
            'using_library' => true,
        ];
    }

    private function buildNative(
        int $vs,
        float $amount,
        string $currencyIso,
        ?string $comment,
        int $dueDays
    ): array {
        $iban = $this->iban ?: $this->computeCzechIban($this->account, $this->bankCode);

        $parts = [
            'SPD',
            '1.0',
            'ACC:' . $iban,
            'AM:' . number_format(round($amount, 2), 2, '.', ''),
            'CC:' . $currencyIso,
        ];
        if ($vs > 0) {
            $parts[] = 'X-VS:' . $vs;
        }
        if ($comment !== null && $comment !== '') {
            $parts[] = 'MSG:' . $comment;
        }
        if ($dueDays > 0) {
            $parts[] = 'DT:' . (new DateTime('+' . $dueDays . ' days'))->format('Ymd');
        }

        return [
            'spayd' => implode('*', $parts),
            'data_uri' => null, // no image without the library's QR provider
            'iban' => $iban,
            'using_library' => false,
        ];
    }

    /** SPAYD MSG: strip the field delimiter and clamp to 60 chars. */
    private function sanitizeMessage(?string $msg): ?string
    {
        if ($msg === null) {
            return null;
        }
        $msg = str_replace('*', ' ', $msg);
        $msg = trim(preg_replace('/\s+/', ' ', $msg));

        return mb_substr($msg, 0, 60);
    }

    /** @return array{0:string,1:string} [prefix, number] */
    private function splitAccount(string $account): array
    {
        $account = preg_replace('/\s+/', '', $account);
        if (strpos($account, '-') !== false) {
            [$prefix, $number] = explode('-', $account, 2);

            return [$prefix, $number];
        }

        return ['', $account];
    }

    /**
     * Compute a Czech IBAN from a (prefix-)account number + bank code.
     * BBAN = bank(4) + prefix(6) + number(10); check = 98 - mod97(BBAN + "123500").
     */
    private function computeCzechIban(string $account, string $bankCode): string
    {
        [$prefix, $number] = $this->splitAccount($account);
        $bban = sprintf('%04d%06d%010d', (int) $bankCode, (int) $prefix, (int) $number);
        $check = 98 - $this->mod97($bban . '123500'); // 'CZ' -> 12 35, plus '00'
        return sprintf('CZ%02d%s', $check, $bban);
    }

    /** mod 97 over an arbitrarily long numeric string. */
    private function mod97(string $numeric): int
    {
        $remainder = 0;
        $len = strlen($numeric);
        for ($i = 0; $i < $len; $i++) {
            $remainder = ($remainder * 10 + (int) $numeric[$i]) % 97;
        }

        return $remainder;
    }
}
