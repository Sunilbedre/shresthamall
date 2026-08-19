<?php
/**
 * app/VoucherPresenter.php
 * Shared formatting + QR URL helpers for success / scan / print views.
 */

declare(strict_types=1);

final class VoucherPresenter
{
    public static function details(array $customer, array $voucher): array
    {
        $eventDate = $voucher['event_date'] ?: Settings::get('event_date', date('Y-m-d'));
        return [
            'name'         => $customer['full_name'],
            'mobile'       => $customer['mobile_number'],
            'mobile_mask'  => Validation::maskMobile($customer['mobile_number']),
            'area'         => $customer['area'] ?? '',
            'code'         => $voucher['voucher_code'],
            'product'      => Products::label($customer['selected_product']) ?? $customer['selected_product'],
            'session'      => $customer['session'],
            'time_slot'    => VoucherService::formatVoucherTimeLabel($voucher),
            'offer_date'   => (new DateTimeImmutable($eventDate))->format('d M Y'),
            'offer_date_raw' => $eventDate,
            'store'        => Settings::get('store_name') . ' – ' . Settings::get('branch_name'),
            'address'      => Settings::get('store_address', ''),
            'maps'         => Settings::get('maps_link', ''),
            'contact'      => Settings::get('contact_number', ''),
            'status'       => VoucherService::checkStatus($voucher),
        ];
    }

    /** Public URL staff can open by scanning the QR. */
    public static function scanUrl(string $voucherCode): string
    {
        return app_url('v/' . rawurlencode($voucherCode));
    }

    /** QR image URL (PNG) for embedding in HTML / print / PDF views. */
    public static function qrImageUrl(string $voucherCode, int $size = 280): string
    {
        $data = self::scanUrl($voucherCode);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
            . '&margin=8&ecc=M&data=' . rawurlencode($data);
    }
}
