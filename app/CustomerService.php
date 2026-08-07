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
        }

        $productKey = (string) ($input['selected_offer'] ?? '');
        if (!Validation::validateProductKey($productKey)) {
            $errors['selected_offer'] = 'Please select one offer.';
        }

        $session = (string) ($input['session'] ?? '');
        if (!Products::isValidSession($session)) {
            $errors['session'] = 'Please select a time slot.';
        }

        $consent = !empty($input['consent']);
        if (!$consent) {
            $errors['consent'] = 'Please accept the consent checkbox to continue.';
        }

        $area = Validation::validateArea((string) ($input['area'] ?? ''));
        if ($area === null) {
            $errors['area'] = 'Please select the Bengaluru area you are coming from.';
        }

        $ageGroup = isset($input['age_group']) ? trim((string) $input['age_group']) : null;
        $gender = isset($input['gender']) ? trim((string) $input['gender']) : null;

        if (!empty($errors)) {
            return ['ok' => false, 'error' => self::ERR_VALIDATION, 'fields' => $errors];
        }

        $pdo = Database::connection();

        // ---- Fast-path duplicate check (pre-transaction, friendly UX) ----
        $existing = self::findByMobile($mobile);
        if ($existing !== null) {
            return ['ok' => false, 'error' => self::ERR_DUPLICATE_MOBILE, 'customer' => $existing];
        }

        // ---- Transaction: limit check + insert customer + voucher atomically ----------
        $pdo->beginTransaction();
        try {
            // Per product × time slot caps (saree 500 / others 100 by default)
            if (Settings::get('product_limits_enabled', '1') === '1') {
                $limitKey = Products::limitKeyFor($productKey);
                $limit = (int) Settings::get($limitKey, (string) Products::defaultLimit($productKey));
                if ($limit > 0) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM customers
                        WHERE selected_product = :p AND session = :s
                    ");
                    $stmt->execute(['p' => $productKey, 's' => $session]);
                    $count = (int) $stmt->fetchColumn();
                    if ($count >= $limit) {
                        $pdo->rollBack();
                        return ['ok' => false, 'error' => self::ERR_PRODUCT_FULL];
                    }
                }
            }

            $insertCustomer = $pdo->prepare("
                INSERT INTO customers
                    (full_name, mobile_number, selected_product, session, area, age_group, gender, consent, ip_address, user_agent, registered_at)
                VALUES
                    (:full_name, :mobile_number, :selected_product, :session, :area, :age_group, :gender, 1, :ip, :ua, datetime('now'))
            ");
            $insertCustomer->execute([
                'full_name' => $name,
                'mobile_number' => $mobile,
                'selected_product' => $productKey,
                'session' => $session,
                'area' => $area ?: null,
                'age_group' => $ageGroup ?: null,
                'gender' => $gender ?: null,
                'ip' => $ip,
                'ua' => mb_substr($userAgent, 0, 255),
            ]);
            $customerId = (int) $pdo->lastInsertId();

            $eventDate = Settings::get('event_date', date('Y-m-d'));
            $window = VoucherService::sessionWindow($eventDate, $session);
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

            AuditLog::record('system', 'REGISTRATION_CREATED', "customer_id={$customerId} mobile={$mobile} product={$productKey} session={$session}", $ip);

            $pdo->commit();
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

    public static function findByMobile(string $normalisedMobile): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE mobile_number = :m LIMIT 1");
        $stmt->execute(['m' => $normalisedMobile]);
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
