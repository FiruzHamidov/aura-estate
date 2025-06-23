<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use OsonSMS\OsonSMSService\OsonSmsService;
use RuntimeException;

class TestOsonSMSServiceCommand extends Command
{
    protected $signature = 'app:test-osonsms-service';
    public function __construct(private readonly OsonSmsService $osonSmsService) {
        parent::__construct();
    }
    public function handle(): void
    {
        try {
            // You need to provide senderName, phonenumber, message and txtId to sendSMS method in order to send SMS.
            $msgId = $this->osonSmsService->sendSMS(
                senderName: config('osonsmsservice.sender_name'),
                phonenumber: '918555581',
                message: 'Hello from Laravel. Your random code: ' . random_int(100, 1000),
                txnId: uniqid('test', true)
            );
            echo "SMS sent successfully with msg_id: $msgId" . PHP_EOL;
            // This is how you can check the sms status using msgId returned from sendSMS method.
            sleep(5); // Intentionally sleeping for 5 seconds in order to get the more accurate sms delivery status
            echo "SMS Status: " .  $this->osonSmsService->getSMSStatus($msgId) . PHP_EOL;
            // To get the balance of your account simply call getBalance() method
            echo "My Balance: " .  $this->osonSmsService->getBalance() . PHP_EOL;
        } catch (RuntimeException|JsonException $exception) {
            echo $exception->getMessage() . PHP_EOL;
        }
    }
}
