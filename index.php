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
        .led-indicator {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin: 10px;
            display: inline-block;
        }
        .led-off {
            background-color: #ccc;
        }
        .led-red {
            background-color: #ff4136;
        }
        .led-yellow {
            background-color: #ffdc00;
        }
        .led-green {
            background-color: #2ecc40;
        }
        .card-dashboard {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .status-indicator {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .status-connected {
            background-color: #2ecc40;
        }
        .status-disconnected {
            background-color: #ff4136;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <header class="pb-3 mb-4 border-bottom">
            <h1 class="display-5 fw-bold">Smart Home Controller</h1>
        </header>

        <div class="row">
            <!-- Status & Controls -->
            <div class="col-md-4">
                <div class="card card-dashboard h-100">
                    <div class="card-body">
                        <h5 class="card-title">System Status</h5>
                        <div class="mb-3">
                            <div class="d-flex align-items-center">
                                <div id="mqtt-status-indicator" class="status-indicator status-disconnected"></div>
                                <span id="mqtt-status-text">MQTT: Disconnected</span>
                            </div>
                        </div>
                        
                        <h5 class="mt-4">LED Control</h5>
                        <form method="post" id="rgb-control-form">
                            <div class="mb-3">
                                <label for="rgb-value" class="form-label">RGB Value (0-30):</label>
                                <input type="number" class="form-control" id="rgb-value" name="rgb_value" 
                                       min="0" max="30" value="<?php echo isset($_SESSION['rgb_value']) ? $_SESSION['rgb_value'] : 0; ?>">
                            </div>
                            <button type="submit" class="btn btn-primary">Update LED</button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <div id="led-red" class="led-indicator led-off"></div>
                            <div id="led-yellow" class="led-indicator led-off"></div>
                            <div id="led-green" class="led-indicator led-off"></div>
                        </div>
                        <div class="text-center">
                            <span>Red</span>
                            <span class="mx-4">Yellow</span>
                            <span>Green</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Current Sensor Data -->
            <div class="col-md-8">
                <div class="card card-dashboard h-100">
                    <div class="card-body">
                        <h5 class="card-title">Current Sensor Data</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="text-center">
                                    <h2 id="current-temp"><?php echo isset($sensor_data['temperature']) ? $sensor_data['temperature'] : 'N/A'; ?> °C</h2>
                                    <p>Temperature</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center">
                                    <h2 id="current-humidity"><?php echo isset($sensor_data['humidity']) ? $sensor_data['humidity'] : 'N/A'; ?> %</h2>
                                    <p>Humidity</p>
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
                            <table class="table table-striped">
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
                    borderWidth: 2
                }, {
                    label: 'Humidity (%)',
                    data: <?php echo json_encode($humidity_data); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
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
            mqttClient.subscribe('test/temperature');
            mqttClient.subscribe('test/humidity');
            mqttClient.subscribe('test/rgb_control');
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
            
            if (topic === 'test/temperature') {
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
            
            if (topic === 'test/humidity') {
                document.getElementById('current-humidity').innerText = value + ' %';
                
                // Update chart
                const latestData = sensorChart.data.datasets[1].data;
                latestData.shift();
                latestData.push(parseFloat(value));
                sensorChart.update();
                
                // Save to database
                saveSensorData('humidity', value);
            }
        });
        
        // Form submission handler
        document.getElementById('rgb-control-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const rgbValue = document.getElementById('rgb-value').value;
            
            // Publish to MQTT
            mqttClient.publish('test/rgb_control', rgbValue.toString());
            
            // Update LED indicators
            updateLedIndicators(rgbValue);
            
            // Form submission via AJAX
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'rgb_value=' + rgbValue
            });
        });
        
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
        
        // Initialize LED indicators based on current value
        const initialRgbValue = document.getElementById('rgb-value').value;
        if (initialRgbValue) {
            updateLedIndicators(initialRgbValue);
        }
    </script>
</body>
</html>