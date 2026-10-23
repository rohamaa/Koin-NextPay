<?php

use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\Nextpay\NextpayGateway;
use Rohama\Translator\Translator;

final class NextpayGatewayTest extends TestCase
{
    public function testParseCallbackVerifiesTheTransactionLikeIdpay(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => 0, 'amount' => 1000]]]));
        $result = $gateway->parseCallback(['trans_id' => 't1', 'order_id' => 'o1', 'amount' => '1000'], []);

        $this->assertSame('success', $result->status);
        $this->assertSame('t1', $result->transactionId);
        $this->assertSame('o1', $result->orderId);
    }

    public function testParseCallbackMapsACanceledPaymentToCanceled(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -4]]]));
        $result = $gateway->parseCallback(['trans_id' => 't1', 'order_id' => 'o1', 'amount' => '1000'], []);

        $this->assertSame('canceled', $result->status);
    }

    public function testParseCallbackThrowsWithoutATransactionId(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new NextpayGateway('k'))->parseCallback([], ['order_id' => 'o1']);
    }

    public function testVerifyMapsARejectedPaymentMinus2ToFailedOutcome(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -2]]]));

        $result = $gateway->verify('t1', 'o1', 1000);

        $this->assertSame('failed', $result->status);
    }

    public function testVerifyReturnsSuccessWithCardHolderAndAmount(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['code' => 0, 'amount' => 1000, 'Shaparak_Ref_Id' => 'r1', 'card_holder' => '5022-29**-****-5020']],
        ]));

        $result = $gateway->verify('t1', 'o1', 1000);

        $this->assertSame('success', $result->status);
        $this->assertSame('r1', $result->referenceId);
        $this->assertSame('5022-29**-****-5020', $result->cardNumber);
    }

    public function testRefundReturnsRefundedStatus(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -90, 'amount' => 1000]]]));

        $result = $gateway->refund('t1', 'o1', 1000);

        $this->assertSame('refunded', $result->status);
        $this->assertSame('t1', $result->transactionId);
    }

    public function testRefundThrowsForAFailureCode(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -93]]]));

        $this->expectException(PaymentFailedException::class);
        $this->expectExceptionMessage('code -93');
        $gateway->refund('t1', 'o1', 1000);
    }

    public function testVerifyReturnsCanceledStatusForARejectedTransaction(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -4]]]));

        $result = $gateway->verify('t1', 'o1', 1000);

        $this->assertSame('canceled', $result->status);
    }

    public function testVerifyReturnsPendingStatusForAwaitingBankResponse(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -3]]]));

        $result = $gateway->verify('t1', 'o1', 1000);

        $this->assertSame('pending', $result->status);
    }

    public function testVerifyReturnsSuccessAndAmount(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['code' => 0, 'amount' => 1000, 'Shaparak_Ref_Id' => 'r1']],
        ]));

        $result = $gateway->verify('t1', 'o1', 1000);

        $this->assertSame('success', $result->status);
        $this->assertSame('r1', $result->referenceId);
        $this->assertNull($result->cardNumber);
    }

    public function testVerifyThrowsForAnErrorCode(): void
    {
        $gateway = new NextpayGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['code' => -33]]]));

        $this->expectException(VerificationFailedException::class);
        $this->expectExceptionMessage('code -33');
        $gateway->verify('t1', 'o1', 1000);
    }

    public function testConfigFieldsListsTheNextpayInputs(): void
    {
        $keys = array_map(fn ($f) => $f['key'], NextpayGateway::configFields());

        $this->assertSame(['apiKey'], $keys);
    }

    public function testLangFilesCoverTheNextpayName(): void
    {
        $this->assertSame('NextPay', Translator::trans('Koin-nextpay:driver.name'));
    }
}
