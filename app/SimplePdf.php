<?php
/**
 * app/SimplePdf.php
 * Tiny PDF builder (no Composer). Supports Helvetica text + JPEG images.
 */

declare(strict_types=1);

final class SimplePdf
{
    private array $pages = [];
    private array $images = [];
    private int $pageW = 595; // A4 points
    private int $pageH = 842;

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function text(float $x, float $y, string $text, float $size = 12, string $style = ''): void
    {
        $this->ensurePage();
        $font = match ($style) {
            'B' => 'F2',
            default => 'F1',
        };
        $escaped = $this->escape($text);
        // PDF y originates at bottom
        $pdfY = $this->pageH - $y;
        $this->pages[count($this->pages) - 1] .=
            "BT /{$font} {$size} Tf {$x} {$pdfY} Td ({$escaped}) Tj ET\n";
    }

    public function jpeg(float $x, float $y, float $w, float $h, string $jpegBytes): void
    {
        $this->ensurePage();
        $info = @getimagesizefromstring($jpegBytes);
        if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
            // Convert PNG/other to JPEG via GD when possible
            $src = @imagecreatefromstring($jpegBytes);
            if ($src === false) {
                return;
            }
            ob_start();
            imagejpeg($src, null, 90);
            imagedestroy($src);
            $jpegBytes = (string) ob_get_clean();
            $info = @getimagesizefromstring($jpegBytes);
            if ($info === false) {
                return;
            }
        }

        $imgIndex = count($this->images) + 1;
        $this->images[$imgIndex] = [
            'data' => $jpegBytes,
            'w' => (int) $info[0],
            'h' => (int) $info[1],
        ];

        $pdfY = $this->pageH - $y - $h;
        $this->pages[count($this->pages) - 1] .=
            "q {$w} 0 0 {$h} {$x} {$pdfY} cm /Im{$imgIndex} Do Q\n";
    }

    public function output(string $filename): never
    {
        $pdf = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        exit;
    }

    private function ensurePage(): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }
    }

    private function escape(string $text): string
    {
        // PDF core fonts are WinAnsi-ish; transliterate common chars
        $map = [
            '₹' => 'Rs.',
            '–' => '-',
            '—' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
            '•' => '-',
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function build(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        $pageCount = count($this->pages);
        $fontRegularObj = 3;
        $fontBoldObj = 4;
        $nextObj = 5;

        $objects[$fontRegularObj] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldObj] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $imageObjIds = [];
        foreach ($this->images as $idx => $img) {
            $objId = $nextObj++;
            $imageObjIds[$idx] = $objId;
            $len = strlen($img['data']);
            $objects[$objId] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
                . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n"
                . $img['data'] . "\nendstream";
        }

        $pageObjIds = [];
        $contentObjIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $contentObjIds[$i] = $nextObj++;
            $pageObjIds[$i] = $nextObj++;
        }

        $pagesKids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pagesKids[] = $pageObjIds[$i] . ' 0 R';
            $content = $this->pages[$i];
            $objects[$contentObjIds[$i]] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";

            $xobjects = '';
            if ($imageObjIds !== []) {
                $parts = [];
                foreach ($imageObjIds as $idx => $objId) {
                    $parts[] = "/Im{$idx} {$objId} 0 R";
                }
                $xobjects = ' /XObject << ' . implode(' ', $parts) . ' >>';
            }

            $objects[$pageObjIds[$i]] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageW} {$this->pageH}] "
                . "/Resources << /Font << /F1 {$fontRegularObj} 0 R /F2 {$fontBoldObj} 0 R >>{$xobjects} >> "
                . "/Contents {$contentObjIds[$i]} 0 R >>";
        }

        $objects[2] = '<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', $pagesKids) . '] >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $off = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }
}

final class VoucherPdf
{
    public static function download(array $customer, array $voucher): never
    {
        $d = VoucherPresenter::details($customer, $voucher);
        $qrBytes = self::fetchQrJpeg($voucher['voucher_code']);

        $pdf = new SimplePdf();
        $pdf->addPage();
        $pdf->text(50, 50, 'Shreeshta Family Store', 16, 'B');
        $pdf->text(50, 72, 'Rs.1 Special Offer Voucher', 14, 'B');
        $pdf->text(50, 95, $d['store'], 11);

        $pdf->text(50, 130, 'Customer Name:', 11);
        $pdf->text(180, 130, $d['name'], 11, 'B');
        $pdf->text(50, 150, 'Mobile:', 11);
        $pdf->text(180, 150, $d['mobile'], 11, 'B');
        if ($d['area'] !== '') {
            $pdf->text(50, 170, 'Area:', 11);
            $pdf->text(180, 170, $d['area'], 11, 'B');
        }
        $pdf->text(50, 190, 'Voucher Code:', 11);
        $pdf->text(180, 190, $d['code'], 12, 'B');
        $pdf->text(50, 210, 'Selected Product:', 11);
        $pdf->text(180, 210, $d['product'], 11, 'B');
        $pdf->text(50, 230, 'Offer Date:', 11);
        $pdf->text(180, 230, $d['offer_date'], 11, 'B');
        $pdf->text(50, 250, 'Time Slot:', 11);
        $pdf->text(180, 250, $d['time_slot'], 11, 'B');

        if ($qrBytes !== null) {
            $pdf->jpeg(50, 290, 180, 180, $qrBytes);
            $pdf->text(50, 485, 'Scan QR at store counter', 10);
        }

        $pdf->text(50, 520, 'Please take a screenshot of your voucher as a backup.', 10, 'B');
        $pdf->text(50, 540, 'One customer / One mobile number / One voucher / One product.', 10);
        $pdf->text(50, 560, 'Show this voucher (or WhatsApp message) at verification.', 10);
        if ($d['address'] !== '') {
            $pdf->text(50, 590, $d['address'], 9);
        }

        $safeCode = preg_replace('/[^A-Za-z0-9\-]/', '', $d['code']) ?: 'voucher';
        $pdf->output('shreeshta-voucher-' . $safeCode . '.pdf');
    }

    private static function fetchQrJpeg(string $code): ?string
    {
        $url = VoucherPresenter::qrImageUrl($code, 400);
        $ca = APP_ROOT . '/storage/certs/cacert.pem';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
        ];
        if (is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $data = curl_exec($ch);
        $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $codeHttp >= 400) {
            return null;
        }
        // QR API returns PNG — convert to JPEG for SimplePdf
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $bg = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefilledrectangle($bg, 0, 0, $w, $h, $white);
        imagecopy($bg, $img, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($bg, null, 92);
        imagedestroy($img);
        imagedestroy($bg);
        return (string) ob_get_clean();
    }
}
