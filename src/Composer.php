<?php

namespace Tokenly\CounterpartyTransactionComposer;

use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use Tokenly\CounterpartyTransactionComposer\ComposedTransaction;
use Tokenly\CounterpartyTransactionComposer\Exception\ComposerException;
use Tokenly\CounterpartyTransactionComposer\Quantity;
use Tokenly\CryptoQuantity\CryptoQuantity;
use \Exception;

/*
* Composer
*/
class Composer
{

    const DEFAULT_FEE         = 10000;  // 0.00010000;
    const DEFAULT_BTC_DUST    = 5430;   // 0.00005430;
    const MINIMUM_CHANGE_SIZE = 5000;   // 0.00005000;

    const HIGH_FEE            = 100000; // 0.001;

    const SATOSHI             = 100000000;

    /**
     * Composes a send transaction
     * @param  string $asset                     A counterparty asset name or BTC
     * @param  mixed  $quantity                  Quantity of asset to send.  Accepts a float or a Tokenly\CounterpartyTransactionComposer\Quantity or Tokenly\CryptoQuantity\CryptoQuantity object.  Use a Quantity object for indivisible assets.
     * @param  mixed  $destination               A single destination bitcoin address.  For BTC sends an array of [[address, amount], [address, amount]] is also allowed.  Amounts should be float values.
     * @param  string $private_key_wif           The private key in ASCII WIF format.  This can be null to compose an unsigned transaction.
     * @param  array  $utxos                     An array of UTXOs.  Each UTXO should be ['txid' => txid, 'n' => n, 'amount' => amount (in satoshis), 'script' => script hexadecimal string]
     * @param  mixed  $change_address_collection a single address string to receive all change. Or an array of [[address, amount], [address, amount], [address]].  Amounts should be float values.  An address with no amount for the last entry will send the remaining change to that address.
     * @param  float  $fee                       A fee
     * @param  float  $btc_dust                  Amount of BTC dust to send with the Counterparty transaction.
     * @return Array returns a ComposedTransaction object
     */
    public function composeSend($asset, $quantity, $destination, $private_key_wif, $utxos, $change_address_collection=null, $fee=null, $btc_dust=null) {
        if ($asset == 'BTC') {
            return $this->composeBTCSend($quantity, $destination, $private_key_wif, $utxos, $change_address_collection, $fee);
        }

        $fee_satoshis      = ($fee === null      ? self::DEFAULT_FEE      : intval(round($fee * self::SATOSHI)));
        $btc_dust_satoshis = ($btc_dust === null ? self::DEFAULT_BTC_DUST : intval(round($btc_dust * self::SATOSHI)));

        // get total and change amount
        $change_amounts = $this->calculateAndValidateChange($utxos, $btc_dust_satoshis, $fee_satoshis, $change_address_collection);

        $tx_builder = TransactionFactory::build();

        // add the UTXO inputs
        $transaction_outputs = $this->addInputsAndReturnPreviousOutputs($utxos, $tx_builder);

        // pay the btc_dust to the destination
        if (is_array($destination)) { throw new Exception("Multiple destinations are not supported for counterparty sends", 1); }
        $tx_builder->payToAddress($btc_dust_satoshis, AddressFactory::fromString($destination));

        // build the OP_RETURN script
        $op_return_builder = new OpReturnBuilder();
        $op_return = $op_return_builder->buildOpReturn($quantity, $asset, $utxos[0]['txid']);
        $script = ScriptFactory::create()->op('OP_RETURN')->push(Buffer::hex($op_return, 28))->getScript();
        $tx_builder->output(0, $script);

        // pay the change to self
        $this->payChange($change_amounts, $tx_builder);

        // sign
        if ($private_key_wif !== null) {
            $signed_transaction = $this->signTx($private_key_wif, $tx_builder, $transaction_outputs);
            return $this->buildReturnValuesFromTransactionAndInputs($signed_transaction, $utxos, true);
        }

        return $this->buildReturnValuesFromTransactionAndInputs($tx_builder->get(), $utxos, false);


    }

