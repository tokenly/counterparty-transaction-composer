<?php

use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use Tokenly\BitcoinAddressLib\BitcoinAddressGenerator;
use Tokenly\CounterpartyTransactionComposer\Composer;
use Tokenly\CounterpartyTransactionComposer\OpReturnBuilder;
use \PHPUnit_Framework_Assert as PHPUnit;

/*
* 
*/
class ComposerTest extends \PHPUnit_Framework_TestCase
{

    const SATOSHI = 100000000;

    public function testComposeOpReturn() {
        $op_return_builder = new OpReturnBuilder();

        $composer = new Composer();
        $fake_txid = 'deadbeef00000000000000000000000000000000000000000000000000001111';
        $hex = $this->arc4decrypt($fake_txid, $op_return_builder->buildOpReturn(5, 'SOUP', $fake_txid));

        // 434e545250525459 | 00000000 | 000000000004fadf | 000000001dcd6500
        // prefix             type       asset              amount
        $expected_hex = '434e545250525459'.'00000000'.'000000000004fadf'.'000000001dcd6500';
        PHPUnit::assertEquals($expected_hex, $hex);
    }

    public function testComposeCounterpartyTransaction() {
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'SOUP';
        $quantity    = 45;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;
        $btc_dust    = 0.00005432;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $sender_address, $fee, $btc_dust);

        // parse the signed hex
        $transaction = TransactionFactory::fromHex($signed_hex);

        // check output 1
        $tx_output_0 = $transaction->getOutput(0);
        PHPUnit::assertEquals(intval(round($btc_dust * self::SATOSHI)), $tx_output_0->getValue());
        PHPUnit::assertEquals($destination, AddressFactory::fromOutputScript($tx_output_0->getScript())->getAddress());

        // check output 2
        $tx_output_1 = $transaction->getOutput(1);
        $op_return = $tx_output_1->getScript()->getScriptParser()->parse()[1]->getHex();
        $txid = $transaction->getInput(0)->getTransactionId();
        $hex = $this->arc4decrypt($txid, $op_return);
        $expected_hex = '434e54525052545900000000000000000004fadf000000010c388d00';
        PHPUnit::assertEquals($expected_hex, $hex);

