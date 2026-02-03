<?php
// calendar_events.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
$host = 'localhost';
$dbname = 'capstone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_events':
        getEvents($pdo);
        break;
    case 'get_events_by_month':
        getEventsByMonth($pdo);
        break;
    case 'add_event':
        addEvent($pdo);
        break;
    case 'update_event':
        updateEvent($pdo);
        break;
    case 'delete_event':
        deleteEvent($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// Get all events for a user
function getEvents($pdo)
{
    try {
        $user_id = $_GET['user_id'] ?? null;

        if (!$user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 
                id,
                user_id,
                title,
                description,
                event_date,
                start_time,
                end_time,
                color,
                created_at,
                updated_at
            FROM calendar_events 
            WHERE user_id = :user_id
            ORDER BY event_date ASC, start_time ASC
        ");

        $stmt->execute(['user_id' => $user_id]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'events' => $events
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// Get events for a specific month
function getEventsByMonth($pdo)
{
    try {
        $user_id = $_GET['user_id'] ?? null;
        $year = $_GET['year'] ?? null;
        $month = $_GET['month'] ?? null;

        if (!$user_id || !$year || !$month) {
            echo json_encode(['success' => false, 'message' => 'User ID, year, and month are required']);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 
                id,
                user_id,
                title,
                description,
                event_date,
                start_time,
                end_time,
                color,
                created_at,
                updated_at
            FROM calendar_events 
            WHERE user_id = :user_id
            AND YEAR(event_date) = :year
            AND MONTH(event_date) = :month
            ORDER BY event_date ASC, start_time ASC
        ");

        $stmt->execute([
            'user_id' => $user_id,
            'year' => $year,
            'month' => $month
        ]);

        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'events' => $events
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// Add new event
function addEvent($pdo)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        $user_id = $data['user_id'] ?? null;
        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;
        $event_date = $data['event_date'] ?? null;
        $start_time = $data['start_time'] ?? null;
        $end_time = $data['end_time'] ?? null;
        $color = $data['color'] ?? 'blue';

        if (!$user_id || !$title || !$event_date) {
            echo json_encode(['success' => false, 'message' => 'User ID, title, and event date are required']);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO calendar_events 
            (user_id, title, description, event_date, start_time, end_time, color) 
            VALUES 
            (:user_id, :title, :description, :event_date, :start_time, :end_time, :color)
        ");

        $stmt->execute([
            'user_id' => $user_id,
            'title' => $title,
            'description' => $description,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'color' => $color
        ]);

        $event_id = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Event added successfully',
            'event_id' => $event_id
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// Update event
function updateEvent($pdo)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        $event_id = $data['event_id'] ?? null;
        $user_id = $data['user_id'] ?? null;
        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;
        $event_date = $data['event_date'] ?? null;
        $start_time = $data['start_time'] ?? null;
        $end_time = $data['end_time'] ?? null;
        $color = $data['color'] ?? null;

        if (!$event_id || !$user_id) {
            echo json_encode(['success' => false, 'message' => 'Event ID and User ID are required']);
            return;
        }

        // Build dynamic update query
        $updates = [];
        $params = ['event_id' => $event_id, 'user_id' => $user_id];

        if ($title !== null) {
            $updates[] = "title = :title";
            $params['title'] = $title;
        }
        if ($description !== null) {
            $updates[] = "description = :description";
            $params['description'] = $description;
        }
        if ($event_date !== null) {
            $updates[] = "event_date = :event_date";
            $params['event_date'] = $event_date;
        }
        if ($start_time !== null) {
            $updates[] = "start_time = :start_time";
            $params['start_time'] = $start_time;
        }
        if ($end_time !== null) {
            $updates[] = "end_time = :end_time";
            $params['end_time'] = $end_time;
        }
        if ($color !== null) {
            $updates[] = "color = :color";
            $params['color'] = $color;
        }

        if (empty($updates)) {
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            return;
        }

        $sql = "UPDATE calendar_events SET " . implode(', ', $updates) . " 
                WHERE id = :event_id AND user_id = :user_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'Event updated successfully'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// Delete event
function deleteEvent($pdo)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        $event_id = $data['event_id'] ?? null;
        $user_id = $data['user_id'] ?? null;

        if (!$event_id || !$user_id) {
            echo json_encode(['success' => false, 'message' => 'Event ID and User ID are required']);
            return;
        }

        $stmt = $pdo->prepare("
            DELETE FROM calendar_events 
            WHERE id = :event_id AND user_id = :user_id
        ");

        $stmt->execute([
            'event_id' => $event_id,
            'user_id' => $user_id
        ]);

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Event deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Event not found or you do not have permission to delete it'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}