    //  @see composeSend
    public function composeBTCSend($btc_quantity, $destination_or_destinations, $private_key_wif, $utxos, $change_address_collection=null, $fee=null) {
        // normalize $btc_quantity
        if ($btc_quantity instanceof CryptoQuantity) {
            $btc_quantity_satoshis = $btc_quantity->getSatoshisString();
        } else if ($btc_quantity instanceof Quantity) {
            $btc_quantity_satoshis = $btc_quantity->getSatoshis();
        } else {
            $btc_quantity_satoshis = intval(round($btc_quantity * self::SATOSHI));
        }

        // normalize the destination pairs
        list($destinations, $btc_quantity_satoshis) = $this->normalizeDestinations($destination_or_destinations, $btc_quantity_satoshis);

        $fee_satoshis = ($fee === null ? self::DEFAULT_FEE : intval(round($fee * self::SATOSHI)));

        // get total and change amount
        $change_amounts = $this->calculateAndValidateChange($utxos, $btc_quantity_satoshis, $fee_satoshis, $change_address_collection);

        $tx_builder = TransactionFactory::build();

        // add the UTXO inputs
        $transaction_outputs = $this->addInputsAndReturnPreviousOutputs($utxos, $tx_builder);

        // pay the btc amount to each destination
        foreach($destinations as $destination_pair) {
            $address = $destination_pair[0];
            $quantity_satoshi = $destination_pair[1];
            $tx_builder->payToAddress($quantity_satoshi, AddressFactory::fromString($address));
        }

        // pay the change to self
        $this->payChange($change_amounts, $tx_builder);

        // sign

        if ($private_key_wif !== null) {
            $signed_transaction = $this->signTx($private_key_wif, $tx_builder, $transaction_outputs);
            return $this->buildReturnValuesFromTransactionAndInputs($signed_transaction, $utxos, true);
        }

        return $this->buildReturnValuesFromTransactionAndInputs($tx_builder->get(), $utxos, false);
    }

    // ------------------------------------------------------------------------
    
    protected function calculateAndValidateChange($utxos, $btc_quantity_satoshis, $fee_satoshis, $change_address_collection) {
        // get the total BTC available to spend
        $vins_amount_total_satoshis = $this->sumUTXOs($utxos);

        // calculate change
        $total_change_amount_satoshis = $vins_amount_total_satoshis - $btc_quantity_satoshis - $fee_satoshis;
        if ($total_change_amount_satoshis < 0) { throw new ComposerException("Insufficient funds for this transaction", ComposerException::ERROR_INSUFFICIENT_FUNDS); }

        // check change address
        if (!$change_address_collection) {
            if ($total_change_amount_satoshis > 0) { throw new ComposerException("No change address specified", ComposerException::ERROR_NO_CHANGE_ADDRESS); }
            
            // no change amounts
            return [];
        }

        if ($total_change_amount_satoshis < self::MINIMUM_CHANGE_SIZE) {
            // very small change transactions are not allowed
            //   set change to 0 and pay the rest as in the fee
            $total_change_amount_satoshis = 0;
            $fee_satoshis = $fee_satoshis + $total_change_amount_satoshis;
        }


        if (!is_array($change_address_collection)) {
            // default is a single change address
            if ($total_change_amount_satoshis > 0) {
                $change_amounts = [[$change_address_collection, $total_change_amount_satoshis]];
            } else {
                $change_amounts = [];
            }
        } else {
            $change_amounts = [];
            $change_remaining = $total_change_amount_satoshis;

            // assign all change
            foreach($change_address_collection as $change_address_collection_entry) {
                $address = $change_address_collection_entry[0];
                $float_amount = isset($change_address_collection_entry[1]) ? $change_address_collection_entry[1] : null;
                if ($float_amount === null) {
                    // null signals the rest of the change
                    $amount = $change_remaining;
                } else {
                    $amount = intval(round($float_amount * self::SATOSHI));
                }

                // skip 0 amounts (remainder)
                if ($amount == 0) { continue; }

                if ($vins_amount_total_satoshis >= $amount) {
                    $change_amounts[] = [$address, $amount];
                    $change_remaining -= $amount;
                } else {
                    throw new ComposerException("Insufficient change available", ComposerException::ERROR_INSUFFICIENT_CHANGE); 
                }
            }

            // recalculate fee
            $fee_satoshis = $fee_satoshis + $change_remaining;
            if ($fee_satoshis AND $fee_satoshis >= self::HIGH_FEE) {
                throw new ComposerException("Found unexpected high fee", ComposerException::ERROR_UNEXPECTED_HIGH_FEE); 
            }
        }

        return $change_amounts;
    }

