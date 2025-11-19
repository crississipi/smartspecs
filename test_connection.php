<?php
/**
 * Test script to verify AivenDB connection
 * Run this file in your browser: http://localhost/portfolio/ai-chatbot/test_connection.php
 */

require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>AivenDB Connection Test</h1>
    
    <?php
    try {
        echo "<h2>Testing Connection...</h2>";
        
        $conn = getDBConnection();
        
        if ($conn) {
            echo "<p class='success'>✓ Database connection successful!</p>";
            
            // Test query
            $result = $conn->query("SELECT VERSION() as version, DATABASE() as database_name");
            if ($result) {
                $row = $result->fetch_assoc();
                echo "<p><strong>MySQL Version:</strong> " . htmlspecialchars($row['version']) . "</p>";
                echo "<p><strong>Database:</strong> " . htmlspecialchars($row['database_name']) . "</p>";
            }
            
            // Check if tables exist
            echo "<h3>Checking Tables...</h3>";
            $tables = ['users', 'password_resets', 'threads', 'messages', 'user_preferences'];
            $existingTables = [];
            
            foreach ($tables as $table) {
                $result = $conn->query("SHOW TABLES LIKE '$table'");
                if ($result && $result->num_rows > 0) {
                    $existingTables[] = $table;
                    echo "<p class='success'>✓ Table '$table' exists</p>";
                } else {
                    echo "<p class='error'>✗ Table '$table' does not exist</p>";
                }
            }
            
            if (count($existingTables) < count($tables)) {
                echo "<h3>⚠️ Setup Required</h3>";
                echo "<p>Some tables are missing. Please run the <code>database.sql</code> script in your AivenDB console.</p>";
                echo "<p>See <code>setup_aivendb.md</code> for instructions.</p>";
            } else {
                echo "<h3 class='success'>✓ All tables are set up correctly!</h3>";
            }
            
            // Test SSL connection
            $result = $conn->query("SHOW STATUS LIKE 'Ssl_cipher'");
            if ($result) {
                $row = $result->fetch_assoc();
                if (!empty($row['Value'])) {
                    echo "<p class='success'>✓ SSL Connection Active</p>";
                    echo "<p><strong>SSL Cipher:</strong> " . htmlspecialchars($row['Value']) . "</p>";
                } else {
                    echo "<p class='error'>⚠️ SSL connection may not be active</p>";
                }
            }
            
        } else {
            echo "<p class='error'>✗ Database connection failed</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<h3>Debugging Information:</h3>";
        echo "<pre>";
        echo "Host: " . DB_HOST . "\n";
        echo "Port: " . DB_PORT . "\n";
        echo "User: " . DB_USER . "\n";
        echo "Database: " . DB_NAME . "\n";
        echo "SSL: " . (DB_SSL ? 'Enabled' : 'Disabled') . "\n";
        echo "</pre>";
    }
    ?>
    
    <hr>
    <p><a href="index.html">← Back to Application</a></p>
</body>
</html>

