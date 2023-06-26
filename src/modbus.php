<?php

require_once './vendor/autoload.php';

use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\ReadHoldingRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\ReadHoldingRegistersResponse;
use ModbusTcpClient\Packet\ModbusFunction\ReadInputRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\ReadInputRegistersResponse;
use ModbusTcpClient\Packet\ResponseFactory;
use ModbusTcpClient\Utils\Endian;

class modbusQuery {
    function __construct($logger, $port, $host) {
        $this->logger = $logger;
        $this->port = $port;
        $this->host = $host;
    }

    public function startQuery($holdingRegisters, $inputRegisters) {
        // defining this because it gets called more than once
        $logger = $this->logger;

        $allRegisters = array_merge($holdingRegisters, $inputRegisters);

        $connection = BinaryStreamConnection::getBuilder()
        ->setPort($this->port)
        ->setHost($this->host)
        ->setConnectTimeoutSec(15)    // timeout when establishing connection to the server
        ->setWriteTimeoutSec(5)       // timeout when writing/sending packet to the server
        ->setReadTimeoutSec(5)        // timeout when waiting response from server
        ->setLogger($logger)
        ->build();

        $logger->info("Starting to query registers...");
        foreach ($allRegisters as $key => $register) {
            try {
                $modbusValue = $this->createQuery($register, $connection, $register[0], $register[3], $register[4]);
                $skipIteration = false;
            } catch (Exception $e) {
                $logger->error("Got exception while querying register \"".$e->getMessage()."\"");
                $skipIteration = true;

                // In the event of a failed client socket connection break the loop
                if (str_contains($e->getMessage(), "Unable to create client socket")) {
                    break;
                }
            }

            if ($skipIteration === false) {
                $logger->info($key.": ".$modbusValue);
                $result[$key] = [
                    "value"       => $modbusValue,
                    "description" => $register[5]
                ];
            }

            // Why?: The inverter expects only 1 query per second. Doing any more will probably result in the inverter not answering subsequent requests.
            sleep(1);
        }
        
        if (empty($result)) {
            $logger->error("Queries result empty");
            $result = false;
        }

        $logger->info("Finished querying registers");
        return $result;
    }

    private function createQuery($registerArray, $connection, $registerType, $outputDataType, $ascii = false) {
        $startAddress = hexdec($registerArray[1]);
        $quantity = $registerArray[2];

        if ($registerType === "holding") {
            $packet = new ReadHoldingRegistersRequest($startAddress, $quantity, 0);
        } else {
            $packet = new ReadInputRegistersRequest($startAddress, $quantity, 0);
        }

        try {
            $binaryData = $connection->connect()->sendAndReceive($packet);
            $response = ResponseFactory::parseResponseOrThrow($binaryData);

            $formattedResponse = "";

            if ($outputDataType === "uint16") {
                foreach ($response as $word) {
                    if ($ascii === true) {
                        $bytes = $word->getBytes();
                        $formattedResponse .= trim(chr($bytes[0]).chr($bytes[1]));
                    } else {
                        $formattedResponse .= $word->getUInt16();
                    }
                }
            }

            if ($outputDataType === "int16") {
                foreach ($response as $word) {
                    if ($ascii === true) {
                        $bytes = $word->getBytes();
                        $formattedResponse .= trim(chr($bytes[0]).chr($bytes[1]));
                    } else {
                        $formattedResponse .= $word->getInt16();
                    }
                }          
            }

            if ($outputDataType === "uint32") {
                $responseWithStartAddress = $response->withStartAddress(2); 
                $formattedResponse .= $responseWithStartAddress->getDoubleWordAt(2)->getUInt32();      
            }

            if ($outputDataType === "int32") {
                $responseWithStartAddress = $response->withStartAddress(2); 
                $formattedResponse .= $responseWithStartAddress->getDoubleWordAt(2)->getInt32();      
            }

            return $formattedResponse;
        } catch (Exception $exception) {
            $exceptionMessage = $exception->getMessage();
            // throw new exception so the loop in startQuery() catches it and breaks the current iteration
            throw new Exception($exceptionMessage);
        } finally {
            $connection->close();
        }
    }
}

?>
