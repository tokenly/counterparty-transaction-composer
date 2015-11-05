<?php

namespace Tokenly\CounterpartyTransactionComposer\Exception;

use \Exception;

/*
* ComposerException
*/
class ComposerException extends Exception
{

    const ERROR_INSUFFICIENT_FUNDS  = 101;
    const ERROR_NO_CHANGE_ADDRESS   = 102;
    const ERROR_INSUFFICIENT_CHANGE = 103;
    const ERROR_UNEXPECTED_HIGH_FEE = 103;

}

