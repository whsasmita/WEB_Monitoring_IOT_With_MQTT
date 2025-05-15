<?php
// Database configuration
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "sensor_data";

// Connect to database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Create tables if not exist
$sql = "CREATE TABLE IF NOT EXISTS sensor_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    mqtt_status VARCHAR(20) NOT NULL,
    rgb_value INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if (!$conn->query($sql)) {
    echo "Error creating table: " . $conn->error;
}

// Process form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rgb_value'])) {
    $rgb_value = intval($_POST['rgb_value']);

    // Here you would publish to MQTT topic
    // We'll implement this with JavaScript/MQTT.js

    // For demo, store the value in session
    session_start();
    $_SESSION['rgb_value'] = $rgb_value;
}

// For demonstration purposes - in real implementation this would come from MQTT
$latest_data = $conn->query("SELECT * FROM sensor_readings ORDER BY timestamp DESC LIMIT 1");
$sensor_data = $latest_data->fetch_assoc();

// History data for chart
$history = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_readings ORDER BY timestamp DESC LIMIT 20");
$temp_data = [];
$humidity_data = [];
$timestamps = [];

while ($row = $history->fetch_assoc()) {
    $temp_data[] = $row['temperature'];
    $humidity_data[] = $row['humidity'];
    $timestamps[] = date('H:i', strtotime($row['timestamp']));
}

// Reverse arrays for chronological order
$temp_data = array_reverse($temp_data);
$humidity_data = array_reverse($humidity_data);
$timestamps = array_reverse($timestamps);

