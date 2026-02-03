<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = $_GET['action'] ?? '';

// Helper: Sanitize labels for charts
function sanitizeLabel($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

try {
    switch ($action) {

        // ============================================
        // 1. GET STATS (Existing - Keep This)
        // ============================================
        case 'get_stats':
            // 1. Total Users
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $total_users = (int)$stmt->fetchColumn();

            // 2. Active Users (last 30 days)
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT user_id) FROM (
                    SELECT id as user_id FROM users WHERE created_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT posted_by as user_id FROM internships WHERE posted_date > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT user_id FROM community_members WHERE joined_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT requester_user_id as user_id FROM skill_requests WHERE created_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT user_id FROM user_learnings WHERE created_at > NOW() - INTERVAL 30 DAY
                ) AS active_users
            ");
            $active_users = (int)$stmt->fetchColumn();

            // 3. Total Skills Offered
            $stmt = $pdo->query("SELECT COUNT(*) FROM skills");
            $total_skills = (int)$stmt->fetchColumn();

            // 4. Total Internships Posted
            $stmt = $pdo->query("SELECT COUNT(*) FROM internships");
            $total_internships = (int)$stmt->fetchColumn();

            // 5. Total Applications
            $stmt = $pdo->query("SELECT COUNT(*) FROM applications");
            $total_applications = (int)$stmt->fetchColumn();

            // 6. Accepted Applications
            $stmt = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'accepted'");
            $accepted_applications = (int)$stmt->fetchColumn();

            // 7. Total Communities Created
            $stmt = $pdo->query("SELECT COUNT(*) FROM communities");
            $total_communities = (int)$stmt->fetchColumn();

            // 8. Total Community Memberships
            $stmt = $pdo->query("SELECT COUNT(*) FROM community_members");
            $total_memberships = (int)$stmt->fetchColumn();

            // 9. Total Skill Requests
            $stmt = $pdo->query("SELECT COUNT(*) FROM skill_requests");
            $total_skill_requests = (int)$stmt->fetchColumn();

            // 10. Pending Skill Requests
            $stmt = $pdo->query("SELECT COUNT(*) FROM skill_requests WHERE status = 'pending'");
            $pending_requests = (int)$stmt->fetchColumn();

            // 11. Total Favorites
            $stmt = $pdo->query("SELECT COUNT(*) FROM favorites");
            $total_favorites = (int)$stmt->fetchColumn();

            // 12. Total Coin Transactions
            $stmt = $pdo->query("SELECT COUNT(*) FROM coin_transactions");
            $total_transactions = (int)$stmt->fetchColumn();

            // 13. Total Coins in Circulation
            $stmt = $pdo->query("SELECT SUM(coins) FROM users WHERE coins IS NOT NULL");
            $total_coins = (int)($stmt->fetchColumn() ?: 0);

            // 14. Completed Learnings
            $stmt = $pdo->query("SELECT COUNT(*) FROM user_learnings WHERE status = 'completed'");
            $completed_learnings = (int)$stmt->fetchColumn();

            // 15. Platform Growth (%)
            $stmt = $pdo->query("
                SELECT 
                    (COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 END) - 
                     COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 END))
                    / NULLIF(COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 END), 0) * 100 AS growth
                FROM users
            ");
            $growth = round((float)($stmt->fetchColumn() ?: 0), 1);

            echo json_encode([
                'total_users' => $total_users,
                'active_users' => $active_users,
                'total_skills' => $total_skills,
                'total_internships' => $total_internships,
                'total_applications' => $total_applications,
                'accepted_applications' => $accepted_applications,
                'total_communities' => $total_communities,
                'total_memberships' => $total_memberships,
                'total_skill_requests' => $total_skill_requests,
                'pending_requests' => $pending_requests,
                'total_favorites' => $total_favorites,
                'total_transactions' => $total_transactions,
                'total_coins' => $total_coins,
                'completed_learnings' => $completed_learnings,
                'platform_growth' => $growth
            ]);
            break;

        // ============================================
        // 2. USER ACTIVITY (Line Chart)
        // ============================================
        case 'get_user_growth':
            $stmt = $pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%b') AS month_label,
                    YEAR(created_at) AS year,
                    MONTH(created_at) AS month,
                    COUNT(*) AS user_count
                FROM users 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY YEAR(created_at), MONTH(created_at)
                ORDER BY YEAR(created_at), MONTH(created_at)
            ");

            // Generate last 6 months (handles missing months)
            $labels = [];
            $data = [];
            $current = new DateTime();

            for ($i = 5; $i >= 0; $i--) {
                $dt = clone $current;
                $dt->modify("-$i months");
                $monthLabel = $dt->format('M');
                $labels[] = $monthLabel;
                $data[$monthLabel] = 0;
            }

            // Fill actual data
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($data[$row['month_label']])) {
                        $data[$row['month_label']] = (int)$row['user_count'];
                    }
                }
            }

            $finalData = array_values($data);

            echo json_encode([
                'labels' => $labels,
                'data' => $finalData
            ]);
            break;

        // ============================================
        // 3. LEARNING PROGRESS (Bar Chart)
        // ============================================
        case 'get_learning_progress':
            $stmt = $pdo->query("
                SELECT 
                    status,
                    COUNT(*) AS count
                FROM user_learnings
                WHERE status IN ('completed', 'in_progress', 'enrolled')
                GROUP BY status
            ");

            $data = ['Completed' => 0, 'In Progress' => 0, 'Enrolled' => 0];
            $statusMap = [
                'completed' => 'Completed',
                'in_progress' => 'In Progress',
                'enrolled' => 'Enrolled'
            ];

            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $label = $statusMap[$row['status']] ?? null;
                    if ($label && isset($data[$label])) {
                        $data[$label] = (int)$row['count'];
                    }
                }
            }

            echo json_encode([
                'labels' => array_keys($data),
                'data' => array_values($data)
            ]);
            break;

        // ============================================
        // 4. ENGAGEMENT METRICS (Doughnut Chart)
        // ============================================
        case 'get_engagement_metrics':
            // Favorites
            $stmt = $pdo->query("SELECT COUNT(*) FROM favorites");
            $favorites = (int)$stmt->fetchColumn();

            // Skill Requests
            $stmt = $pdo->query("SELECT COUNT(*) FROM skill_requests");
            $requests = (int)$stmt->fetchColumn();

            // Applications
            $stmt = $pdo->query("SELECT COUNT(*) FROM applications");
            $applications = (int)$stmt->fetchColumn();

            // Communities
            $stmt = $pdo->query("SELECT COUNT(*) FROM communities");
            $communities = (int)$stmt->fetchColumn();

            echo json_encode([
                'labels' => ['Favorites', 'Requests', 'Applications', 'Communities'],
                'data' => [$favorites, $requests, $applications, $communities]
            ]);
            break;

        // ============================================
        // 5. MONTHLY ACTIVITY (Bar Chart)
        // ============================================
        case 'get_monthly_activity':
            // Active Users (last 30 days)
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT user_id) FROM (
                    SELECT id as user_id FROM users WHERE created_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT user_id FROM community_members WHERE joined_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT user_id FROM user_learnings WHERE created_at > NOW() - INTERVAL 30 DAY
                ) AS active_users
            ");
            $activeUsers = (int)$stmt->fetchColumn();

            // New Skills (last 30 days)
            $stmt = $pdo->query("SELECT COUNT(*) FROM skills WHERE created_at > NOW() - INTERVAL 30 DAY");
            $newSkills = (int)$stmt->fetchColumn();

            // New Internships (last 30 days)
            $stmt = $pdo->query("SELECT COUNT(*) FROM internships WHERE posted_date > NOW() - INTERVAL 30 DAY");
            $newInternships = (int)$stmt->fetchColumn();

            echo json_encode([
                'labels' => ['Active Users', 'New Skills', 'New Internships'],
                'data' => [$activeUsers, $newSkills, $newInternships]
            ]);
            break;

        // ============================================
        // 6. TOP METRICS (Radar Chart)
        // ============================================
        case 'get_top_metrics':
            // Total Users
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $users = (int)$stmt->fetchColumn();

            // Total Skills
            $stmt = $pdo->query("SELECT COUNT(*) FROM skills");
            $skills = (int)$stmt->fetchColumn();

            // Total Transactions
            $stmt = $pdo->query("SELECT COUNT(*) FROM coin_transactions");
            $transactions = (int)$stmt->fetchColumn();

            // Total Communities
            $stmt = $pdo->query("SELECT COUNT(*) FROM communities");
            $communities = (int)$stmt->fetchColumn();

            // Total Internships
            $stmt = $pdo->query("SELECT COUNT(*) FROM internships");
            $internships = (int)$stmt->fetchColumn();

            echo json_encode([
                'labels' => ['Users', 'Skills', 'Transactions', 'Communities', 'Internships'],
                'data' => [$users, $skills, $transactions, $communities, $internships]
            ]);
            break;

        // ============================================
        // 7. PLATFORM GROWTH (Polar Area Chart)
        // ============================================
        case 'get_platform_growth':
            // Platform Growth %
            $stmt = $pdo->query("
                SELECT 
                    (COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 END) - 
                     COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 END))
                    / NULLIF(COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 END), 0) * 100 AS growth
                FROM users
            ");
            $growth = round((float)($stmt->fetchColumn() ?: 0), 1);

            // Active Users
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT user_id) FROM (
                    SELECT id as user_id FROM users WHERE created_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT user_id FROM community_members WHERE joined_at > NOW() - INTERVAL 30 DAY
                    UNION
                    SELECT user_id FROM user_learnings WHERE created_at > NOW() - INTERVAL 30 DAY
                ) AS active_users
            ");
            $activeUsers = (int)$stmt->fetchColumn();

            // Completed Learnings
            $stmt = $pdo->query("SELECT COUNT(*) FROM user_learnings WHERE status = 'completed'");
            $completed = (int)$stmt->fetchColumn();

            // Total Transactions
            $stmt = $pdo->query("SELECT COUNT(*) FROM coin_transactions");
            $transactions = (int)$stmt->fetchColumn();

            echo json_encode([
                'labels' => ['Growth %', 'Active Users', 'Completed Courses', 'Coin Transactions'],
                'data' => [$growth, $activeUsers, $completed, $transactions]
            ]);
            break;

        // ============================================
        // 8. SKILL RATINGS (Bar Chart) - FIXED: Lowered minimum to 1 rating
        // ============================================
        case 'get_skill_ratings':
            try {
                $stmt = $pdo->query("
                    SELECT 
                        s.name AS label,
                        ROUND(AVG(r.rating), 1) AS avg_rating,
                        COUNT(r.id) AS rating_count
                    FROM skills s
                    INNER JOIN ratings r ON s.id = r.rateable_id AND r.rateable_type = 'skill'
                    GROUP BY s.id, s.name
                    HAVING rating_count >= 1  -- ✅ CHANGED FROM >= 3 TO >= 1
                    ORDER BY avg_rating DESC, rating_count DESC
                    LIMIT 5
                ");

                $labels = [];
                $data = [];

                if ($stmt && $stmt->rowCount() > 0) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $labels[] = sanitizeLabel($row['label']);
                        $data[] = (float)$row['avg_rating'];
                    }
                }

                echo json_encode([
                    'labels' => $labels,
                    'data' => $data
                ]);
            } catch (PDOException $e) {
                // Table might not exist - return empty but valid response
                echo json_encode(['labels' => [], 'data' => []]);
            }
            break;

        // ============================================
        // 9. COMMUNITY RATINGS (Bar Chart) - FIXED: Lowered minimum to 1 rating
        // ============================================
        case 'get_community_ratings':
            try {
                $stmt = $pdo->query("
                    SELECT 
                        c.name AS label,
                        ROUND(AVG(r.rating), 1) AS avg_rating,
                        COUNT(r.id) AS rating_count
                    FROM communities c
                    INNER JOIN ratings r ON c.id = r.rateable_id AND r.rateable_type = 'community'
                    GROUP BY c.id, c.name
                    HAVING rating_count >= 1  -- ✅ CHANGED FROM >= 2 TO >= 1
                    ORDER BY avg_rating DESC, rating_count DESC
                    LIMIT 4
                ");

                $labels = [];
                $data = [];

                if ($stmt && $stmt->rowCount() > 0) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $labels[] = sanitizeLabel($row['label']);
                        $data[] = (float)$row['avg_rating'];
                    }
                }

                echo json_encode([
                    'labels' => $labels,
                    'data' => $data
                ]);
            } catch (PDOException $e) {
                // Table might not exist - return empty but valid response
                echo json_encode(['labels' => [], 'data' => []]);
            }
            break;

        // ============================================
        // 10. TOP ENROLLED COMMUNITIES (Horizontal Bar)
        // ============================================
        case 'get_top_communities':
            $stmt = $pdo->query("
                SELECT 
                    c.name AS label,
                    COUNT(cm.user_id) AS member_count
                FROM communities c
                LEFT JOIN community_members cm ON c.id = cm.community_id
                GROUP BY c.id, c.name
                ORDER BY member_count DESC
                LIMIT 4
            ");

            $labels = [];
            $data = [];

            if ($stmt && $stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $labels[] = sanitizeLabel($row['label']);
                    $data[] = (int)$row['member_count'];
                }
            }

            echo json_encode([
                'labels' => $labels,
                'data' => $data
            ]);
            break;

        // ============================================
        // 11. TOP ENROLLED SKILLS (Horizontal Bar)
        // ============================================
        case 'get_top_skills':
            $stmt = $pdo->query("
                SELECT 
                    s.name AS label,
                    COUNT(ul.user_id) AS enrolled_count
                FROM skills s
                LEFT JOIN user_learnings ul ON s.id = ul.skill_id
                GROUP BY s.id, s.name
                ORDER BY enrolled_count DESC
                LIMIT 4
            ");

            $labels = [];
            $data = [];

            if ($stmt && $stmt->rowCount() > 0) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $labels[] = sanitizeLabel($row['label']);
                    $data[] = (int)$row['enrolled_count'];
                }
            }

            echo json_encode([
                'labels' => $labels,
                'data' => $data
            ]);
            break;

        // ============================================
        // DEFAULT: Invalid Action
        // ============================================
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action: ' . $action]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch data: ' . $e->getMessage()]);
}
