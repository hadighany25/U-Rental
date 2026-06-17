<?php
// ភ្ជាប់ទៅកាន់ឯកសារផ្ទៀងផ្ទាត់ Database
include 'config.php';
header('Content-Type: application/json');

// ឆែកមើលថាតើមានការបញ្ជូន Room ID មកដែរឬទេ
if (isset($_GET['room_id'])) {
    $room_id = intval($_GET['room_id']);
    
    // ទាញយកតែការកក់ណាដែលមិនទាន់បាន Checked Out តែប៉ុណ្ណោះ
    $stmt = $conn->prepare("SELECT check_in_time, check_out_time FROM bookings WHERE room_id = ? AND status != 'checked_out' ORDER BY check_in_time ASC");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = [
            'check_in' => $row['check_in_time'],
            'check_out' => $row['check_out_time'],
            'formatted_in' => date('d M Y, h:i A', strtotime($row['check_in_time'])),
            'formatted_out' => date('d M Y, h:i A', strtotime($row['check_out_time']))
        ];
    }
    // បញ្ជូនទិន្នន័យចេញជាទម្រង់ JSON
    echo json_encode($bookings);
    exit();
}
echo json_encode([]);
?>