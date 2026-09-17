<?php
/**
 * app/CustomerService.php
 * Registration workflow. The one-mobile-one-voucher rule is enforced at
 * TWO levels:
 *   1. Application level: SELECT check before insert (fast path, friendly message)
 *   2. Database level: UNIQUE constraint on customers.mobile_number (hard backstop
 *      against race conditions — two simultaneous submits with the same number)
 * Both must hold. Never remove the DB unique index.
 */

declare(strict_types=1);

final class CustomerService
{
    public const ERR_DUPLICATE_MOBILE = 'DUPLICATE_MOBILE';
    public const ERR_REGISTRATION_CLOSED = 'REGISTRATION_CLOSED';
    public const ERR_PRODUCT_FULL = 'PRODUCT_FULL';
    public const ERR_VALIDATION = 'VALIDATION';
    public const ERR_SLOT_INVALID = 'SLOT_INVALID';
    public const ERR_CAMPAIGN_MOBILE_USED = 'CAMPAIGN_MOBILE_USED';

    /**
     * Attempts to register a customer + generate their voucher in one DB transaction.
     *
     * @return array{ok:bool, error?:string, fields?:array, customer?:array, voucher?:array}
     */
    public static function register(array $input, string $ip, string $userAgent): array
    {
        if (Settings::get('registration_status', 'OPEN') !== 'OPEN') {
            return ['ok' => false, 'error' => self::ERR_REGISTRATION_CLOSED];
        }

        // ---- Validate ----------------------------------------------------
        $errors = [];

        $name = Validation::validateName((string) ($input['full_name'] ?? ''));
        if ($name === null) {
            $errors['full_name'] = 'Please enter a valid name (2–80 letters).';
        }

        $mobile = Validation::normaliseMobile((string) ($input['mobile_number'] ?? ''));
        if ($mobile === null) {
            $errors['mobile_number'] = 'Please enter a valid 10-digit Indian mobile number.';
        } elseif (SmsAlertService::isEnabled() && !OtpService::isVerified($mobile)) {
            $errors['otp'] = 'Please verify your mobile number with OTP.';
        }

        $slotId = (int) ($input['offer_slot_id'] ?? 0);
        $slot = $slotId > 0 ? OfferCatalog::findSlot($slotId) : null;
        if ($slot === null || (int) ($slot['active'] ?? 0) !== 1) {
            $errors['offer_slot_id'] = 'Please select a product date and time slot.';
        } elseif (!OfferCatalog::productExists((string) $slot['product_key'])) {
            $errors['offer_slot_id'] = 'Selected offer is not available.';
        }

        $consent = !empty($input['consent']);
        if (!$consent) {
            $errors['consent'] = 'Please agree to the Terms & Conditions and WhatsApp updates to continue.';
        }

        $area = Validation::validateArea((string) ($input['area'] ?? ''));
        if ($area === null) {
            $errors['area'] = 'Please select the area you are coming from.';
        }

        $ageGroup = isset($input['age_group']) ? trim((string) $input['age_group']) : null;
        $gender = isset($input['gender']) ? trim((string) $input['gender']) : null;

        if (!empty($errors)) {
            return ['ok' => false, 'error' => self::ERR_VALIDATION, 'fields' => $errors];
        }

        $productKey = (string) $slot['product_key'];
        $session = (string) $slot['session'];
        $eventDate = (string) $slot['event_date'];

        $pdo = Database::connection();

        // ---- Fast-path duplicate check (weekend = one mobile per non-campaign registration) ----
        $existing = self::findWeekendByMobile($mobile);
        if ($existing !== null) {
            return ['ok' => false, 'error' => self::ERR_DUPLICATE_MOBILE, 'customer' => $existing];
        }

        // ---- Transaction: limit check + insert customer + voucher atomically ----------
        $pdo->beginTransaction();
        try {
            // Capacity for this exact product × date × slot
            $capacity = (int) $slot['capacity'];
            if ($capacity > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM customers
                    WHERE selected_product = :p AND session = :s AND event_date = :d
                ");
                $stmt->execute(['p' => $productKey, 's' => $session, 'd' => $eventDate]);
                $count = (int) $stmt->fetchColumn();
                if ($count >= $capacity) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => self::ERR_PRODUCT_FULL];
                }
            }