    protected function addInputsAndReturnPreviousOutputs($utxos, $tx_builder) {
        $prev_transaction_outputs = [];
        foreach($utxos as $utxo) {
            $script_instance = ScriptFactory::fromHex($utxo['script']);
            $tx_builder->input($utxo['txid'], $utxo['n'], $script_instance);

            $prev_transaction_output = new TransactionOutput($utxo['amount'] / self::SATOSHI, $script_instance);
            $prev_transaction_outputs[] = $prev_transaction_output;
        }
        return $prev_transaction_outputs;
    }

    protected function payChange($change_amounts, $tx_builder) {
        if ($change_amounts) {
            foreach($change_amounts as $change_amount_pair) {
                $address      = $change_amount_pair[0];
                $btc_satoshis = $change_amount_pair[1];
                $tx_builder->payToAddress($btc_satoshis, AddressFactory::fromString($address));
            }
        }
    }

    protected function signTx($private_key_wif, $tx_builder, $transaction_outputs) {
        $private_key = PrivateKeyFactory::fromWif($private_key_wif);
        $transaction = $tx_builder->get();
        $signer = new Signer($transaction, Bitcoin::getEcAdapter());

        // sign each input script
        foreach($transaction_outputs as $offset => $transaction_output) {
            $signer->sign($offset, $private_key, $transaction_output);
        }

        $signed_transaction = $signer->get();
        return $signed_transaction;
    }

    protected function buildReturnValuesFromTransactionAndInputs($signed_transaction, $input_utxos, $is_signed) {
        $txid = $signed_transaction->getTxId()->getHex();
        $hex  = $signed_transaction->getHex();

        // get the UTXOs we just created
        $output_utxos = [];
        foreach ($signed_transaction->getOutputs() as $n => $output) {
            $has_value = ($output->getValue() > 0);

            $output_utxos[] = [
                'txid'        => $txid,
                'n'           => $n,
                'amount'      => $output->getValue(), // in satoshis
                'script'      => $output->getScript()->getHex(),
            ];
        };

        return new ComposedTransaction($txid, $hex, $input_utxos, $output_utxos, $is_signed);
    }

    protected function sumUTXOs($utxos) {
        $total = 0;
        foreach($utxos as $utxo) {
            $total += $utxo['amount'];
        }
        return $total;
    }

    protected function normalizeDestinations($destination_or_destinations, $total_btc_quantity_satoshis) {
        if (is_array($destination_or_destinations)) {
            $normalized_destinations = [];
            $calculated_btc_quantity_satoshis = 0;
            foreach($destination_or_destinations as $destination_pair) {
                $satoshis_int = intval(round($destination_pair[1] * self::SATOSHI));
                $normalized_destinations[] = [$destination_pair[0], $satoshis_int];

                $calculated_btc_quantity_satoshis += $satoshis_int;
            }

            if ($calculated_btc_quantity_satoshis != $total_btc_quantity_satoshis) { throw new Exception("Invalid BTC quantity total", 1); }
            return [$normalized_destinations, $total_btc_quantity_satoshis];
        }

        // just an address
        return [
            [
                [$destination_or_destinations, $total_btc_quantity_satoshis],
            ],
            $total_btc_quantity_satoshis,
        ];
    }

}

