<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Certificate System Setup</h1>";

$baseDir = __DIR__;
$certificatesDir = $baseDir . '/certificates/';
$fpdfDir = $baseDir . '/fpdf/';

// Create certificates folder
if (!file_exists($certificatesDir)) {
    if (mkdir($certificatesDir, 0777, true)) {
        echo "<p>✅ Created certificates folder</p>";
    } else {
        echo "<p>❌ Failed to create certificates folder</p>";
    }
} else {
    echo "<p>✅ Certificates folder already exists</p>";
}

// Check writable
if (is_writable($certificatesDir)) {
    echo "<p>✅ Certificates folder is writable</p>";
} else {
    echo "<p>⚠️ Certificates folder is NOT writable - check permissions</p>";
}

// Check FPDF folder
if (!file_exists($fpdfDir)) {
    if (mkdir($fpdfDir, 0777, true)) {
        echo "<p>✅ Created fpdf folder</p>";
    } else {
        echo "<p>❌ Failed to create fpdf folder</p>";
    }
} else {
    echo "<p>✅ FPDF folder already exists</p>";
}

// Check for fpdf.php
$fpdfFile = $fpdfDir . 'fpdf.php';
if (file_exists($fpdfFile)) {
    echo "<p>✅ FPDF library found</p>";
} else {
    echo "<p>❌ FPDF library NOT found</p>";
    echo "<p>   Download from: <a href='http://www.fpdf.org/' target='_blank'>http://www.fpdf.org/</a></p>";
    echo "<p>   Extract and place fpdf.php in: " . $fpdfDir . "</p>";
}

// Test database connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=capstone", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p>✅ Database connection successful</p>";

    // Check if certificates table exists
    $result = $pdo->query("SHOW TABLES LIKE 'certificates'");
    if ($result->rowCount() > 0) {
        echo "<p>✅ Certificates table exists</p>";
    } else {
        echo "<p>⚠️ Certificates table does NOT exist</p>";
        echo "<p>   Run the SQL in phpMyAdmin (instructions provided)</p>";
    }
} catch (PDOException $e) {
    echo "<p>❌ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Setup Checklist:</h2>";
echo "<ol>";
echo "<li>Create certificates folder ✓</li>";
echo "<li>Create fpdf folder ✓</li>";
echo "<li>Download FPDF from <a href='http://www.fpdf.org/'>fpdf.org</a></li>";
echo "<li>Place fpdf.php in fpdf/ folder</li>";
echo "<li>Run the SQL table creation in phpMyAdmin</li>";
echo "<li>Test by accessing generate_certificate.php</li>";
echo "</ol>";
?>
```

---

### **Step 6: Run Setup**

1. Visit: **http://localhost/flutter_backend/setup.php**
2. Follow any instructions it shows
3. Make sure everything shows **✅**

---

### **Step 7: Test Certificate Generation**

Once setup is complete, test it:

**Open in browser:**
```
http://localhost/flutter_backend/generate_certificate.php?action=check&user_id=27&skill_id=31