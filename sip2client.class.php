<?php
/*
    SIP2 PHP Client Class
    Copyright (C) 2011 Thomas Berezansky <tsbere@mvlc.org>

    This library is free software; you can redistribute it and/or
    modify it under the terms of the GNU Lesser General Public
    License as published by the Free Software Foundation; either
    version 2.1 of the License, or (at your option) any later version.

    This library is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
    Lesser General Public License for more details.

    You should have received a copy of the GNU Lesser General Public
    License along with this library; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

class SIP2Client
{
    // Message Terminator
    // Should only be a carriage return, not carriage return and linefeed
    // As defined by spec, private
    private $msgTerm = "\r";

    // Field Terminator
    // Public because it should be configurable according to docs
    public $fieldTerm = '|';

    // Terminal Password
    public $termPass = null;

    // Terminal Location
    public $location = '';

    // Sequence IDs Enabled (and current sequence id)
    public $doSequence = true;
    private $seq = -1;

    // Checksums Enabled
    public $doChecksum = true;

    // We use unknown by default, but let someone override
    public $language = '000';

    // Timeouts, so that we don't hang forever (in seconds)
    public $recvTimeO = 3;
    public $sendTimeO = 6;

    // Information from last ACS status message
    // ACS can send ACS status in lieu of *any* other response.
    public $acsStatus = array();

    // Place to store variable fields for adding to messages
    private $varFields = array();

    // Socket handle
    private $socket;

    // Connection information
    private $hostname = null;
    private $port = null;
    private $username = null;
    private $password = null;

    // Internal State Info
    private $connected = false;
    private $requestStatus = false;

    // Summary Types for Patron Information Response
    private $summaryTypes = array(
        'Hold',
        'Overdue',
        'Charged',
        'Fine',
        'Recall',
        'UnavailableHolds',
        'Fee',
    );

    // Connect to a server
    // If username and password are both provided, send login message too.
    function connect($hostname, $port, $username = null, $password = null) {
        if($this->connected) $this->disconnect;
        // Store hostname/port for _getSocket
        $this->hostname = trim($hostname);
        $this->port = trim($port);
        // Store username/password for login
        $this->username = $username; // In theory, could include legit leading/trailing spaces. Not likely, though.
        $this->password = $password; // In theory, could include legit leading/trailing spaces. Not likely, though.

        if(!$this->_getSocket()) return false;

        $this->connected = true;

        // Username/password?
        if($this->username != null && $this->password != null) {
            // For username/password, first message is login.
            if($this->_login() === false) {
                $this->disconnect();
                return false;
            }
        }

        // Send initial status message
        if(!$this->sendStatus()) {
            return false;
        }

        // Everything seems to be fine at this point
        return true;
    }

    // In the event you want to inherit and change what kind of connection is opened, just override this.
    function _getSocket() {
        // Validate port is > 0
        $this->port = (int)$this->port;
        if($this->port <= 0) return false;

        // Convert hostname to IP, then validate IP is IP4
        $addr = gethostbyname($this->hostname);
        if(!filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE))
            return false;

        // Open socket
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if($this->socket === false) return false;

        // Attempt to enable timeouts, so we don't hang forever
        // Note: On windows, usec doesn't work
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->recvTimeO, 'usec' => 0));
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->sendTimeO, 'usec' => 0));

        // Attempt to connect
        if(!socket_connect($this->socket, $addr, $this->port)) {
            $this->disconnect();
            return false;
        }

        return true;
    }

    function disconnect() {
        $this->connected = false;
        socket_close($this->socket);
    }

    function sendStatus() {
        $message = $this->_construct99();
        $this->requestStatus = true;
        $result = $this->_sendMessage($message);
        $this->requestStatus = false;
        return $result;
    }

    function patronStatus($barcode, $pin = null) {
        $this->varFields['AA'] = $barcode;
        $this->varFields['AD'] = $pin;
        $this->varFields['AC'] = $this->termPass;
        $message = $this->_construct23();
        return $this->_sendMessage($message);
    }

    function patronInformation($barcode, $pin = null, $summaryType = 'none', $startItem = null, $endItem = null) {
        $this->varFields['AA'] = $barcode;
        $this->varFields['AD'] = $pin;
        $this->varFields['AC'] = $this->termPass;
        $this->varFields['BP'] = $startItem;
        $this->varFields['BQ'] = $endItem;
        $summary = '          ';
        $pos = array_search($summaryType, $this->summaryTypes);
        if($pos !== false && $pos = (int)$pos && $pos >=0) $summary[$pos] = 'Y';
        $message = $this->_construct63($summary);
        return $this->_sendMessage($message);
    }

    function patronInformationIfValid($barcode, $pin = null, $summaryType = 'none', $startItem = null, $endItem = null) {
        $patronInfo = $this->patronInformation($barcode, $pin, $summaryType, $startItem, $endItem);
        if($patronInfo === false) return false;
        if(!isset($patronInfo['BL'][0]) || $patronInfo['BL'][0] == 'N') return false;
        if($pin != null && (!isset($patronInfo['CQ'][0]) || $patronInfo['CQ'][0] == 'N')) return false;
        return $patronInfo;
    }

    function patronValid($barcode, $pin) {
        return $this->patronInformationIfValid($barcode, $pin) !== false;
    }

    function payFee($feeType, $paymentType, $currencyType, $feeAmount, $barcode, $pin = null, $feeID = null, $tranactionID = null) {
        $this->varFields['AA'] = $barcode;
        $this->varFields['AD'] = $pin;
        $this->varFields['AC'] = $this->termPass;
        $this->varFields['BV'] = sprintf("%.2f", $feeAmount);
        $this->varFields['CG'] = $feeID;
        $this->varFields['BK'] = $transactionID;
        $message = $this->_construct37($feeType, $paymentType, $currencyType);
        return $this->_sendMessage($message);
    }

    function _login() {
        $this->varFields['CN'] = $this->username;
        $this->varFields['CO'] = $this->password;
        $this->varFields['CP'] = $this->location;
        $message = $this->_construct93();
        $result = $this->_sendMessage($message);
        if($result['Ok'] == '1') return true;
        return false;
    }

    function _sendMessage($message) {
        if(!$this->connected) return false;
        $message .= $this->_checkSum($message);
        $message .= $this->msgTerm;
        $retries = 3;
        $buffer = '';
        $inbuf = '';
        do {
            $result = socket_write($this->socket, $message);
            if(!$result) {
                $this->disconnect;
                return false;
            }
            do {
                $result = socket_recv($this->socket, $inbuf, 1, MSG_WAITALL);
                if($result !== false) $buffer .= $inbuf;
            } while($result !== false && $inbuf != "\r" && $inbuf != '');
            $buffer = trim($buffer);
            $result = $this->_parseMessage($buffer);
            if($result === false) $retries--; // False is "bad checksum"
            // True is "unexpected ACS status message or resend request"
        } while($result === true || ($result === false && $retries != 0));
        return $result;
    }

    /*  Patron Status Request
        Message ID 23
     
        Fixed Fields:
            Language - 3 char
            Transaction Date - 18 char
        Variable Fields:
            Institution ID - AO - Required
            Patron Identifier - AA - Required
            Terminal Password - AC - Required
            Patron Password - AD - Required
    */
    function _construct23() {
        $message = sprintf('23%3s%18s',
                $this->language,
                $this->_sipDate());

        $message .= $this->_addVarField('AO',true);
        $message .= $this->_addVarField('AA',true);
        $message .= $this->_addVarField('AC',true);
        $message .= $this->_addVarField('AD',true);
        $message .= $this->_seq();

        return $message;
    }

    /*  Patron Status Response
        Message ID 24

        Fixed Fields:
            Patron Status - 14 char
            Language - 3 char
            Transaction Date - 18 char
    */
    function _parse24($message) {
        $matches = array();
        if(!preg_match("/^24(.{14})(.{3})(.{18})(.*)$/",$message, $matches))
            throw new Exception('_parse24 failed to parse message');
        $result = array(
            'PatronStatus' => $matches[1],
            'Language' => $matches[2],
            'TransactionDate' => $matches[3]
        );
        return $result + $this->_parseVariable($matches[4]);
    }

    /*  Fee Paid
        Message ID 37

        Fixed Fields:
            Transaction Date - 18 char
            Fee Type - 2 char (01 - 99)
            Payment Type - 2 char (00 - 99)
            Currency - 3 char
    */
    function _construct37($feeType, $paymentType, $currencyType) {
        $message = sprintf('37%18s%2s%2s%3s',
                 $this->_sipDate(),
                 $feeType,
                 $paymentType,
                 $currencyType);
        $message .= $this->_addVarField('BV',true);
        $message .= $this->_addVarField('AO',true);
        $message .= $this->_addVarField('AA',true);
        $message .= $this->_addVarField('AC',false);
        $message .= $this->_addVarField('AD',false);
        $message .= $this->_addVarField('CG',false);
        $message .= $this->_addVarField('BK',false);
        $message .= $this->_seq();
        return $message;
    }

    /*  Fee Paid Response
        Message ID 38

        Fixed Fields:
            Payment Accepted - 1 char (Y or N)
            Transaction Date - 18 char
    */
    function _parse38($message) {
        $matches = array();
        if(!preg_match("/^38([YN])(.{18})(.*)$/",$message,$matches))
            throw new Exception('_parse38 failed to parse message');
        $result = array(
             'PaymentAccepted' => $matches[1],
             'TransactionDate' => $matches[2]
        );
        return $result + $this->_parseVariable($matches[3]);
    }

    /*  Patron Information
        Message ID 63

        Fixed Fields:
            Language - 3 char
            Transaction Date - 18 char
            Summary - 10 char
        Variable Fields:
            Institution ID - AO - Required
            Patron Identifier - AA - Required
            Terminal Password - AC - Optional
            Patron Password - AD - Optional
            Start Item - BP - Optional
            End Item - BQ - Optional
    */
    function _construct63($summary) {
        $message = sprintf('63%s%s%s',
                $this->language,
                $this->_sipDate(),
                $summary);

        $message .= $this->_addVarField('AO',true);
        $message .= $this->_addVarField('AA',true);
        $message .= $this->_addVarField('AC',false);
        $message .= $this->_addVarField('AD',false);
        $message .= $this->_addVarField('BP',false);
        $message .= $this->_addVarField('BQ',false);
        $message .= $this->_seq();

        return $message;
    }

    /*  Patron Information Response
        Message ID 64

        Fixed Fields:
            Patron Status - 14 char
            Language - 3 char
            Transaction Date - 18 char
            Hold Items Count - 4 char
            Overdue Items Count - 4 char
            Charged Items Count - 4 char
            Fine Items Count - 4 char
            Recall Items Count - 4 char
            Unavailable Holds Count - 4 char
    */
    function _parse64($message) {
        $matches = array();
        if(!preg_match("/^64(.{14})(.{3})(.{18})(.{4})(.{4})(.{4})(.{4})(.{4})(.{4})(.*)$/",$message, $matches))
            throw new Exception('_parse64 failed to parse message');
        $result = array(
            'PatronStatus' => $matches[1],
            'Language' => $matches[2],
            'TransactionDate' => $matches[3],
            'HoldItems' => $matches[4],
            'OverdueItems' => $matches[5],
            'ChargedItems' => $matches[6],
            'FineItems' => $matches[7],
            'RecallItems' => $matches[8],
            'UnavailableHolds' => $matches[9]
        );
        return $result + $this->_parseVariable($matches[10]);
    }

    /*  Login
        Message ID 93

        Fixed Fields:
            UID algorithm - 1 char
            PWD algorithm - 1 char
        Variable Fields:
            User ID - CN - Required
            Password - CO - Required
            Location Code - CP - Optional

        We don't support anything but plaintext UID/PWD
    */
    function _construct93() {
        $message = '9300';

        $message .= $this->_addVarField('CN',true);
        $message .= $this->_addVarField('CO',true);
        $message .= $this->_addVarField('CP',false);
        $message .= $this->_seq();

        return $message;
    }

    /*  Login Response
        Message ID 94

        Fixed Fields:
            Ok - 1 char
        Technically normally doesn't have variable fields. Checks anyway.
    */
    function _parse94($message) {
        $matches = array();
        if(!preg_match("/^94(.)(.*)$/", $message, $matches))
            throw new Exception('_parse94 failed to parse message');
        $result = array('Ok' => $matches[1]);
        return $result + $this->_parseVariable($matches[2]);
    }

    /*  Request SC Resend
        Message ID 96

        No fields, should never have fields
    */
    function _parse96($message) {
        if($message == '96') return true;
        else throw new Exception('_parse96 failed to parse message');
    }

    /*  ACS Status
        Message ID 98

        Fixed Fields:
            On-line Status - 1 char
            Checkin Ok - 1 char
            Checkout Ok - 1 char
            ACS Renewal Policy - 1 char
            Status Update ok - 1 char
            Off-line Ok - 1 char
            Timeout Period - 3 char
            Retries Allowed - 3 char
            Date/Time Sync - 18 char
            Protocol Version - 4 char
    */
    function _parse98($message) {
        $matches = array();
        if(!preg_match("/^98(.)(.)(.)(.)(.)(.)(.{3})(.{3})(.{18})(.{4})(.*)$/", $message, $matches))
            throw new Exception('_parse98 failed to parse message');
        $result = array(
            'On-line' => $matches[1],
            'Checkin' => $matches[2],
            'Checkout' => $matches[3],
            'Renewal' => $matches[4],
            'StatusUpdate' => $matches[5],
            'Off-line' => $matches[6],
            'Timeout' => $matches[7],
            'Retries' => $matches[8],
            'DateTime' => $matches[9],
            'Protocol' => $matches[10]
        );
        $this->acsStatus = $result + $this->_parseVariable($matches[11]);
        if($this->acsStatus['AO'][0] != '') $this->varFields['AO'] = $this->acsStatus['AO'][0];
        return $this->acsStatus;
    }

    /*  Selfcheck Status
        Message ID 99

        Fixed Fields:
            Status Code - 1 char
            Max Print Width - 3 char
            Protocol Version - 4 char

        Hardcoded to:
            Online
            80 chars
            2.00
    */
    function _construct99() {
        return '9900802.00' . $this->_seq();
    }

    // Helper functions

    function _parseMessage($message) {
        $message = trim($message, "\n");
        $message = trim($message, "\r");
        if(strlen($message) < 2) return false;
        $message = $this->_validateAndStripChecksumAndSequence($message);
        if($message === false) return false;
        $msgId = substr($message, 0, 2);
        $func = '_parse' . $msgId;
        $result = false;
        if(method_exists($this, $func))
            $result = $this->$func($message);
        if(!$this->requestStatus and $msgId == '98') return true;
        return $result;
    }

    function _parseVariable($variableBlock) {
        $chunks = explode($this->fieldTerm, $variableBlock);
        $return = array();
        foreach($chunks as $chunk) {
            if(strlen($chunk) < 2) continue;
            $return[substr($chunk,0,2)][] = substr($chunk,2);
        }
        return $return;
    }

    function _sipDate($date = null) {
        if($date == null) $date = time();
        return date('Ymd    His', $date);
    }

    function _addVarField($field, $required) {
        if(!$required && !isset($this->varFields[$field])) return '';
        if(!isset($this->varFields[$field])) return $field . $this->fieldTerm;
        return $field . $this->varFields[$field] . $this->fieldTerm;
    }

    function _seq() {
        $seqOut = '';
        if($this->doSequence) {
            $this->seq = ($this->seq + 1) % 10;
            $seqOut = 'AY' . (string)$this->seq;
        }
        return $seqOut;
    }

    function _checkSum($message, $check = false) {
        if(!$this->doChecksum && !$check) return '';
        $message .= 'AZ';
        $checksum = 0;
        $len = strlen($message);
        for($i = 0; $i < $len; $i++)
            $checksum += ord($message[$i]);
        $checksum *= -1;
        $checksum &= 0xFFFF;
        return 'AZ' . sprintf('%4X', $checksum);
    }

    function _validateAndStripChecksumAndSequence($message) {
        if(preg_match('/^(.*)(AZ.{4})$/', $message, $matches)) { // Has a checksum?
            if($matches[2] == $this->_checkSum($matches[1], true))
                $message = $matches[1];
            else
                return false;
        }
        $matches = '';
        if(preg_match('/^(.*)AY(.)$/', $message, $matches)) { // Has a sequence?
            if($matches[2] == $this->seq)
                $message = $matches[1];
            else
                return false;
        }
        return $message;
    }
}