        // check output 3
        $tx_output_2 = $transaction->getOutput(2);
        PHPUnit::assertEquals(intval(round((0.123 + 0.0005 - $fee - $btc_dust) * self::SATOSHI)), $tx_output_2->getValue());
        PHPUnit::assertEquals($sender_address, AddressFactory::fromOutputScript($tx_output_2->getScript())->getAddress());
    }

    public function testComposeBTCTransaction() {
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'BTC';
        $quantity    = 0.023;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $sender_address, $fee);

        // parse the signed hex
        $transaction = TransactionFactory::fromHex($signed_hex);

        // 2 outputs only
        PHPUnit::assertCount(2, $transaction->getOutputs());

        // check destination
        $tx_output_0 = $transaction->getOutput(0);
        PHPUnit::assertEquals(intval(round($quantity * self::SATOSHI)), $tx_output_0->getValue());
        PHPUnit::assertEquals($destination, AddressFactory::fromOutputScript($tx_output_0->getScript())->getAddress());

        // check change output 
        $tx_output_1 = $transaction->getOutput(1);
        PHPUnit::assertEquals(intval(round((0.123 + 0.0005 - $fee - $quantity) * self::SATOSHI)), $tx_output_1->getValue());
        PHPUnit::assertEquals($sender_address, AddressFactory::fromOutputScript($tx_output_1->getScript())->getAddress());

    }

    public function testComposeTransactionWithNoChange_BTC() {
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'BTC';
        $quantity    = 0.1234;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $sender_address, $fee);

        // parse the signed hex
        $transaction = TransactionFactory::fromHex($signed_hex);

        // 1 output only
        PHPUnit::assertCount(1, $transaction->getOutputs());

        // check destination
        $tx_output_0 = $transaction->getOutput(0);
        PHPUnit::assertEquals(intval(round($quantity * self::SATOSHI)), $tx_output_0->getValue());
        PHPUnit::assertEquals($destination, AddressFactory::fromOutputScript($tx_output_0->getScript())->getAddress());
    }

    public function testComposeTransactionWithNoChange_Counterparty() {
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'SOUP';
        $quantity    = 45;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;
        $btc_dust    = 0.1234;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $sender_address, $fee, $btc_dust);

        // parse the signed hex
        $transaction = TransactionFactory::fromHex($signed_hex);

        // 2 outputs only
        PHPUnit::assertCount(2, $transaction->getOutputs());

        // check destination
        $tx_output_0 = $transaction->getOutput(0);
        PHPUnit::assertEquals(intval(round($btc_dust * self::SATOSHI)), $tx_output_0->getValue());
        PHPUnit::assertEquals($destination, AddressFactory::fromOutputScript($tx_output_0->getScript())->getAddress());

        $tx_output_1 = $transaction->getOutput(1);
        $op_return = $tx_output_1->getScript()->getScriptParser()->parse()[0];
        PHPUnit::assertEquals("OP_RETURN", $op_return);
    }

    /**
     * @expectedException        Exception
     * @expectedExceptionMessage Insufficient funds for this transaction
     */
    public function testCheckInsufficientFunds_Counterparty() {
        // BTC and Counterparty
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'SOUP';
        $quantity    = 45;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;
        $btc_dust    = 99.1234;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $sender_address, $fee, $btc_dust);
    }

    /**
     * @expectedException        \Tokenly\CounterpartyTransactionComposer\Exception\ComposerException
     * @expectedExceptionMessage Insufficient funds for this transaction
     */
    public function testCheckInsufficientFunds_BTC() {
        // BTC and Counterparty
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'BTC';
        $quantity    = 99;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $sender_address, $fee);
    }

    /**
     * @expectedException        \Tokenly\CounterpartyTransactionComposer\Exception\ComposerException
     * @expectedExceptionMessage No change address specified
     */
    public function testCheckNoChangeAddress_Counterparty() {
        // BTC and Counterparty
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'SOUP';
        $quantity    = 45;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;
        $btc_dust    = 0.00005432;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, null, $fee, $btc_dust);
    }

    /**
     * @expectedException        \Tokenly\CounterpartyTransactionComposer\Exception\ComposerException
     * @expectedExceptionMessage No change address specified
     */
    public function testCheckNoChangeAddress_BTC() {
        // BTC and Counterparty
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'BTC';
        $quantity    = 0.0111;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, null, $fee);
    }

    /**
     * @expectedException        \Tokenly\CounterpartyTransactionComposer\Exception\ComposerException
     * @expectedExceptionMessage Found unexpected high fee
     */
    public function testCatchUnexpectedHighFee() {
        // BTC and Counterparty
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'BTC';
        $quantity    = 0.0111;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $fee         = 0.0001;
        $change_addresses = [
            ['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', 0.00015430],
            ['1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', 0.00015430],
        ];

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $change_addresses, $fee);
    }


    public function testComposeMultipleChangeOutputTransaction_BTC() {
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'BTC';
        $quantity    = 0.023;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';
        $change_addresses = [
            ['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD', 0.00015430],
            ['1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', 0.00015430],
            ['1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD'],
        ];
        $fee         = 0.0001;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $change_addresses, $fee);

        // parse the signed hex
        $transaction = TransactionFactory::fromHex($signed_hex);

        // 4 outputs only
        PHPUnit::assertCount(4, $transaction->getOutputs());

        // check destination
        $tx_output_0 = $transaction->getOutput(0);
        PHPUnit::assertEquals(intval(round($quantity * self::SATOSHI)), $tx_output_0->getValue());
        PHPUnit::assertEquals($destination, AddressFactory::fromOutputScript($tx_output_0->getScript())->getAddress());

        // check change outputs 
        $tx_output = $transaction->getOutput(1);
        PHPUnit::assertEquals(15430, $tx_output->getValue());
        PHPUnit::assertEquals($change_addresses[0][0], AddressFactory::fromOutputScript($tx_output->getScript())->getAddress());

        $tx_output = $transaction->getOutput(2);
        PHPUnit::assertEquals(15430, $tx_output->getValue());
        PHPUnit::assertEquals($change_addresses[1][0], AddressFactory::fromOutputScript($tx_output->getScript())->getAddress());

        $tx_output = $transaction->getOutput(3);
        PHPUnit::assertEquals(intval(round((0.1235 - $fee - $quantity - (0.00015430 * 2)) * self::SATOSHI)), $tx_output->getValue());
        PHPUnit::assertEquals($change_addresses[2][0], AddressFactory::fromOutputScript($tx_output->getScript())->getAddress());
    }

    public function testComposeMultipleChangeOutputTransaction_Counterparty() {
        list($sender_address, $wif_key) = $this->newAddressAndKey();

        // variables
        $utxos       = $this->fakeUTXOs($sender_address);
        $asset       = 'SOUP';
        $quantity    = 45;
        $destination = '1JztLWos5K7LsqW5E78EASgiVBaCe6f7cD';

        $change_addresses = [
            ['19dcawoKcZdQz365WpXWMhX6QCUpR9SY4r', 0.00015430],
            ['1AGNa15ZQXAZUgFiqJ2i7Z2DPU2J6hW62i', 0.00015430],
            ['13p1ijLwsnrcuyqcTvJXkq2ASdXqcnEBLE'],
        ];
        $fee         = 0.0001;
        $btc_dust    = 0.00005432;

        // compose the send
        $composer = new Composer();
        list($txid, $signed_hex) = $composer->composeSend($asset, $quantity, $destination, $wif_key, $utxos, $change_addresses, $fee, $btc_dust);

        // parse the signed hex
        $transaction = TransactionFactory::fromHex($signed_hex);

        // 4 outputs only
        PHPUnit::assertCount(5, $transaction->getOutputs());

        // check destination
        $tx_output_0 = $transaction->getOutput(0);
        PHPUnit::assertEquals(intval(round($btc_dust * self::SATOSHI)), $tx_output_0->getValue());
        PHPUnit::assertEquals($destination, AddressFactory::fromOutputScript($tx_output_0->getScript())->getAddress());

        // OP_RETURN
        PHPUnit::assertEquals("OP_RETURN", $transaction->getOutput(1)->getScript()->getScriptParser()->parse()[0]);

        // check change outputs
        $tx_output = $transaction->getOutput(2);
        PHPUnit::assertEquals(15430, $tx_output->getValue());
        PHPUnit::assertEquals($change_addresses[0][0], AddressFactory::fromOutputScript($tx_output->getScript())->getAddress());

        $tx_output = $transaction->getOutput(3);
        PHPUnit::assertEquals(15430, $tx_output->getValue());
        PHPUnit::assertEquals($change_addresses[1][0], AddressFactory::fromOutputScript($tx_output->getScript())->getAddress());

        $tx_output = $transaction->getOutput(4);
        PHPUnit::assertEquals(intval(round((0.1235 - $fee - $btc_dust - (0.00015430 * 2)) * self::SATOSHI)), $tx_output->getValue());
        PHPUnit::assertEquals($change_addresses[2][0], AddressFactory::fromOutputScript($tx_output->getScript())->getAddress());
    }

    // ------------------------------------------------------------------------
    
    protected static function arc4decrypt($key, $encrypted_text)
    {
        $init_vector = '';
        return bin2hex(mcrypt_decrypt(MCRYPT_ARCFOUR, hex2bin($key), hex2bin($encrypted_text), MCRYPT_MODE_STREAM, $init_vector));
    }

    protected function newAddressAndKey() {
        $master_key = uniqid('testmasterkey');
        $token = uniqid('testtoken');

        $generator = new BitcoinAddressGenerator($master_key);
        return [$generator->publicAddress($token, 0), $generator->WIFPrivateKey($token, 0)];

    }

    // ------------------------------------------------------------------------
    
    protected function fakeUTXOs($address) {
        $fake_utxos = [];
        $fake_utxos[] = $this->buildFakeUTXO($address, 0.123, 111, 0);
        $fake_utxos[] = $this->buildFakeUTXO($address, 0.0005, 222, 2);
        return $fake_utxos;
    }

    protected function buildFakeUTXO($destination, $amount, $txid_number, $n) {
        $tx = TransactionFactory::build()
            ->input('deadbeef00000000000000000000000000000000000000000000000000'.sprintf('%06d', $txid_number), $n)
            ->payToAddress(intval(round($amount * self::SATOSHI)), AddressFactory::fromString($destination))
            ->get();

        $script = $tx->getOutput(0)->getScript();
        return [
            'txid'   => $tx->getTxId()->getHex(),
            'n'      => $n,
            'amount' => intval(round($amount * self::SATOSHI)),
            'script' => $script->getHex(),
        ];
    }

}
