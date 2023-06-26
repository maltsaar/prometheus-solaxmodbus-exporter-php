<?php

require_once './vendor/autoload.php';
require_once './modbus.php';

use Monolog\Level;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Yaml\Yaml;

// logging
$dateFormat = "Y-m-d H:i:s";
$formatter = new LineFormatter(null, $dateFormat, false, true);
$stream = new StreamHandler('php://stdout', Level::Info);
$stream->setFormatter($formatter);

// setup metrics logger
$logger = new Logger('metrics');
$logger->pushHandler($stream);

// setup logger for modbus class
$modbusLogger = new Logger('modbus');
$modbusLogger->pushHandler($stream);

$env = [
    "host"           => getenv("HOST"),
    "port"           => getenv("PORT"),
    "listenAddress"  => getenv("LISTEN_ADDRESS"),
];

$port = $env["port"];
$host = $env["host"];
$listenAddress = $env["listenAddress"];

if (empty($port) or empty($host) or empty($listenAddress)) {
    $logger->error("One or more environment variables are not set correctly");
    die();
}

try {
    $yaml = Yaml::parseFile("./registers.yml");
} catch (Exception $e) {
    $logger->error($e->getMessage());
    die();
}
$holdingRegisters = $yaml["holding_registers"];
$inputRegisters   = $yaml["input_registers"];

$http = new React\Http\HttpServer(function (Psr\Http\Message\ServerRequestInterface $request) {
    global $logger;
    global $modbusLogger;
    global $port;
    global $host;
    global $holdingRegisters;
    global $inputRegisters;

    $path = $request->getUri()->getPath();
    $logger->info("Got path: \"".$path."\"\n");

    // index page
    if ($path === "/") {
        return React\Http\Message\Response::plaintext(
            "/metrics for data in prometheus text-based format\n/json for data in json format"
        );
    }

    // metrics
    if ($path === "/metrics") {
        $logger->info("Calling modbus query");
        $modbusQuery = new modbusQuery($modbusLogger, $port, $host);
        $data = $modbusQuery->startQuery($holdingRegisters, $inputRegisters);
        
        $data = parseData($data, $logger);
        
        if (isset($data["scrape_status"]) and $data["scrape_status"] === "failed") {
            return React\Http\Message\Response::plaintext(
                "503 scrape failed"
            )->withStatus(React\Http\Message\Response::STATUS_SERVICE_UNAVAILABLE);
        } else {
            return React\Http\Message\Response::plaintext(
                $data
            );
        }
    }

    // json
    elseif ($path === "/json") {
        $logger->info("Calling modbus query");
        $modbusQuery = new modbusQuery($modbusLogger, $port, $host);
        $data = $modbusQuery->startQuery($holdingRegisters, $inputRegisters);
        
        $data = parseData($data, $logger, "json");

        if (isset($data["scrape_status"]) and $data["scrape_status"] === "failed") {
            return React\Http\Message\Response::json(
                $data
            )->withStatus(React\Http\Message\Response::STATUS_SERVICE_UNAVAILABLE);
        } else {
            return React\Http\Message\Response::json(
                $data
            );
        }
    }

    // favicon :^)
    elseif ($path === "/favicon.ico") {
        $img = file_get_contents("./favicon.ico");

        $response = new React\Http\Message\Response (
            React\Http\Message\Response::STATUS_OK,
            ["Content-Type" => "image/png"],
            $img
        );

        return $response;
    }    

    else {
        return React\Http\Message\Response::plaintext(
            "404"
        )->withStatus(React\Http\Message\Response::STATUS_NOT_FOUND);
    }
});
$http->on('error', function (Throwable $t) {
    global $logger;
    $logger->error($t->getMessage());
    die();
}
);

$socket = new React\Socket\SocketServer($listenAddress);
$http->listen($socket);
$logger->info("Now listening on ".$listenAddress."\n");

function parseData($data, $logger, $returnDataType="prometheus") {
    if (empty($data)) {
        $logger->error("Scrape failed");
        $data = ["scrape_status" => "failed"];
    } else {
        $logger->info("Scrape succeeded");
        $data = array_merge($data, ["scrape_status" => "success"]);
    }

    if ($data["scrape_status"] === "success") {
        $prometheusData = "";

        foreach ($data as $key => $value) {
            switch ($key) {
                // ignore static data
                case "scrape_status":
                    break;
                case "slx_mb_serial":
                    break;
                case "slx_mb_factory_name":
                    break;
                default:
                    $prometheusData .= createPrometheusGauge($key, $value["description"], $value["value"]);       
            }
        }
    } else {
        $prometheusData = $data;
    }

    if ($returnDataType === "prometheus") {
        return $prometheusData;
    } else {
        return $data;
    }
}

use PNX\Prometheus\Gauge;
use PNX\Prometheus\Serializer\MetricSerializerFactory;

function createPrometheusGauge($name, $description, $value) {
    $gauge = new Gauge("solax", $name, $description);
    $gauge->set($value);
    $serializer = MetricSerializerFactory::create();
    $output = $serializer->serialize($gauge, 'prometheus');
    return $output;
}

?>