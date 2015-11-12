<?php

namespace Tokenly\CounterpartyTransactionComposer;

use \Exception;

/*
* ComposedTransaction
*/
class ComposedTransaction
{

    protected $txid;
    protected $hex;
    protected $input_utxos;
    protected $output_utxos;

    function __construct($txid, $hex, $input_utxos, $output_utxos) {
        $this->txid         = $txid;
        $this->hex          = $hex;
        $this->input_utxos  = $input_utxos;
        $this->output_utxos = $output_utxos;
    }


    public function getTxId() { return $this->txid; }
    public function getTransactionHex() { return $this->hex; }
    public function getInputUtxos() { return $this->input_utxos; }
    public function getOutputUtxos() { return $this->output_utxos; }


}

