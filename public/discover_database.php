<?php
// ===== discover_database.php =====
// Save this file as: C:\xampp\htdocs\flutter_backend\discover_database.php
// Then visit: http://localhost/flutter_backend/discover_database.php

header('Content-Type: text/html; charset=utf-8');
    // cors.php - Include this at the top of your PHP files
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
?>
<!DOCTYPE html>
<html>

<head>
    <title>Database Discovery Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        h2 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }

        .success {
            color: #4CAF50;
            font-weight: bold;
        }

        .error {
            color: #f44336;
            font-weight: bold;
        }

        .info {
            color: #2196F3;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }

        table td,
        table th {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        table th {
            background: #4CAF50;
            color: white;
        }

        .code {
            background: #f4f4f4;
            padding: 15px;
            border-left: 4px solid #4CAF50;
            margin: 10px 0;
            overflow-x: auto;
        }

        pre {
            margin: 0;
        }
    </style>
</head>

<body>
    <h1>🔍 Database Discovery Tool</h1>

    <?php
    // Database connection details
    $servername = "localhost";
    $username = "root";
    $password = "";

    echo '<div class="section">';
    echo '<h2>Step 1: Testing MySQL Connection</h2>';

    // Try to connect to MySQL (without selecting a database)
    $conn = @new mysqli($servername, $username, $password);

    if ($conn->connect_error) {
        echo '<p class="error">❌ Connection Failed: ' . $conn->connect_error . '</p>';
        echo '<p>Make sure XAMPP MySQL is running!</p>';
        exit;
    } else {
        echo '<p class="success">✅ MySQL Connection Successful!</p>';
    }
    echo '</div>';

    // List all databases
    echo '<div class="section">';
    echo '<h2>Step 2: Available Databases</h2>';

    $result = $conn->query("SHOW DATABASES");

    if ($result) {
        echo '<table>';
        echo '<tr><th>Database Name</th><th>Recommended for Your App?</th></tr>';

        $app_databases = [];
        while ($row = $result->fetch_assoc()) {
            $db_name = $row['Database'];
            $is_system = in_array($db_name, ['information_schema', 'mysql', 'performance_schema', 'phpmyadmin', 'test']);

            if (!$is_system) {
                $app_databases[] = $db_name;
                echo '<tr>';
                echo '<td><strong>' . htmlspecialchars($db_name) . '</strong></td>';
                echo '<td class="success">✅ Likely your app database</td>';
                echo '</tr>';
            } else {
                echo '<tr style="opacity: 0.5;">';
                echo '<td>' . htmlspecialchars($db_name) . '</td>';
                echo '<td class="info">System database (ignore)</td>';
                echo '</tr>';
            }
        }
        echo '</table>';

        if (empty($app_databases)) {
            echo '<p class="error">⚠️ No app databases found! You may need to create one.</p>';
        }
    }
    echo '</div>';

    // Check each app database for tables
    if (!empty($app_databases)) {
        foreach ($app_databases as $db_name) {
            echo '<div class="section">';
            echo '<h2>Step 3: Analyzing Database: ' . htmlspecialchars($db_name) . '</h2>';

            $conn->select_db($db_name);

            // Check for tables
            $tables_result = $conn->query("SHOW TABLES");

            if ($tables_result && $tables_result->num_rows > 0) {
                echo '<p class="success">✅ Found ' . $tables_result->num_rows . ' tables</p>';
                echo '<table>';
                echo '<tr><th>Table Name</th><th>Status</th></tr>';

                $has_users = false;
                $has_coins = false;
                $has_transactions = false;

                while ($row = $tables_result->fetch_array()) {
                    $table_name = $row[0];
                    echo '<tr><td>' . htmlspecialchars($table_name) . '</td>';

                    if ($table_name === 'users') {
                        $has_users = true;
                        echo '<td class="success">✅ Users table found!</td>';
                    } elseif ($table_name === 'transactions') {
                        $has_transactions = true;
                        echo '<td class="success">✅ Transactions table exists</td>';
                    } else {
                        echo '<td class="info">Regular table</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';

                // Check users table for coins column
                if ($has_users) {
                    $columns_result = $conn->query("DESCRIBE users");
                    echo '<h3>Users Table Structure:</h3>';
                    echo '<table>';
                    echo '<tr><th>Column</th><th>Type</th><th>Status</th></tr>';

                    while ($col = $columns_result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($col['Field']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Type']) . '</td>';

                        if ($col['Field'] === 'coins') {
                            $has_coins = true;
                            echo '<td class="success">✅ Coins column exists!</td>';
                        } else {
                            echo '<td>-</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</table>';

                    if (!$has_coins) {
                        echo '<p class="error">⚠️ Coins column NOT found! You need to add it.</p>';
                    }
                }

                // Generate db_connection.php code
                echo '<h3>📝 Your db_connection.php Code:</h3>';
                echo '<div class="code"><pre>';
                echo htmlspecialchars('<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "' . $db_name . '";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        \'success\' => false,
        \'message\' => \'Connection failed: \' . $conn->connect_error
    ]));
}

$conn->set_charset("utf8mb4");
?>');
                echo '</pre></div>';

                // Show next steps
                echo '<h3>📋 Next Steps:</h3>';
                echo '<ol>';

                if (!$has_coins) {
                    echo '<li class="error">Add coins column to users table:
                    <div class="code"><pre>ALTER TABLE users ADD COLUMN coins INT NOT NULL DEFAULT 100;</pre></div>
                    </li>';
                }

                if (!$has_transactions) {
                    echo '<li class="error">Create transactions table:
                    <div class="code"><pre>CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    receiver_email VARCHAR(255) NOT NULL,
    amount INT NOT NULL,
    skill_id VARCHAR(255),
    transaction_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);</pre></div>
                    </li>';
                }

                echo '<li>Copy the db_connection.php code above and save it as:
                <div class="code"><pre>C:\\xampp\\htdocs\\flutter_backend\\db_connection.php</pre></div>
                </li>';

                echo '<li class="success">Your database is ready to use!</li>';
                echo '</ol>';
            } else {
                echo '<p class="error">⚠️ No tables found in this database.</p>';
            }

            echo '</div>';
        }
    }

    $conn->close();
    ?>

    <div class="section">
        <h2>🎯 Summary</h2>
        <p>This tool has analyzed your XAMPP MySQL setup. Follow the steps above to:</p>
        <ol>
            <li>Copy the generated <code>db_connection.php</code> code</li>
            <li>Run any missing SQL queries in phpMyAdmin</li>
            <li>Create the new PHP files for coin management</li>
        </ol>
        <p><strong>Need help?</strong> Open phpMyAdmin at: <a href="http://localhost/phpmyadmin" target="_blank">http://localhost/phpmyadmin</a></p>
    </div>
</body>

</html>