            $insertCustomer = $pdo->prepare("
                INSERT INTO customers
                    (full_name, mobile_number, selected_product, session, event_date, offer_slot_id, campaign_id, area, age_group, gender, consent, ip_address, user_agent, registered_at)
                VALUES
                    (:full_name, :mobile_number, :selected_product, :session, :event_date, :offer_slot_id, NULL, :area, :age_group, :gender, 1, :ip, :ua, datetime('now'))
            ");
            $insertCustomer->execute([
                'full_name' => $name,
                'mobile_number' => $mobile,
                'selected_product' => $productKey,
                'session' => $session,
                'event_date' => $eventDate,
                'offer_slot_id' => $slotId,
                'area' => $area ?: null,
                'age_group' => $ageGroup ?: null,
                'gender' => $gender ?: null,
                'ip' => $ip,
                'ua' => mb_substr($userAgent, 0, 255),
            ]);
            $customerId = (int) $pdo->lastInsertId();

            $window = VoucherService::slotWindow($slot);
            $voucherCode = VoucherService::generateCode();

            $insertVoucher = $pdo->prepare("
                INSERT INTO vouchers
                    (customer_id, voucher_code, event_date, session_start, session_end, status, created_at)
                VALUES
                    (:customer_id, :voucher_code, :event_date, :session_start, :session_end, 'ACTIVE', datetime('now'))
            ");
            $insertVoucher->execute([
                'customer_id' => $customerId,
                'voucher_code' => $voucherCode,
                'event_date' => $eventDate,
                'session_start' => $window['start']->format('Y-m-d H:i:s'),
                'session_end' => $window['end']->format('Y-m-d H:i:s'),
            ]);
            $voucherId = (int) $pdo->lastInsertId();

            AuditLog::record('system', 'REGISTRATION_CREATED', "customer_id={$customerId} mobile={$mobile} product={$productKey} date={$eventDate} session={$session}", $ip);

            $pdo->commit();
            OtpService::clear();
        } catch (PDOException $e) {
            $pdo->rollBack();
            // UNIQUE constraint race: someone else registered this exact mobile microseconds earlier.
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $existing = self::findByMobile($mobile);
                return ['ok' => false, 'error' => self::ERR_DUPLICATE_MOBILE, 'customer' => $existing];
            }
            throw $e;
        }

        $customer = self::findById($customerId);
        $voucher = VoucherModel::findById($voucherId);