// Get current RGB value for LED display (use session if available)
session_start();
$current_rgb_value = isset($_SESSION['rgb_value']) ? $_SESSION['rgb_value'] : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Home Controller</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mqtt/4.3.7/mqtt.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 1200px;
        }
        
        header {
            background-color: #fff;
            border-radius: 15px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .led-indicator {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 10px;
            display: inline-block;
            border: 3px solid #e9ecef;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .led-indicator.active {
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
        }

        .led-off {
            background-color: #e9ecef;
        }

        .led-red {
            background-color: #ff4136;
            box-shadow: 0 0 15px rgba(255, 65, 54, 0.7);
        }

        .led-yellow {
            background-color: #ffdc00;
            box-shadow: 0 0 15px rgba(255, 220, 0, 0.7);
        }

        .led-green {
            background-color: #2ecc40;
            box-shadow: 0 0 15px rgba(46, 204, 64, 0.7);
        }
        
        .led-label {
            margin-top: 8px;
            font-weight: 500;
            font-size: 14px;
            color: #495057;
        }

        .card-dashboard {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .card-dashboard:hover {
            transform: translateY(-5px);
        }
        
        .card-title {
            font-size: 1.2rem;
            color: #495057;
            font-weight: 600;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .status-indicator {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }

        .status-connected {
            background-color: #2ecc40;
            box-shadow: 0 0 8px rgba(46, 204, 64, 0.7);
        }

        .status-disconnected {
            background-color: #ff4136;
            box-shadow: 0 0 8px rgba(255, 65, 54, 0.7);
        }
        
        .btn {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            margin: 5px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0,0,0,0.1);
        }
        
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        
        .sensor-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0;
            color: #343a40;
        }
        
        .sensor-label {
            font-size: 1rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .table {
            box-shadow: 0 0 10px rgba(0,0,0,0.03);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
        }
        
        .sensor-card {
            background-color: #fff;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .sensor-icon {
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: #007bff;
        }
        
        .control-panel {
            background-color: #fff;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .mqtt-status {
            display: flex;
            align-items: center;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        #mqtt-status-text {
            font-weight: 500;
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <header class="pb-3 mb-4 border-bottom">
            <h1 class="display-5 fw-bold text-center">Smart Home Controller</h1>
        </header>

        <div class="row">
            <!-- Status & Controls -->
            <div class="col-md-4">
                <div class="card card-dashboard h-100">
                    <div class="card-body">
                        <h5 class="card-title">System Status & Controls</h5>
                        <div class="mb-4">
                            <div class="mqtt-status">
                                <div id="mqtt-status-indicator" class="status-indicator status-disconnected"></div>
                                <span id="mqtt-status-text">MQTT: Disconnected</span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">LED Control</label>
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-secondary" onclick="sendRGBValue(0)">LED OFF</button>
                                <button class="btn btn-success" onclick="sendRGBValue(1)">Green</button>
                                <button class="btn btn-warning" onclick="sendRGBValue(11)">Yellow</button>
                                <button class="btn btn-danger" onclick="sendRGBValue(21)">Red</button>
                            </div>
                        </div>

                        <div class="text-center mt-5">
                            <div class="d-flex justify-content-center">
                                <div class="text-center">
                                    <div id="led-red" class="led-indicator <?php echo ($current_rgb_value > 20) ? 'led-red' : 'led-off'; ?>"></div>
                                    <div class="led-label">Red</div>
                                </div>
                                <div class="text-center">
                                    <div id="led-yellow" class="led-indicator <?php echo ($current_rgb_value > 10 && $current_rgb_value <= 20) ? 'led-yellow' : 'led-off'; ?>"></div>
                                    <div class="led-label">Yellow</div>
                                </div>
                                <div class="text-center">
                                    <div id="led-green" class="led-indicator <?php echo ($current_rgb_value > 0 && $current_rgb_value <= 10) ? 'led-green' : 'led-off'; ?>"></div>
                                    <div class="led-label">Green</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Sensor Data -->
            <div class="col-md-8">
                <div class="card card-dashboard h-100">
                    <div class="card-body">
                        <h5 class="card-title">Current Sensor Data</h5>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="sensor-card">
                                    <div class="sensor-icon">🌡️</div>
                                    <h2 id="current-temp" class="sensor-value"><?php echo isset($sensor_data['temperature']) ? $sensor_data['temperature'] : 'N/A'; ?> °C</h2>
                                    <p class="sensor-label">Temperature</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="sensor-card">
                                    <div class="sensor-icon">💧</div>
                                    <h2 id="current-humidity" class="sensor-value"><?php echo isset($sensor_data['humidity']) ? $sensor_data['humidity'] : 'N/A'; ?> %</h2>
                                    <p class="sensor-label">Humidity</p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <canvas id="sensorChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Historical Data Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card card-dashboard">
                    <div class="card-body">
                        <h5 class="card-title">Sensor History</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Temperature</th>
                                        <th>Humidity</th>
                                        <th>RGB Value</th>
                                        <th>MQTT Status</th>
                                    </tr>
                                </thead>
                                <tbody id="history-table-body">
                                    <?php
                                    $history_data = $conn->query("SELECT * FROM sensor_readings ORDER BY timestamp DESC LIMIT 10");
                                    while ($row = $history_data->fetch_assoc()) {
                                        echo "<tr>";
                                        echo "<td>" . $row['timestamp'] . "</td>";
                                        echo "<td>" . $row['temperature'] . " °C</td>";
                                        echo "<td>" . $row['humidity'] . " %</td>";
                                        echo "<td>" . $row['rgb_value'] . "</td>";
                                        echo "<td>" . $row['mqtt_status'] . "</td>";
                                        echo "</tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart initialization
        const ctx = document.getElementById('sensorChart').getContext('2d');
        const sensorChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($timestamps); ?>,
                datasets: [{
                    label: 'Temperature (°C)',
                    data: <?php echo json_encode($temp_data); ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 2,
                    tension: 0.4
                }, {
                    label: 'Humidity (%)',
                    data: <?php echo json_encode($humidity_data); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                }
            }
        });

        // MQTT Client
        const mqttClient = mqtt.connect('wss://5083093cbb184aedb7c5c48140d30bb3.s1.eu.hivemq.cloud:8884/mqtt', {
            username: 'testing',
            password: 'Testing123',
            clientId: 'web_client_' + Math.random().toString(16).substr(2, 8),
            clean: true,
            connectTimeout: 4000
        });

        // MQTT connection handling
        mqttClient.on('connect', function() {
            console.log('Connected to MQTT broker');
            document.getElementById('mqtt-status-indicator').className = 'status-indicator status-connected';
            document.getElementById('mqtt-status-text').innerText = 'MQTT: Connected';

            // Subscribe to topics
            mqttClient.subscribe('wahyu/temperature');
            mqttClient.subscribe('wahyu/humidity');
            mqttClient.subscribe('wahyu/control');
        });

        mqttClient.on('error', function(error) {
            console.log('MQTT error:', error);
            document.getElementById('mqtt-status-indicator').className = 'status-indicator status-disconnected';
            document.getElementById('mqtt-status-text').innerText = 'MQTT: Error';
        });

        mqttClient.on('disconnect', function() {
            console.log('Disconnected from MQTT broker');
            document.getElementById('mqtt-status-indicator').className = 'status-indicator status-disconnected';
            document.getElementById('mqtt-status-text').innerText = 'MQTT: Disconnected';
        });

        // Handle incoming messages
        mqttClient.on('message', function(topic, message) {
            const value = message.toString();
            console.log('Received message:', topic, value);

            if (topic === 'wahyu/temperature') {
                document.getElementById('current-temp').innerText = value + ' °C';

                // Update chart (simplified - in real implementation you would need more logic)
                const latestData = sensorChart.data.datasets[0].data;
                latestData.shift();
                latestData.push(parseFloat(value));
                sensorChart.update();

                // Save to database via AJAX (simplified)
                // In real implementation, you would batch this or handle it server-side
                saveSensorData('temperature', value);
            }

            if (topic === 'wahyu/humidity') {
                document.getElementById('current-humidity').innerText = value + ' %';

                // Update chart
                const latestData = sensorChart.data.datasets[1].data;
                latestData.shift();
                latestData.push(parseFloat(value));
                sensorChart.update();

                // Save to database
                saveSensorData('humidity', value);
            }
            
            if (topic === 'wahyu/control') {
                updateLedIndicators(parseInt(value));
            }
        });

        function sendRGBValue(value) {
            if (mqttClient && mqttClient.connected) {
                mqttClient.publish("wahyu/control", value.toString());
                console.log("RGB value sent:", value);
                
                // Update LED indicators immediately for better UX
                updateLedIndicators(value);
                
                // Send to server for persistence
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'rgb_value=' + value
                });
            } else {
                console.warn("MQTT not connected.");
            }
        }

        // Update LED indicators based on RGB value
        function updateLedIndicators(value) {
            // Reset all LEDs
            document.getElementById('led-red').className = 'led-indicator led-off';
            document.getElementById('led-yellow').className = 'led-indicator led-off';
            document.getElementById('led-green').className = 'led-indicator led-off';

            // Set the active LED
            if (value > 0 && value <= 10) {
                document.getElementById('led-green').className = 'led-indicator led-green';
            } else if (value > 10 && value <= 20) {
                document.getElementById('led-yellow').className = 'led-indicator led-yellow';
            } else if (value > 20) {
                document.getElementById('led-red').className = 'led-indicator led-red';
            }
        }

        // Function to save sensor data to database via AJAX
        function saveSensorData(type, value) {
            // In real implementation, you would have a dedicated endpoint
            // This is simplified for demonstration
            console.log(`Saving ${type}: ${value}`);
        }
        
        // Add event listeners for button hover effects
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>

</html>