<?php

namespace Rohama\Koin\Nextpay;

use GuzzleHttp\Client;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Support\PaymentStatus;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\PaymentGateway;
use Rohama\Koin\RefundableGateway;
use Rohama\Koin\Requests\Callback;
use Rohama\Koin\Responses\PaymentResponse;
use Rohama\Koin\Responses\RefundResponse;
use Rohama\Koin\Responses\VerificationResponse;
use Rohama\Koin\Traits\IsPayment;
use Rohama\Translator\Translator;

/**
 * NextPay gateway — direct gateway REST web service.
 */
class NextpayGateway implements PaymentGateway, RefundableGateway
{
    use IsPayment { __construct as protected paymentConstruct; }

    public const name = 'nextpay';

    private const TOKEN_CODE_ISSUED = -1;
    private const VERIFY_CODE_SUCCESS = 0;
    private const VERIFY_CODE_AWAITING_PAYMENT = -1;
    private const VERIFY_CODE_AWAITING_BANK = -3;
    private const VERIFY_CODE_REJECTED = -2;
    private const VERIFY_CODE_CANCELED = -4;
    private const REFUND_CODE_SUCCESS = -90;

    private const RESULT_CODES = [
        0 => 'payment completed successfully',
        -1 => 'awaiting payment',
        -2 => 'rejected by user or bank',
        -3 => 'awaiting bank response',
        -4 => 'payment canceled',
        -20 => 'api_key missing',
        -21 => 'trans_id missing',
        -22 => 'amount missing',
        -23 => 'link missing',
        -24 => 'amount is not valid',
        -25 => 'transaction already processed',
        -26 => 'token missing',
        -27 => 'order id is not valid',
        -28 => 'custom_json_fields is not valid JSON',
        -29 => 'refund code is not valid',
        -30 => 'amount is below the minimum',
        -31 => 'wallet not found',
        -32 => 'callback path is not valid',
        -33 => 'api_key is not valid',
        -34 => 'transaction code is not valid',
        -35 => 'api_key structure is not valid',
        -36 => 'order id missing',
        -37 => 'transaction not found',
        -38 => 'token not found',
        -39 => 'api_key not found',
        -40 => 'api_key blocked',
        -41 => 'bank validation id missing',
        -42 => 'payment system error',
        -43 => 'gateway not found',
        -44 => 'invalid bank response',
        -45 => 'payment system inactive',
        -46 => 'invalid request',
        -47 => 'api_key deleted',
        -48 => 'commission rate not set',
        -49 => 'duplicate transaction',
        -50 => 'wallet not found',
        -51 => 'user id not found',
        -52 => 'account not verified',
        -60 => 'email is not valid',
        -61 => 'national code is not valid',
        -62 => 'postal code is not valid',
        -63 => 'postal address is not valid',
        -64 => 'description is not valid or too long',
        -65 => 'name is not valid or too long',
        -66 => 'phone is not valid',
        -67 => 'username is not valid',
        -68 => 'product name is not valid',
        -69 => 'success return address is not valid',
        -70 => 'failure return address is not valid',
        -71 => 'mobile is not valid',
        -72 => 'bank did not respond',
        -73 => 'callback path has an error or is too long',
        -90 => 'refund completed successfully',
        -91 => 'refund operation failed',
        -92 => 'refund error',
        -93 => 'insufficient wallet balance for refund',
        -94 => 'refund key not found',
    ];

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [])
    {
        $this->paymentConstruct($apiKey, $callbackUrl, $sandbox, $extra);

        Translator::register('Koin-' . self::name, dirname(__DIR__) . '/lang');
        $this->client = new Client([
            'base_uri' => 'https://nextpay.org/nx/gateway',
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public static function configFields(): array
    {
        $prefix = 'Koin-' . self::name;

        return [
            [
                'key' => 'apiKey',
                'name' => Translator::trans($prefix . ':fields.apiKey'),
                'desc' => Translator::trans($prefix . ':fields.apiKey_help'),
                'type' => 'text',
                'required' => true,
                'validation' => ['required', 'string'],
                'regex' => '/^.+$/',
            ],
        ];
    }

    public function request(
        int $amount,
        string $orderId = '',
        ?string $callbackUrl = null,
        string $description = '',
        ?string $mobile = null,
        ?string $email = null,
        ?string $currency = null,
        array $metadata = [],
        array $extra = [],
    ): PaymentResponse {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        if ($orderId === '') {
            throw new InvalidRequestException('NextPay requires an order id.', gateway: self::name);
        }

        if ($callbackUrl === null && $this->callbackUrl === null) {
            throw new InvalidRequestException('NextPay requires a callback URL.', gateway: self::name);
        }

        $response = $this->client()->post('/token', [
            'json' => array_filter(array_merge([
                'api_key' => $this->apiKey,
                'order_id' => $orderId,
                'amount' => $amount,
                'callback_uri' => $callbackUrl ?? $this->callbackUrl,
                'currency' => ($currency ?? Currency::TOMAN),
                'customer_phone' => $mobile,
                'payer_name' => $metadata['name'] ?? null,
                'payer_desc' => $description,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $code = (int) ($data['code'] ?? -99);
        $transId = isset($data['trans_id']) ? (string) $data['trans_id'] : '';

        if ($code !== self::TOKEN_CODE_ISSUED || $transId === '') {
            throw new PaymentFailedException($this->resultError($code), $code, raw: $data, gateway: self::name);
        }

        return new PaymentResponse(
            $data,
            $response,
            PaymentStatus::PENDING,
            $transId,
            'https://nextpay.org/nx/gateway/payment/' . $transId,
            $orderId,
            null,
        );
    }

    public function parseCallback(array $query, array $body): Callback
    {
        $raw = array_merge($query, $body);
        $transId = isset($raw['trans_id']) ? (string) $raw['trans_id'] : '';
        $orderId = isset($raw['order_id']) ? (string) $raw['order_id'] : '';
        $amount = isset($raw['amount']) ? (int) $raw['amount'] : null;

        if ($transId === '') {
            throw new InvalidRequestException('NextPay invalid Callback payload.', gateway: self::name);
        }

        $verified = $this->verify($transId, $orderId, $amount);

        return new Callback($verified->status, $transId, $orderId, $raw);
    }

    public function verify(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        if ($amount === null) {
            throw new InvalidRequestException('NextPay requires the original amount for verification.', gateway: self::name);
        }

        $response = $this->client()->post('/verify', [
            'json' => array_filter(array_merge([
                'api_key' => $this->apiKey,
                'trans_id' => $transactionId,
                'amount' => $amount,
                'currency' => ($currency ?? Currency::TOMAN),
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $code = (int) ($data['code'] ?? -99);

        if ($code !== self::VERIFY_CODE_SUCCESS) {
            if ($code === self::VERIFY_CODE_AWAITING_PAYMENT || $code === self::VERIFY_CODE_AWAITING_BANK) {
                return new VerificationResponse(
                    $data,
                    $response,
                    PaymentStatus::PENDING,
                    $transactionId,
                    null,
                    null,
                    null,
                    $this->resultError($code),
                );
            }

            if ($code === self::VERIFY_CODE_CANCELED) {
                return new VerificationResponse(
                    $data,
                    $response,
                    PaymentStatus::CANCELED,
                    $transactionId,
                    null,
                    null,
                    null,
                    $this->resultError($code),
                );
            }

            if ($code === self::VERIFY_CODE_REJECTED) {
                return new VerificationResponse(
                    $data,
                    $response,
                    PaymentStatus::FAILED,
                    $transactionId,
                    null,
                    null,
                    null,
                    $this->resultError($code),
                );
            }

            throw new VerificationFailedException($this->resultError($code), $code, raw: $data, gateway: self::name);
        }

        if (isset($data['amount']) && (int) $data['amount'] !== $amount) {
            throw new VerificationFailedException(
                sprintf('NextPay verified amount mismatch: expected %d, got %s.', $amount, $data['amount']),
                0,
                raw: ['expected' => $amount, 'actual' => $data['amount']],
                gateway: self::name,
            );
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::SUCCESS,
            $transactionId,
            isset($data['Shaparak_Ref_Id']) ? (string) $data['Shaparak_Ref_Id'] : null,
            isset($data['amount']) ? (int) $data['amount'] : $amount,
            isset($data['card_holder']) ? (string) $data['card_holder'] : null,
            null,
        );
    }

    public function refund(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): RefundResponse {
        if ($amount === null) {
            throw new InvalidRequestException('NextPay requires the amount to refund.', gateway: self::name);
        }

        $response = $this->client()->post('/verify', [
            'json' => array_filter(array_merge([
                'api_key' => $this->apiKey,
                'trans_id' => $transactionId,
                'amount' => $amount,
                'refund_request' => 'yes_money_back',
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $code = (int) ($data['code'] ?? -99);

        if ($code !== self::REFUND_CODE_SUCCESS) {
            throw new PaymentFailedException($this->resultError($code), $code, raw: $data, gateway: self::name);
        }

        return new RefundResponse(
            $data,
            $response,
            PaymentStatus::REFUNDED,
            $transactionId,
            isset($data['Shaparak_Ref_Id']) ? (string) $data['Shaparak_Ref_Id'] : null,
            null,
        );
    }

    /**
     * Builds an error message from a NextPay result code.
     */
    private function resultError(int $code): string
    {
        $label = self::RESULT_CODES[$code] ?? 'unknown';
        return sprintf('NextPay error (code %d): %s', $code, $label);
    }
}