        return ['ok' => true, 'customer' => $customer, 'voucher' => $voucher];
    }

    /**
     * Special-event registration (separate link per product, daily capacity, one mobile per campaign).
     *
     * @return array{ok:bool, error?:string, fields?:array, customer?:array, voucher?:array}
     */
    public static function registerSpecial(array $input, array $campaign, array $product, string $ip, string $userAgent): array
    {
        if (Settings::get('registration_status', 'OPEN') !== 'OPEN') {
            return ['ok' => false, 'error' => self::ERR_REGISTRATION_CLOSED];
        }
        if (!CampaignService::isOpen($campaign)) {
            return ['ok' => false, 'error' => self::ERR_REGISTRATION_CLOSED];
        }
        if (CampaignService::isProductFull($product)) {
            return ['ok' => false, 'error' => self::ERR_PRODUCT_FULL];
        }

        $errors = [];
        $name = Validation::validateName((string) ($input['full_name'] ?? ''));
        if ($name === null) {
            $errors['full_name'] = 'Please enter a valid name (2–80 letters).';
        }

        $mobile = Validation::normaliseMobile((string) ($input['mobile_number'] ?? ''));
        if ($mobile === null) {
            $errors['mobile_number'] = 'Please enter a valid 10-digit Indian mobile number.';
        } elseif (SmsAlertService::isEnabled() && !OtpService::isVerified($mobile)) {
            $errors['otp'] = 'Please verify your mobile number with OTP.';
        }

        $session = (string) ($input['session'] ?? '');
        if (!Products::isValidSession($session)) {
            $errors['session'] = 'Please select a time slot.';
        }

        $consent = !empty($input['consent']);
        if (!$consent) {
            $errors['consent'] = 'Please agree to the Terms & Conditions and WhatsApp updates to continue.';
        }

        $area = Validation::validateArea((string) ($input['area'] ?? ''));
        if ($area === null) {
            $errors['area'] = 'Please select the area you are coming from.';
        }

        if (!empty($errors)) {
            return ['ok' => false, 'error' => self::ERR_VALIDATION, 'fields' => $errors];
        }

        $campaignId = (int) $campaign['id'];
        $productKey = (string) $product['product_key'];
        $eventDate = (string) $campaign['event_date'];
        $dailyCap = (int) $product['daily_capacity'];

        $existingCampaign = self::findByMobileInCampaign($mobile, $campaignId);
        if ($existingCampaign !== null) {
            return ['ok' => false, 'error' => self::ERR_CAMPAIGN_MOBILE_USED, 'customer' => $existingCampaign];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($dailyCap > 0 && $dailyCap < 99999) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM customers
                    WHERE campaign_id = :cid AND selected_product = :p AND event_date = :d
                ");
                $stmt->execute(['cid' => $campaignId, 'p' => $productKey, 'd' => $eventDate]);
                if ((int) $stmt->fetchColumn() >= $dailyCap) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => self::ERR_PRODUCT_FULL];
                }
            }

            $insertCustomer = $pdo->prepare("
                INSERT INTO customers
                    (full_name, mobile_number, selected_product, session, event_date, offer_slot_id, campaign_id, area, age_group, gender, consent, ip_address, user_agent, registered_at)
                VALUES
                    (:full_name, :mobile_number, :selected_product, :session, :event_date, NULL, :campaign_id, :area, :age_group, :gender, 1, :ip, :ua, datetime('now'))
            ");
            $insertCustomer->execute([
                'full_name' => $name,
                'mobile_number' => $mobile,
                'selected_product' => $productKey,
                'session' => $session,
                'event_date' => $eventDate,
                'campaign_id' => $campaignId,
                'area' => $area ?: null,
                'age_group' => null,
                'gender' => null,
                'ip' => $ip,
                'ua' => mb_substr($userAgent, 0, 255),
            ]);
            $customerId = (int) $pdo->lastInsertId();

            $window = CampaignService::sessionWindow($campaign, $session);
            $voucherCode = VoucherService::generateCode();

            $insertVoucher = $pdo->prepare("
                INSERT INTO vouchers
                    (customer_id, voucher_code, event_date, session_start, session_end, status, created_at)
                VALUES
                    (:customer_id, :voucher_code, :event_date, :session_start, :session_end, 'ACTIVE', datetime('now'))
            ");
            $insertVoucher->execute([
                'customer_id' => $customerId,
                'voucher_code' => $voucherCode,
                'event_date' => $eventDate,
                'session_start' => $window['start']->format('Y-m-d H:i:s'),
                'session_end' => $window['end']->format('Y-m-d H:i:s'),
            ]);
            $voucherId = (int) $pdo->lastInsertId();

            AuditLog::record(
                'system',
                'SPECIAL_REGISTRATION_CREATED',
                "campaign_id={$campaignId} customer_id={$customerId} mobile={$mobile} product={$productKey} date={$eventDate} session={$session}",
                $ip
            );

            $pdo->commit();
            OtpService::clear();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $existing = self::findByMobileInCampaign($mobile, $campaignId)
                    ?? self::findWeekendByMobile($mobile);
                if ($existing !== null) {
                    $err = ((int) ($existing['campaign_id'] ?? 0) === $campaignId)
                        ? self::ERR_CAMPAIGN_MOBILE_USED
                        : self::ERR_DUPLICATE_MOBILE;
                    return ['ok' => false, 'error' => $err, 'customer' => $existing];
                }
            }
            throw $e;
        }

        return [
            'ok' => true,
            'customer' => self::findById($customerId),
            'voucher' => VoucherModel::findById($voucherId),
        ];
    }

    /** Any registration on this mobile (legacy helper). */
    public static function findByMobile(string $normalisedMobile): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE mobile_number = :m LIMIT 1');
        $stmt->execute(['m' => $normalisedMobile]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Weekend / regular offer registration (campaign_id IS NULL). */
    public static function findWeekendByMobile(string $normalisedMobile): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE mobile_number = :m AND campaign_id IS NULL LIMIT 1');
        $stmt->execute(['m' => $normalisedMobile]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByMobileInCampaign(string $normalisedMobile, int $campaignId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE mobile_number = :m AND campaign_id = :cid LIMIT 1');
        $stmt->execute(['m' => $normalisedMobile, 'cid' => $campaignId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

final class VoucherModel
{
    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByCustomerId(int $customerId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE customer_id = :cid LIMIT 1");
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE voucher_code = :code LIMIT 1");
        $stmt->execute(['code' => strtoupper(trim($code))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Atomically redeems a voucher. Re-checks status inside the transaction
     * (row lock via BEGIN IMMEDIATE) to prevent double redemption from two
     * staff members tapping "Redeem" at the same moment.
     */
    public static function redeem(int $voucherId, string $staffUsername, ?string $billingRef, bool $allowOutsideWindow = false): array
    {
        $pdo = Database::connection();
        // NOTE: PDO's beginTransaction()/commit()/rollBack() cannot express SQLite's
        // BEGIN IMMEDIATE (write-lock-on-start) semantics, and once we issue BEGIN
        // IMMEDIATE via a raw exec(), PDO no longer considers itself "in a
        // transaction" — so commit/rollback must also go through exec() here,
        // consistently, rather than mixing with the PDO transaction API.
        $pdo->exec('BEGIN IMMEDIATE'); // acquire write lock immediately (SQLite)
        try {
            $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $voucherId]);
            $voucher = $stmt->fetch();

            if (!$voucher) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'NOT_FOUND'];
            }

            $liveStatus = VoucherService::checkStatus($voucher);
            $canRedeem = $liveStatus === 'VALID'
                || ($allowOutsideWindow && in_array($liveStatus, ['NOT_ACTIVE_YET', 'TIME_EXPIRED'], true) && ($voucher['status'] ?? '') === 'ACTIVE');

            if (!$canRedeem) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => $liveStatus];
            }

            $update = $pdo->prepare("
                UPDATE vouchers
                SET status = 'REDEEMED', redeemed_at = datetime('now'), redeemed_by = :staff, billing_reference = :ref
                WHERE id = :id AND status = 'ACTIVE'
            ");
            $update->execute(['staff' => $staffUsername, 'ref' => $billingRef, 'id' => $voucherId]);

            if ($update->rowCount() === 0) {
                // Someone else redeemed it in the split second between our check and update
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'ALREADY_REDEEMED'];
            }

            $detail = "voucher_id={$voucherId}";
            if ($allowOutsideWindow && $liveStatus !== 'VALID') {
                $detail .= " outside_window={$liveStatus}";
            }
            AuditLog::record($staffUsername, 'VOUCHER_REDEEMED', $detail, $_SERVER['REMOTE_ADDR'] ?? null);
            $pdo->exec('COMMIT');

            $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $voucherId]);
            return ['ok' => true, 'voucher' => $stmt->fetch()];
        } catch (Throwable $e) {
            // Best-effort rollback; ignore if there's nothing to roll back.
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
    }

    public static function setStatus(int $voucherId, string $status, string $actor): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE vouchers SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $voucherId]);
        AuditLog::record($actor, 'VOUCHER_STATUS_CHANGED', "voucher_id={$voucherId} status={$status}", $_SERVER['REMOTE_ADDR'] ?? null);
    }
}

final class AuditLog
{
    public static function record(string $actor, string $action, ?string $details, ?string $ip): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (actor, action, details, ip_address, created_at)
            VALUES (:actor, :action, :details, :ip, datetime('now'))
        ");
        $stmt->execute(['actor' => $actor, 'action' => $action, 'details' => $details, 'ip' => $ip]);
    }
}
