<?php
// Jalankan dengan: php mqtt_bridge.php

// Konfigurasi
$mqtt_server = "5083093cbb184aedb7c5c48140d30bb3.s1.eu.hivemq.cloud";
$mqtt_port = 8883; // Port aman untuk MQTTS
$mqtt_client_id = "php_mysql_bridge";
$mqtt_username = "testing";
$mqtt_password = "Testing123";

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "sensor_data";

// Koneksi ke database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Pastikan tabel sudah ada
$sql = "CREATE TABLE IF NOT EXISTS sensor_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    mqtt_status VARCHAR(20) NOT NULL,
    rgb_value INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if (!$conn->query($sql)) {
    die("Error creating table: " . $conn->error);
}

// Memerlukan MQTT client library
// Instal: composer require php-mqtt/client
require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

// Variabel untuk menyimpan data sementara
$current_data = [
    'temperature' => 0,
    'humidity' => 0,
    'mqtt_status' => 'Connected',
    'rgb_value' => 0
];

// Fungsi untuk menyimpan data ke database
function saveToDatabase($conn, $data) {
    $stmt = $conn->prepare("INSERT INTO sensor_readings 
        (temperature, humidity, mqtt_status, rgb_value) 
        VALUES (?, ?, ?, ?)");
    
    $stmt->bind_param(
        "ddsi", 
        $data['temperature'], 
        $data['humidity'], 
        $data['mqtt_status'], 
        $data['rgb_value']
    );
    
    if ($stmt->execute()) {
        echo date('Y-m-d H:i:s') . " - Data saved to database\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Error saving data: " . $stmt->error . "\n";
    }
    
    $stmt->close();
}

try {
    // Buat koneksi ke broker MQTT
    $connectionSettings = (new ConnectionSettings)
        ->setUsername($mqtt_username)
        ->setPassword($mqtt_password)
        ->setUseTls(true)
        ->setTlsVerifyPeer(false); // Dalam produksi, sebaiknya true dengan sertifikat CA yang benar

    echo "Connecting to MQTT broker...\n";
    $mqtt = new MqttClient($mqtt_server, $mqtt_port, $mqtt_client_id);
    $mqtt->connect($connectionSettings, true);
    echo "Connected to MQTT broker.\n";

    // Subscribe ke topik yang diperlukan
    $mqtt->subscribe('test/temperature', function ($topic, $message) use (&$current_data, $conn) {
        echo date('Y-m-d H:i:s') . " - Received temperature: $message\n";
        $current_data['temperature'] = floatval($message);
        
        // Simpan data jika kita memiliki temperature dan humidity
        if ($current_data['temperature'] > 0 && $current_data['humidity'] > 0) {
            saveToDatabase($conn, $current_data);
        }
    }, 0);

    $mqtt->subscribe('test/humidity', function ($topic, $message) use (&$current_data, $conn) {
        echo date('Y-m-d H:i:s') . " - Received humidity: $message\n";
        $current_data['humidity'] = floatval($message);
        
        // Simpan data jika kita memiliki temperature dan humidity
        if ($current_data['temperature'] > 0 && $current_data['humidity'] > 0) {
            saveToDatabase($conn, $current_data);
        }
    }, 0);

    $mqtt->subscribe('test/rgb_control', function ($topic, $message) use (&$current_data) {
        echo date('Y-m-d H:i:s') . " - Received RGB control: $message\n";
        $current_data['rgb_value'] = intval($message);
    }, 0);

    // Loop utama untuk menjaga koneksi tetap hidup
    $mqtt->loop(true);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Jika loop terputus, tutup koneksi
$mqtt->disconnect();
$conn->close();
echo "Disconnected from MQTT broker and database.\n";